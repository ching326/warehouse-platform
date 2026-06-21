<?php

namespace App\Livewire;

use App\Models\InboundOrder;
use App\Models\Tenant;
use App\Models\Warehouse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithPagination;

class InboundOrderIndex extends Component
{
    use WithPagination;

    public string $tenantId = '';

    public string $warehouseId = '';

    public string $status = '';

    public int $perPage = 15;

    private bool $visibleTenantIdsResolved = false;

    private ?array $visibleTenantIdsCache = null;

    public function updatedTenantId(): void
    {
        $this->resetPage();
    }

    public function updatedWarehouseId(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function markArrived(int $orderId): void
    {
        $order = InboundOrder::query()
            ->when($this->visibleTenantIds() !== null, fn ($query) => $query->whereIn('tenant_id', $this->visibleTenantIds()))
            ->where('status', InboundOrder::STATUS_PENDING)
            ->findOrFail($orderId);

        $order->update([
            'status' => InboundOrder::STATUS_ARRIVED,
            'arrived_at' => now(),
            'arrived_by_user_id' => Auth::id(),
        ]);

        session()->flash('status', __('inbound.order_arrived'));
    }

    public function cancel(int $orderId): void
    {
        $order = InboundOrder::query()
            ->when($this->visibleTenantIds() !== null, fn ($query) => $query->whereIn('tenant_id', $this->visibleTenantIds()))
            ->whereIn('status', [InboundOrder::STATUS_PENDING, InboundOrder::STATUS_ARRIVED])
            ->findOrFail($orderId);

        if ($order->lines()->where('received_qty', '>', 0)->exists()) {
            return;
        }

        $order->update(['status' => InboundOrder::STATUS_CANCELLED]);

        session()->flash('status', __('inbound.order_cancelled'));
    }

    public function render()
    {
        return view('livewire.inbound-order-index', [
            'orders' => $this->orders(),
            'tenants' => $this->tenantOptions(),
            'warehouses' => $this->warehouseOptions(),
            'showTenantFilter' => $this->isInternalUser(),
            'statuses' => [
                InboundOrder::STATUS_PENDING,
                InboundOrder::STATUS_ARRIVED,
                InboundOrder::STATUS_PARTIALLY_RECEIVED,
                InboundOrder::STATUS_RECEIVED,
                InboundOrder::STATUS_CANCELLED,
            ],
        ])->layout('inventory', [
            'title' => __('inbound.page_title'),
            'subtitle' => __('inbound.page_subtitle'),
            'pageWide' => true,
        ]);
    }

    public function statusLabel(string $status): string
    {
        return __('inbound.status_'.$status);
    }

    public function statusColor(string $status): string
    {
        return match ($status) {
            InboundOrder::STATUS_PENDING => 'amber',
            InboundOrder::STATUS_ARRIVED => 'blue',
            InboundOrder::STATUS_PARTIALLY_RECEIVED => 'indigo',
            InboundOrder::STATUS_RECEIVED => 'green',
            InboundOrder::STATUS_CANCELLED => 'zinc',
            default => 'zinc',
        };
    }

    private function orders()
    {
        return InboundOrder::query()
            ->with(['tenant:id,code,name', 'warehouse:id,code,name'])
            ->withCount('lines')
            ->when($this->visibleTenantIds() !== null, fn ($query) => $query->whereIn('tenant_id', $this->visibleTenantIds()))
            ->when($this->tenantId !== '', fn ($query) => $query->where('tenant_id', $this->tenantId))
            ->when($this->warehouseId !== '', fn ($query) => $query->where('warehouse_id', $this->warehouseId))
            ->when($this->status !== '', fn ($query) => $query->where('status', $this->status))
            ->orderByDesc('id')
            ->paginate($this->perPage);
    }
    private function isInternalUser(): bool
    {
        $user = Auth::user();

        return $user?->user_type === 'internal';
    }

    private function visibleTenantIds(): ?array
    {
        if ($this->visibleTenantIdsResolved) {
            return $this->visibleTenantIdsCache;
        }

        $this->visibleTenantIdsResolved = true;
        $user = Auth::user();

        if (! $user) {
            return $this->visibleTenantIdsCache = [];
        }

        if ($user->user_type === 'internal') {
            return $this->visibleTenantIdsCache = null;
        }

        return $this->visibleTenantIdsCache = $user->activeTenantIds();
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
            ->orderBy('name')
            ->get(['id', 'code', 'name']);
    }
}
