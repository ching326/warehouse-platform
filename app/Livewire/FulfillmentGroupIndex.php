<?php

namespace App\Livewire;

use App\Models\FulfillmentGroup;
use App\Models\SalesOrder;
use App\Models\ShippingMethod;
use App\Models\Tenant;
use App\Models\Warehouse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class FulfillmentGroupIndex extends Component
{
    use WithPagination;

    #[Url(as: 'tenant_id', except: '')]
    public string $tenantId = '';

    #[Url(as: 'warehouse_id', except: '')]
    public string $warehouseId = '';

    #[Url(as: 'status', except: '')]
    public string $statusFilter = '';

    #[Url(as: 'print_waiting', except: false)]
    public bool $printWaiting = false;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public array $selectedIds = [];

    public array $noteDrafts = [];

    public array $trackingDrafts = [];

    private bool $allowedTenantIdsResolved = false;

    private array $allowedTenantIdsCache = [];

    public function mount(): void
    {
        $this->authorizeTenantAccess();
    }

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

    public function updatedPrintWaiting(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function statusLabel(string $status): string
    {
        return $this->statuses()[$status] ?? $status;
    }

    public function statusColor(string $status): string
    {
        return match ($status) {
            FulfillmentGroup::STATUS_SHIPPED => 'green',
            FulfillmentGroup::STATUS_CANCELLED => 'red',
            default => 'blue',
        };
    }

    public function updateNote(int $groupId, string $value): void
    {
        $note = trim($value);
        $note = $note === '' ? null : mb_substr($note, 0, 2000);

        FulfillmentGroup::query()
            ->whereIn('tenant_id', $this->allowedTenantIds())
            ->whereKey($groupId)
            ->update(['note' => $note]);

        $this->noteDrafts[$groupId] = $note ?? '';
    }

    public function updateShippingMethod(int $groupId, string $value): void
    {
        $methodId = $value === '' ? null : (int) $value;

        if ($value !== '' && $methodId <= 0) {
            return;
        }

        if ($methodId !== null && ! ShippingMethod::query()
            ->where('status', 'active')
            ->whereKey($methodId)
            ->exists()) {
            return;
        }

        FulfillmentGroup::query()
            ->whereIn('tenant_id', $this->allowedTenantIds())
            ->whereKey($groupId)
            ->update(['shipping_method_id' => $methodId]);
    }

    public function updateTracking(int $groupId, string $value): void
    {
        $trackingNo = trim($value);
        $trackingNo = $trackingNo === '' ? null : mb_substr($trackingNo, 0, 255);

        $group = FulfillmentGroup::query()
            ->whereIn('tenant_id', $this->allowedTenantIds())
            ->with('groupOrders:id,fulfillment_group_id,sales_order_id')
            ->find($groupId);

        if (! $group) {
            return;
        }

        DB::transaction(function () use ($group, $trackingNo) {
            $group->groupOrders()->update(['tracking_no' => $trackingNo]);

            SalesOrder::query()
                ->whereIn('id', $group->groupOrders->pluck('sales_order_id'))
                ->update(['tracking_no' => $trackingNo]);
        });

        $this->trackingDrafts[$groupId] = $trackingNo ?? '';
    }

    public function render()
    {
        $groups = FulfillmentGroup::query()
            ->with([
                'tenant:id,code,name',
                'warehouse:id,code,name',
                'shippingMethod:id,name',
                'outboundOrder:id,fulfillment_group_id,shipping_method',
                'groupOrders:id,fulfillment_group_id,sales_order_id,tracking_no,arranged_at,shipped_at',
                'groupOrders.salesOrder:id,shop_id,platform_order_id,courier_csv_exported_at,shipping_method',
                'groupOrders.salesOrder.shop:id,name',
                'groupOrders.salesOrder.lines:id,sales_order_id,quantity',
            ])
            ->withCount('orders')
            ->withMin('groupOrders', 'arranged_at')
            ->whereIn('tenant_id', $this->allowedTenantIds())
            ->when($this->tenantId !== '', fn ($query) => $query->where('tenant_id', (int) $this->tenantId))
            ->when($this->warehouseId !== '', fn ($query) => $query->where('warehouse_id', (int) $this->warehouseId))
            ->when($this->statusFilter !== '', fn ($query) => $query->where('status', $this->statusFilter))
            ->when($this->printWaiting, fn ($query) => $query
                ->whereHas('groupOrders.salesOrder', fn ($sub) => $sub->whereNull('courier_csv_exported_at')))
            ->when($this->search !== '', function ($query) {
                $like = '%'.$this->search.'%';

                $query->where(fn ($inner) => $inner
                    ->where('reference_no', 'like', $like)
                    ->orWhere('recipient_name', 'like', $like)
                    ->orWhereHas('groupOrders', fn ($sub) => $sub->where('tracking_no', 'like', $like)));
            })
            ->orderByRaw('group_orders_min_arranged_at is null')
            ->orderBy('group_orders_min_arranged_at')
            ->orderBy('id')
            ->paginate(30);

        foreach ($groups as $group) {
            $this->noteDrafts[$group->id] ??= $group->note ?? '';
            $this->trackingDrafts[$group->id] ??= (string) ($group->groupOrders
                ->pluck('tracking_no')
                ->filter()
                ->first() ?? '');
        }

        return view('livewire.fulfillment-group-index', [
            'groups' => $groups,
            'tenants' => Tenant::query()
                ->whereIn('id', $this->allowedTenantIds())
                ->orderBy('name')
                ->get(['id', 'code', 'name']),
            'warehouses' => Warehouse::query()
                ->where('status', 'active')
                ->orderBy('name')
                ->get(['id', 'code', 'name']),
            'shippingMethods' => ShippingMethod::query()
                ->where('status', 'active')
                ->orderBy('name')
                ->get(['id', 'name'])
                ->mapWithKeys(fn (ShippingMethod $method) => [(string) $method->id => $method->name])
                ->all(),
            'statuses' => $this->statuses(),
            'showTenantFilter' => $this->isInternalUser(),
        ])->layout('inventory', [
            'title' => __('fulfillment_groups.page_title'),
            'subtitle' => __('fulfillment_groups.page_subtitle'),
            'pageWide' => true,
        ]);
    }

    private function statuses(): array
    {
        return [
            FulfillmentGroup::STATUS_RESERVED => __('fulfillment_groups.status_reserved'),
            FulfillmentGroup::STATUS_SHIPPED => __('fulfillment_groups.status_shipped'),
            FulfillmentGroup::STATUS_CANCELLED => __('fulfillment_groups.status_cancelled'),
        ];
    }

    private function isInternalUser(): bool
    {
        $user = Auth::user();

        return $user?->user_type === 'internal';
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

        $user = Auth::user();

        if (! $user) {
            return $this->allowedTenantIdsCache = [];
        }

        return $this->allowedTenantIdsCache = $user->activeTenantIds();
    }

    private function authorizeTenantAccess(): void
    {
        if (! $this->isInternalUser() && $this->allowedTenantIds() === []) {
            abort(403);
        }
    }
}
