<?php

namespace App\Livewire;

use App\Livewire\Concerns\AutoSelectsSingleActiveWarehouse;
use App\Models\InventoryBalance;
use App\Models\StockItem;
use App\Models\Tenant;
use App\Models\Warehouse;
use App\Services\InventoryService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Attributes\Url;
use Livewire\Component;

class StockAdjustmentCreate extends Component
{
    use AutoSelectsSingleActiveWarehouse;

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

    #[Url(as: 'tenant_id', except: '')]
    public string $tenantId = '';

    #[Url(as: 'warehouse_id', except: '')]
    public string $warehouseId = '';

    #[Url(as: 'stock_item_id', except: '')]
    public string $stockItemId = '';

    public string $stockItemSearch = '';

    public string $action = '';

    public string $quantity = '';

    public string $reason = '';

    public string $note = '';

    public string $refId = '';

    public bool $currentWarehouseIsDefault = false;

    public function mount(): void
    {
        if (! $this->isInternalUser() && $this->tenantId === '') {
            $this->tenantId = (string) ($this->activeTenantIds()[0] ?? '');
        }

        if ($this->warehouseId === '') {
            $savedWarehouseId = Auth::user()?->preference(self::PREF_DEFAULT_WAREHOUSE_ID);

            if ($this->validActiveWarehouseId($savedWarehouseId)) {
                $this->warehouseId = (string) $savedWarehouseId;
            }
        }

        $this->autoSelectSingleActiveWarehouse();
        $this->syncCurrentWarehouseIsDefault();
    }

    public function updatedTenantId(): void
    {
        $this->warehouseId = '';
        $this->stockItemId = '';
        $this->stockItemSearch = '';
    }

    public function updatedWarehouseId(): void
    {
        $this->stockItemId = '';
        $this->stockItemSearch = '';
        $this->syncCurrentWarehouseIsDefault();
    }

    public function updatedCurrentWarehouseIsDefault(bool $checked): void
    {
        $user = Auth::user();

        if (! $user || ! $this->validActiveWarehouseId($this->warehouseId)) {
            $this->currentWarehouseIsDefault = false;

            return;
        }

        if ($checked) {
            $user->setPreference(self::PREF_DEFAULT_WAREHOUSE_ID, (string) $this->warehouseId);
            session()->flash('status', __('stock_adjustments.default_warehouse_saved'));

            return;
        }

        $user->forgetPreference(self::PREF_DEFAULT_WAREHOUSE_ID);
        session()->flash('status', __('stock_adjustments.default_warehouse_cleared'));
    }

    public function updatedAction(): void
    {
        $this->reason = '';
    }

    public function save()
    {
        $tenantId = $this->validatedTenantId();
        $this->validateInput($tenantId);

        try {
            app(InventoryService::class)->adjustStock(
                $tenantId,
                (int) $this->warehouseId,
                (int) $this->stockItemId,
                $this->signedQuantity(),
                [
                    'ref_type' => 'manual_adjustment',
                    'ref_id' => $this->nullableString($this->refId),
                    'user_id' => Auth::id(),
                    'note' => $this->movementNote(),
                ],
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['quantity' => $exception->getMessage()]);
        }

        session()->flash('status', __('stock_adjustments.adjustment_saved'));

        return redirect()->route('inventory.index');
    }

    public function render()
    {
        return view('livewire.stock-adjustment-create', [
            'tenants' => $this->tenantOptions(),
            'warehouses' => $this->warehouseOptions(),
            'stockItems' => $this->stockItemOptions(),
            'selectedStockItem' => $this->selectedStockItem(),
            'currentBalance' => $this->currentBalance(),
            'showTenantSelect' => $this->isInternalUser(),
            'currentTenant' => $this->currentTenant(),
            'actionOptions' => $this->actionOptions(),
            'reasonOptions' => $this->reasonOptions(),
        ])->layout('inventory', [
            'title' => __('stock_adjustments.page_title'),
            'subtitle' => __('stock_adjustments.page_subtitle'),
        ]);
    }

    private function validateInput(int $tenantId): void
    {
        validator($this->formData(), [
            'tenant_id' => ['required', 'integer'],
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('status', 'active')],
            'stock_item_id' => ['required', 'integer', Rule::exists('stock_items', 'id')->where('tenant_id', $tenantId)],
            'action' => ['required', Rule::in([self::ACTION_ADD, self::ACTION_DEDUCT])],
            'quantity' => ['required', 'integer', 'min:1'],
            'reason' => ['required', Rule::in($this->allowedReasons())],
            'note' => ['nullable', 'string', 'max:1000'],
            'ref_id' => ['nullable', 'string', 'max:255'],
        ])->validate();
    }

    private function formData(): array
    {
        return [
            'tenant_id' => $this->tenantId,
            'warehouse_id' => $this->warehouseId,
            'stock_item_id' => $this->stockItemId,
            'action' => $this->action,
            'quantity' => $this->quantity,
            'reason' => $this->reason,
            'note' => $this->note,
            'ref_id' => $this->refId,
        ];
    }

    private function isInternalUser(): bool
    {
        $user = Auth::user();

        return $user?->user_type === 'internal';
    }

    private function allowedTenantIds(): array
    {
        if ($this->isInternalUser()) {
            return Tenant::query()->pluck('id')->all();
        }

        return $this->activeTenantIds();
    }

    private function validatedTenantId(): int
    {
        $tenantId = (int) $this->tenantId;

        if ($tenantId <= 0 || ! in_array($tenantId, $this->allowedTenantIds(), true)) {
            throw ValidationException::withMessages(['tenantId' => __('stock_adjustments.invalid_tenant')]);
        }

        return $tenantId;
    }

    private function activeTenantIds(): array
    {
        $user = Auth::user();

        if (! $user) {
            return [];
        }

        return $user->activeTenantIds();
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

    private function syncCurrentWarehouseIsDefault(): void
    {
        $this->currentWarehouseIsDefault = $this->warehouseId !== ''
            && (string) Auth::user()?->preference(self::PREF_DEFAULT_WAREHOUSE_ID) === (string) $this->warehouseId;
    }

    private function currentBalance(): ?InventoryBalance
    {
        if ($this->tenantId === '' || $this->warehouseId === '' || $this->stockItemId === '') {
            return null;
        }

        return InventoryBalance::query()
            ->where('tenant_id', $this->tenantId)
            ->where('warehouse_id', $this->warehouseId)
            ->where('stock_item_id', $this->stockItemId)
            ->first();
    }

    private function stockItemOptions(): Collection
    {
        if ($this->tenantId === '' || $this->warehouseId === '') {
            return collect();
        }

        $searchTerm = trim($this->stockItemSearch);
        $search = '%'.$searchTerm.'%';

        return StockItem::query()
            ->where('tenant_id', $this->tenantId)
            ->with(['skus:id,stock_item_id,sku'])
            ->when($searchTerm !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query
                        ->where('code', 'like', $search)
                        ->orWhere('name', 'like', $search)
                        ->orWhere('name_en', 'like', $search)
                        ->orWhere('name_ja', 'like', $search)
                        ->orWhere('name_zh_tw', 'like', $search)
                        ->orWhere('name_zh_cn', 'like', $search)
                        ->orWhere('short_name', 'like', $search)
                        ->orWhere('barcode', 'like', $search)
                        ->orWhereHas('skus', function ($query) use ($search): void {
                            $query
                                ->where('sku', 'like', $search)
                                ->orWhere('name', 'like', $search)
                                ->orWhere('name_en', 'like', $search)
                                ->orWhere('name_ja', 'like', $search)
                                ->orWhere('name_zh_tw', 'like', $search)
                                ->orWhere('name_zh_cn', 'like', $search)
                                ->orWhere('platform_sku', 'like', $search)
                                ->orWhere('platform_label_code', 'like', $search);
                        });
                });
            })
            ->orderBy('code')
            ->limit(30)
            ->get(['id', 'code', ...StockItem::DISPLAY_NAME_COLUMNS, 'barcode']);
    }

    private function selectedStockItem(): ?StockItem
    {
        if ($this->tenantId === '' || $this->stockItemId === '') {
            return null;
        }

        return StockItem::query()
            ->where('tenant_id', $this->tenantId)
            ->find($this->stockItemId, ['id', 'code', ...StockItem::DISPLAY_NAME_COLUMNS]);
    }

    private function currentTenant(): ?Tenant
    {
        if ($this->tenantId === '') {
            return null;
        }

        return Tenant::query()->find($this->tenantId, ['id', 'code', 'name']);
    }

    private function signedQuantity(): int
    {
        $quantity = (int) $this->quantity;

        return $this->action === self::ACTION_DEDUCT ? -$quantity : $quantity;
    }

    private function movementNote(): string
    {
        $reason = __('stock_adjustments.reasons.'.$this->reason);
        $note = $this->nullableString($this->note);

        return $note === null
            ? __('stock_adjustments.movement_note_reason', ['reason' => $reason])
            : __('stock_adjustments.movement_note_reason_with_note', ['reason' => $reason, 'note' => $note]);
    }

    private function actionOptions(): array
    {
        return [
            self::ACTION_ADD => __('stock_adjustments.action_add'),
            self::ACTION_DEDUCT => __('stock_adjustments.action_deduct'),
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

    private function nullableString(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
