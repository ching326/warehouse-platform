<?php

namespace App\Livewire;

use App\Models\FulfillmentGroup;
use App\Models\SalesOrder;
use App\Models\ShippingMethod;
use App\Models\Tenant;
use App\Models\Warehouse;
use App\Services\Courier\CourierExportService;
use App\Services\Outbound\ShipOutboundOrderService;
use App\Support\CourierCarrier;
use App\Support\SalesOrderFilters;
use App\Support\TrackingNumber;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use RuntimeException;

class FulfillmentGroupIndex extends Component
{
    use WithPagination;

    /** @var array<int, string> */
    #[Url(as: 'tenant_ids', except: [])]
    public array $tenantIds = [];

    #[Url(as: 'warehouse_id', except: '')]
    public string $warehouseId = '';

    /** @var array<int, string> */
    #[Url(as: 'statuses', except: [])]
    public array $statusesFilter = [];

    #[Url(as: 'print_waiting', except: false)]
    public bool $printWaiting = false;

    /** @var array<int, string> */
    #[Url(as: 'shipping', except: [])]
    public array $shippingMethodsFilter = [];

    /** @var array<int, string> */
    #[Url(as: 'others', except: [])]
    public array $othersFilter = [];

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'detailed', except: false)]
    public bool $detailed = false;

    public array $selectedIds = [];

    public array $visibleGroupIds = [];

    public array $noteDrafts = [];

    public array $trackingDrafts = [];

    public ?string $pendingCourierExportCarrier = null;

    public array $pendingCourierExportGroupIds = [];

    public ?string $pendingExportWarning = null;

    public bool $showTrackingImportModal = false;

    private bool $allowedTenantIdsResolved = false;

    private array $allowedTenantIdsCache = [];

    public function mount(): void
    {
        $this->authorizeTenantAccess();
    }

    public function updatedTenantIds(): void
    {
        $this->resetPage();
    }

    public function updatedWarehouseId(): void
    {
        $this->resetPage();
    }

    public function updatedStatusesFilter(): void
    {
        $this->resetPage();
    }

    public function updatedPrintWaiting(): void
    {
        $this->resetPage();
    }

    public function updatedShippingMethodsFilter(): void
    {
        $this->resetPage();
    }

    public function updatedOthersFilter(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function toggleDetailed(): void
    {
        $this->detailed = ! $this->detailed;
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

    public function pickSummaryUrl(): string
    {
        $query = [];

        if ($this->warehouseId !== '') {
            $query['warehouse_id'] = $this->warehouseId;
        }

        if (property_exists($this, 'tenantIds')) {
            $tenantIds = array_values(array_filter($this->tenantIds, fn ($id): bool => ctype_digit((string) $id)));
            if (count($tenantIds) === 1) {
                $query['tenant_id'] = $tenantIds[0];
            }
        } elseif (property_exists($this, 'tenantId') && $this->tenantId !== '') {
            $query['tenant_id'] = $this->tenantId;
        }

        if (property_exists($this, 'shippingMethodsFilter')) {
            $shippingMethodIds = array_values(array_filter($this->shippingMethodsFilter, fn ($id): bool => ctype_digit((string) $id)));
            if (count($shippingMethodIds) === 1) {
                $query['shipping_method_id'] = $shippingMethodIds[0];
            }
        }

        return route('fulfillment.pick-summary', $query);
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
        $trackingNo = TrackingNumber::normalize(mb_substr($value, 0, 255));

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

    public function markShipped(): void
    {
        $selectedIds = collect($this->selectedIds)
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if ($selectedIds->isEmpty()) {
            return;
        }

        $groups = FulfillmentGroup::query()
            ->whereIn('id', $selectedIds)
            ->whereIn('tenant_id', $this->allowedTenantIds())
            ->where('status', FulfillmentGroup::STATUS_RESERVED)
            ->whereHas('outboundOrder')
            ->with([
                'outboundOrder',
                'shippingMethod.carrier:id,code',
                'groupOrders:id,fulfillment_group_id,tracking_no',
            ])
            ->get();

        $updated = 0;

        foreach ($groups as $group) {
            if (! $group->outboundOrder) {
                continue;
            }

            try {
                app(ShipOutboundOrderService::class)->ship($group->outboundOrder, [
                    'courier' => $group->shippingMethod?->carrier?->code ?? '',
                    'shipping_method' => $group->shippingMethod?->name ?? '',
                    'tracking_no' => $group->groupOrders->pluck('tracking_no')->filter()->first() ?? '',
                ]);

                $updated++;
            } catch (InvalidArgumentException) {
                continue;
            }
        }

        $this->selectedIds = [];

        session()->flash('status', __('fulfillment_groups.batch_mark_shipped_result', [
            'updated' => $updated,
            'skipped' => $selectedIds->count() - $updated,
        ]));
    }

    public function exportYamato(): mixed
    {
        return $this->validateCourierExport(CourierCarrier::YAMATO);
    }

    public function exportSagawa(): mixed
    {
        return $this->validateCourierExport(CourierCarrier::SAGAWA);
    }

    public function validateCourierExport(string $carrier): mixed
    {
        $this->pendingCourierExportCarrier = null;
        $this->pendingCourierExportGroupIds = [];
        $this->pendingExportWarning = null;

        if ($this->selectedIds === []) {
            session()->flash('error', __('fulfillment_groups.courier_export_no_selection'));

            return null;
        }

        $carrier = $this->normalizeCourierCarrier($carrier);
        $result = app(CourierExportService::class)->validateGroupExport(
            fulfillmentGroupIds: $this->normalizedSelectedIds(),
            carrier: $carrier,
            allowedTenantIds: $this->allowedTenantIds(),
        );

        if ($result->hasHardBlock()) {
            session()->flash('error', $this->courierExportMessage($result->toArray()));

            return null;
        }

        if ($result->requiresConfirmation) {
            $this->pendingCourierExportCarrier = $carrier;
            $this->pendingCourierExportGroupIds = $this->normalizedSelectedIds();
            $this->pendingExportWarning = $this->reExportWarning($result->alreadyExportedOrderIds);

            return null;
        }

        return $this->performCourierExport($carrier, confirmedReExport: false);
    }

    public function confirmCourierExport(): mixed
    {
        if ($this->pendingCourierExportCarrier === null || $this->pendingCourierExportGroupIds === []) {
            return null;
        }

        $carrier = $this->pendingCourierExportCarrier;
        $groupIds = $this->pendingCourierExportGroupIds;
        $this->clearPendingExport();

        return $this->performCourierExport($carrier, confirmedReExport: true, groupIds: $groupIds);
    }

    public function openTrackingImportModal(): void
    {
        $this->showTrackingImportModal = true;
    }

    public function closeTrackingImportModal(): void
    {
        $this->showTrackingImportModal = false;
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
                'groupOrders.salesOrder.lines:id,sales_order_id,sku_id,quantity',
            ])
            ->when($this->detailed, fn ($query) => $query->with([
                'groupOrders.salesOrder.lines.sku:id,sku,name,stock_item_id',
                'groupOrders.salesOrder.lines.sku.stockItem:id,short_name,name',
            ]))
            ->withCount('orders')
            ->withMin('groupOrders', 'arranged_at')
            ->whereIn('tenant_id', $this->allowedTenantIds())
            ->when($this->tenantIds !== [], fn ($query) => $query
                ->whereIn('tenant_id', array_map('intval', $this->tenantIds)))
            ->when($this->warehouseId !== '', fn ($query) => $query->where('warehouse_id', (int) $this->warehouseId))
            ->when($this->statusesFilter !== [], fn ($query) => $query->whereIn('status', $this->statusesFilter))
            ->when($this->printWaiting, fn ($query) => $query
                ->whereHas('groupOrders.salesOrder', fn ($sub) => $sub->whereNull('courier_csv_exported_at')))
            ->when(in_array(SalesOrderFilters::OTHER_PRINTED, $this->othersFilter, true), fn ($query) => $query
                ->whereHas('groupOrders.salesOrder', fn ($sub) => $sub->whereNotNull('courier_csv_exported_at')))
            ->when(in_array(SalesOrderFilters::OTHER_NOT_PRINTED, $this->othersFilter, true), fn ($query) => $query
                ->whereHas('groupOrders.salesOrder', fn ($sub) => $sub->whereNull('courier_csv_exported_at')))
            ->when($this->shippingMethodsFilter !== [], function ($query) {
                $query->where(function ($inner) {
                    $methodIds = array_values(array_filter(
                        $this->shippingMethodsFilter,
                        fn ($value): bool => ctype_digit((string) $value),
                    ));
                    $hasEmpty = in_array(SalesOrderFilters::EMPTY_SHIPPING, $this->shippingMethodsFilter, true);

                    if ($methodIds !== []) {
                        $inner->whereIn('shipping_method_id', array_map('intval', $methodIds));
                    }

                    if ($hasEmpty) {
                        $methodIds !== []
                            ? $inner->orWhereNull('shipping_method_id')
                            : $inner->whereNull('shipping_method_id');
                    }
                });
            })
            ->when(in_array(SalesOrderFilters::OTHER_MULTI_ITEM, $this->othersFilter, true), fn ($query) => $query
                ->where(fn ($inner) => $inner
                    ->whereHas('groupOrders.salesOrder.lines', fn ($line) => $line->where('quantity', '>', 1))
                    ->orWhereHas('groupOrders.salesOrder.lines', fn ($line) => $line, '>=', 2)))
            ->when($this->search !== '', function ($query) {
                $like = '%'.$this->search.'%';

                $query->where(fn ($inner) => $inner
                    ->where('reference_no', 'like', $like)
                    ->orWhere('recipient_name', 'like', $like)
                    ->orWhereHas('groupOrders', fn ($sub) => $sub->where('tracking_no', 'like', $like))
                    ->orWhereHas('groupOrders.salesOrder', fn ($sub) => $sub->where('platform_order_id', 'like', $like)));
            })
            ->orderByRaw('group_orders_min_arranged_at is null')
            ->orderBy('group_orders_min_arranged_at')
            ->orderBy('id')
            ->paginate(30);

        $this->visibleGroupIds = $groups->getCollection()->pluck('id')->map(fn ($id) => (int) $id)->all();

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
            'shippingMethods' => $shippingMethods = ShippingMethod::query()
                ->where('shipping_methods.status', 'active')
                ->ordered()
                ->get()
                ->mapWithKeys(fn (ShippingMethod $method) => [(string) $method->id => $method->name])
                ->all(),
            'shippingMethodFilterOptions' => $shippingMethods + [
                SalesOrderFilters::EMPTY_SHIPPING => __('fulfillment_groups.shipping_method_unset'),
            ],
            'statuses' => $this->statuses(),
            'showTenantFilter' => $this->isInternalUser(),
            'visibleGroupIds' => $this->visibleGroupIds,
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

    private function performCourierExport(string $carrier, bool $confirmedReExport, ?array $groupIds = null): mixed
    {
        try {
            $batch = app(CourierExportService::class)->exportGroups(
                fulfillmentGroupIds: $groupIds ?? $this->normalizedSelectedIds(),
                carrier: $carrier,
                allowedTenantIds: $this->allowedTenantIds(),
                user: Auth::user(),
                confirmedReExport: $confirmedReExport,
            );
        } catch (RuntimeException $exception) {
            session()->flash('error', $exception->getMessage());

            return null;
        }

        $this->selectedIds = [];
        $this->pendingCourierExportCarrier = null;
        $this->pendingCourierExportGroupIds = [];

        return redirect()->route('courier-export-batches.download', $batch);
    }

    private function courierExportMessage(array $result): string
    {
        $parts = [];

        foreach ([
            'wrong_carrier_order_ids' => 'courier_export_wrong_carrier_ids',
            'unsupported_courier_order_ids' => 'courier_export_unsupported_courier_ids',
            'blocked_status_order_ids' => 'courier_export_blocked_status_ids',
            'no_ready_lines_order_ids' => 'courier_export_no_ready_lines_ids',
            'mixed_tenant_order_ids' => 'courier_export_mixed_tenant_ids',
            'missing_order_ids' => 'courier_export_missing_ids',
        ] as $key => $translationKey) {
            if ($result[$key] !== []) {
                $parts[] = __(
                    'fulfillment_groups.'.$translationKey,
                    ['ids' => implode(', ', $result[$key])]
                );
            }
        }

        return implode("\n", $parts ?: [$result['message']]);
    }

    private function reExportWarning(array $groupIds): string
    {
        $references = FulfillmentGroup::query()
            ->whereIn('id', $groupIds)
            ->whereIn('tenant_id', $this->allowedTenantIds())
            ->orderBy('reference_no')
            ->pluck('reference_no')
            ->all();

        return __('fulfillment_groups.courier_export_reexport_warning')."\n".implode("\n", $references);
    }

    private function clearPendingExport(): void
    {
        $this->pendingCourierExportCarrier = null;
        $this->pendingCourierExportGroupIds = [];
        $this->pendingExportWarning = null;

        session()->forget('warning');
    }

    private function normalizedSelectedIds(): array
    {
        return collect($this->selectedIds)
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function normalizeCourierCarrier(string $carrier): string
    {
        $carrier = CourierCarrier::normalize($carrier);

        if (! in_array($carrier, CourierCarrier::values(), true)) {
            throw new InvalidArgumentException('Unsupported courier carrier.');
        }

        return $carrier;
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
