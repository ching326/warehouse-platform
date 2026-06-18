<?php

namespace App\Livewire;

use App\Models\InventoryMovement;
use App\Models\StockItem;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class InventoryMovementsIndex extends Component
{
    use WithPagination;

    public string $search = '';

    #[Url(as: 'tenant_id', except: '')]
    public string $tenantId = '';

    #[Url(as: 'warehouse_id', except: '')]
    public string $warehouseId = '';

    #[Url(as: 'movement_type', except: '')]
    public string $movementType = '';

    #[Url(as: 'stock_item_id', except: '')]
    public string $stockItemId = '';

    public string $userId = '';

    public string $dateFrom = '';

    public string $dateTo = '';

    public int $perPage = 15;

    private bool $visibleTenantIdsResolved = false;

    private ?array $visibleTenantIdsCache = null;

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatedTenantId(): void
    {
        $this->resetPage();
    }

    public function updatedWarehouseId(): void
    {
        $this->resetPage();
    }

    public function updatedMovementType(): void
    {
        $this->resetPage();
    }

    public function updatedStockItemId(): void
    {
        $this->resetPage();
    }

    public function updatedUserId(): void
    {
        $this->resetPage();
    }

    public function updatedDateFrom(): void
    {
        $this->resetPage();
    }

    public function updatedDateTo(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'tenantId', 'warehouseId', 'movementType', 'stockItemId', 'userId', 'dateFrom', 'dateTo']);
        $this->resetPage();
    }

    public function render()
    {
        return view('livewire.inventory-movements-index', [
            'movements' => $this->movements(),
            'summary' => $this->summary(),
            'tenants' => $this->tenantOptions(),
            'warehouses' => $this->warehouseOptions(),
            'stockItems' => $this->stockItemOptions(),
            'movementTypes' => $this->movementTypeOptions(),
            'users' => $this->userOptions(),
            'showTenantColumn' => $this->showTenantColumn(),
            'selectedStockItem' => $this->selectedStockItem(),
        ])->layout('inventory', [
            'title' => 'Inventory Movements',
            'subtitle' => 'Review append-only stock movement history and current balance impact.',
        ]);
    }

    public function movements()
    {
        return $this->baseQuery()
            ->with([
                'tenant:id,code,name',
                'warehouse:id,code,name',
                'stockItem:id,tenant_id,code,name,barcode',
                'user:id,name,email',
            ])
            ->latest('created_at')
            ->latest('id')
            ->paginate($this->perPage);
    }

    public function summary(): array
    {
        $query = $this->baseQuery();

        return [
            'movements' => (clone $query)->count(),
            'netAvailable' => (clone $query)->sum('available_delta'),
            'positive' => (clone $query)->where('available_delta', '>', 0)->sum('available_delta'),
            'negative' => abs((clone $query)->where('available_delta', '<', 0)->sum('available_delta')),
            'latest' => (clone $query)->max('created_at'),
        ];
    }

    public function showTenantColumn(): bool
    {
        $user = Auth::user();

        return (! $user || $user->user_type === 'internal')
            && $this->tenantId === ''
            && $this->stockItemId === '';
    }

    public function movementTypeLabel(string $type): string
    {
        return str($type)->replace('_', ' ')->title()->toString();
    }

    public function movementColor(int $quantityDelta): string
    {
        return match (true) {
            $quantityDelta > 0 => 'green',
            $quantityDelta < 0 => 'red',
            default => 'zinc',
        };
    }

    public function quantityDeltaClass(int $quantityDelta): string
    {
        return match (true) {
            $quantityDelta > 0 => 'delta-positive',
            $quantityDelta < 0 => 'delta-negative',
            default => 'delta-zero',
        };
    }

    /**
     * @return array<int, array{label:string,value:int}>
     */
    public function movementImpactBuckets(InventoryMovement $movement): array
    {
        $buckets = [
            'Available' => $movement->available_delta,
            'On hand' => $movement->on_hand_delta,
            'Reserved' => $movement->reserved_delta,
            'Inbound' => $movement->inbound_delta,
            'Hold' => $movement->hold_delta,
            'Damaged' => $movement->damaged_delta,
        ];

        $impacts = collect($buckets)
            ->filter(fn (int $value) => $value !== 0)
            ->map(fn (int $value, string $label) => ['label' => $label, 'value' => $value])
            ->values()
            ->all();

        return $impacts === [] ? [['label' => 'Available', 'value' => 0]] : $impacts;
    }

    /**
     * @return array<int, array{label:string,value:int}>
     */
    public function movementAfterBuckets(InventoryMovement $movement): array
    {
        $buckets = [
            'Available' => $movement->available_after,
            'On hand' => $movement->on_hand_after,
            'Reserved' => $movement->reserved_after,
            'Hold' => $movement->hold_after,
            'Damaged' => $movement->damaged_after,
            'Inbound' => $movement->inbound_after,
        ];

        return collect($buckets)
            ->filter(fn (int $value, string $label) => in_array($label, ['Available', 'On hand'], true) || $value !== 0)
            ->map(fn (int $value, string $label) => ['label' => $label, 'value' => $value])
            ->values()
            ->all();
    }

    public function signedQuantity(int $quantity): string
    {
        return ($quantity > 0 ? '+' : '').number_format($quantity);
    }

    private function baseQuery(): Builder
    {
        return InventoryMovement::query()
            ->when($this->visibleTenantIds() !== null, fn ($query) => $query->whereIn('tenant_id', $this->visibleTenantIds()))
            ->when($this->tenantId !== '', fn ($query) => $query->where('tenant_id', $this->tenantId))
            ->when($this->warehouseId !== '', fn ($query) => $query->where('warehouse_id', $this->warehouseId))
            ->when($this->movementType !== '', fn ($query) => $query->where('movement_type', $this->movementType))
            ->when($this->stockItemId !== '', fn ($query) => $query->where('stock_item_id', $this->stockItemId))
            ->when($this->userId !== '', fn ($query) => $query->where('user_id', $this->userId))
            ->when($this->dateFrom !== '', fn ($query) => $query->whereDate('created_at', '>=', $this->dateFrom))
            ->when($this->dateTo !== '', fn ($query) => $query->whereDate('created_at', '<=', $this->dateTo))
            ->when($this->search !== '', function ($query) {
                $search = '%'.$this->search.'%';

                $query->where(function ($query) use ($search) {
                    $query
                        ->where('ref_type', 'like', $search)
                        ->orWhere('ref_id', 'like', $search)
                        ->orWhere('note', 'like', $search)
                        ->orWhereHas('stockItem', function ($query) use ($search) {
                            $query
                                ->where('code', 'like', $search)
                                ->orWhere('name', 'like', $search)
                                ->orWhere('barcode', 'like', $search);
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
            ->whereHas('inventoryMovements', fn ($query) => $this->applyTenantScope($query))
            ->orderBy('name')
            ->get(['id', 'code', 'name']);
    }

    private function stockItemOptions(): Collection
    {
        return StockItem::query()
            ->whereHas('inventoryMovements', fn ($query) => $this->applyTenantScope($query))
            ->when($this->tenantId !== '', fn ($query) => $query->where('tenant_id', $this->tenantId))
            ->orderBy('code')
            ->get(['id', 'tenant_id', 'code', 'name']);
    }

    private function movementTypeOptions(): Collection
    {
        return InventoryMovement::query()
            ->when($this->visibleTenantIds() !== null, fn ($query) => $query->whereIn('tenant_id', $this->visibleTenantIds()))
            ->select('movement_type')
            ->distinct()
            ->orderBy('movement_type')
            ->pluck('movement_type');
    }

    private function userOptions(): Collection
    {
        return User::query()
            ->whereHas('inventoryMovements', fn ($query) => $this->applyTenantScope($query))
            ->orderBy('name')
            ->get(['id', 'name', 'email']);
    }

    private function selectedStockItem(): ?StockItem
    {
        if ($this->stockItemId === '') {
            return null;
        }

        return StockItem::query()
            ->when($this->visibleTenantIds() !== null, fn ($query) => $query->whereIn('tenant_id', $this->visibleTenantIds()))
            ->find($this->stockItemId, ['id', 'code', 'name']);
    }

    private function applyTenantScope(Builder $query): Builder
    {
        return $query->when($this->visibleTenantIds() !== null, fn ($query) => $query->whereIn('tenant_id', $this->visibleTenantIds()));
    }

    private function visibleTenantIds(): ?array
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
