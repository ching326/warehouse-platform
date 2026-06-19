<?php

namespace App\Livewire;

use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\Shop;
use App\Models\Sku;
use App\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Facades\Excel;

class SalesOrderImport extends Component
{
    use WithFileUploads;

    #[Url(as: 'shop_id', except: '')]
    public string $shopId = '';

    public string $importFormat = 'generic';

    public ?TemporaryUploadedFile $file = null;

    public array $parsedRows = [];

    public bool $parsed = false;

    public bool $hasErrors = false;

    private bool $allowedTenantIdsResolved = false;

    private array $allowedTenantIdsCache = [];

    public function mount(): void
    {
        $this->authorizeTenantAccess();
    }

    public function updatedShopId(): void
    {
        $this->resetPreview();
    }

    public function updatedImportFormat(): void
    {
        $this->resetPreview();
    }

    public function updatedFile(): void
    {
        $this->resetPreview();
    }

    public function parse(): void
    {
        $shop = $this->validatedShop();

        if ($this->importFormat === 'amazon_report') {
            $this->validateAmazonShop($shop);
            $this->validate(['file' => ['required', 'file', 'mimes:txt', 'max:5120']]);
            [$parsed, $hasErrors] = $this->parseAmazonRows($shop);
        } else {
            $this->validate(['file' => ['required', 'file', 'mimes:csv,txt,xlsx', 'max:5120']]);
            [$parsed, $hasErrors] = $this->parseGenericRows($shop);
        }

        $this->parsedRows = $parsed;
        $this->parsed = true;
        $this->hasErrors = $hasErrors;
    }

    public function import()
    {
        $shop = $this->validatedShop();

        if (! $this->parsed || $this->parsedRows === []) {
            session()->flash('error', __('sales_orders.import_nothing_to_import'));

            return;
        }

        if ($this->hasErrors) {
            session()->flash('error', __('sales_orders.import_has_errors'));

            return;
        }

        $groups = [];
        foreach ($this->parsedRows as $row) {
            if ($row['platform_order_id'] !== '') {
                $groups[$row['platform_order_id']][] = $row;
            }
        }

        $platformOrderIds = array_keys($groups);
        $duplicates = SalesOrder::query()
            ->where('tenant_id', $shop->tenant_id)
            ->where('shop_id', $shop->id)
            ->whereIn('platform_order_id', $platformOrderIds)
            ->pluck('platform_order_id')
            ->all();

        $duplicateLookup = array_flip($duplicates);
        $groups = array_filter(
            $groups,
            fn (string $platformOrderId) => ! isset($duplicateLookup[$platformOrderId]),
            ARRAY_FILTER_USE_KEY
        );

        $skippedDuplicateCount = count($duplicates);

        if ($groups === []) {
            session()->flash('status', __('sales_orders.import_no_new_orders'));

            return;
        }

        $orderCount = 0;

        try {
            DB::transaction(function () use ($shop, $groups, &$orderCount) {
                foreach ($groups as $platformOrderId => $rows) {
                    $first = $rows[0];

                    $order = SalesOrder::create([
                        'tenant_id' => $shop->tenant_id,
                        'shop_id' => $shop->id,
                        'source' => $first['source'] ?? SalesOrder::SOURCE_CSV,
                        'platform_order_id' => $platformOrderId,
                        'platform_ordered_at' => $this->nullableDate($first['platform_ordered_at'] ?? null),
                        'latest_ship_at' => $this->nullableDate($first['latest_ship_at'] ?? null),
                        'order_status' => $first['order_status'] ?? SalesOrder::ORDER_STATUS_PENDING,
                        'fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_UNFULFILLED,
                        'shipping_method' => $first['shipping_method'] ?? null,
                        'recipient_name' => $this->nullableString($first['recipient_name']),
                        'recipient_phone' => $this->nullableString($first['recipient_phone']),
                        'recipient_country_code' => $this->nullableString($first['recipient_country_code']),
                        'recipient_postal_code' => $this->nullableString($first['recipient_postal_code']),
                        'recipient_state' => $this->nullableString($first['recipient_state']),
                        'recipient_city' => $this->nullableString($first['recipient_city']),
                        'recipient_address_line1' => $this->nullableString($first['recipient_address_line1']),
                        'recipient_address_line2' => $this->nullableString($first['recipient_address_line2']),
                        'note' => $this->nullableString($first['order_note']),
                        'created_by_user_id' => Auth::id(),
                    ]);

                    foreach ($rows as $line) {
                        $order->lines()->create([
                            'platform_line_id' => $this->nullableString($line['platform_line_id'] ?? ''),
                            'platform_product_name' => $this->nullableString($line['platform_product_name'] ?? ''),
                            'sku_id' => $line['sku_id'],
                            'quantity' => $line['quantity'],
                            'unit_price' => $line['unit_price'] ?? null,
                            'currency' => $line['currency'] ?? null,
                            'line_status' => SalesOrderLine::STATUS_READY,
                            'note' => $this->nullableString($line['line_note']),
                        ]);
                    }

                    $orderCount++;
                }
            });
        } catch (QueryException $exception) {
            if (! $this->isDuplicateOrderConstraintViolation($exception)) {
                throw $exception;
            }

            session()->flash('error', __('sales_orders.import_duplicate_race_retry'));

            return;
        }

        $this->resetPreview();
        $this->reset('file');

        session()->flash('status', __('sales_orders.import_succeeded', [
            'orders' => $orderCount,
            'skipped' => $skippedDuplicateCount,
        ]));

        return redirect()->route('sales.orders.index');
    }

    public function render()
    {
        return view('livewire.sales-order-import', [
            'shops' => $this->shopOptions(),
            'importFormatOptions' => $this->importFormatOptions(),
        ])->layout('inventory', [
            'title' => __('sales_orders.import_page_title'),
            'subtitle' => __('sales_orders.import_page_subtitle'),
        ]);
    }

    private function parseGenericRows(Shop $shop): array
    {
        $rows = $this->readRows();

        if ($rows === []) {
            session()->flash('error', __('sales_orders.import_empty_file'));

            return [[], false];
        }

        $skuMap = $this->skuMapForShop($shop);
        $existingOrderIds = $this->existingPlatformOrderIds($shop);

        $parsed = [];
        $hasErrors = false;

        foreach ($rows as $index => $raw) {
            $rowNo = $index + 1;
            $errors = [];
            $skuNotFound = false;

            $orderId = trim((string) ($raw['platform_order_id'] ?? ''));
            $skuCode = trim((string) ($raw['sku'] ?? ''));
            $isDuplicate = $orderId !== '' && in_array($orderId, $existingOrderIds, true);
            $quantityRaw = trim((string) ($raw['quantity'] ?? ''));
            $quantity = null;

            if ($orderId === '') {
                $errors[] = __('sales_orders.import_missing_order_id');
            }

            if (! $isDuplicate) {
                if ($quantityRaw === '') {
                    $errors[] = __('sales_orders.import_missing_quantity');
                } elseif (! preg_match('/^[1-9]\d*$/', $quantityRaw)) {
                    $errors[] = __('sales_orders.import_bad_quantity');
                } else {
                    $quantity = (int) $quantityRaw;
                }

                if ($skuCode === '') {
                    $errors[] = __('sales_orders.import_missing_sku');
                } elseif (! $skuMap->has($skuCode)) {
                    $skuNotFound = true;
                    $errors[] = __('sales_orders.import_unknown_sku', ['sku' => $skuCode]);
                }
            }

            $parsed[] = [
                'row' => $rowNo,
                'is_duplicate' => $isDuplicate,
                'sku_not_found' => $skuNotFound,
                'tenant_id' => $shop->tenant_id,
                'shop_id' => $shop->id,
                'platform_order_id' => $orderId,
                'sku' => $skuCode,
                'sku_id' => $skuMap->get($skuCode),
                'quantity' => $quantity ?? 0,
                'line_note' => trim((string) ($raw['line_note'] ?? '')),
                'recipient_name' => trim((string) ($raw['recipient_name'] ?? '')),
                'recipient_phone' => trim((string) ($raw['recipient_phone'] ?? '')),
                'recipient_country_code' => strtoupper(trim((string) ($raw['recipient_country_code'] ?? ''))),
                'recipient_postal_code' => trim((string) ($raw['recipient_postal_code'] ?? '')),
                'recipient_state' => trim((string) ($raw['recipient_state'] ?? '')),
                'recipient_city' => trim((string) ($raw['recipient_city'] ?? '')),
                'recipient_address_line1' => trim((string) ($raw['recipient_address_line1'] ?? '')),
                'recipient_address_line2' => trim((string) ($raw['recipient_address_line2'] ?? '')),
                'order_note' => trim((string) ($raw['order_note'] ?? '')),
                'errors' => $errors,
            ];

            if ($errors !== []) {
                $hasErrors = true;
            }
        }

        return $this->validateParsedOrderRows($parsed, $hasErrors, [
            'recipient_name',
            'recipient_phone',
            'recipient_country_code',
            'recipient_postal_code',
            'recipient_state',
            'recipient_city',
            'recipient_address_line1',
            'recipient_address_line2',
            'order_note',
        ]);
    }

    private function parseAmazonRows(Shop $shop): array
    {
        $rows = $this->readAmazonRows();

        if ($rows === []) {
            session()->flash('error', __('sales_orders.import_empty_file'));

            return [[], false];
        }

        $skuMap = $this->skuMapForShop($shop);
        $existingOrderIds = $this->existingPlatformOrderIds($shop);
        $parsed = [];
        $hasErrors = false;

        foreach ($rows as $index => $raw) {
            $errors = [];
            $skuNotFound = false;
            $orderId = trim((string) ($raw['order-id'] ?? ''));
            $skuCode = trim((string) ($raw['sku'] ?? ''));
            $isDuplicate = $orderId !== '' && in_array($orderId, $existingOrderIds, true);
            $quantityRaw = trim((string) ($raw['quantity-purchased'] ?? ''));
            $quantity = null;

            if ($orderId === '') {
                $errors[] = __('sales_orders.import_missing_order_id');
            }

            if (! $isDuplicate) {
                if ($quantityRaw === '') {
                    $errors[] = __('sales_orders.import_missing_quantity');
                } elseif (! preg_match('/^[1-9]\d*$/', $quantityRaw)) {
                    $errors[] = __('sales_orders.import_bad_quantity');
                } else {
                    $quantity = (int) $quantityRaw;
                }

                if ($skuCode === '') {
                    $errors[] = __('sales_orders.import_missing_sku');
                } elseif (! $skuMap->has($skuCode)) {
                    $skuNotFound = true;
                    $errors[] = __('sales_orders.import_unknown_sku', ['sku' => $skuCode]);
                }
            }

            $platformOrderedAt = $this->parseAmazonDate($raw['purchase-date'] ?? null);
            $latestShipAt = $this->parseAmazonDate($raw['latest-ship-date'] ?? null);

            if (! $isDuplicate && ($raw['purchase-date'] ?? '') !== '' && $platformOrderedAt === null) {
                $errors[] = __('sales_orders.import_amazon_bad_date');
            }

            if (! $isDuplicate && ($raw['latest-ship-date'] ?? '') !== '' && $latestShipAt === null) {
                $errors[] = __('sales_orders.import_amazon_bad_date');
            }

            $cancelRequested = $this->amazonBoolean($raw['is-buyer-requested-cancellation'] ?? '');
            $recipientPhone = trim((string) ($raw['ship-phone-number'] ?? ''));
            $buyerPhone = trim((string) ($raw['buyer-phone-number'] ?? ''));
            $currency = trim((string) (($raw['currency'] ?? '') !== '' ? $raw['currency'] : ($raw['order-currency-code'] ?? '')));
            $itemPrice = trim((string) (($raw['item-price'] ?? '') !== '' ? $raw['item-price'] : ($raw['item-price-amount'] ?? '')));

            $row = [
                'row' => $index + 1,
                'is_duplicate' => $isDuplicate,
                'sku_not_found' => $skuNotFound,
                'tenant_id' => $shop->tenant_id,
                'shop_id' => $shop->id,
                'source' => SalesOrder::SOURCE_AMAZON_REPORT,
                'platform_order_id' => $orderId,
                'platform_ordered_at' => $platformOrderedAt,
                'latest_ship_at' => $latestShipAt,
                'shipping_method' => $this->normalizeAmazonShippingMethod((string) ($raw['ship-service-level'] ?? '')),
                'order_status' => $cancelRequested ? SalesOrder::ORDER_STATUS_CANCEL_REQUESTED : SalesOrder::ORDER_STATUS_PENDING,
                'sku' => $skuCode,
                'sku_id' => $skuMap->get($skuCode),
                'quantity' => $quantity ?? 0,
                'platform_line_id' => trim((string) ($raw['order-item-id'] ?? '')),
                'platform_product_name' => trim((string) ($raw['product-name'] ?? '')),
                'unit_price' => $this->amazonUnitPrice($itemPrice, $quantity),
                'currency' => $currency !== '' ? strtoupper($currency) : null,
                'line_note' => '',
                'recipient_name' => trim((string) ($raw['recipient-name'] ?? '')),
                'recipient_phone' => $recipientPhone !== '' ? $recipientPhone : $buyerPhone,
                'recipient_country_code' => strtoupper(trim((string) ($raw['ship-country'] ?? ''))),
                'recipient_postal_code' => trim((string) ($raw['ship-postal-code'] ?? '')),
                'recipient_state' => trim((string) ($raw['ship-state'] ?? '')),
                'recipient_city' => trim((string) ($raw['ship-city'] ?? '')),
                'recipient_address_line1' => trim((string) ($raw['ship-address-1'] ?? '')),
                'recipient_address_line2' => trim(implode(' ', array_filter([
                    trim((string) ($raw['ship-address-2'] ?? '')),
                    trim((string) ($raw['ship-address-3'] ?? '')),
                ], fn ($value) => $value !== ''))),
                'order_note' => '',
                'amazon_consistency' => $this->amazonConsistencyFields($raw),
                'errors' => $errors,
            ];

            if (! $isDuplicate && $row['recipient_country_code'] === '') {
                $row['errors'][] = __('sales_orders.import_bad_country');
            }

            if ($row['errors'] !== []) {
                $hasErrors = true;
            }

            $parsed[] = $row;
        }

        [$parsed, $hasErrors] = $this->validateParsedOrderRows($parsed, $hasErrors, ['amazon_consistency']);

        foreach ($parsed as $idx => $row) {
            unset($parsed[$idx]['amazon_consistency']);
        }

        return [$parsed, $hasErrors];
    }

    private function readAmazonRows(): array
    {
        $path = $this->file->getRealPath();
        $content = file_get_contents($path);
        $content = preg_replace('/^\xEF\xBB\xBF/', '', (string) $content);
        $content = mb_convert_encoding($content, 'UTF-8', $this->detectFileEncoding($path));
        $content = preg_replace('/^\x{FEFF}/u', '', $content);
        $lines = preg_split("/\r\n|\n|\r/", $content);

        if (! $lines || count($lines) < 2) {
            return [];
        }

        $headerLine = array_shift($lines);
        $header = array_map(fn ($value) => strtolower(trim((string) $value)), str_getcsv((string) $headerLine, "\t", '"', ''));
        $requiredHeaders = [
            'order-id',
            'order-item-id',
            'purchase-date',
            'sku',
            'product-name',
            'quantity-purchased',
            'recipient-name',
            'ship-address-1',
            'ship-state',
            'ship-postal-code',
            'ship-country',
            'shipment-status',
            'is-buyer-requested-cancellation',
        ];
        $missingHeaders = array_values(array_diff($requiredHeaders, $header));

        if ($missingHeaders !== []) {
            throw ValidationException::withMessages([
                'file' => __('sales_orders.import_amazon_missing_headers', [
                    'headers' => implode(', ', $missingHeaders),
                ]),
            ]);
        }

        $rows = [];

        foreach ($lines as $index => $line) {
            if (trim((string) $line) === '') {
                continue;
            }

            $values = str_getcsv((string) $line, "\t", '"', '');
            $values = array_slice(array_pad($values, count($header), ''), 0, count($header));
            $row = array_combine($header, $values);
            $row['__row'] = $index + 2;
            $rows[] = $row;
        }

        return $rows;
    }

    private function readRows(): array
    {
        $sheets = Excel::toArray(new class implements ToArray
        {
            public function array(array $array): void {}
        }, $this->file->getRealPath());

        $sheet = $sheets[0] ?? [];

        if (count($sheet) < 2) {
            return [];
        }

        $header = array_map(fn ($value) => strtolower(trim((string) $value)), $sheet[0]);
        $missingHeaders = array_values(array_diff(['platform_order_id', 'sku', 'quantity'], $header));

        if ($missingHeaders !== []) {
            throw ValidationException::withMessages([
                'file' => __('sales_orders.import_missing_headers', [
                    'headers' => implode(', ', $missingHeaders),
                ]),
            ]);
        }

        $rows = [];

        foreach ($sheet as $index => $line) {
            if ($index === 0) {
                continue;
            }

            if (collect($line)->every(fn ($value) => trim((string) $value) === '')) {
                continue;
            }

            $values = array_slice(array_pad($line, count($header), null), 0, count($header));
            $row = array_combine($header, $values);
            $row['__row'] = $index + 1;
            $rows[] = $row;
        }

        return $rows;
    }

    private function validateParsedOrderRows(array $parsed, bool $hasErrors, array $orderFieldKeys): array
    {
        foreach ($parsed as $idx => $row) {
            if ($row['is_duplicate'] ?? false) {
                continue;
            }

            if ($row['recipient_country_code'] !== '' && ! preg_match('/^[A-Z]{2}$/', $row['recipient_country_code'])) {
                $parsed[$idx]['errors'][] = __('sales_orders.import_bad_country');
                $hasErrors = true;
            }
        }

        $orderIdGroups = [];
        foreach ($parsed as $idx => $row) {
            if ($row['platform_order_id'] !== '') {
                $orderIdGroups[$row['platform_order_id']][] = $idx;
            }
        }

        foreach ($orderIdGroups as $orderId => $indices) {
            if (count($indices) <= 1) {
                continue;
            }

            $first = $parsed[$indices[0]];
            $hasConflict = false;

            foreach ($indices as $idx) {
                if ($parsed[$idx]['is_duplicate'] ?? false) {
                    continue;
                }

                foreach ($orderFieldKeys as $key) {
                    if (($parsed[$idx][$key] ?? null) !== ($first[$key] ?? null)) {
                        $hasConflict = true;
                        break 2;
                    }
                }
            }

            if ($hasConflict) {
                foreach ($indices as $idx) {
                    $parsed[$idx]['errors'][] = __('sales_orders.import_conflicting_order_fields', ['id' => $orderId]);
                }

                $hasErrors = true;
            }
        }

        return [$parsed, $hasErrors];
    }

    private function skuMapForShop(Shop $shop): Collection
    {
        return Sku::query()
            ->where('tenant_id', $shop->tenant_id)
            ->where('shop_id', $shop->id)
            ->where('status', 'active')
            ->where(fn ($query) => $query
                ->where('sku_type', 'virtual_bundle')
                ->orWhereNotNull('stock_item_id'))
            ->pluck('id', 'sku');
    }

    private function existingPlatformOrderIds(Shop $shop): array
    {
        return SalesOrder::query()
            ->where('tenant_id', $shop->tenant_id)
            ->where('shop_id', $shop->id)
            ->whereNotNull('platform_order_id')
            ->pluck('platform_order_id')
            ->all();
    }

    private function detectFileEncoding(string $path): string
    {
        $header = file_get_contents($path, false, null, 0, 4);

        foreach ([
            "\xEF\xBB\xBF" => 'UTF-8',
            "\xFF\xFE" => 'UTF-16LE',
            "\xFE\xFF" => 'UTF-16BE',
        ] as $bom => $encoding) {
            if (strncmp((string) $header, $bom, strlen($bom)) === 0) {
                return $encoding;
            }
        }

        $sample = file_get_contents($path, false, null, 0, 4096);

        return mb_detect_encoding((string) $sample, ['UTF-8', 'SJIS-win', 'CP932', 'EUC-JP'], true) ?: 'CP932';
    }

    private function parseAmazonDate(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->utc()->toDateTimeString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function nullableDate(?string $value): ?Carbon
    {
        $value = trim((string) $value);

        return $value === '' ? null : Carbon::createFromFormat('Y-m-d H:i:s', $value, 'UTC');
    }

    private function amazonBoolean(?string $value): bool
    {
        return in_array(strtolower(trim((string) $value)), ['true', '1', 'yes', 'y'], true);
    }

    private function amazonUnitPrice(string $itemPrice, ?int $quantity): ?string
    {
        $itemPrice = str_replace(',', '', trim($itemPrice));

        if ($itemPrice === '' || ! is_numeric($itemPrice) || ! $quantity || $quantity < 1) {
            return null;
        }

        return number_format(((float) $itemPrice) / $quantity, 2, '.', '');
    }

    private function normalizeAmazonShippingMethod(string $shipServiceLevel): ?string
    {
        return match (strtolower(trim($shipServiceLevel))) {
            'yamato' => 'yamato',
            'sagawa' => 'sagawa',
            'japan_post', 'japan post' => 'japan_post',
            default => null,
        };
    }

    private function amazonConsistencyFields(array $raw): array
    {
        $keys = [
            'purchase-date',
            'latest-ship-date',
            'ship-service-level',
            'recipient-name',
            'ship-phone-number',
            'buyer-phone-number',
            'ship-country',
            'ship-postal-code',
            'ship-state',
            'ship-city',
            'ship-address-1',
            'ship-address-2',
            'ship-address-3',
            'is-buyer-requested-cancellation',
        ];

        $fields = [];
        foreach ($keys as $key) {
            $fields[$key] = trim((string) ($raw[$key] ?? ''));
        }

        return $fields;
    }

    private function resetPreview(): void
    {
        $this->parsedRows = [];
        $this->parsed = false;
        $this->hasErrors = false;
    }

    private function validatedShop(): Shop
    {
        if ($this->shopId === '') {
            throw ValidationException::withMessages(['shopId' => __('sales_orders.shop_required')]);
        }

        $shop = Shop::query()
            ->where('status', 'active')
            ->whereIn('tenant_id', $this->allowedTenantIds())
            ->find((int) $this->shopId);

        if (! $shop) {
            throw ValidationException::withMessages(['shopId' => __('sales_orders.invalid_shop')]);
        }

        return $shop;
    }

    private function validateAmazonShop(Shop $shop): void
    {
        if ($shop->platform !== 'amazon') {
            throw ValidationException::withMessages(['shopId' => __('sales_orders.import_amazon_only')]);
        }
    }

    private function shopOptions(): Collection
    {
        return Shop::query()
            ->whereIn('tenant_id', $this->allowedTenantIds())
            ->where('status', 'active')
            ->with('tenant:id,code')
            ->orderBy('name')
            ->get(['id', 'tenant_id', 'platform', 'marketplace', 'code', 'name']);
    }

    private function importFormatOptions(): array
    {
        return [
            'generic' => __('sales_orders.import_format_generic'),
            'amazon_report' => __('sales_orders.import_format_amazon_report'),
        ];
    }

    // TODO: remove unauthenticated fallback when auth is implemented
    private function isInternalUser(): bool
    {
        $user = Auth::user();

        return ! $user || $user->user_type === 'internal';
    }

    private function allowedTenantIds(): array
    {
        if ($this->allowedTenantIdsResolved) {
            return $this->allowedTenantIdsCache;
        }

        $this->allowedTenantIdsResolved = true;

        if ($this->isInternalUser()) {
            return $this->allowedTenantIdsCache = Tenant::query()->pluck('id')->all();
        }

        return $this->allowedTenantIdsCache = Auth::user()
            ->tenantUsers()
            ->where('status', 'active')
            ->pluck('tenant_id')
            ->all();
    }

    private function authorizeTenantAccess(): void
    {
        if (! $this->isInternalUser() && $this->allowedTenantIds() === []) {
            abort(403);
        }
    }

    private function nullableString(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function isDuplicateOrderConstraintViolation(QueryException $exception): bool
    {
        $message = $exception->getMessage();

        return in_array($exception->getCode(), ['23000', '23505'], true)
            || str_contains($message, 'sales_orders_tenant_shop_platform_order_unique')
            || str_contains($message, 'UNIQUE constraint failed: sales_orders.tenant_id, sales_orders.shop_id, sales_orders.platform_order_id');
    }
}
