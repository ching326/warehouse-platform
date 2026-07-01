<?php

namespace App\Livewire;

use App\Livewire\Concerns\AutoSelectsSingleActiveWarehouse;
use App\Models\BarcodeAlias;
use App\Models\InventoryBalance;
use App\Models\Sku;
use App\Models\StockCountImportMapping;
use App\Models\StockItem;
use App\Models\Tenant;
use App\Models\Warehouse;
use App\Services\SkuImport\SkuImportReader;
use App\Services\StockCountPostingService;
use App\Support\StockCountImport\StockCountImportFields;
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

class StockCountImport extends Component
{
    use AutoSelectsSingleActiveWarehouse;
    use WithFileUploads;

    private const PREF_DEFAULT_WAREHOUSE_ID = 'stock_adjustment_default_warehouse_id';

    public string $step = 'upload';

    public string $tenantId = '';

    public string $warehouseId = '';

    public string $note = '';

    public ?TemporaryUploadedFile $file = null;

    public string $fileName = '';

    public string $filePath = '';

    public array $fileHeaders = [];

    public array $sampleRows = [];

    public int $totalDataRows = 0;

    public array $mapping = [];

    public string $selectedTemplateId = '';

    public bool $doSaveTemplate = false;

    public string $templateName = '';

    public bool $templateAsDefault = false;

    public int $validRowCount = 0;

    public int $errorRowCount = 0;

    public array $previewRows = [];

    public int $resultTotal = 0;

    public int $resultAdjusted = 0;

    public int $resultNoChange = 0;

    public int $resultFailed = 0;

    public ?int $resultRunId = null;

    public function mount(): void
    {
        if (! $this->isInternalUser()) {
            $ids = $this->activeTenantIds();

            if ($ids === []) {
                abort(403);
            }

            $this->tenantId = (string) $ids[0];
        }

        $this->selectPreferredWarehouse();
    }

    public function updatedTenantId(): void
    {
        $this->warehouseId = '';
        $this->resetTemplateState();
        $this->selectPreferredWarehouse();
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
        $this->validateUploadFields();
        $tenantId = $this->validatedTenantId();
        $this->deleteStoredImportFile();

        $this->filePath = $this->storeImportFile();
        $this->fileName = $this->file?->getClientOriginalName() ?? '';

        $data = $this->readStoredImportFile();

        if ($data['total'] === 0) {
            throw ValidationException::withMessages(['file' => __('stock_counts.empty_file')]);
        }

        if ($data['total'] > 2000) {
            throw ValidationException::withMessages(['file' => __('stock_counts.row_cap_exceeded', ['count' => $data['total']])]);
        }

        $this->fileHeaders = $data['headers'];
        $this->sampleRows = array_slice($data['rows'], 0, 5);
        $this->totalDataRows = $data['total'];
        $this->mapping = StockCountImportFields::autoGuess($this->fileHeaders);
        $this->applyDefaultTemplateForTenant($tenantId);
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
        $template = StockCountImportMapping::query()->where('tenant_id', $tenantId)->find($id);

        if (! $template) {
            return;
        }

        $this->applyTemplateMapping($template);
        $this->selectedTemplateId = (string) $template->id;
    }

    public function saveTemplate(): void
    {
        $tenantId = $this->validatedTenantId();
        $name = trim($this->templateName);

        if ($name === '') {
            throw ValidationException::withMessages(['templateName' => __('stock_counts.template_name_required')]);
        }

        if (StockCountImportMapping::query()->where('tenant_id', $tenantId)->where('name', $name)->exists()) {
            throw ValidationException::withMessages(['templateName' => __('stock_counts.template_name_duplicate')]);
        }

        DB::transaction(function () use ($tenantId, $name): void {
            if ($this->templateAsDefault) {
                StockCountImportMapping::query()->where('tenant_id', $tenantId)->update(['is_default' => false]);
            }

            $template = StockCountImportMapping::create([
                'tenant_id' => $tenantId,
                'name' => $name,
                'mapping' => $this->mapping,
                'is_default' => $this->templateAsDefault,
                'created_by_user_id' => Auth::id(),
            ]);

            $this->selectedTemplateId = (string) $template->id;
        });

        $this->templateName = '';
        $this->templateAsDefault = false;
        $this->doSaveTemplate = false;
        session()->flash('status', __('stock_counts.template_saved'));
    }

    public function setDefaultTemplate(int $id): void
    {
        $tenantId = $this->validatedTenantId();
        $template = StockCountImportMapping::query()->where('tenant_id', $tenantId)->find($id);

        if (! $template) {
            return;
        }

        DB::transaction(function () use ($tenantId, $template): void {
            StockCountImportMapping::query()->where('tenant_id', $tenantId)->update(['is_default' => false]);
            $template->update(['is_default' => true]);
        });
    }

    public function deleteTemplate(int $id): void
    {
        $tenantId = $this->validatedTenantId();
        StockCountImportMapping::query()->where('tenant_id', $tenantId)->where('id', $id)->delete();
    }

    public function advanceToPreview(): void
    {
        $missing = $this->missingRequiredFields();

        if ($missing !== []) {
            session()->flash('error', __('stock_counts.required_fields_missing', ['fields' => implode(', ', $missing)]));

            return;
        }

        $tenantId = $this->validatedTenantId();
        $this->validateContext();
        $evaluation = $this->evaluateStoredRows($tenantId, (int) $this->warehouseId);
        $this->applyEvaluation($evaluation);
        $this->step = 'preview';
    }

    public function backToMap(): void
    {
        $this->step = 'map';
    }

    public function confirmImport(StockCountPostingService $postingService): mixed
    {
        $tenantId = $this->validatedTenantId();
        $this->validateContext();
        $evaluation = $this->evaluateStoredRows($tenantId, (int) $this->warehouseId);
        $this->applyEvaluation($evaluation);

        if ($evaluation['errorCount'] > 0) {
            $this->step = 'preview';
            session()->flash('error', __('stock_counts.confirm_blocked'));

            return null;
        }

        try {
            $run = $postingService->postImport(
                tenantId: $tenantId,
                warehouseId: (int) $this->warehouseId,
                fileName: $this->fileName,
                note: $this->nullableString($this->note),
                rows: $evaluation['validRows'],
                userId: Auth::id(),
            );
        } catch (\InvalidArgumentException $exception) {
            $this->step = 'preview';
            session()->flash('error', $exception->getMessage());

            return null;
        }

        $this->resultTotal = $run->total_lines;
        $this->resultAdjusted = $run->adjusted_lines;
        $this->resultNoChange = $run->no_change_lines;
        $this->resultFailed = $run->failed_lines;
        $this->resultRunId = $run->id;
        $this->deleteStoredImportFile();
        $this->step = 'result';

        session()->flash('status', __('stock_counts.imported', ['count' => $run->total_lines]));

        return null;
    }

    public function render(): View
    {
        return view('livewire.stock-count-import', [
            'tenants' => $this->tenantOptions(),
            'warehouses' => $this->warehouseOptions(),
            'showTenantSelect' => $this->isInternalUser(),
            'currentTenant' => $this->currentTenant(),
            'fields' => StockCountImportFields::all(),
            'columnToField' => $this->buildColumnToFieldMap(),
            'savedTemplates' => $this->savedTemplates(),
        ])->layout('inventory', [
            'title' => __('stock_counts.import_title'),
            'subtitle' => __('stock_counts.import_subtitle'),
        ]);
    }

    private function validateUploadFields(): void
    {
        validator([
            'tenantId' => $this->tenantId,
            'warehouseId' => $this->warehouseId,
            'file' => $this->file,
        ], [
            'tenantId' => ['required'],
            'warehouseId' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('status', 'active')],
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:5120'],
        ])->validate();
    }

    private function validateContext(): void
    {
        validator(['warehouseId' => $this->warehouseId], [
            'warehouseId' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('status', 'active')],
        ])->validate();
    }

    /**
     * @return array{total:int,validRows:list<array<string,mixed>>,errorCount:int,previewRows:list<array<string,mixed>>}
     */
    private function evaluateStoredRows(int $tenantId, int $warehouseId): array
    {
        $data = $this->readStoredImportFile();
        $columnIndex = $this->buildColumnIndex();
        $seenStockItems = [];
        $validRows = [];
        $previewRows = [];
        $errorCount = 0;

        foreach ($data['rows'] as $idx => $row) {
            $rowData = $this->extractRowData($row, $columnIndex);
            $evaluation = $this->evaluateRow($rowData, $tenantId, $warehouseId, $seenStockItems);

            if ($evaluation['status'] === 'error') {
                $errorCount++;
            } else {
                $validRows[] = [
                    'stock_item_id' => (int) $evaluation['stock_item_id'],
                    'identifier_raw' => $evaluation['identifier'],
                    'counted_qty' => (int) $evaluation['counted_qty'],
                    'line_note' => $evaluation['line_note'] ?: null,
                    'reference_no' => $evaluation['reference_no'] ?: null,
                ];
            }

            if (count($previewRows) < 50) {
                $previewRows[] = ['row' => $idx + 1, ...$evaluation];
            }
        }

        return [
            'total' => $data['total'],
            'validRows' => $validRows,
            'errorCount' => $errorCount,
            'previewRows' => $previewRows,
        ];
    }

    private function evaluateRow(array $rowData, int $tenantId, int $warehouseId, array &$seenStockItems): array
    {
        $identifier = trim((string) ($rowData['identifier'] ?? ''));
        $countedRaw = trim((string) ($rowData['counted_qty'] ?? ''));
        $lineNote = trim((string) ($rowData['line_note'] ?? ''));
        $referenceNo = trim((string) ($rowData['reference_no'] ?? ''));
        $errors = [];
        $stockItem = null;

        if ($identifier === '') {
            $errors[] = __('stock_counts.error_identifier_required');
        } else {
            $resolved = $this->resolveIdentifier($tenantId, $identifier);
            $stockItem = $resolved['stockItem'];
            $errors = [...$errors, ...$resolved['errors']];
        }

        if (! preg_match('/^(0|[1-9][0-9]*)$/', $countedRaw)) {
            $errors[] = __('stock_counts.error_counted_qty_integer');
        }

        $countedQty = preg_match('/^(0|[1-9][0-9]*)$/', $countedRaw) ? (int) $countedRaw : 0;
        $currentOnHand = 0;
        $reserved = 0;
        $hold = 0;
        $damaged = 0;

        if ($stockItem) {
            $balance = InventoryBalance::query()
                ->where('tenant_id', $tenantId)
                ->where('warehouse_id', $warehouseId)
                ->where('stock_item_id', $stockItem->id)
                ->first();

            $currentOnHand = (int) ($balance?->on_hand_qty ?? 0);
            $reserved = (int) ($balance?->reserved_qty ?? 0);
            $hold = (int) ($balance?->hold_qty ?? 0);
            $damaged = (int) ($balance?->damaged_qty ?? 0);

            if (isset($seenStockItems[(string) $stockItem->id])) {
                $errors[] = __('stock_counts.error_duplicate_stock_item');
            } else {
                $seenStockItems[(string) $stockItem->id] = true;
            }

            if ($countedQty < ($reserved + $hold + $damaged)) {
                $errors[] = __('stock_counts.error_counted_below_committed');
            }
        }

        return [
            'status' => $errors === [] ? 'valid' : 'error',
            'errors' => $errors,
            'identifier' => $identifier,
            'stock_item_id' => $stockItem?->id,
            'stock_item_code' => $stockItem?->code ?? '',
            'tenant_item_code' => $stockItem?->tenant_item_code ?? '',
            'stock_item_name' => $stockItem?->displayName() ?? '',
            'current_on_hand' => $currentOnHand,
            'reserved_qty' => $reserved,
            'hold_qty' => $hold,
            'damaged_qty' => $damaged,
            'counted_qty' => $countedQty,
            'delta_qty' => $countedQty - $currentOnHand,
            'line_note' => $lineNote,
            'reference_no' => $referenceNo,
        ];
    }

    /**
     * @return array{stockItem:?StockItem,errors:list<string>}
     */
    private function resolveIdentifier(int $tenantId, string $identifier): array
    {
        $stockItemIds = [];

        StockItem::query()->where('tenant_id', $tenantId)->where('code', $identifier)->pluck('id')
            ->each(function ($id) use (&$stockItemIds): void {
                $stockItemIds[] = (int) $id;
            });

        StockItem::query()->where('tenant_id', $tenantId)->whereNotNull('tenant_item_code')->where('tenant_item_code', $identifier)->pluck('id')
            ->each(function ($id) use (&$stockItemIds): void {
                $stockItemIds[] = (int) $id;
            });

        Sku::query()->where('tenant_id', $tenantId)->where('sku', $identifier)->whereNotNull('stock_item_id')->pluck('stock_item_id')
            ->each(function ($id) use (&$stockItemIds): void {
                $stockItemIds[] = (int) $id;
            });

        $normalized = BarcodeAlias::normalize($identifier);

        BarcodeAlias::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->where('normalized_barcode', $normalized)
            ->get(['model_type', 'model_id'])
            ->each(function (BarcodeAlias $alias) use ($tenantId, &$stockItemIds): void {
                if ($alias->model_type === BarcodeAlias::MODEL_TYPE_STOCK_ITEM) {
                    $stockItemIds[] = (int) $alias->model_id;

                    return;
                }

                if ($alias->model_type === BarcodeAlias::MODEL_TYPE_SKU) {
                    $stockItemId = Sku::query()
                        ->where('tenant_id', $tenantId)
                        ->whereKey($alias->model_id)
                        ->value('stock_item_id');

                    if ($stockItemId !== null) {
                        $stockItemIds[] = (int) $stockItemId;
                    }
                }
            });

        $stockItemIds = array_values(array_unique(array_filter($stockItemIds)));

        if ($stockItemIds === []) {
            return ['stockItem' => null, 'errors' => [__('stock_counts.error_identifier_not_found')]];
        }

        if (count($stockItemIds) > 1) {
            return ['stockItem' => null, 'errors' => [__('stock_counts.error_identifier_ambiguous')]];
        }

        $stockItem = StockItem::query()
            ->where('tenant_id', $tenantId)
            ->find($stockItemIds[0], ['id', 'tenant_id', 'code', 'tenant_item_code', ...StockItem::DISPLAY_NAME_COLUMNS]);

        return $stockItem
            ? ['stockItem' => $stockItem, 'errors' => []]
            : ['stockItem' => null, 'errors' => [__('stock_counts.error_identifier_not_found')]];
    }

    private function applyEvaluation(array $evaluation): void
    {
        $this->totalDataRows = $evaluation['total'];
        $this->validRowCount = count($evaluation['validRows']);
        $this->errorRowCount = $evaluation['errorCount'];
        $this->previewRows = $evaluation['previewRows'];
    }

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

    private function missingRequiredFields(): array
    {
        $missing = [];

        foreach (StockCountImportFields::all() as $field) {
            if ($field->required && ($this->mapping[$field->key] ?? '') === '') {
                $missing[] = __($field->labelKey);
            }
        }

        return $missing;
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

        return StockCountImportMapping::query()
            ->where('tenant_id', $tenantId)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get(['id', 'name', 'is_default']);
    }

    private function applyDefaultTemplateForTenant(int $tenantId): void
    {
        $template = StockCountImportMapping::query()
            ->where('tenant_id', $tenantId)
            ->where('is_default', true)
            ->first(['id', 'mapping']);

        if (! $template) {
            return;
        }

        $this->applyTemplateMapping($template);
        $this->selectedTemplateId = (string) $template->id;
    }

    private function applyTemplateMapping(StockCountImportMapping $template): void
    {
        $validHeaders = array_flip($this->fileHeaders);
        $loaded = [];

        foreach ((array) ($template->mapping ?? []) as $fieldKey => $header) {
            $loaded[$fieldKey] = isset($validHeaders[$header]) ? $header : '';
        }

        foreach (StockCountImportFields::all() as $field) {
            if (! array_key_exists($field->key, $loaded)) {
                $loaded[$field->key] = $this->mapping[$field->key] ?? '';
            }
        }

        $this->mapping = $loaded;
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

    private function tenantOptions(): Collection
    {
        return Tenant::query()->whereIn('id', $this->allowedTenantIds())->orderBy('name')->get(['id', 'code', 'name']);
    }

    private function warehouseOptions(): Collection
    {
        return Warehouse::query()->where('status', 'active')->orderBy('name')->get(['id', 'code', 'name']);
    }

    private function currentTenant(): ?Tenant
    {
        if ($this->tenantId === '') {
            return null;
        }

        return Tenant::query()->whereIn('id', $this->allowedTenantIds())->find($this->tenantId, ['id', 'code', 'name']);
    }

    private function selectPreferredWarehouse(): void
    {
        if ($this->warehouseId !== '') {
            return;
        }

        $savedWarehouseId = Auth::user()?->preference(self::PREF_DEFAULT_WAREHOUSE_ID);

        if (is_numeric($savedWarehouseId) && Warehouse::query()->whereKey((int) $savedWarehouseId)->where('status', 'active')->exists()) {
            $this->warehouseId = (string) $savedWarehouseId;

            return;
        }

        $this->autoSelectSingleActiveWarehouse();
    }

    private function validatedTenantId(): int
    {
        $tenantId = (int) $this->tenantId;

        if ($tenantId <= 0 || ! in_array($tenantId, $this->allowedTenantIds(), true)) {
            throw ValidationException::withMessages(['tenantId' => __('stock_adjustments.invalid_tenant')]);
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

    private function resetTemplateState(): void
    {
        $this->selectedTemplateId = '';
        $this->doSaveTemplate = false;
        $this->templateName = '';
        $this->templateAsDefault = false;
    }

    private function resetFileState(): void
    {
        $this->deleteStoredImportFile();
        $this->fileHeaders = [];
        $this->sampleRows = [];
        $this->totalDataRows = 0;
        $this->filePath = '';
        $this->fileName = '';
        $this->mapping = [];
        $this->previewRows = [];
    }

    private function storeImportFile(): string
    {
        if (! $this->file) {
            throw ValidationException::withMessages(['file' => __('validation.required', ['attribute' => 'file'])]);
        }

        return $this->file->store('tmp/stock-count-imports', 'local');
    }

    private function readStoredImportFile(): array
    {
        if ($this->filePath === '' || ! Storage::disk('local')->exists($this->filePath)) {
            $this->resetFileState();
            $this->step = 'upload';
            throw ValidationException::withMessages(['file' => __('stock_counts.file_expired')]);
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

    private function nullableString(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
