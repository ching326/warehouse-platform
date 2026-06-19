<?php

namespace App\Livewire;

use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\Shop;
use App\Models\Tenant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class SalesOrderIndex extends Component
{
    use WithPagination;

    #[Url(as: 'shop', except: '')]
    public string $shopId = '';

    #[Url(as: 'fulfillment', except: '')]
    public string $fulfillmentStatus = '';

    #[Url(as: 'order_status', except: '')]
    public string $orderStatus = '';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public array $selectedIds = [];

    private bool $allowedTenantIdsResolved = false;

    private array $allowedTenantIdsCache = [];

    public function mount(): void
    {
        $this->authorizeTenantAccess();
    }

    public function updatedShopId(): void
    {
        $this->selectedIds = [];
        $this->resetPage();
    }

    public function updatedFulfillmentStatus(): void
    {
        $this->selectedIds = [];
        $this->resetPage();
    }

    public function updatedOrderStatus(): void
    {
        $this->selectedIds = [];
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->selectedIds = [];
        $this->resetPage();
    }

    public function bulkMarkReady(): void
    {
        if ($this->selectedIds === []) {
            return;
        }

        $selectedIds = array_values(array_unique(array_map('intval', $this->selectedIds)));

        $orders = SalesOrder::query()
            ->whereIn('id', $selectedIds)
            ->whereIn('tenant_id', $this->allowedTenantIds())
            ->where('order_status', SalesOrder::ORDER_STATUS_PENDING)
            ->where('fulfillment_status', SalesOrder::FULFILLMENT_STATUS_UNFULFILLED)
            ->whereNotNull('ship_together_key')
            ->whereHas('lines', fn ($query) => $query
                ->where('line_status', SalesOrderLine::STATUS_READY))
            ->whereDoesntHave('lines', fn ($query) => $query
                ->where('line_status', SalesOrderLine::STATUS_READY)
                ->whereHas('sku', fn ($skuQuery) => $skuQuery
                    ->whereNull('stock_item_id')
                    ->where(fn ($missingStockQuery) => $missingStockQuery
                        ->where('sku_type', '!=', 'virtual_bundle')
                        ->orWhereDoesntHave('bundleComponents'))))
            ->get();

        $updated = $orders->count();
        $orders->each->update(['fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_READY]);

        $skipped = count($selectedIds) - $updated;
        $this->selectedIds = [];

        session()->flash('status', __('sales_orders.bulk_ready_result', [
            'updated' => $updated,
            'skipped' => $skipped,
        ]));
    }

    public function fulfillmentStatusLabel(string $status): string
    {
        return $this->fulfillmentStatuses()[$status] ?? $status;
    }

    public function orderStatusLabel(string $status): string
    {
        return $this->orderStatuses()[$status] ?? $status;
    }

    public function fulfillmentStatusColor(string $status): string
    {
        return match ($status) {
            SalesOrder::FULFILLMENT_STATUS_READY => 'blue',
            SalesOrder::FULFILLMENT_STATUS_IN_GROUP => 'amber',
            SalesOrder::FULFILLMENT_STATUS_SHIPPED => 'green',
            SalesOrder::FULFILLMENT_STATUS_CANCELLED => 'red',
            default => 'zinc',
        };
    }

    public function orderStatusColor(string $status): string
    {
        return match ($status) {
            SalesOrder::ORDER_STATUS_ON_HOLD => 'amber',
            SalesOrder::ORDER_STATUS_BACKORDER => 'orange',
            SalesOrder::ORDER_STATUS_CANCELLED => 'red',
            SalesOrder::ORDER_STATUS_COMPLETED => 'green',
            default => 'zinc',
        };
    }

    public function render()
    {
        $orders = SalesOrder::query()
            ->with(['shop.tenant'])
            ->whereIn('tenant_id', $this->allowedTenantIds())
            ->when($this->shopId !== '', fn ($query) => $query->where('shop_id', (int) $this->shopId))
            ->when($this->fulfillmentStatus !== '', fn ($query) => $query->where('fulfillment_status', $this->fulfillmentStatus))
            ->when($this->orderStatus !== '', fn ($query) => $query->where('order_status', $this->orderStatus))
            ->when($this->search !== '', function ($query) {
                $like = '%'.$this->search.'%';

                $query->where(fn ($query) => $query
                    ->where('platform_order_id', 'like', $like)
                    ->orWhere('recipient_name', 'like', $like));
            })
            ->orderByDesc('created_at')
            ->paginate(30);

        return view('livewire.sales-order-index', [
            'orders' => $orders,
            'shops' => $this->shopOptions(),
            'fulfillmentStatuses' => $this->fulfillmentStatuses(),
            'orderStatuses' => $this->orderStatuses(),
        ])->layout('inventory', [
            'title' => __('sales_orders.index_page_title'),
            'subtitle' => __('sales_orders.index_page_subtitle'),
        ]);
    }

    private function shopOptions(): Collection
    {
        return Shop::query()
            ->whereIn('tenant_id', $this->allowedTenantIds())
            ->where('status', 'active')
            ->with('tenant:id,code')
            ->orderBy('name')
            ->get(['id', 'tenant_id', 'name', 'platform']);
    }

    private function fulfillmentStatuses(): array
    {
        return [
            SalesOrder::FULFILLMENT_STATUS_UNFULFILLED => __('sales_orders.fulfillment_unfulfilled'),
            SalesOrder::FULFILLMENT_STATUS_READY => __('sales_orders.fulfillment_ready'),
            SalesOrder::FULFILLMENT_STATUS_IN_GROUP => __('sales_orders.fulfillment_in_group'),
            SalesOrder::FULFILLMENT_STATUS_SHIPPED => __('sales_orders.fulfillment_shipped'),
            SalesOrder::FULFILLMENT_STATUS_CANCELLED => __('sales_orders.fulfillment_cancelled'),
        ];
    }

    private function orderStatuses(): array
    {
        return [
            SalesOrder::ORDER_STATUS_PENDING => __('sales_orders.order_pending'),
            SalesOrder::ORDER_STATUS_ON_HOLD => __('sales_orders.order_on_hold'),
            SalesOrder::ORDER_STATUS_BACKORDER => __('sales_orders.order_backorder'),
            SalesOrder::ORDER_STATUS_CANCELLED => __('sales_orders.order_cancelled'),
            SalesOrder::ORDER_STATUS_COMPLETED => __('sales_orders.order_completed'),
        ];
    }

    // TODO: remove unauthenticated fallback when auth is implemented
    private function isInternalUser(): bool
    {
        $user = Auth::user();

        return ! $user || $user->user_type === 'internal';
    }

    private function allowedTenantIds(): array
    {
        if ($this->allowedTenantIdsResolved) {
            return $this->allowedTenantIdsCache;
        }

        $this->allowedTenantIdsResolved = true;

        if ($this->isInternalUser()) {
            return $this->allowedTenantIdsCache = Tenant::query()->pluck('id')->all();
        }

        return $this->allowedTenantIdsCache = Auth::user()
            ->tenantUsers()
            ->where('status', 'active')
            ->pluck('tenant_id')
            ->all();
    }

    private function authorizeTenantAccess(): void
    {
        if (! $this->isInternalUser() && $this->allowedTenantIds() === []) {
            abort(403);
        }
    }
}
