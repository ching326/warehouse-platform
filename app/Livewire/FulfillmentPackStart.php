<?php

namespace App\Livewire;

use App\Models\FulfillmentGroup;
use App\Models\FulfillmentGroupOrder;
use App\Models\FulfillmentPackScan;
use App\Models\ShippingMethod;
use App\Models\Tenant;
use App\Models\Warehouse;
use App\Services\Fulfillment\FulfillmentPackService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class FulfillmentPackStart extends Component
{
    use WithPagination;

    #[Url(as: 'warehouse_id', except: '')]
    public string $warehouseId = '';

    #[Url(as: 'shipping_method_id', except: '')]
    public string $shippingMethodId = '';

    public string $scan = '';

    public ?string $lastScan = null;

    public ?string $message = null;

    public string $queueSearch = '';

    private bool $allowedTenantIdsResolved = false;

    private array $allowedTenantIdsCache = [];

    public function mount(): void
    {
        $this->authorizeInternalUser();

        $warehouses = $this->warehouseOptions();

        if ($this->warehouseId === '' && $warehouses->count() === 1) {
            $this->warehouseId = (string) $warehouses->first()->id;
        }
    }

    public function search(FulfillmentPackService $service)
    {
        $this->authorizeInternalUser();
        $this->lastScan = trim($this->scan) === '' ? null : trim($this->scan);

        if (! $this->filtersReady()) {
            $this->message = __('fulfillment_pack.select_station_first');
            $this->dispatch('pack-scan-focus');

            return null;
        }

        $result = $service->findGroupForTrackingNo(
            trackingNo: $this->scan,
            allowedTenantIds: $this->allowedTenantIds(),
            warehouseId: (int) $this->warehouseId,
            shippingMethodId: (int) $this->shippingMethodId,
        );
        $this->scan = '';

        if ($result->status === 'found' && $result->group) {
            return $this->redirectRoute('fulfillment-groups.pack', $result->group, navigate: true);
        }

        $this->message = match ($result->status) {
            'multiple' => __('fulfillment_pack.multiple_matches'),
            'already_shipped' => __('fulfillment_pack.already_shipped'),
            'cancelled' => __('fulfillment_pack.cancelled_group'),
            default => __('fulfillment_pack.not_found_for_station'),
        };

        $this->dispatch('pack-scan-focus');

        return null;
    }

    public function updatedWarehouseId(): void
    {
        $this->resetPage();
        $this->focusScannerWhenReady();
    }

    public function updatedShippingMethodId(): void
    {
        $this->resetPage();
        $this->focusScannerWhenReady();
    }

    public function updatedQueueSearch(): void
    {
        $this->resetPage();
    }

    public function render(FulfillmentPackService $service)
    {
        $this->authorizeInternalUser();
        $queue = null;
        $queueProgress = [];
        $summary = null;

        if ($this->filtersReady()) {
            $queue = $this->queueQuery()
                ->with([
                    'tenant:id,code,name',
                    'orders:id,platform_order_id,tracking_no',
                    'groupOrders:id,fulfillment_group_id,sales_order_id,tracking_no',
                    'outboundOrder:id,fulfillment_group_id,tracking_no',
                ])
                ->paginate(25);

            foreach ($queue->getCollection() as $group) {
                $lines = $service->packLinesWithProgress($group);
                $queueProgress[$group->id] = [
                    'required_qty' => (int) collect($lines)->sum('required_qty'),
                    'scanned_qty' => (int) collect($lines)->sum('scanned_qty'),
                ];
            }

            $summary = $this->stationSummary($queueProgress);
        }

        return view('livewire.fulfillment-pack-start', [
            'warehouses' => $this->warehouseOptions(),
            'shippingMethods' => $this->shippingMethodOptions(),
            'filtersReady' => $this->filtersReady(),
            'queue' => $queue,
            'queueProgress' => $queueProgress,
            'summary' => $summary,
        ])
            ->layout('inventory', [
                'title' => __('fulfillment_pack.start_page_title'),
                'subtitle' => __('fulfillment_pack.page_title'),
            ]);
    }

    private function authorizeInternalUser(): void
    {
        if (! $this->isInternalUser()) {
            abort(403);
        }
    }

    private function isInternalUser(): bool
    {
        return Auth::user()?->user_type === 'internal';
    }

    private function allowedTenantIds(): array
    {
        if ($this->allowedTenantIdsResolved) {
            return $this->allowedTenantIdsCache;
        }

        $this->allowedTenantIdsResolved = true;

        return $this->allowedTenantIdsCache = Tenant::query()->pluck('id')->all();
    }

    private function filtersReady(): bool
    {
        return (int) $this->warehouseId > 0 && (int) $this->shippingMethodId > 0;
    }

    private function focusScannerWhenReady(): void
    {
        if ($this->filtersReady()) {
            $this->dispatch('pack-scan-focus');
        }
    }

    private function queueQuery(): Builder
    {
        return FulfillmentGroup::query()
            ->whereIn('tenant_id', $this->allowedTenantIds())
            ->where('status', FulfillmentGroup::STATUS_RESERVED)
            ->where('warehouse_id', (int) $this->warehouseId)
            ->where('shipping_method_id', (int) $this->shippingMethodId)
            ->when(trim($this->queueSearch) !== '', function (Builder $query): void {
                $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim($this->queueSearch)).'%';

                $query->where(function (Builder $query) use ($like): void {
                    $query
                        ->where('fulfillment_groups.reference_no', 'like', $like)
                        ->orWhere('fulfillment_groups.tracking_no', 'like', $like)
                        ->orWhere('fulfillment_groups.recipient_name', 'like', $like)
                        ->orWhere('fulfillment_groups.recipient_phone', 'like', $like)
                        ->orWhereHas('outboundOrder', fn (Builder $query) => $query->where('outbound_orders.tracking_no', 'like', $like))
                        ->orWhereHas('groupOrders', fn (Builder $query) => $query->where('fulfillment_group_orders.tracking_no', 'like', $like))
                        ->orWhereHas('orders', function (Builder $query) use ($like): void {
                            $query
                                ->where('sales_orders.tracking_no', 'like', $like)
                                ->orWhere('sales_orders.platform_order_id', 'like', $like);
                        });
                });
            })
            ->orderBy('created_at')
            ->orderBy('id');
    }

    /**
     * @param  array<int, array{required_qty: int, scanned_qty: int}>  $queueProgress
     * @return array<string, int>
     */
    private function stationSummary(array $queueProgress): array
    {
        $groupIds = $this->queueIdSubquery();

        return [
            'waiting_groups' => (clone $this->queueQuery())->count(),
            'waiting_orders' => FulfillmentGroupOrder::query()
                ->whereIn('fulfillment_group_id', $groupIds)
                ->count(),
            'required_qty_page' => (int) collect($queueProgress)->sum('required_qty'),
            'exception_scans_today' => FulfillmentPackScan::query()
                ->whereIn('fulfillment_group_id', $this->queueIdSubquery())
                ->where('result', '!=', FulfillmentPackScan::RESULT_ACCEPTED)
                ->whereDate('created_at', today())
                ->count(),
        ];
    }

    private function queueIdSubquery(): Builder
    {
        return (clone $this->queueQuery())
            ->select('fulfillment_groups.id')
            ->reorder();
    }

    private function warehouseOptions()
    {
        return Warehouse::query()
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'code', 'name']);
    }

    private function shippingMethodOptions()
    {
        return ShippingMethod::query()
            ->where('shipping_methods.status', 'active')
            ->with('carrier:id,code,name')
            ->ordered()
            ->get(['shipping_methods.id', 'shipping_methods.carrier_id', 'shipping_methods.code', 'shipping_methods.name']);
    }
}
