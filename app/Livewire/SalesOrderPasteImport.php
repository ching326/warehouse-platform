<?php

namespace App\Livewire;

use App\Models\SalesOrder;
use App\Models\SalesOrderPasteImportMapping;
use App\Models\Shop;
use App\Models\Tenant;
use App\Services\SalesOrders\SalesOrderImporter;
use App\Support\SalesOrderSkuIdentifierMap;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\Component;

class SalesOrderPasteImport extends Component
{
    private const ROW_COUNT = 60;

    private const COL_COUNT = 18;

    #[Url(as: 'shop_id', except: '')]
    public string $shopId = '';

    public array $grid = [];

    public array $parsedRows = [];

    public bool $parsed = false;

    public bool $hasErrors = false;

    public array $columnMapping = [];

    public array $columnFieldMapping = [];

    public int $dataStartRow = 0;

    public string $selectedTemplateId = '';

    public string $templateName = '';

    public bool $saveTemplateAsDefault = false;

    private bool $allowedTenantIdsResolved = false;

    private array $allowedTenantIdsCache = [];

    public function mount(): void
    {
        $this->authorizeTenantAccess();
        $this->grid = $this->blankGrid();

        if ($this->shopId !== '') {
            $this->loadDefaultTemplateForSelectedShop();
        }
    }

    public function updatedShopId(): void
    {
        $this->applySelectedShopChange();
    }

    public function selectShop(string $shopId): void
    {
        $this->shopId = $shopId;
        $this->applySelectedShopChange();
    }

    public function updatedColumnMapping(): void
    {
        $this->columnFieldMapping = $this->fieldMappingToColumnFieldMapping($this->normalizeMapping($this->columnMapping));
        $this->resetPreview();
    }

    public function updatedColumnFieldMapping(mixed $value, ?string $key = null): void
    {
        if (is_array($value) || $key === null) {
            $this->columnFieldMapping = $this->uniqueColumnFieldMapping((array) $this->columnFieldMapping);
            $this->columnMapping = $this->stringifyMapping($this->columnFieldMappingToFieldMapping($this->columnFieldMapping));
            $this->resetPreview();

            return;
        }

        $value = (string) $value;

        if ($value !== '') {
            foreach ($this->columnFieldMapping as $column => $field) {
                if ((string) $column !== (string) $key && $field === $value) {
                    $this->columnFieldMapping[$column] = '';
                }
            }
        }

        $this->columnMapping = $this->stringifyMapping($this->columnFieldMappingToFieldMapping($this->columnFieldMapping));
        $this->resetPreview();
    }

    public function updatedDataStartRow(): void
    {
        $this->resetPreview();
    }

    public function updatedSaveTemplateAsDefault(bool $value): void
    {
        if (! $value) {
            return;
        }

        if ($this->selectedTemplateId === '') {
            $this->saveTemplateAsDefault = false;
            session()->flash('error', __('sales_orders.paste_import_template_default_requires_saved'));

            return;
        }

        $this->setSelectedTemplateAsDefault();
    }

    public function clearGrid(): void
    {
        $this->grid = $this->blankGrid();
        $this->columnMapping = [];
        $this->columnFieldMapping = [];
        $this->dataStartRow = 0;
        $this->selectedTemplateId = '';
        $this->templateName = '';
        $this->saveTemplateAsDefault = false;
        $this->resetPreview();
    }

    public function loadTemplate(): void
    {
        if ($this->selectedTemplateId === '') {
            return;
        }

        $tenantId = $this->validatedTemplateTenantId();
        $template = SalesOrderPasteImportMapping::query()
            ->where('tenant_id', $tenantId)
            ->find((int) $this->selectedTemplateId);

        if (! $template) {
            return;
        }

        $this->setMapping((array) $template->mapping);
        $this->dataStartRow = max(0, min(self::ROW_COUNT - 1, (int) $template->data_start_row));
        $this->saveTemplateAsDefault = (bool) $template->is_default;
        $this->resetPreview();
        session()->flash('status', __('sales_orders.paste_import_template_loaded'));
    }

    public function saveTemplate(): void
    {
        $tenantId = $this->validatedTemplateTenantId();
        $name = trim($this->templateName);

        if ($name === '') {
            throw ValidationException::withMessages([
                'templateName' => __('sales_orders.paste_import_template_name_required'),
            ]);
        }

        [$mapping, $dataStartRow] = $this->mappingForParse();

        if ($this->saveTemplateAsDefault) {
            SalesOrderPasteImportMapping::query()
                ->where('tenant_id', $tenantId)
                ->update(['is_default' => false]);
        }

        $template = SalesOrderPasteImportMapping::query()->updateOrCreate(
            ['tenant_id' => $tenantId, 'name' => $name],
            [
                'mapping' => $mapping,
                'data_start_row' => $dataStartRow,
                'is_default' => $this->saveTemplateAsDefault,
                'created_by_user_id' => Auth::id(),
            ],
        );

        $this->selectedTemplateId = (string) $template->id;
        $this->saveTemplateAsDefault = (bool) $template->is_default;
        session()->flash('status', __('sales_orders.paste_import_template_saved'));
    }

    public function preview(): void
    {
        $shop = $this->validatedShop();
        [$parsed, $hasErrors] = $this->parseGridRows($shop);

        $this->parsedRows = $parsed;
        $this->parsed = true;
        $this->hasErrors = $hasErrors;
    }

    public function import()
    {
        if (! $this->parsed || $this->parsedRows === []) {
            session()->flash('error', __('sales_orders.import_nothing_to_import'));

            return null;
        }

        if ($this->hasErrors) {
            session()->flash('error', __('sales_orders.import_has_errors'));

            return null;
        }

        $shop = $this->validatedShop();

        try {
            $result = app(SalesOrderImporter::class)->import($shop, $this->parsedRows, Auth::id());
        } catch (QueryException $exception) {
            if ($this->isDuplicateOrderConstraintViolation($exception)) {
                session()->flash('error', __('sales_orders.import_duplicate_race_retry'));

                return null;
            }

            throw $exception;
        }

        if ($result->importedOrders === 0) {
            session()->flash('error', __('sales_orders.import_no_new_orders'));

            return null;
        }

        session()->flash('status', __('sales_orders.import_succeeded', [
            'orders' => $result->importedOrders,
            'skipped' => $result->skippedDuplicates,
        ]));

        return redirect()->route('sales.orders.index');
    }

    public function render()
    {
        return view('livewire.sales-order-paste-import', [
            'shops' => $this->shopOptions(),
            'columnLabels' => range('A', 'R'),
            'fieldOptions' => $this->fieldOptions(),
            'templates' => $this->templateOptions(),
        ])->layout('inventory', [
            'title' => __('sales_orders.paste_import_page_title'),
            'subtitle' => __('sales_orders.paste_import_page_subtitle'),
        ]);
    }

    private function parseGridRows(Shop $shop): array
    {
        $rows = $this->filledGridRows();

        if ($rows === []) {
            session()->flash('error', __('sales_orders.import_empty_file'));

            return [[], false];
        }

        [$headerMap, $dataStartRow] = $this->mappingForParse();

        $skuMap = $this->skuMapForShop($shop);
        $existingOrderIds = $this->existingPlatformOrderIds($shop);
        $parsed = [];
        $hasErrors = false;
        $dataRowNo = 0;
        $lastOrderContext = [];
        $orderContextFields = [
            'platform_order_id',
            'platform_ordered_at',
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
        $allFields = array_merge($orderContextFields, [
            'platform_product_name',
            'sku',
            'quantity',
            'line_note',
        ]);

        foreach (array_slice($rows, $dataStartRow) as $raw) {
            if ($this->looksLikeSeparatorRow($raw)) {
                continue;
            }

            $raw = $this->repairExtraBlankCellsAroundSku($raw, $headerMap);

            $values = [];
            foreach ($allFields as $field) {
                $values[$field] = $this->cell($raw, $headerMap, $field);
            }

            if ($values['platform_order_id'] === '' && $values['sku'] !== '' && $lastOrderContext !== []) {
                foreach ($orderContextFields as $field) {
                    if ($values[$field] === '') {
                        $values[$field] = $lastOrderContext[$field];
                    }
                }
            }

            if ($values['platform_order_id'] !== '') {
                $lastOrderContext = array_intersect_key($values, array_flip($orderContextFields));
            }

            $orderId = $values['platform_order_id'];
            $skuCode = $values['sku'];

            if ($orderId === '' && $skuCode === '') {
                continue;
            }

            $dataRowNo++;
            $errors = [];
            $skuNotFound = false;
            $isDuplicate = $orderId !== '' && in_array($orderId, $existingOrderIds, true);
            $quantityRaw = $values['quantity'];
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

            $country = strtoupper($values['recipient_country_code']);
            $country = $country !== '' ? $country : 'JP';
            $address = $this->splitJapanesePrefectureFromAddress(
                $values['recipient_state'],
                $values['recipient_address_line1'],
                $this->isJapanMarketplace($shop)
            );

            $parsed[] = [
                'row' => $dataRowNo,
                'is_duplicate' => $isDuplicate,
                'sku_not_found' => $skuNotFound,
                'tenant_id' => $shop->tenant_id,
                'shop_id' => $shop->id,
                'source' => SalesOrder::SOURCE_CSV,
                'platform_order_id' => $orderId,
                'platform_ordered_at' => $this->parseDate($values['platform_ordered_at']),
                'sku' => $skuCode,
                'sku_id' => $skuMap->get($skuCode),
                'quantity' => $quantity ?? 0,
                'platform_product_name' => $values['platform_product_name'],
                'line_note' => $values['line_note'],
                'recipient_name' => $values['recipient_name'],
                'recipient_phone' => $values['recipient_phone'],
                'recipient_country_code' => $country,
                'recipient_postal_code' => $values['recipient_postal_code'],
                'recipient_state' => $address['state'],
                'recipient_city' => $values['recipient_city'],
                'recipient_address_line1' => $address['address_line1'],
                'recipient_address_line2' => $values['recipient_address_line2'],
                'order_note' => $values['order_note'],
                'errors' => $errors,
            ];

            if ($errors !== []) {
                $hasErrors = true;
            }
        }

        if ($parsed === []) {
            session()->flash('error', __('sales_orders.import_empty_file'));

            return [[], false];
        }

        return $this->validateParsedOrderRows($parsed, $hasErrors, [
            'platform_ordered_at',
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

    private function filledGridRows(): array
    {
        $rows = [];

        foreach ($this->grid as $row) {
            $values = array_map(fn ($value) => trim((string) $value), array_values((array) $row));

            if (collect($values)->every(fn ($value) => $value === '')) {
                continue;
            }

            $rows[] = array_slice(array_pad($values, self::COL_COUNT, ''), 0, self::COL_COUNT);
        }

        return $rows;
    }

    /**
     * @return array{state:string,address_line1:string}
     */
    private function splitJapanesePrefectureFromAddress(string $state, string $addressLine1, bool $isJapanMarketplace): array
    {
        if ($state !== '' || ! $isJapanMarketplace || $addressLine1 === '') {
            return ['state' => $state, 'address_line1' => $addressLine1];
        }

        foreach ($this->japanesePrefectures() as $prefecture) {
            if (str_starts_with($addressLine1, $prefecture)) {
                return [
                    'state' => $prefecture,
                    'address_line1' => trim(mb_substr($addressLine1, mb_strlen($prefecture))),
                ];
            }
        }

        return ['state' => $state, 'address_line1' => $addressLine1];
    }

    private function isJapanMarketplace(Shop $shop): bool
    {
        return strtoupper(trim((string) $shop->marketplace)) === 'JP';
    }

    /**
     * @return array<int,string>
     */
    private function japanesePrefectures(): array
    {
        return [
            '北海道',
            '青森県',
            '岩手県',
            '宮城県',
            '秋田県',
            '山形県',
            '福島県',
            '茨城県',
            '栃木県',
            '群馬県',
            '埼玉県',
            '千葉県',
            '東京都',
            '神奈川県',
            '新潟県',
            '富山県',
            '石川県',
            '福井県',
            '山梨県',
            '長野県',
            '岐阜県',
            '静岡県',
            '愛知県',
            '三重県',
            '滋賀県',
            '京都府',
            '大阪府',
            '兵庫県',
            '奈良県',
            '和歌山県',
            '鳥取県',
            '島根県',
            '岡山県',
            '広島県',
            '山口県',
            '徳島県',
            '香川県',
            '愛媛県',
            '高知県',
            '福岡県',
            '佐賀県',
            '長崎県',
            '熊本県',
            '大分県',
            '宮崎県',
            '鹿児島県',
            '沖縄県',
        ];
    }

    private function mappingForParse(): array
    {
        $mapping = $this->currentMapping();

        if (! $this->hasRequiredMapping($mapping)) {
            throw ValidationException::withMessages([
                'grid' => __('sales_orders.paste_import_missing_mapping'),
            ]);
        }

        $dataStartRow = max(0, min(self::ROW_COUNT - 1, (int) $this->dataStartRow));

        return [$mapping, $dataStartRow];
    }

    private function currentMapping(): array
    {
        $columnFirstMapping = $this->columnFieldMappingToFieldMapping($this->columnFieldMapping);

        if ($columnFirstMapping !== []) {
            return $columnFirstMapping;
        }

        return $this->normalizeMapping($this->columnMapping);
    }

    private function setMapping(array $mapping): void
    {
        $normalized = $this->normalizeMapping($mapping);
        $this->columnMapping = $this->stringifyMapping($normalized);
        $this->columnFieldMapping = $this->fieldMappingToColumnFieldMapping($normalized);
    }

    private function normalizeMapping(array $mapping): array
    {
        $normalized = [];

        foreach ($this->mappingFields() as $field => $label) {
            $value = $mapping[$field] ?? '';
            if ($value === '' || ! is_numeric($value)) {
                continue;
            }

            $column = (int) $value;
            if ($column < 0 || $column >= self::COL_COUNT) {
                continue;
            }

            $normalized[$field] = $column;
        }

        return $normalized;
    }

    private function stringifyMapping(array $mapping): array
    {
        $stringified = [];

        foreach ($this->mappingFields() as $field => $label) {
            $value = $mapping[$field] ?? '';
            $stringified[$field] = $value === '' ? '' : (string) $value;
        }

        return $stringified;
    }

    private function columnFieldMappingToFieldMapping(array $mapping): array
    {
        $normalized = [];
        $validFields = array_flip(array_keys($this->mappingFields()));

        foreach ($mapping as $column => $field) {
            if ($field === '' || ! isset($validFields[$field]) || ! is_numeric($column)) {
                continue;
            }

            $column = (int) $column;
            if ($column < 0 || $column >= self::COL_COUNT) {
                continue;
            }

            $normalized[$field] = $column;
        }

        return $normalized;
    }

    private function uniqueColumnFieldMapping(array $mapping): array
    {
        $unique = array_fill(0, self::COL_COUNT, '');
        $seen = [];
        $validFields = array_flip(array_keys($this->mappingFields()));

        foreach ($mapping as $column => $field) {
            if ($field === '' || ! isset($validFields[$field]) || isset($seen[$field]) || ! is_numeric($column)) {
                continue;
            }

            $column = (int) $column;
            if ($column < 0 || $column >= self::COL_COUNT) {
                continue;
            }

            $unique[$column] = (string) $field;
            $seen[$field] = true;
        }

        return $unique;
    }

    private function fieldMappingToColumnFieldMapping(array $mapping): array
    {
        $columnMapping = array_fill(0, self::COL_COUNT, '');

        foreach ($this->normalizeMapping($mapping) as $field => $column) {
            $columnMapping[$column] = $field;
        }

        return array_map(fn ($value) => (string) $value, $columnMapping);
    }

    private function hasRequiredMapping(array $mapping): bool
    {
        return isset($mapping['platform_order_id'], $mapping['sku'], $mapping['quantity']);
    }

    private function mappingFields(): array
    {
        return [
            'platform_order_id' => __('sales_orders.field_platform_order_id'),
            'platform_ordered_at' => __('sales_orders.field_order_date'),
            'platform_product_name' => __('sales_orders.field_product_name'),
            'sku' => __('sales_orders.field_sku'),
            'quantity' => __('sales_orders.field_quantity'),
            'recipient_name' => __('sales_orders.field_recipient_name'),
            'recipient_phone' => __('sales_orders.field_recipient_phone'),
            'recipient_country_code' => __('sales_orders.field_country_code'),
            'recipient_postal_code' => __('sales_orders.field_postal_code'),
            'recipient_state' => __('sales_orders.field_state'),
            'recipient_city' => __('sales_orders.field_city'),
            'recipient_address_line1' => __('sales_orders.field_address_line1'),
            'recipient_address_line2' => __('sales_orders.field_address_line2'),
            'line_note' => __('sales_orders.field_line_note'),
            'order_note' => __('sales_orders.field_note'),
        ];
    }

    private function fieldOptions(): array
    {
        $options = ['' => __('sales_orders.paste_import_column_ignore')];

        foreach ($this->mappingFields() as $field => $label) {
            $options[$field] = $label;
        }

        return $options;
    }

    private function templateOptions(): Collection
    {
        if ($this->shopId === '') {
            return collect();
        }

        try {
            $tenantId = $this->validatedTemplateTenantId();
        } catch (ValidationException) {
            return collect();
        }

        return SalesOrderPasteImportMapping::query()
            ->where('tenant_id', $tenantId)
            ->orderBy('name')
            ->get(['id', 'name', 'is_default']);
    }

    private function loadDefaultTemplateForSelectedShop(): void
    {
        if ($this->shopId === '') {
            $this->clearTemplateSelection();

            return;
        }

        try {
            $tenantId = $this->validatedTemplateTenantId();
        } catch (ValidationException) {
            return;
        }

        $template = SalesOrderPasteImportMapping::query()
            ->where('tenant_id', $tenantId)
            ->where('is_default', true)
            ->orderByDesc('updated_at')
            ->first(['id', 'mapping', 'data_start_row', 'is_default']);

        if (! $template && SalesOrderPasteImportMapping::query()->where('tenant_id', $tenantId)->count() === 1) {
            $template = SalesOrderPasteImportMapping::query()
                ->where('tenant_id', $tenantId)
                ->first(['id', 'mapping', 'data_start_row', 'is_default']);
        }

        if (! $template) {
            return;
        }

        $this->selectedTemplateId = (string) $template->id;
        $this->setMapping((array) $template->mapping);
        $this->dataStartRow = max(0, min(self::ROW_COUNT - 1, (int) $template->data_start_row));
        $this->saveTemplateAsDefault = (bool) $template->is_default;
    }

    private function setSelectedTemplateAsDefault(): void
    {
        $tenantId = $this->validatedTemplateTenantId();
        $template = SalesOrderPasteImportMapping::query()
            ->where('tenant_id', $tenantId)
            ->find((int) $this->selectedTemplateId);

        if (! $template) {
            $this->selectedTemplateId = '';
            $this->saveTemplateAsDefault = false;

            return;
        }

        SalesOrderPasteImportMapping::query()
            ->where('tenant_id', $tenantId)
            ->update(['is_default' => false]);

        $template->update(['is_default' => true]);
        $this->saveTemplateAsDefault = true;

        session()->flash('status', __('sales_orders.paste_import_template_default_saved'));
    }

    private function applySelectedShopChange(): void
    {
        $this->clearTemplateSelection();
        $this->loadDefaultTemplateForSelectedShop();
        $this->resetPreview();
    }

    private function clearTemplateSelection(): void
    {
        $this->selectedTemplateId = '';
        $this->columnMapping = [];
        $this->columnFieldMapping = [];
        $this->dataStartRow = 0;
    }

    private function cell(array $row, array $headerMap, string $field): string
    {
        if (! array_key_exists($field, $headerMap)) {
            return '';
        }

        return trim((string) ($row[$headerMap[$field]] ?? ''));
    }

    private function repairExtraBlankCellsAroundSku(array $row, array $headerMap): array
    {
        if (! isset($headerMap['sku'], $headerMap['quantity'])) {
            return $row;
        }

        $skuCol = $headerMap['sku'];
        $quantityCol = $headerMap['quantity'];

        for ($i = 0; $i < 3; $i++) {
            $sku = trim((string) ($row[$skuCol] ?? ''));
            $next = trim((string) ($row[$skuCol + 1] ?? ''));
            $quantity = trim((string) ($row[$quantityCol] ?? ''));
            $nextQuantity = trim((string) ($row[$quantityCol + 1] ?? ''));

            if ($sku === '' && $next !== '' && ! preg_match('/^[1-9]\d*$/', $quantity)) {
                array_splice($row, $skuCol, 1);
                $row[] = '';

                continue;
            }

            if ($sku !== '' && $quantity === '' && preg_match('/^[1-9]\d*$/', $nextQuantity)) {
                array_splice($row, $quantityCol, 1);
                $row[] = '';

                continue;
            }

            if ($sku !== '' || $next === '' || preg_match('/^[1-9]\d*$/', $quantity)) {
                break;
            }
        }

        return array_slice(array_pad($row, self::COL_COUNT, ''), 0, self::COL_COUNT);
    }

    private function looksLikeSeparatorRow(array $row): bool
    {
        $joined = implode(' ', array_map(fn ($value) => trim((string) $value), $row));

        return str_contains($joined, "\u{51FA}\u{8377}")
            || str_contains($joined, "\u{96C6}\u{8377}\u{6642}\u{9593}")
            || str_contains($joined, "\u{7DE0}\u{5207}\u{6642}\u{9593}");
    }

    private function parseDate(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value, 'Asia/Tokyo')->utc()->toDateTimeString();
        } catch (\Throwable) {
            return null;
        }
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

    private function blankGrid(): array
    {
        return array_fill(0, self::ROW_COUNT, array_fill(0, self::COL_COUNT, ''));
    }

    private function skuMapForShop(Shop $shop): Collection
    {
        return SalesOrderSkuIdentifierMap::forShop($shop, 'active');
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

    private function validatedTemplateTenantId(): int
    {
        return (int) $this->validatedShop()->tenant_id;
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

    private function resetPreview(): void
    {
        $this->parsedRows = [];
        $this->parsed = false;
        $this->hasErrors = false;
    }

    private function isInternalUser(): bool
    {
        $user = Auth::user();

        return $user?->user_type === 'internal';
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

        $user = Auth::user();

        if (! $user) {
            return $this->allowedTenantIdsCache = [];
        }

        return $this->allowedTenantIdsCache = $user->activeTenantIds();
    }

    private function authorizeTenantAccess(): void
    {
        if (! $this->isInternalUser() && $this->allowedTenantIds() === []) {
            abort(403);
        }
    }

    private function isDuplicateOrderConstraintViolation(QueryException $exception): bool
    {
        $message = $exception->getMessage();

        return in_array($exception->getCode(), ['23000', '23505'], true)
            || str_contains($message, 'sales_orders_tenant_shop_platform_order_unique')
            || str_contains($message, 'UNIQUE constraint failed: sales_orders.tenant_id, sales_orders.shop_id, sales_orders.platform_order_id');
    }
}
