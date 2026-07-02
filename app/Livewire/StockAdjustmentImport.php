<?php

namespace App\Livewire;

use App\Livewire\Concerns\AutoSelectsSingleActiveWarehouse;
use App\Models\BarcodeAlias;
use App\Models\InventoryBalance;
use App\Models\Sku;
use App\Models\StockAdjustmentImportMapping;
use App\Models\StockAdjustmentImportRun;
use App\Models\StockItem;
use App\Models\Tenant;
use App\Models\Warehouse;
use App\Services\InventoryService;
use App\Services\SkuImport\SkuImportReader;
use App\Support\StockAdjustmentImport\StockAdjustmentImportFields;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

class StockAdjustmentImport extends Component
{
    use AutoSelectsSingleActiveWarehouse;
    use WithFileUploads;

    private const PREF_DEFAULT_WAREHOUSE_ID = 'stock_adjustment_default_warehouse_id';

    private const ACTION_ADD = 'add';

    private const ACTION_DEDUCT = 'deduct';

    private const ADD_REASONS = [
        'found_stock',
        'correction',
        'return_to_stock',
        'supplier_replacement',
        'other',
    ];

    private const DEDUCT_REASONS = [
        'lost_missing',
        'package_damage',
        'product_damage',
        'write_off',
        'correction',
        'internal_use',
        'sample_demo_units',
        'marketing_giveaways',
        'other',
    ];

    public string $step = 'upload';

    public string $tenantId = '';

    public string $warehouseId = '';

    public string $action = '';

    public string $reason = '';

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

    public int $validRowCount = 0;

    public int $errorRowCount = 0;

    public array $previewRows = [];

    public int $resultTotal = 0;

    public int $resultAdjusted = 0;

    public int $resultFailed = 0;

    public array $resultFailedRows = [];

    public ?int $resultRunId = null;

    public ?string $pendingErrorImportWarning = null;

    public function mount(): void
    {
        if (! Auth::user()?->canMutateInventory()) {
            abort(403);
        }

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

    public function updatedAction(): void
    {
        $this->reason = '';
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
            throw ValidationException::withMessages(['file' => __('stock_adjustment_import.empty_file')]);
        }

        if ($data['total'] > 2000) {
            throw ValidationException::withMessages([
                'file' => __('stock_adjustment_import.row_cap_exceeded', ['count' => $data['total']]),
            ]);
        }

        $this->fileHeaders = $data['headers'];
        $this->sampleRows = array_slice($data['rows'], 0, 5);
        $this->totalDataRows = $data['total'];
        $this->mapping = StockAdjustmentImportFields::autoGuess($this->fileHeaders);
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
        $template = StockAdjustmentImportMapping::query()
            ->where('tenant_id', $tenantId)
            ->find($id);

        if (! $template) {
            return;
        }

        $this->applyTemplateMapping($template);
        $this->selectedTemplateId = (string) $template->id;
    }

    public function setDefaultTemplate(int $id): void
    {
        $tenantId = $this->validatedTenantId();
        $template = StockAdjustmentImportMapping::query()
            ->where('tenant_id', $tenantId)
            ->find($id);

        if (! $template) {
            return;
        }

        DB::transaction(function () use ($tenantId, $template): void {
            StockAdjustmentImportMapping::query()
                ->where('tenant_id', $tenantId)
                ->update(['is_default' => false]);
            $template->update(['is_default' => true]);
        });

        session()->flash('status', __('stock_adjustment_import.template_default_saved'));
    }

    public function deleteTemplate(int $id): void
    {
        $tenantId = $this->validatedTenantId();

        StockAdjustmentImportMapping::query()
            ->where('tenant_id', $tenantId)
            ->where('id', $id)
            ->delete();

        if ($this->selectedTemplateId === (string) $id) {
            $this->selectedTemplateId = '';
        }
    }

    public function advanceToPreview(): void
    {
        $missing = $this->missingRequiredFields();

        if ($missing !== []) {
            session()->flash('error', __('stock_adjustment_import.required_fields_missing', [
                'fields' => implode(', ', $missing),
            ]));

            return;
        }

        $tenantId = $this->validatedTenantId();
        $this->validateContext($tenantId);

        if (! $this->saveTemplateIfRequested($tenantId)) {
            return;
        }

        $evaluation = $this->evaluateStoredRows($tenantId, (int) $this->warehouseId);
        $this->applyEvaluation($evaluation);
        $this->step = 'preview';
    }

    public function backToMap(): void
    {
        $this->pendingErrorImportWarning = null;
        $this->step = 'map';
    }

    public function confirmImport(): void
    {
        $this->applyImport(allowErrors: false);
    }

    public function confirmImportWithErrors(): void
    {
        $this->applyImport(allowErrors: true);
    }

    public function cancelImportWithErrors(): void
    {
        $this->pendingErrorImportWarning = null;
    }

    private function applyImport(bool $allowErrors): void
    {
        $tenantId = $this->validatedTenantId();
        $this->validateContext($tenantId);

        $evaluation = $this->evaluateStoredRows($tenantId, (int) $this->warehouseId);
        $this->applyEvaluation($evaluation);

        if ($evaluation['errorCount'] > 0 && ! $allowErrors) {
            $this->step = 'preview';
            $this->pendingErrorImportWarning = __('stock_adjustment_import.confirm_with_errors_warning', [
                'valid' => count($evaluation['validRows']),
                'errors' => $evaluation['errorCount'],
            ]);

            return;
        }

        $this->pendingErrorImportWarning = null;

        if (count($evaluation['validRows']) === 0) {
            $this->step = 'preview';
            session()->flash('error', __('stock_adjustment_import.confirm_no_valid_rows'));

            return;
        }

        try {
            $run = DB::transaction(function () use ($tenantId, $evaluation): StockAdjustmentImportRun {
                $run = StockAdjustmentImportRun::create([
                    'tenant_id' => $tenantId,
                    'warehouse_id' => (int) $this->warehouseId,
                    'action' => $this->action,
                    'reason' => $this->reason,
                    'note' => $this->nullableString($this->note),
                    'file_name' => $this->fileName,
                    'total_rows' => $evaluation['total'],
                    'adjusted_rows' => 0,
                    'failed_rows' => $evaluation['errorCount'],
                    'created_by_user_id' => Auth::id(),
                    'confirmed_at' => now(),
                ]);

                foreach ($evaluation['validRows'] as $row) {
                    app(InventoryService::class)->adjustStock(
                        tenantId: $tenantId,
                        warehouseId: (int) $this->warehouseId,
                        stockItemId: (int) $row['stock_item_id'],
                        quantityDelta: (int) $row['signed_qty'],
                        context: [
                            'ref_type' => 'stock_adjustment_import',
                            'ref_id' => (string) $run->id,
                            'user_id' => Auth::id(),
                            'note' => $this->movementNote($row),
                        ],
                    );
                }

                $run->update(['adjusted_rows' => count($evaluation['validRows'])]);

                return $run;
            });
        } catch (InvalidArgumentException $exception) {
            session()->flash('error', $exception->getMessage());

            return;
        }

        $this->resultTotal = $evaluation['total'];
        $this->resultAdjusted = count($evaluation['validRows']);
        $this->resultFailed = $evaluation['errorCount'];
        $this->resultFailedRows = $evaluation['errorRows'];
        $this->resultRunId = $run->id;
        $this->deleteStoredImportFile();
        $this->step = 'result';

        session()->flash('status', __('stock_adjustment_import.imported', ['count' => $this->resultAdjusted]));
    }

    public function startOver(): void
    {
        $this->deleteStoredImportFile();

        $this->reset([
            'file', 'fileName', 'filePath', 'fileHeaders', 'sampleRows', 'totalDataRows',
            'mapping', 'selectedTemplateId', 'doSaveTemplate', 'templateName',
            'validRowCount', 'errorRowCount', 'previewRows',
            'resultTotal', 'resultAdjusted', 'resultFailed', 'resultFailedRows', 'resultRunId', 'pendingErrorImportWarning',
        ]);

        $this->step = 'upload';
    }

    public function render(): View
    {
        return view('livewire.stock-adjustment-import', [
            'tenants' => $this->tenantOptions(),
            'warehouses' => $this->warehouseOptions(),
            'showTenantSelect' => $this->isInternalUser(),
            'currentTenant' => $this->currentTenant(),
            'actionOptions' => $this->actionOptions(),
            'reasonOptions' => $this->reasonOptions(),
            'fields' => StockAdjustmentImportFields::all(),
            'columnToField' => $this->buildColumnToFieldMap(),
            'savedTemplates' => $this->savedTemplates(),
        ])->layout('inventory', [
            'title' => __('stock_adjustment_import.page_title'),
            'subtitle' => __('stock_adjustment_import.page_subtitle'),
            'pageWide' => $this->step === 'preview',
        ]);
    }

    private function validateUploadFields(): void
    {
        validator([
            'tenantId' => $this->tenantId,
            'warehouseId' => $this->warehouseId,
            'action' => $this->action,
            'reason' => $this->reason,
            'file' => $this->file,
        ], [
            'tenantId' => ['required'],
            'warehouseId' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('status', 'active')],
            'action' => ['required', Rule::in([self::ACTION_ADD, self::ACTION_DEDUCT])],
            'reason' => ['required', Rule::in($this->allowedReasons())],
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:5120'],
        ])->validate();
    }

    private function validateContext(int $tenantId): void
    {
        validator([
            'warehouseId' => $this->warehouseId,
            'action' => $this->action,
            'reason' => $this->reason,
        ], [
            'warehouseId' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('status', 'active')],
            'action' => ['required', Rule::in([self::ACTION_ADD, self::ACTION_DEDUCT])],
            'reason' => ['required', Rule::in($this->allowedReasons())],
        ])->validate();

        if (! in_array($tenantId, $this->allowedTenantIds(), true)) {
            throw ValidationException::withMessages(['tenantId' => __('stock_adjustments.invalid_tenant')]);
        }
    }

    /**
     * @return array{total: int, validRows: list<array<string, mixed>>, errorRows: list<array<string, mixed>>, errorCount: int, previewRows: list<array<string, mixed>>}
     */
    private function evaluateStoredRows(int $tenantId, int $warehouseId): array
    {
        $data = $this->readStoredImportFile();
        $columnIndex = $this->buildColumnIndex();
        /** @var array<int, bool> $seenStockItems */
        $seenStockItems = [];
        $validRows = [];
        $errorRows = [];
        $previewRows = [];
        $errorCount = 0;

        foreach ($data['rows'] as $idx => $row) {
            $rowNo = $idx + 1;
            $rowData = $this->extractRowData($row, $columnIndex);
            $evaluation = $this->evaluateRow($rowData, $tenantId, $warehouseId, $seenStockItems);

            if ($evaluation['status'] === 'error') {
                $errorCount++;
                $errorRows[] = [
                    'row' => $rowNo,
                    ...$evaluation,
                ];
            } else {
                $validRows[] = $evaluation;
            }

            if (count($previewRows) < 50) {
                $previewRows[] = [
                    'row' => $rowNo,
                    ...$evaluation,
                ];
            }
        }

        return [
            'total' => $data['total'],
            'validRows' => $validRows,
            'errorRows' => $errorRows,
            'errorCount' => $errorCount,
            'previewRows' => $previewRows,
        ];
    }

    /**
     * @param  array<int, bool>  $seenStockItems
     * @return array<string, mixed>
     */
    private function evaluateRow(array $rowData, int $tenantId, int $warehouseId, array &$seenStockItems): array
    {
        $identifier = trim((string) ($rowData['identifier'] ?? ''));
        $quantityRaw = trim((string) ($rowData['quantity'] ?? ''));
        $lineNote = trim((string) ($rowData['line_note'] ?? ''));
        $referenceNo = trim((string) ($rowData['reference_no'] ?? ''));
        $errors = [];
        $stockItem = null;

        if ($identifier === '') {
            $errors[] = __('stock_adjustment_import.error_identifier_required');
        } else {
            $resolved = $this->resolveIdentifier($tenantId, $identifier);
            $stockItem = $resolved['stockItem'];
            $errors = [...$errors, ...$resolved['errors']];
        }

        if (! preg_match('/^[1-9][0-9]*$/', $quantityRaw)) {
            $errors[] = __('stock_adjustment_import.error_quantity_positive_integer');
        }

        $quantity = preg_match('/^[1-9][0-9]*$/', $quantityRaw) ? (int) $quantityRaw : 0;
        $signedQty = $this->action === self::ACTION_DEDUCT ? -$quantity : $quantity;
        $currentBalance = null;
        $currentOnHand = 0;
        $currentAvailable = 0;

        if ($stockItem) {
            $currentBalance = InventoryBalance::query()
                ->where('tenant_id', $tenantId)
                ->where('warehouse_id', $warehouseId)
                ->where('stock_item_id', $stockItem->id)
                ->first();

            if ($currentBalance instanceof InventoryBalance) {
                $currentOnHand = (int) $currentBalance->on_hand_qty;
                $currentAvailable = (int) $currentBalance->available_qty;
            }

            $stockItemKey = (int) $stockItem->id;

            if (isset($seenStockItems[$stockItemKey])) {
                $errors[] = __('stock_adjustment_import.error_duplicate_stock_item');
            } else {
                $seenStockItems[$stockItemKey] = true;
            }

            if ($this->action === self::ACTION_DEDUCT && $currentOnHand - $quantity < 0) {
                $errors[] = __('stock_adjustment_import.error_negative_on_hand');
            }

            if ($this->action === self::ACTION_DEDUCT && $currentAvailable - $quantity < 0) {
                $errors[] = __('stock_adjustment_import.error_negative_available');
            }
        }

        return [
            'status' => $errors === [] ? 'valid' : 'error',
            'errors' => $errors,
            'identifier' => $identifier,
            'stock_item_id' => $stockItem instanceof StockItem ? $stockItem->id : null,
            'stock_item_code' => $stockItem instanceof StockItem ? $stockItem->code : '',
            'tenant_item_code' => $stockItem instanceof StockItem ? ($stockItem->tenant_item_code ?? '') : '',
            'stock_item_name' => $stockItem instanceof StockItem ? $stockItem->displayName() : '',
            'current_on_hand' => $currentOnHand,
            'current_available' => $currentAvailable,
            'quantity' => $quantity,
            'signed_qty' => $signedQty,
            'resulting_on_hand' => $currentOnHand + $signedQty,
            'line_note' => $lineNote,
            'reference_no' => $referenceNo,
        ];
    }

    /**
     * @return array{stockItem: ?StockItem, errors: list<string>}
     */
    private function resolveIdentifier(int $tenantId, string $identifier): array
    {
        $stockItemIds = [];

        StockItem::query()
            ->where('tenant_id', $tenantId)
            ->where('code', $identifier)
            ->pluck('id')
            ->each(function ($id) use (&$stockItemIds): void {
                $stockItemIds[] = (int) $id;
            });

        StockItem::query()
            ->where('tenant_id', $tenantId)
            ->whereNotNull('tenant_item_code')
            ->where('tenant_item_code', $identifier)
            ->pluck('id')
            ->each(function ($id) use (&$stockItemIds): void {
                $stockItemIds[] = (int) $id;
            });

        Sku::query()
            ->where('tenant_id', $tenantId)
            ->where('sku', $identifier)
            ->whereNotNull('stock_item_id')
            ->pluck('stock_item_id')
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
            return ['stockItem' => null, 'errors' => [__('stock_adjustment_import.error_identifier_not_found')]];
        }

        if (count($stockItemIds) > 1) {
            return ['stockItem' => null, 'errors' => [__('stock_adjustment_import.error_identifier_ambiguous')]];
        }

        $stockItem = StockItem::query()
            ->where('tenant_id', $tenantId)
            ->find($stockItemIds[0], ['id', 'tenant_id', 'code', 'tenant_item_code', ...StockItem::DISPLAY_NAME_COLUMNS]);

        if (! $stockItem) {
            return ['stockItem' => null, 'errors' => [__('stock_adjustment_import.error_identifier_not_found')]];
        }

        return ['stockItem' => $stockItem, 'errors' => []];
    }

    private function applyEvaluation(array $evaluation): void
    {
        $this->totalDataRows = $evaluation['total'];
        $this->validRowCount = count($evaluation['validRows']);
        $this->errorRowCount = $evaluation['errorCount'];
        $this->previewRows = $evaluation['previewRows'];
    }

    private function saveTemplateIfRequested(int $tenantId): bool
    {
        if (! $this->doSaveTemplate) {
            return true;
        }

        $name = trim($this->templateName);

        if ($name === '') {
            throw ValidationException::withMessages(['templateName' => __('stock_adjustment_import.template_name_required')]);
        }

        if (StockAdjustmentImportMapping::query()->where('tenant_id', $tenantId)->where('name', $name)->exists()) {
            throw ValidationException::withMessages(['templateName' => __('stock_adjustment_import.template_name_duplicate')]);
        }

        DB::transaction(function () use ($tenantId, $name): void {
            StockAdjustmentImportMapping::query()
                ->where('tenant_id', $tenantId)
                ->update(['is_default' => false]);

            $template = StockAdjustmentImportMapping::create([
                'tenant_id' => $tenantId,
                'name' => $name,
                'mapping' => $this->mapping,
                'is_default' => true,
                'created_by_user_id' => Auth::id(),
            ]);

            $this->selectedTemplateId = (string) $template->id;
        });

        $this->templateName = '';
        $this->doSaveTemplate = false;
        session()->flash('status', __('stock_adjustment_import.template_saved'));

        return true;
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

    private function movementNote(array $row): string
    {
        $parts = [__('stock_adjustments.reasons.'.$this->reason)];

        foreach ([$this->note, $row['line_note'] ?? '', $row['reference_no'] ?? ''] as $part) {
            $part = $this->nullableString((string) $part);

            if ($part !== null) {
                $parts[] = $part;
            }
        }

        return __('stock_adjustment_import.movement_note', [
            'parts' => implode(' | ', $parts),
        ]);
    }

    private function missingRequiredFields(): array
    {
        $missing = [];

        foreach (StockAdjustmentImportFields::all() as $field) {
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

        return StockAdjustmentImportMapping::query()
            ->where('tenant_id', $tenantId)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get(['id', 'name', 'is_default']);
    }

    private function applyDefaultTemplateForTenant(int $tenantId): void
    {
        $template = StockAdjustmentImportMapping::query()
            ->where('tenant_id', $tenantId)
            ->where('is_default', true)
            ->first(['id', 'mapping']);

        if (! $template) {
            return;
        }

        $this->applyTemplateMapping($template);
        $this->selectedTemplateId = (string) $template->id;
        session()->flash('status', __('stock_adjustment_import.template_default_loaded'));
    }

    private function applyTemplateMapping(StockAdjustmentImportMapping $template): void
    {
        $validHeaders = array_flip($this->fileHeaders);
        $loaded = [];

        foreach ((array) ($template->mapping ?? []) as $fieldKey => $header) {
            $loaded[$fieldKey] = isset($validHeaders[$header]) ? $header : '';
        }

        foreach (StockAdjustmentImportFields::all() as $field) {
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
        return Tenant::query()
            ->whereIn('id', $this->allowedTenantIds())
            ->orderBy('name')
            ->get(['id', 'code', 'name']);
    }

    private function warehouseOptions(): Collection
    {
        return Warehouse::query()
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'code', 'name']);
    }

    private function currentTenant(): ?Tenant
    {
        if ($this->tenantId === '') {
            return null;
        }

        return Tenant::query()
            ->whereIn('id', $this->allowedTenantIds())
            ->find($this->tenantId, ['id', 'code', 'name']);
    }

    private function selectPreferredWarehouse(): void
    {
        if ($this->warehouseId !== '') {
            return;
        }

        $savedWarehouseId = Auth::user()?->preference(self::PREF_DEFAULT_WAREHOUSE_ID);

        if ($this->validActiveWarehouseId($savedWarehouseId)) {
            $this->warehouseId = (string) $savedWarehouseId;

            return;
        }

        $this->autoSelectSingleActiveWarehouse();
    }

    private function validActiveWarehouseId(mixed $warehouseId): bool
    {
        if (! is_numeric($warehouseId) || (int) $warehouseId <= 0) {
            return false;
        }

        return Warehouse::query()
            ->whereKey((int) $warehouseId)
            ->where('status', 'active')
            ->exists();
    }

    private function actionOptions(): array
    {
        return [
            self::ACTION_ADD => __('stock_adjustment_import.action_add'),
            self::ACTION_DEDUCT => __('stock_adjustment_import.action_deduct'),
        ];
    }

    private function reasonOptions(): array
    {
        $options = [];

        foreach ($this->allowedReasons() as $reason) {
            $options[$reason] = __('stock_adjustments.reasons.'.$reason);
        }

        return $options;
    }

    private function allowedReasons(): array
    {
        return match ($this->action) {
            self::ACTION_ADD => self::ADD_REASONS,
            self::ACTION_DEDUCT => self::DEDUCT_REASONS,
            default => [],
        };
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

        return $this->file->store('tmp/stock-adjustment-imports', 'local');
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
                'file' => __('stock_adjustment_import.file_expired'),
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

    private function nullableString(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
