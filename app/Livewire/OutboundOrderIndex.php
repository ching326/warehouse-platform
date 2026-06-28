<?php

namespace App\Livewire;

use App\Livewire\Concerns\AutoSelectsSingleActiveWarehouse;
use App\Models\OutboundOrder;
use App\Models\Shop;
use App\Models\Tenant;
use App\Models\Warehouse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class OutboundOrderIndex extends Component
{
    use AutoSelectsSingleActiveWarehouse;
    use WithPagination;

    #[Url(as: 'tenant_id', except: '')]
    public string $tenantId = '';

    #[Url(as: 'warehouse_id', except: '')]
    public string $warehouseId = '';

    #[Url(as: 'shop_id', except: '')]
    public string $shopId = '';

    #[Url(as: 'status', except: '')]
    public string $statusFilter = '';

    #[Url(as: 'reason', except: '')]
    public string $reasonFilter = '';

    private bool $visibleTenantIdsResolved = false;

    private array $visibleTenantIdsCache = [];

    public function mount(): void
    {
        $this->autoSelectSingleActiveWarehouse();
    }

    public function updatedTenantId(): void
    {
        $this->shopId = '';
        $this->resetPage();
    }

    public function updatedShopId(): void
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

    public function updatedReasonFilter(): void
    {
        $this->resetPage();
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
            ->when($this->shopId !== '', function ($query) {
                $query->where(function ($query) {
                    $query
                        ->whereHas('salesOrders', fn ($query) => $query->where('shop_id', (int) $this->shopId))
                        ->orWhereHas('lines.sku', fn ($query) => $query->where('shop_id', (int) $this->shopId));
                });
            })
            ->when($this->warehouseId !== '', fn ($query) => $query->where('warehouse_id', (int) $this->warehouseId))
            ->when($this->reasonFilter !== '', fn ($query) => $query->where('reason', $this->reasonFilter))
            ->when($this->statusFilter !== '', fn ($query) => $query->where('status', $this->statusFilter))
            ->with([
                'tenant:id,code,name',
                'warehouse:id,code,name',
                'salesOrders:id,shop_id',
                'salesOrders.shop:id,code,name',
                'parentLines.sku:id,shop_id,sku,sku_type',
                'parentLines.sku.shop:id,code,name',
            ])
            ->orderByDesc('created_at')
            ->paginate(30);

        return view('livewire.outbound-order-index', [
            'orders' => $orders,
            'tenants' => Tenant::query()
                ->whereIn('id', $this->visibleTenantIds())
                ->orderBy('name')
                ->get(['id', 'code', 'name']),
            'shops' => $this->shopOptions(),
            'warehouses' => Warehouse::query()->where('status', 'active')->orderBy('name')->get(['id', 'code', 'name']),
            'statuses' => [
                OutboundOrder::STATUS_PENDING => __('outbound.status_pending'),
                OutboundOrder::STATUS_SHIPPED => __('outbound.status_shipped'),
                OutboundOrder::STATUS_CANCELLED => __('outbound.status_cancelled'),
            ],
            'reasons' => [
                OutboundOrder::REASON_CUSTOMER_ORDER => __('outbound.reason_customer_order'),
                OutboundOrder::REASON_RE_SHIP => __('outbound.reason_re_ship'),
                OutboundOrder::REASON_REPLACEMENT => __('outbound.reason_replacement'),
                OutboundOrder::REASON_GIFT => __('outbound.reason_gift'),
                OutboundOrder::REASON_FBA => __('outbound.reason_fba'),
                OutboundOrder::REASON_RETURN_TO_TENANT => __('outbound.reason_return_to_tenant'),
                OutboundOrder::REASON_B2B => __('outbound.reason_b2b'),
                OutboundOrder::REASON_SAMPLE => __('outbound.reason_sample'),
                OutboundOrder::REASON_OTHER => __('outbound.reason_other'),
            ],
        ])->layout('inventory', [
            'title' => __('outbound.page_title'),
            'subtitle' => __('outbound.page_subtitle'),
            'pageWide' => true,
        ]);
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

    private function shopOptions(): Collection
    {
        return Shop::query()
            ->whereIn('tenant_id', $this->visibleTenantIds())
            ->when($this->tenantId !== '', fn ($query) => $query->where('tenant_id', (int) $this->tenantId))
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'tenant_id', 'code', 'name']);
    }
}
