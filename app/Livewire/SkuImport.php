<?php

namespace App\Livewire;

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

    // Step 3 computed
    public int $validRowCount = 0;

    public int $existsRowCount = 0;

    public int $errorRowCount = 0;

    public array $previewRows = [];

    public bool $allowUpsert = false;

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
    }

    public function updatedTenantId(): void
    {
        $this->shopId = '';
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

        $this->validate([
            'shopId' => ['nullable', Rule::exists('shops', 'id')->where('tenant_id', $tenantId)],
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:5120'],
        ]);

        $path = $this->file->getRealPath();
        $data = app(SkuImportReader::class)->read($path);

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
        $this->filePath = $path;

        $this->mapping = SkuImportFields::autoGuess($this->fileHeaders);
        $this->step = 'map';
    }

    public function backToUpload(): void
    {
        $this->step = 'upload';
    }

    public function loadTemplate(int $id): void
    {
        $tenantId = (int) $this->tenantId;
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
        $tenantId = (int) $this->tenantId;
        SkuImportMapping::where('tenant_id', $tenantId)->where('id', $id)->delete();
    }

    public function advanceToPreview(): void
    {
        $this->validateMappingRequiredFields();

        $tenantId = (int) $this->tenantId;
        $shopId = $this->shopId !== '' ? (int) $this->shopId : null;

        $data = app(SkuImportReader::class)->read($this->filePath);
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
            $rowNo = $idx + 1;
            $rowData = $this->extractRowData($row, $columnIndex);
            $skuCode = trim($rowData['sku'] ?? '');

            $isDupInFile = $skuCode !== '' && isset($seenSkus[$skuCode]);
            if ($skuCode !== '') {
                $seenSkus[$skuCode] = true;
            }

            $errors = $this->validateRowData($rowData, $existingSkuCodes, $validProductTypes, $isDupInFile);
            $skuExists = $skuCode !== '' && isset($existingSkuCodes[$skuCode]);

            if ($errors !== []) {
                $status = 'error';
                $errorCount++;
            } elseif ($skuExists) {
                $status = 'exists';
                $existsCount++;
            } else {
                $status = 'valid';
                $validCount++;
            }

            if (count($previewRows) < 20) {
                $previewRows[] = [
                    'row' => $rowNo,
                    'status' => $status,
                    'sku' => $skuCode,
                    'name' => trim($rowData['name'] ?? ''),
                    'errors' => $errors,
                ];
            }
        }

        $this->validRowCount = $validCount;
        $this->existsRowCount = $existsCount;
        $this->errorRowCount = $errorCount;
        $this->previewRows = $previewRows;
        $this->step = 'preview';
    }

    public function backToMap(): void
    {
        $this->step = 'map';
    }

    public function confirmImport(): void
    {
        if ($this->doSaveTemplate && trim($this->saveTemplateName) === '') {
            $this->addError('saveTemplateName', __('sku_import.template_name_required'));

            return;
        }

        $tenantId = (int) $this->tenantId;
        $shopId = $this->shopId !== '' ? (int) $this->shopId : null;

        if ($this->doSaveTemplate && trim($this->saveTemplateName) !== '') {
            $exists = SkuImportMapping::where('tenant_id', $tenantId)
                ->where('name', trim($this->saveTemplateName))
                ->exists();

            if ($exists) {
                $this->addError('saveTemplateName', __('sku_import.template_name_duplicate'));

                return;
            }

            SkuImportMapping::create([
                'tenant_id' => $tenantId,
                'name' => trim($this->saveTemplateName),
                'mapping' => $this->mapping,
                'created_by_user_id' => Auth::id(),
            ]);
        }

        $data = app(SkuImportReader::class)->read($this->filePath);
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
        $allowUpsert = $this->allowUpsert;
        $writer = app(SkuWriter::class);

        DB::transaction(function () use (
            $rows, $columnIndex, $tenantId, $shopId,
            $existingSkuCodes, $validProductTypes, $writer, $allowUpsert,
            &$created, &$updated, &$skipped, &$failed, &$errorRows, &$seenSkus,
        ) {
            foreach ($rows as $idx => $row) {
                $rowNo = $idx + 1;
                $rowData = $this->extractRowData($row, $columnIndex);
                $skuCode = trim($rowData['sku'] ?? '');

                $isDupInFile = $skuCode !== '' && isset($seenSkus[$skuCode]);
                if ($skuCode !== '') {
                    $seenSkus[$skuCode] = true;
                }

                $errors = $this->validateRowData($rowData, $existingSkuCodes, $validProductTypes, $isDupInFile);

                if ($errors !== []) {
                    $failed++;
                    $errorRows[] = ['row' => $rowNo, 'sku' => $skuCode, 'errors' => implode('; ', $errors)];

                    continue;
                }

                $skuExists = $skuCode !== '' && isset($existingSkuCodes[$skuCode]);

                if ($skuExists && ! $allowUpsert) {
                    $skipped++;

                    continue;
                }

                try {
                    $result = $writer->upsert(
                        $tenantId,
                        $shopId,
                        $this->buildSkuData($rowData),
                        $this->buildStockItemData($rowData),
                        $allowUpsert,
                    );

                    if ($result->isCreated()) {
                        $created++;
                    } elseif ($result->isUpdated()) {
                        $updated++;
                    } else {
                        $skipped++;
                    }
                } catch (\Throwable $e) {
                    $failed++;
                    $errorRows[] = ['row' => $rowNo, 'sku' => $skuCode, 'errors' => $e->getMessage()];
                }
            }
        });

        $this->resultCreated = $created;
        $this->resultUpdated = $updated;
        $this->resultSkipped = $skipped;
        $this->resultFailed = $failed;
        $this->errorRows = $errorRows;
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
        $this->reset([
            'file', 'fileHeaders', 'sampleRows', 'totalDataRows', 'filePath',
            'mapping', 'validRowCount', 'existsRowCount', 'errorRowCount', 'previewRows',
            'allowUpsert', 'doSaveTemplate', 'saveTemplateName',
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

    private function extractRowData(array $row, array $columnIndex): array
    {
        $data = [];

        foreach ($columnIndex as $fieldKey => $colIdx) {
            $data[$fieldKey] = trim((string) ($row[$colIdx] ?? ''));
        }

        return $data;
    }

    private function validateRowData(
        array $rowData,
        array $existingSkuCodes,
        array $validProductTypes,
        bool $isDupInFile,
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
            $errors[] = __('sku_import.error_name_required');
        }

        if ($errors !== []) {
            return $errors;
        }

        $validStatuses = ['active', 'inactive', 'draft', 'archived'];
        $validBarcodeTypes = ['unknown', 'jan', 'ean', 'upc', 'fnsku', 'platform_label', 'internal_label'];

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
            'sku', 'name', 'name_ja', 'name_zh_tw', 'name_zh_cn',
            'platform_sku', 'platform_product_id', 'platform_variant_id',
            'platform_variant_name', 'platform_label_code',
            'short_name', 'brand', 'model_number', 'variation_code', 'color', 'size', 'barcode',
        ];

        foreach ($stringFields as $field) {
            if (isset($rowData[$field]) && strlen($rowData[$field]) > 255) {
                $errors[] = __('sku_import.error_too_long', ['field' => $field]);
            }
        }

        return $errors;
    }

    private function buildSkuData(array $rowData): array
    {
        return [
            'sku' => $rowData['sku'] ?? '',
            'name' => $rowData['name'] ?? '',
            'name_ja' => $rowData['name_ja'] ?? '',
            'name_zh_tw' => $rowData['name_zh_tw'] ?? '',
            'name_zh_cn' => $rowData['name_zh_cn'] ?? '',
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

    private function validateMappingRequiredFields(): void
    {
        $missing = [];

        foreach (SkuImportFields::all() as $field) {
            if ($field->required && ($this->mapping[$field->key] ?? '') === '') {
                $missing[] = __('sku_import.field_'.$field->key);
            }
        }

        if ($missing !== []) {
            throw ValidationException::withMessages([
                'mapping' => __('sku_import.required_fields_missing', ['fields' => implode(', ', $missing)]),
            ]);
        }
    }

    private function savedTemplates(): Collection
    {
        if ($this->tenantId === '') {
            return collect();
        }

        return SkuImportMapping::where('tenant_id', $this->tenantId)
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

        return Shop::query()
            ->where('tenant_id', $this->tenantId)
            ->orderBy('name')
            ->get(['id', 'code', 'name']);
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

    private function resetFileState(): void
    {
        $this->fileHeaders = [];
        $this->sampleRows = [];
        $this->totalDataRows = 0;
        $this->filePath = '';
        $this->mapping = [];
    }
}
