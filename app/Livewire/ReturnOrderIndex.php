<?php

namespace App\Livewire;

use App\Livewire\Concerns\AutoSelectsSingleActiveWarehouse;
use App\Models\ReturnOrder;
use App\Models\Tenant;
use App\Models\Warehouse;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class ReturnOrderIndex extends Component
{
    use AutoSelectsSingleActiveWarehouse;
    use WithPagination;

    #[Url(as: 'tenant_id', except: '')]
    public string $tenantId = '';

    #[Url(except: '')]
    public string $warehouseId = '';

    #[Url(except: '')]
    public string $statusFilter = '';

    #[Url(except: '')]
    public string $typeFilter = '';

    #[Url(except: '')]
    public string $reasonFilter = '';

    #[Url(except: '')]
    public string $paymentFilter = '';

    #[Url(except: '')]
    public string $search = '';

    public function mount(): void
    {
        $this->autoSelectSingleActiveWarehouse();
    }

    public function render()
    {
        $orders = ReturnOrder::query()
            ->with(['tenant:id,code,name', 'warehouse:id,code,name', 'lines.sku:id,sku,name', 'lines.stockItem:id,code,name', 'costs'])
            ->whereIn('tenant_id', $this->allowedTenantIds())
            ->when($this->tenantId !== '', fn ($q) => $q->where('tenant_id', $this->tenantId))
            ->when($this->warehouseId !== '', fn ($q) => $q->where('warehouse_id', $this->warehouseId))
            ->when($this->statusFilter !== '', fn ($q) => $q->where('status', $this->statusFilter))
            ->when($this->statusFilter === '', fn ($q) => $q->whereNotIn('status', [
                ReturnOrder::STATUS_DISPOSITIONED,
                ReturnOrder::STATUS_CLOSED,
                ReturnOrder::STATUS_CANCELLED,
                ReturnOrder::STATUS_EXPIRED,
            ]))
            ->when($this->typeFilter !== '', fn ($q) => $q->where('return_type', $this->typeFilter))
            ->when($this->reasonFilter !== '', fn ($q) => $q->where('return_reason', $this->reasonFilter))
            ->when($this->paymentFilter !== '', fn ($q) => $q->where('payment_type', $this->paymentFilter))
            ->when(trim($this->search) !== '', function ($q): void {
                $term = '%'.trim($this->search).'%';
                $q->where(function ($nested) use ($term): void {
                    $nested->where('return_no', 'like', $term)->orWhere('tracking_no', 'like', $term)->orWhere('original_order_no', 'like', $term)->orWhere('customer_name', 'like', $term)->orWhere('external_return_id', 'like', $term)->orWhere('note', 'like', $term)
                        ->orWhereHas('lines.sku', fn ($line) => $line->where('sku', 'like', $term)->orWhere('name', 'like', $term))
                        ->orWhereHas('lines.stockItem', fn ($line) => $line->where('code', 'like', $term)->orWhere('name', 'like', $term));
                });
            })
            ->latest('id')
            ->paginate(15);

        return view('livewire.return-order-index', [
            'orders' => $orders,
            'tenants' => Tenant::query()->whereIn('id', $this->allowedTenantIds())->orderBy('name')->get(['id', 'code', 'name']),
            'warehouses' => Warehouse::query()->where('status', 'active')->orderBy('name')->get(['id', 'code', 'name']),
            'statuses' => ReturnOrder::statusOptions(),
            'types' => ReturnOrder::typeOptions(),
            'reasons' => ReturnOrder::reasonOptions(),
            'paymentTypes' => ReturnOrder::paymentTypeOptions(),
            'showTenantSelect' => $this->isInternalUser(),
        ])->layout('inventory', ['title' => __('return_orders.page_title'), 'subtitle' => __('return_orders.page_subtitle')]);
    }

    private function isInternalUser(): bool
    {
        return Auth::user()?->user_type === 'internal';
    }

    private function allowedTenantIds(): array
    {
        return $this->isInternalUser() ? Tenant::query()->pluck('id')->all() : (Auth::user()?->activeTenantIds() ?? []);
    }
}
