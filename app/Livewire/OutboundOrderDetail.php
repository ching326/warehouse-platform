<?php

namespace App\Livewire;

use App\Models\OutboundOrder;
use App\Models\Tenant;
use App\Services\InventoryService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class OutboundOrderDetail extends Component
{
    public int $orderId = 0;

    private bool $visibleTenantIdsResolved = false;

    private array $visibleTenantIdsCache = [];

    public function mount(OutboundOrder $order): void
    {
        $this->orderId = $this->scopedOrderQuery()->findOrFail($order->id)->id;
    }

    public function cancel(): void
    {
        $order = $this->scopedOrderQuery()
            ->with('leafLines')
            ->findOrFail($this->orderId);

        if ($order->status !== OutboundOrder::STATUS_PENDING) {
            return;
        }

        DB::transaction(function () use ($order): void {
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
        $order = $this->scopedOrderQuery()
            ->with([
                'tenant:id,code,name',
                'warehouse:id,code,name',
                'createdBy:id,name',
                'shippedBy:id,name',
                'cancelledBy:id,name',
                'parentLines.sku:id,sku,sku_type,name',
                'parentLines.stockItem:id,code,name',
                'parentLines.childLines.stockItem:id,code,name',
            ])
            ->findOrFail($this->orderId);

        return view('livewire.outbound-order-detail', [
            'order' => $order,
        ])->layout('inventory', [
            'title' => __('outbound.detail_page_title'),
            'subtitle' => __('outbound.detail_page_subtitle'),
            'pageWide' => true,
        ]);
    }

    private function scopedOrderQuery()
    {
        return OutboundOrder::query()->whereIn('tenant_id', $this->visibleTenantIds());
    }

    private function isInternalUser(): bool
    {
        $user = Auth::user();

        return $user?->user_type === 'internal';
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

        $user = Auth::user();

        if (! $user) {
            return $this->visibleTenantIdsCache = [];
        }

        return $this->visibleTenantIdsCache = $user->activeTenantIds();
    }
}
