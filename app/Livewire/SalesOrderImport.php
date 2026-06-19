<?php

namespace App\Livewire;

use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\Shop;
use App\Models\Sku;
use App\Models\Tenant;
use Illuminate\Support\Collection;
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

    public function updatedFile(): void
    {
        $this->resetPreview();
    }

    public function parse(): void
    {
        $shop = $this->validatedShop();

        $this->validate([
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx', 'max:5120'],
        ]);

        $rows = $this->readRows();

        if ($rows === []) {
            session()->flash('error', __('sales_orders.import_empty_file'));

            return;
        }

        $skuMap = Sku::query()
            ->where('tenant_id', $shop->tenant_id)
            ->where('shop_id', $shop->id)
            ->where('status', 'active')
            ->where(fn ($query) => $query
                ->where('sku_type', 'virtual_bundle')
                ->orWhereNotNull('stock_item_id'))
            ->pluck('id', 'sku');

        $existingOrderIds = SalesOrder::query()
            ->where('tenant_id', $shop->tenant_id)
            ->where('shop_id', $shop->id)
            ->whereNotNull('platform_order_id')
            ->pluck('platform_order_id')
            ->all();

        $parsed = [];
        $hasErrors = false;

        foreach ($rows as $raw) {
            $rowNo = (int) $raw['__row'];
            $errors = [];

            $orderId = trim((string) ($raw['platform_order_id'] ?? ''));
            $skuCode = trim((string) ($raw['sku'] ?? ''));
            $quantityRaw = trim((string) ($raw['quantity'] ?? ''));
            $quantity = null;

            if ($quantityRaw === '') {
                $errors[] = __('sales_orders.import_missing_quantity');
            } elseif (! preg_match('/^[1-9]\d*$/', $quantityRaw)) {
                $errors[] = __('sales_orders.import_bad_quantity');
            } else {
                $quantity = (int) $quantityRaw;
            }

            if ($orderId === '') {
                $errors[] = __('sales_orders.import_missing_order_id');
            }

            if ($skuCode === '') {
                $errors[] = __('sales_orders.import_missing_sku');
            } elseif (! $skuMap->has($skuCode)) {
                $errors[] = __('sales_orders.import_unknown_sku', ['sku' => $skuCode]);
            }

            if ($orderId !== '' && in_array($orderId, $existingOrderIds, true)) {
                $errors[] = __('sales_orders.import_duplicate_order', ['id' => $orderId]);
            }

            $parsed[] = [
                'row' => $rowNo,
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

        foreach ($parsed as $idx => $row) {
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

        $orderFieldKeys = [
            'recipient_name',
            'recipient_phone',
            'recipient_country_code',
            'recipient_postal_code',
            'recipient_state',
            'recipient_city',
            'recipient_address_line1',
            'recipient_address_line2',
            'order_note',
        ];

        foreach ($orderIdGroups as $orderId => $indices) {
            if (count($indices) <= 1) {
                continue;
            }

            $first = $parsed[$indices[0]];
            $hasConflict = false;

            foreach ($indices as $idx) {
                foreach ($orderFieldKeys as $key) {
                    if ($parsed[$idx][$key] !== $first[$key]) {
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

        if ($duplicates !== []) {
            session()->flash('error', __('sales_orders.import_duplicate_during_confirm', [
                'ids' => implode(', ', $duplicates),
            ]));

            return;
        }

        $orderCount = 0;

        DB::transaction(function () use ($shop, $groups, &$orderCount) {
            foreach ($groups as $platformOrderId => $rows) {
                $first = $rows[0];

                $order = SalesOrder::create([
                    'tenant_id' => $shop->tenant_id,
                    'shop_id' => $shop->id,
                    'source' => SalesOrder::SOURCE_CSV,
                    'platform_order_id' => $platformOrderId,
                    'order_status' => SalesOrder::ORDER_STATUS_PENDING,
                    'fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_UNFULFILLED,
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
                        'sku_id' => $line['sku_id'],
                        'quantity' => $line['quantity'],
                        'line_status' => SalesOrderLine::STATUS_READY,
                        'note' => $this->nullableString($line['line_note']),
                    ]);
                }

                $orderCount++;
            }
        });

        $this->resetPreview();
        $this->reset('file');

        session()->flash('status', __('sales_orders.import_succeeded', [
            'orders' => $orderCount,
        ]));

        return redirect()->route('sales.orders.index');
    }

    public function render()
    {
        return view('livewire.sales-order-import', [
            'shops' => $this->shopOptions(),
        ])->layout('inventory', [
            'title' => __('sales_orders.import_page_title'),
            'subtitle' => __('sales_orders.import_page_subtitle'),
        ]);
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

    private function shopOptions(): Collection
    {
        return Shop::query()
            ->whereIn('tenant_id', $this->allowedTenantIds())
            ->where('status', 'active')
            ->with('tenant:id,code')
            ->orderBy('name')
            ->get(['id', 'tenant_id', 'platform', 'marketplace', 'code', 'name']);
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
}
