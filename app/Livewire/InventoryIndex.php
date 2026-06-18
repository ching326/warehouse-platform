<?php

namespace App\Livewire;

use App\Models\InventoryBalance;
use App\Models\Shop;
use App\Models\Tenant;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithPagination;

class InventoryIndex extends Component
{
    use WithPagination;

    public string $search = '';

    public string $tenantId = '';

    public string $warehouseId = '';

    public string $shopId = '';

    public string $productType = '';

    public string $status = '';

    public int $perPage = 10;

    private bool $visibleTenantIdsResolved = false;

    private ?array $visibleTenantIdsCache = null;

    /**
     * @var array<int, bool>
     */
    public array $expandedStockItems = [];

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatedTenantId(): void
    {
        $this->shopId = '';
        $this->resetPage();
    }

    public function updatedWarehouseId(): void
    {
        $this->resetPage();
    }

    public function updatedShopId(): void
    {
        $this->resetPage();
    }

    public function updatedProductType(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        return view('livewire.inventory-index', [
            'balances' => $this->balances(),
            'summary' => $this->summary(),
            'tenants' => $this->tenantOptions(),
            'warehouses' => $this->warehouseOptions(),
            'shops' => $this->shopOptions(),
            'productTypes' => $this->productTypeOptions(),
            'statuses' => $this->statusOptions(),
            'showTenantColumn' => $this->showTenantColumn(),
        ])->layout('inventory');
    }

    public function toggleSkuList(int $stockItemId): void
    {
        $this->expandedStockItems[$stockItemId] = ! ($this->expandedStockItems[$stockItemId] ?? false);
    }

    public function isSkuListExpanded(int $stockItemId): bool
    {
        return $this->expandedStockItems[$stockItemId] ?? false;
    }

    public function availableStatusClass(int $availableQty): string
    {
        if ($availableQty <= 0) {
            return 'available available-danger';
        }

        if ($availableQty <= 10) {
            return 'available available-warning';
        }

        return 'available available-success';
    }

    public function balances()
    {
        return $this->baseQuery()
            ->with([
                'tenant:id,code,name',
                'warehouse:id,code,name',
                'stockItem' => fn ($query) => $query->select([
                    'id',
                    'tenant_id',
                    'code',
                    'name',
                    'barcode',
                    'product_type',
                    'status',
                ]),
                'stockItem.skus' => fn ($query) => $query
                    ->select([
                        'id',
                        'tenant_id',
                        'shop_id',
                        'stock_item_id',
                        'sku',
                        'platform_sku',
                        'platform_label_code',
                    ])
                    ->orderBy('sku'),
                'stockItem.skus.shop:id,code,name,platform',
            ])
            ->orderBy('tenant_id')
            ->orderBy('warehouse_id')
            ->orderBy(
                InventoryBalance::query()
                    ->select('code')
                    ->from('stock_items')
                    ->whereColumn('stock_items.id', 'inventory_balances.stock_item_id')
                    ->limit(1),
            )
            ->paginate($this->perPage);
    }

    public function summary(): array
    {
        $query = $this->baseQuery();

        return [
            'stock_items' => (clone $query)->count(),
            'on_hand' => (clone $query)->sum('on_hand_qty'),
            'available' => (clone $query)->sum('available_qty'),
            'reserved' => (clone $query)->sum('reserved_qty'),
        ];
    }

    public function showTenantColumn(): bool
    {
        $user = Auth::user();

        return (! $user || $user->user_type === 'internal') && $this->tenantId === '';
    }

    /**
     * @return Builder<InventoryBalance>
     */
    private function baseQuery(): Builder
    {
        return InventoryBalance::query()
            ->when($this->visibleTenantIds() !== null, fn ($query) => $query->whereIn('tenant_id', $this->visibleTenantIds()))
            ->when($this->tenantId !== '', fn ($query) => $query->where('tenant_id', $this->tenantId))
            ->when($this->warehouseId !== '', fn ($query) => $query->where('warehouse_id', $this->warehouseId))
            ->when($this->productType !== '', fn ($query) => $query->whereHas('stockItem', fn ($query) => $query->where('product_type', $this->productType)))
            ->when($this->status !== '', fn ($query) => $query->whereHas('stockItem', fn ($query) => $query->where('status', $this->status)))
            ->when($this->shopId !== '', fn ($query) => $query->whereHas('stockItem.skus', fn ($query) => $query->where('shop_id', $this->shopId)))
            ->when($this->search !== '', function ($query) {
                $search = '%'.$this->search.'%';

                $query->where(function ($query) use ($search) {
                    $query
                        ->whereHas('stockItem', function ($query) use ($search) {
                            $query
                                ->where('code', 'like', $search)
                                ->orWhere('name', 'like', $search)
                                ->orWhere('barcode', 'like', $search);
                        })
                        ->orWhereHas('stockItem.skus', function ($query) use ($search) {
                            $query
                                ->where('sku', 'like', $search)
                                ->orWhere('platform_sku', 'like', $search)
                                ->orWhere('platform_label_code', 'like', $search);
                        });
                });
            });
    }

    private function tenantOptions(): Collection
    {
        return Tenant::query()
            ->when($this->visibleTenantIds() !== null, fn ($query) => $query->whereIn('id', $this->visibleTenantIds()))
            ->orderBy('name')
            ->get(['id', 'code', 'name']);
    }

    private function warehouseOptions(): Collection
    {
        return Warehouse::query()
            ->whereHas('inventoryBalances', fn ($query) => $this->applyTenantScope($query))
            ->orderBy('name')
            ->get(['id', 'code', 'name']);
    }

    private function shopOptions(): Collection
    {
        return Shop::query()
            ->when($this->visibleTenantIds() !== null, fn ($query) => $query->whereIn('tenant_id', $this->visibleTenantIds()))
            ->when($this->tenantId !== '', fn ($query) => $query->where('tenant_id', $this->tenantId))
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'platform']);
    }

    private function productTypeOptions(): Collection
    {
        return InventoryBalance::query()
            ->join('stock_items', 'stock_items.id', '=', 'inventory_balances.stock_item_id')
            ->when($this->visibleTenantIds() !== null, fn ($query) => $query->whereIn('inventory_balances.tenant_id', $this->visibleTenantIds()))
            ->select('stock_items.product_type')
            ->distinct()
            ->orderBy('stock_items.product_type')
            ->pluck('stock_items.product_type');
    }

    private function statusOptions(): Collection
    {
        return InventoryBalance::query()
            ->join('stock_items', 'stock_items.id', '=', 'inventory_balances.stock_item_id')
            ->when($this->visibleTenantIds() !== null, fn ($query) => $query->whereIn('inventory_balances.tenant_id', $this->visibleTenantIds()))
            ->select('stock_items.status')
            ->distinct()
            ->orderBy('stock_items.status')
            ->pluck('stock_items.status');
    }

    private function applyTenantScope(Builder $query): Builder
    {
        return $query->when($this->visibleTenantIds() !== null, fn ($query) => $query->whereIn('tenant_id', $this->visibleTenantIds()));
    }

    public function visibleTenantIds(): ?array
    {
        if ($this->visibleTenantIdsResolved) {
            return $this->visibleTenantIdsCache;
        }

        $this->visibleTenantIdsResolved = true;
        $user = Auth::user();

        if (! $user || $user->user_type === 'internal') {
            return $this->visibleTenantIdsCache = null;
        }

        return $this->visibleTenantIdsCache = $user->tenantUsers()
            ->where('status', 'active')
            ->pluck('tenant_id')
            ->all();
    }
}
