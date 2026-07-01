<?php

namespace App\Livewire;

use App\Livewire\Concerns\AutoSelectsSingleActiveWarehouse;
use App\Models\InventoryBalance;
use App\Models\StockItem;
use App\Models\Tenant;
use App\Models\Warehouse;
use App\Services\StockCountPostingService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;
use Livewire\Component;

class StockCountCreate extends Component
{
    use AutoSelectsSingleActiveWarehouse;

    private const PREF_DEFAULT_WAREHOUSE_ID = 'stock_adjustment_default_warehouse_id';

    public string $tenantId = '';

    public string $warehouseId = '';

    public string $stockItemId = '';

    public string $stockItemSearch = '';

    public string $countedQty = '';

    public string $note = '';

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
        $this->stockItemId = '';
        $this->stockItemSearch = '';
        $this->selectPreferredWarehouse();
    }

    public function updatedWarehouseId(): void
    {
        $this->stockItemId = '';
        $this->stockItemSearch = '';
    }

    public function save(StockCountPostingService $postingService)
    {
        $tenantId = $this->validatedTenantId();
        $this->validateInput($tenantId);

        try {
            $run = $postingService->postManual(
                tenantId: $tenantId,
                warehouseId: (int) $this->warehouseId,
                stockItemId: (int) $this->stockItemId,
                countedQty: (int) $this->countedQty,
                note: $this->nullableString($this->note),
                userId: Auth::id(),
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['countedQty' => $exception->getMessage()]);
        }

        session()->flash('status', __('stock_counts.saved'));

        return redirect()->route('stock-counts.show', $run);
    }

    public function render(): View
    {
        return view('livewire.stock-count-create', [
            'tenants' => $this->tenantOptions(),
            'warehouses' => $this->warehouseOptions(),
            'stockItems' => $this->stockItemOptions(),
            'selectedStockItem' => $this->selectedStockItem(),
            'currentBalance' => $this->currentBalance(),
            'showTenantSelect' => $this->isInternalUser(),
            'currentTenant' => $this->currentTenant(),
            'deltaQty' => $this->deltaQty(),
        ])->layout('inventory', [
            'title' => __('stock_counts.create_title'),
            'subtitle' => __('stock_counts.create_subtitle'),
        ]);
    }

    private function validateInput(int $tenantId): void
    {
        validator([
            'tenantId' => $this->tenantId,
            'warehouseId' => $this->warehouseId,
            'stockItemId' => $this->stockItemId,
            'countedQty' => $this->countedQty,
            'note' => $this->note,
        ], [
            'tenantId' => ['required'],
            'warehouseId' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('status', 'active')],
            'stockItemId' => ['required', 'integer', Rule::exists('stock_items', 'id')->where('tenant_id', $tenantId)],
            'countedQty' => ['required', 'integer', 'min:0'],
            'note' => ['nullable', 'string', 'max:1000'],
        ])->validate();

        $balance = $this->currentBalance();
        $minimum = (int) ($balance?->reserved_qty ?? 0) + (int) ($balance?->hold_qty ?? 0) + (int) ($balance?->damaged_qty ?? 0);

        if ((int) $this->countedQty < $minimum) {
            throw ValidationException::withMessages(['countedQty' => __('stock_counts.error_counted_below_committed')]);
        }
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

    private function deltaQty(): ?int
    {
        if ($this->countedQty === '' || ! is_numeric($this->countedQty)) {
            return null;
        }

        return (int) $this->countedQty - (int) ($this->currentBalance()?->on_hand_qty ?? 0);
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
                        ->orWhere('tenant_item_code', 'like', $search)
                        ->orWhere('name', 'like', $search)
                        ->orWhere('name_en', 'like', $search)
                        ->orWhere('name_ja', 'like', $search)
                        ->orWhere('name_zh_tw', 'like', $search)
                        ->orWhere('name_zh_cn', 'like', $search)
                        ->orWhere('short_name', 'like', $search)
                        ->orWhereHas('barcodeAliases', fn ($query) => $query
                            ->where('is_active', true)
                            ->where('barcode', 'like', $search))
                        ->orWhereHas('skus', function ($query) use ($search): void {
                            $query->where('sku', 'like', $search)
                                ->orWhere('platform_sku', 'like', $search)
                                ->orWhere('platform_label_code', 'like', $search);
                        });
                });
            })
            ->orderBy('code')
            ->limit(30)
            ->get(['id', 'code', 'tenant_item_code', ...StockItem::DISPLAY_NAME_COLUMNS]);
    }

    private function selectedStockItem(): ?StockItem
    {
        if ($this->tenantId === '' || $this->stockItemId === '') {
            return null;
        }

        return StockItem::query()
            ->where('tenant_id', $this->tenantId)
            ->find($this->stockItemId, ['id', 'code', 'tenant_item_code', ...StockItem::DISPLAY_NAME_COLUMNS]);
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

        return Tenant::query()->whereIn('id', $this->allowedTenantIds())->find($this->tenantId, ['id', 'code', 'name']);
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
        return is_numeric($warehouseId)
            && Warehouse::query()->whereKey((int) $warehouseId)->where('status', 'active')->exists();
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

    private function nullableString(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
