<?php

namespace App\Livewire;

use App\Models\BarcodeAlias;
use App\Models\ProductType;
use App\Models\Shop;
use App\Models\Sku;
use App\Models\SkuImportMapping;
use App\Models\Tenant;
use App\Services\SkuImport\SkuImportReader;
use App\Services\SkuImport\SkuWriter;
use App\Support\SkuImport\SkuImportFields;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

class SkuImport extends Component
{
    use WithFileUploads;

    public string $step = 'upload';

    // Step 1 inputs
    public string $tenantId = '';

    public string $shopId = '';

    public ?TemporaryUploadedFile $file = null;

    // Extracted after upload
    public array $fileHeaders = [];

    public array $sampleRows = [];

    public int $totalDataRows = 0;

    public string $filePath = '';

    // Step 2
    public array $mapping = [];

    public string $defaultBarcodeType = '';

    // Step 3 computed
    public int $validRowCount = 0;

    public int $existsRowCount = 0;

    public int $errorRowCount = 0;

    public array $previewRows = [];

    public string $allowUpsert = '';

    public bool $doSaveTemplate = false;

    public string $saveTemplateName = '';

    // Step 4 results
    public int $resultCreated = 0;

    public int $resultUpdated = 0;

    public int $resultSkipped = 0;

    public int $resultFailed = 0;

    public array $errorRows = [];

    public function mount(): void
    {
        if (! $this->isInternalUser()) {
            $ids = $this->activeTenantIds();
            if ($ids === []) {
                abort(403);
            }
            $this->tenantId = (string) $ids[0];
        }

        $this->autoFillSingleShop();
    }

    public function updatedTenantId(): void
    {
        $this->shopId = '';
        $this->autoFillSingleShop();
    }

    public function updatedFile(): void
    {
        if (in_array($this->step, ['map', 'preview', 'result'], true)) {
            $this->resetFileState();
            $this->step = 'upload';
        }
    }

    public function readFile(): void
    {
        $tenantId = $this->validatedTenantId();
        $this->autoFillSingleShop();

        $this->validate([
            'shopId' => ['required', Rule::exists('shops', 'id')->where('tenant_id', $tenantId)],
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:5120'],
        ]);

        $this->deleteStoredImportFile();
        $path = $this->storeImportFile();
        $this->filePath = $path;
        $data = app(SkuImportReader::class)->read(Storage::disk('local')->path($path));

        if ($data['total'] === 0) {
            throw ValidationException::withMessages(['file' => __('sku_import.empty_file')]);
        }

        if ($data['total'] > 2000) {
            throw ValidationException::withMessages([
                'file' => __('sku_import.row_cap_exceeded', ['count' => $data['total']]),
            ]);
        }

        $this->fileHeaders = $data['headers'];
        $this->sampleRows = array_slice($data['rows'], 0, 5);
        $this->totalDataRows = $data['total'];
        $this->mapping = SkuImportFields::autoGuess($this->fileHeaders);
        $this->step = 'map';
    }

    public function backToUpload(): void
    {
        $this->step = 'upload';
    }

    public function setFieldForColumn(int $colIdx, string $fieldKey): void
    {
        $header = $this->fileHeaders[$colIdx] ?? null;

        if ($header === null) {
            return;
        }

        foreach ($this->mapping as $key => $mappedHeader) {
            if ($mappedHeader === $header) {
                $this->mapping[$key] = '';
            }
        }

        if ($fieldKey !== '' && array_key_exists($fieldKey, $this->mapping)) {
            $this->mapping[$fieldKey] = $header;
        }
    }

    public function loadTemplate(int $id): void
    {
        $tenantId = $this->validatedTenantId();
        $template = SkuImportMapping::where('tenant_id', $tenantId)->find($id);

        if ($template === null) {
            return;
        }

        $validHeaders = array_flip($this->fileHeaders);
        $loaded = [];

        foreach ((array) ($template->mapping ?? []) as $fieldKey => $header) {
            $loaded[$fieldKey] = isset($validHeaders[$header]) ? $header : '';
        }

        foreach (SkuImportFields::all() as $field) {
            if (! array_key_exists($field->key, $loaded)) {
                $loaded[$field->key] = $this->mapping[$field->key] ?? '';
            }
        }

        $this->mapping = $loaded;
    }

    public function deleteTemplate(int $id): void
    {
        $tenantId = $this->validatedTenantId();
        SkuImportMapping::where('tenant_id', $tenantId)->where('id', $id)->delete();
    }

    public function advanceToPreview(): void
    {
        $missing = $this->missingRequiredFields();

        if ($missing !== []) {
            session()->flash('error', __('sku_import.required_fields_missing', ['fields' => implode(', ', $missing)]));

            return;
        }

        if ($this->defaultBarcodeType !== '' && ! in_array($this->defaultBarcodeType, BarcodeAlias::BARCODE_TYPES, true)) {
            session()->flash('error', __('sku_import.error_invalid_barcode_type', [
                'values' => implode(', ', BarcodeAlias::BARCODE_TYPES),
            ]));

            return;
        }

        $tenantId = (int) $this->tenantId;
        $shopId = $this->shopId !== '' ? (int) $this->shopId : null;

        $data = $this->readStoredImportFile();
        $rows = $data['rows'];

        $columnIndex = $this->buildColumnIndex();
        $existingSkuCodes = $this->loadExistingSkuCodes($tenantId, $shopId);
        $validProductTypes = $this->loadValidProductTypes();

        $validCount = 0;
        $existsCount = 0;
        $errorCount = 0;
        $previewRows = [];
        $seenSkus = [];

        foreach ($rows as $idx => $row) {
            $eval = $this->evaluateRow($row, $columnIndex, $existingSkuCodes, $validProductTypes, $seenSkus, $tenantId);

            match ($eval['status']) {
                'error' => $errorCount++,
                'exists' => $existsCount++,
                default => $validCount++,
            };

            if (count($previewRows) < 20) {
                $previewRows[] = [
                    'row' => $idx + 1,
                    'status' => $eval['status'],
                    'sku' => $eval['sku'],
                    'name' => trim($eval['rowData']['name'] ?? ''),
                    'errors' => $eval['errors'],
                ];
            }
        }

        $this->validRowCount = $validCount;
        $this->existsRowCount = $existsCount;
        $this->errorRowCount = $errorCount;
        $this->previewRows = $previewRows;
        $this->allowUpsert = '';
        $this->step = 'preview';
    }

    public function backToMap(): void
    {
        $this->step = 'map';
    }

    public function confirmImport(): void
    {
        if ($this->existsRowCount > 0 && $this->allowUpsert === '') {
            session()->flash('error', __('sku_import.error_mode_required'));

            return;
        }

        if ($this->doSaveTemplate && trim($this->saveTemplateName) === '') {
            session()->flash('error', __('sku_import.template_name_required'));

            return;
        }

        $tenantId = (int) $this->tenantId;
        $shopId = $this->shopId !== '' ? (int) $this->shopId : null;

        if ($this->doSaveTemplate && trim($this->saveTemplateName) !== '') {
            $exists = SkuImportMapping::where('tenant_id', $tenantId)
                ->where('name', trim($this->saveTemplateName))
                ->exists();

            if ($exists) {
                session()->flash('error', __('sku_import.template_name_duplicate'));

                return;
            }

            SkuImportMapping::create([
                'tenant_id' => $tenantId,
                'name' => trim($this->saveTemplateName),
                'mapping' => $this->mapping,
                'created_by_user_id' => Auth::id(),
            ]);
        }

        $data = $this->readStoredImportFile();
        $rows = $data['rows'];
        $columnIndex = $this->buildColumnIndex();

        $existingSkuCodes = $this->loadExistingSkuCodes($tenantId, $shopId);
        $validProductTypes = $this->loadValidProductTypes();

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $failed = 0;
        $errorRows = [];
        $seenSkus = [];
        $allowUpsert = $this->allowUpsert === '1';
        $writer = app(SkuWriter::class);

        DB::transaction(function () use (
            $rows, $columnIndex, $tenantId, $shopId,
            $existingSkuCodes, $validProductTypes, $writer, $allowUpsert,
            &$created, &$updated, &$skipped, &$failed, &$errorRows, &$seenSkus,
        ) {
            foreach ($rows as $idx => $row) {
                $rowNo = $idx + 1;
                $eval = $this->evaluateRow($row, $columnIndex, $existingSkuCodes, $validProductTypes, $seenSkus, $tenantId);

                if ($eval['status'] === 'error') {
                    $failed++;
                    $errorRows[] = ['row' => $rowNo, 'sku' => $eval['sku'], 'errors' => implode('; ', $eval['errors'])];

                    continue;
                }

                if ($eval['status'] === 'exists' && ! $allowUpsert) {
                    $skipped++;

                    continue;
                }

                try {
                    $result = DB::transaction(fn () => $writer->upsert(
                        $tenantId,
                        $shopId,
                        $this->buildSkuData($eval['rowData']),
                        $this->buildStockItemData($eval['rowData']),
                        $allowUpsert,
                    ));

                    if ($result->isCreated()) {
                        $created++;
                    } elseif ($result->isUpdated()) {
                        $updated++;
                    } else {
                        $skipped++;
                    }
                } catch (\Throwable $e) {
                    $failed++;
                    $errorRows[] = ['row' => $rowNo, 'sku' => $eval['sku'], 'errors' => $e->getMessage()];
                }
            }
        });

        $this->resultCreated = $created;
        $this->resultUpdated = $updated;
        $this->resultSkipped = $skipped;
        $this->resultFailed = $failed;
        $this->errorRows = $errorRows;
        $this->deleteStoredImportFile();
        $this->step = 'result';
    }

    public function downloadErrors(): mixed
    {
        return response()->streamDownload(function () {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Row', 'SKU', 'Errors']);
            foreach ($this->errorRows as $errorRow) {
                fputcsv($handle, [
                    (string) $errorRow['row'],
                    (string) $errorRow['sku'],
                    (string) $errorRow['errors'],
                ]);
            }
            fclose($handle);
        }, 'sku-import-errors.csv', ['Content-Type' => 'text/csv; charset=utf-8']);
    }

    public function startOver(): void
    {
        $this->deleteStoredImportFile();

        $this->reset([
            'file', 'fileHeaders', 'sampleRows', 'totalDataRows', 'filePath',
            'mapping', 'validRowCount', 'existsRowCount', 'errorRowCount', 'previewRows',
            'defaultBarcodeType', 'allowUpsert', 'doSaveTemplate', 'saveTemplateName',
            'resultCreated', 'resultUpdated', 'resultSkipped', 'resultFailed', 'errorRows',
        ]);
        $this->step = 'upload';
    }

    public function render(): View
    {
        return view('livewire.sku-import', [
            'tenants' => $this->tenantOptions(),
            'shops' => $this->shopOptions(),
            'fields' => SkuImportFields::all(),
            'savedTemplates' => $this->savedTemplates(),
            'showTenantSelect' => $this->isInternalUser(),
            'columnToField' => $this->buildColumnToFieldMap(),
            'needsDefaultBarcodeType' => $this->needsDefaultBarcodeType(),
            'barcodeTypeOptions' => $this->barcodeTypeOptions(),
        ])->layout('inventory', [
            'title' => __('sku_import.page_title'),
            'subtitle' => __('sku_import.page_subtitle'),
        ]);
    }

    // ---- Private helpers ----

    private function buildColumnIndex(): array
    {
        $headerFlip = array_flip($this->fileHeaders);
        $columnIndex = [];

        foreach ($this->mapping as $fieldKey => $header) {
            if ($header !== '' && isset($headerFlip[$header])) {
                $columnIndex[$fieldKey] = $headerFlip[$header];
            }
        }

        return $columnIndex;
    }

    /**
     * Extract one raw row's mapped data and classify it as valid, exists, or error.
     * Shared by the preview pass and the import pass so both stay in sync.
     *
     * @param  array<string, bool>  $seenSkus  mutated to track in-file duplicates
     * @return array{rowData: array<string, string>, sku: string, status: string, errors: list<string>}
     */
    private function evaluateRow(
        array $row,
        array $columnIndex,
        array $existingSkuCodes,
        array $validProductTypes,
        array &$seenSkus,
        int $tenantId,
    ): array {
        $rowData = $this->applyDefaultBarcodeType($this->extractRowData($row, $columnIndex));
        $skuCode = trim($rowData['sku'] ?? '');

        $isDupInFile = $skuCode !== '' && isset($seenSkus[$skuCode]);
        if ($skuCode !== '') {
            $seenSkus[$skuCode] = true;
        }

        $errors = $this->validateRowData($rowData, $existingSkuCodes, $validProductTypes, $isDupInFile, $tenantId);

        if ($errors !== []) {
            $status = 'error';
        } elseif ($skuCode !== '' && isset($existingSkuCodes[$skuCode])) {
            $status = 'exists';
        } else {
            $status = 'valid';
        }

        return ['rowData' => $rowData, 'sku' => $skuCode, 'status' => $status, 'errors' => $errors];
    }

    private function extractRowData(array $row, array $columnIndex): array
    {
        $data = [];

        foreach ($columnIndex as $fieldKey => $colIdx) {
            $data[$fieldKey] = trim((string) ($row[$colIdx] ?? ''));
        }

        return $data;
    }

    private function applyDefaultBarcodeType(array $rowData): array
    {
        if (
            $this->needsDefaultBarcodeType()
            && ($rowData['barcode'] ?? '') !== ''
            && ($rowData['barcode_type'] ?? '') === ''
        ) {
            $rowData['barcode_type'] = $this->defaultBarcodeType;
        }

        return $rowData;
    }

    private function validateRowData(
        array $rowData,
        array $existingSkuCodes,
        array $validProductTypes,
        bool $isDupInFile,
        int $tenantId,
    ): array {
        $errors = [];

        if ($isDupInFile) {
            $errors[] = __('sku_import.error_sku_duplicate_in_file');

            return $errors;
        }

        $skuCode = trim($rowData['sku'] ?? '');
        $name = trim($rowData['name'] ?? '');

        if ($skuCode === '') {
            $errors[] = __('sku_import.error_sku_required');
        }

        if ($name === '') {
            $errors[] = __('sku_import.error_name_required', [
                'field' => $this->fieldLabel('name'),
            ]);
        }

        if ($errors !== []) {
            return $errors;
        }

        $validStatuses = ['active', 'inactive', 'draft', 'archived'];
        $validBarcodeTypes = BarcodeAlias::BARCODE_TYPES;

        if (($rowData['status'] ?? '') !== '' && ! in_array($rowData['status'], $validStatuses, true)) {
            $errors[] = __('sku_import.error_invalid_status', [
                'field' => __('sku_import.field_sku_status'),
                'values' => implode(', ', $validStatuses),
            ]);
        }

        if (($rowData['si_status'] ?? '') !== '' && ! in_array($rowData['si_status'], $validStatuses, true)) {
            $errors[] = __('sku_import.error_invalid_status', [
                'field' => __('sku_import.field_si_status'),
                'values' => implode(', ', $validStatuses),
            ]);
        }

        if (($rowData['barcode_type'] ?? '') !== '' && ! in_array($rowData['barcode_type'], $validBarcodeTypes, true)) {
            $errors[] = __('sku_import.error_invalid_barcode_type', [
                'values' => implode(', ', $validBarcodeTypes),
            ]);
        }

        if (($rowData['product_type'] ?? '') !== '' && ! isset($validProductTypes[$rowData['product_type']])) {
            $errors[] = __('sku_import.error_invalid_product_type', ['value' => $rowData['product_type']]);
        }

        foreach (['weight_value', 'length_value', 'width_value', 'height_value'] as $field) {
            $val = $rowData[$field] ?? '';
            if ($val !== '' && (! is_numeric($val) || (float) $val < 0)) {
                $errors[] = __('sku_import.error_decimal_negative', ['field' => $field]);
            }
        }

        $stringFields = [
            'sku', 'name',
            'platform_sku', 'platform_product_id', 'platform_variant_id',
            'platform_variant_name', 'platform_label_code',
            'short_name', 'brand', 'model_number', 'variation_code', 'color', 'size', 'barcode', 'tenant_item_code',
        ];

        foreach ($stringFields as $field) {
            if (isset($rowData[$field]) && strlen($rowData[$field]) > 255) {
                $errors[] = __('sku_import.error_too_long', ['field' => $field]);
            }
        }

        $systemStockItemCode = trim($rowData['stock_item_code'] ?? '');
        $tenantItemCode = trim($rowData['tenant_item_code'] ?? '');

        if ($systemStockItemCode !== '' && $tenantItemCode !== '') {
            $systemStockItemId = DB::table('stock_items')
                ->where('tenant_id', $tenantId)
                ->where('code', $systemStockItemCode)
                ->value('id');
            $tenantStockItemId = DB::table('stock_items')
                ->where('tenant_id', $tenantId)
                ->where('tenant_item_code', $tenantItemCode)
                ->value('id');

            if ($systemStockItemId !== null && $tenantStockItemId !== null && (int) $systemStockItemId !== (int) $tenantStockItemId) {
                $errors[] = __('sku_import.error_stock_code_conflict');
            }
        }

        return $errors;
    }

    private function buildSkuData(array $rowData): array
    {
        return [
            'sku' => $rowData['sku'] ?? '',
            'platform_sku' => $rowData['platform_sku'] ?? '',
            'platform_product_id' => $rowData['platform_product_id'] ?? '',
            'platform_variant_id' => $rowData['platform_variant_id'] ?? '',
            'platform_variant_name' => $rowData['platform_variant_name'] ?? '',
            'platform_label_code' => $rowData['platform_label_code'] ?? '',
            'status' => $rowData['status'] ?? '',
            'note' => $rowData['note'] ?? '',
        ];
    }

    private function buildStockItemData(array $rowData): array
    {
        return [
            'stock_item_code' => $rowData['stock_item_code'] ?? '',
            'tenant_item_code' => $rowData['tenant_item_code'] ?? '',
            'name' => $rowData['name'] ?? '',
            'si_name_ja' => $rowData['si_name_ja'] ?? '',
            'si_name_zh_tw' => $rowData['si_name_zh_tw'] ?? '',
            'si_name_zh_cn' => $rowData['si_name_zh_cn'] ?? '',
            'short_name' => $rowData['short_name'] ?? '',
            'brand' => $rowData['brand'] ?? '',
            'model_number' => $rowData['model_number'] ?? '',
            'variation_code' => $rowData['variation_code'] ?? '',
            'color' => $rowData['color'] ?? '',
            'size' => $rowData['size'] ?? '',
            'barcode' => $rowData['barcode'] ?? '',
            'barcode_type' => $rowData['barcode_type'] ?? '',
            'product_type' => $rowData['product_type'] ?? '',
            'is_dangerous_goods' => $rowData['is_dangerous_goods'] ?? '',
            'requires_expiry_tracking' => $rowData['requires_expiry_tracking'] ?? '',
            'requires_lot_tracking' => $rowData['requires_lot_tracking'] ?? '',
            'weight_value' => $rowData['weight_value'] ?? '',
            'weight_unit' => $rowData['weight_unit'] ?? '',
            'length_value' => $rowData['length_value'] ?? '',
            'width_value' => $rowData['width_value'] ?? '',
            'height_value' => $rowData['height_value'] ?? '',
            'dimension_unit' => $rowData['dimension_unit'] ?? '',
            'description' => $rowData['description'] ?? '',
            'si_note' => $rowData['si_note'] ?? '',
            'handling_note' => $rowData['handling_note'] ?? '',
            'si_status' => $rowData['si_status'] ?? '',
        ];
    }

    private function loadExistingSkuCodes(int $tenantId, ?int $shopId): array
    {
        return Sku::query()
            ->where('tenant_id', $tenantId)
            ->when(
                $shopId !== null,
                fn ($q) => $q->where('shop_id', $shopId),
                fn ($q) => $q->whereNull('shop_id'),
            )
            ->pluck('sku')
            ->flip()
            ->all();
    }

    private function loadValidProductTypes(): array
    {
        return ProductType::pluck('slug')->flip()->all();
    }

    /** @return list<string> labels of required fields that are not yet mapped */
    private function missingRequiredFields(): array
    {
        $missing = [];

        foreach (SkuImportFields::all() as $field) {
            if ($field->required && ($this->mapping[$field->key] ?? '') === '') {
                $missing[] = $this->fieldLabel($field->key);
            }
        }

        if (($this->mapping['name'] ?? '') === '') {
            $missing[] = $this->fieldLabel('name');
        }

        if ($this->needsDefaultBarcodeType() && $this->defaultBarcodeType === '') {
            $missing[] = __('sku_import.default_barcode_type');
        }

        return array_values(array_unique($missing));
    }

    private function needsDefaultBarcodeType(): bool
    {
        return ($this->mapping['barcode'] ?? '') !== ''
            && ($this->mapping['barcode_type'] ?? '') === '';
    }

    private function barcodeTypeOptions(): array
    {
        $options = [];

        foreach (BarcodeAlias::BARCODE_TYPES as $type) {
            $options[$type] = __('common.barcode_types.'.$type);
        }

        return $options;
    }

    private function fieldLabel(string $fieldKey): string
    {
        return __('sku_import.field_'.$fieldKey);
    }

    private function savedTemplates(): Collection
    {
        if ($this->tenantId === '') {
            return collect();
        }

        $tenantId = (int) $this->tenantId;

        if ($tenantId <= 0 || ! in_array($tenantId, $this->allowedTenantIds(), true)) {
            return collect();
        }

        return SkuImportMapping::where('tenant_id', $tenantId)
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    private function tenantOptions(): Collection
    {
        return Tenant::query()
            ->whereIn('id', $this->allowedTenantIds())
            ->orderBy('name')
            ->get(['id', 'code', 'name']);
    }

    private function shopOptions(): Collection
    {
        if ($this->tenantId === '') {
            return collect();
        }

        $tenantId = (int) $this->tenantId;

        if ($tenantId <= 0 || ! in_array($tenantId, $this->allowedTenantIds(), true)) {
            return collect();
        }

        return Shop::query()
            ->where('tenant_id', $tenantId)
            ->orderBy('name')
            ->get(['id', 'code', 'name']);
    }

    private function autoFillSingleShop(): void
    {
        $shops = $this->shopOptions();

        if ($shops->count() === 1) {
            $this->shopId = (string) $shops->first()->id;

            return;
        }

        if ($this->shopId !== '' && ! $shops->contains('id', (int) $this->shopId)) {
            $this->shopId = '';
        }
    }

    private function validatedTenantId(): int
    {
        $tenantId = (int) $this->tenantId;

        if ($tenantId <= 0 || ! in_array($tenantId, $this->allowedTenantIds(), true)) {
            throw ValidationException::withMessages(['tenantId' => __('skus.invalid_tenant')]);
        }

        return $tenantId;
    }

    private function allowedTenantIds(): array
    {
        if ($this->isInternalUser()) {
            return Tenant::query()->pluck('id')->all();
        }

        return $this->activeTenantIds();
    }

    private function activeTenantIds(): array
    {
        return Auth::user()?->activeTenantIds() ?? [];
    }

    private function isInternalUser(): bool
    {
        return Auth::user()?->user_type === 'internal';
    }

    private function buildColumnToFieldMap(): array
    {
        $map = [];

        foreach ($this->mapping as $fieldKey => $header) {
            if ($header !== '') {
                $map[$header] = $fieldKey;
            }
        }

        return $map;
    }

    private function resetFileState(): void
    {
        $this->deleteStoredImportFile();

        $this->fileHeaders = [];
        $this->sampleRows = [];
        $this->totalDataRows = 0;
        $this->filePath = '';
        $this->mapping = [];
    }

    private function storeImportFile(): string
    {
        if (! $this->file) {
            throw ValidationException::withMessages(['file' => __('validation.required', ['attribute' => 'file'])]);
        }

        return $this->file->store('tmp/sku-imports', 'local');
    }

    /**
     * @return array{headers: list<string>, rows: list<list<mixed>>, total: int}
     */
    private function readStoredImportFile(): array
    {
        if ($this->filePath === '' || ! Storage::disk('local')->exists($this->filePath)) {
            $this->resetFileState();
            $this->step = 'upload';

            throw ValidationException::withMessages([
                'file' => __('sku_import.file_expired'),
            ]);
        }

        return app(SkuImportReader::class)->read(Storage::disk('local')->path($this->filePath));
    }

    private function deleteStoredImportFile(): void
    {
        if ($this->filePath !== '') {
            Storage::disk('local')->delete($this->filePath);
            $this->filePath = '';
        }
    }
}
