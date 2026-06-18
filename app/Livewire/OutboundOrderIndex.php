<?php

namespace App\Livewire;

use App\Models\OutboundOrder;
use App\Models\Tenant;
use App\Models\Warehouse;
use App\Services\InventoryService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class OutboundOrderIndex extends Component
{
    use WithPagination;

    #[Url(as: 'tenant_id', except: '')]
    public string $tenantId = '';

    #[Url(as: 'warehouse_id', except: '')]
    public string $warehouseId = '';

    #[Url(as: 'status', except: '')]
    public string $statusFilter = '';

    private bool $visibleTenantIdsResolved = false;

    private array $visibleTenantIdsCache = [];

    public function updatedTenantId(): void
    {
        $this->resetPage();
    }

    public function updatedWarehouseId(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function cancel(int $orderId): void
    {
        $order = OutboundOrder::with('leafLines')
            ->whereIn('tenant_id', $this->visibleTenantIds())
            ->findOrFail($orderId);

        if ($order->status !== OutboundOrder::STATUS_PENDING) {
            return;
        }

        DB::transaction(function () use ($order) {
            foreach ($order->leafLines as $line) {
                app(InventoryService::class)->releaseReserve(
                    tenantId: $order->tenant_id,
                    warehouseId: $order->warehouse_id,
                    stockItemId: $line->stock_item_id,
                    quantity: $line->qty,
                    context: [
                        'ref_type' => 'outbound_order',
                        'ref_id' => (string) $order->id,
                        'user_id' => Auth::id(),
                    ],
                );
            }

            $order->status = OutboundOrder::STATUS_CANCELLED;
            $order->cancelled_at = now();
            $order->cancelled_by_user_id = Auth::id();
            $order->save();
        });

        session()->flash('status', __('outbound.order_cancelled'));
    }

    public function statusLabel(string $status): string
    {
        return match ($status) {
            OutboundOrder::STATUS_PENDING => __('outbound.status_pending'),
            OutboundOrder::STATUS_SHIPPED => __('outbound.status_shipped'),
            OutboundOrder::STATUS_CANCELLED => __('outbound.status_cancelled'),
            default => $status,
        };
    }

    public function statusColor(string $status): string
    {
        return match ($status) {
            OutboundOrder::STATUS_PENDING => 'amber',
            OutboundOrder::STATUS_SHIPPED => 'green',
            OutboundOrder::STATUS_CANCELLED => 'red',
            default => 'zinc',
        };
    }

    public function render()
    {
        $orders = OutboundOrder::query()
            ->whereIn('tenant_id', $this->visibleTenantIds())
            ->when($this->tenantId !== '', fn ($query) => $query->where('tenant_id', (int) $this->tenantId))
            ->when($this->warehouseId !== '', fn ($query) => $query->where('warehouse_id', (int) $this->warehouseId))
            ->when($this->statusFilter !== '', fn ($query) => $query->where('status', $this->statusFilter))
            ->with([
                'tenant:id,code,name',
                'warehouse:id,code,name',
                'parentLines.sku:id,sku,sku_type',
            ])
            ->orderByDesc('created_at')
            ->paginate(30);

        return view('livewire.outbound-order-index', [
            'orders' => $orders,
            'tenants' => Tenant::query()
                ->whereIn('id', $this->visibleTenantIds())
                ->orderBy('name')
                ->get(['id', 'code', 'name']),
            'warehouses' => Warehouse::query()->orderBy('name')->get(['id', 'code', 'name']),
            'statuses' => [
                OutboundOrder::STATUS_PENDING => __('outbound.status_pending'),
                OutboundOrder::STATUS_SHIPPED => __('outbound.status_shipped'),
                OutboundOrder::STATUS_CANCELLED => __('outbound.status_cancelled'),
            ],
        ])->layout('inventory', [
            'title' => __('outbound.page_title'),
            'subtitle' => __('outbound.page_subtitle'),
        ]);
    }

    // TODO: remove unauthenticated fallback when auth is implemented
    private function isInternalUser(): bool
    {
        $user = Auth::user();

        return ! $user || $user->user_type === 'internal';
    }

    private function visibleTenantIds(): array
    {
        if ($this->visibleTenantIdsResolved) {
            return $this->visibleTenantIdsCache;
        }

        $this->visibleTenantIdsResolved = true;

        if ($this->isInternalUser()) {
            return $this->visibleTenantIdsCache = Tenant::query()->pluck('id')->all();
        }

        return $this->visibleTenantIdsCache = Auth::user()
            ->tenantUsers()
            ->where('status', 'active')
            ->pluck('tenant_id')
            ->all();
    }
}
