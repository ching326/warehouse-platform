<?php

namespace App\Livewire;

use App\Models\OutboundOrder;
use App\Models\SalesOrder;
use App\Models\ShippingMethod;
use App\Models\Sku;
use App\Models\StockItem;
use App\Models\Tenant;
use App\Models\Warehouse;
use App\Services\Courier\CourierExportService;
use App\Services\Outbound\HoldOutboundOrderService;
use App\Services\Outbound\ShipOutboundOrderService;
use App\Services\SalesOrders\SkuDefaultShippingMethodResolver;
use App\Support\CourierCarrier;
use App\Support\SalesOrderFilters;
use App\Support\TrackingNumber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use RuntimeException;

class FulfillmentIndex extends Component
{
    use WithPagination;

    /** Maps the user-facing fulfillment statuses to OutboundOrder statuses. */
    private const STATUS_MAP = [
        'reserved' => OutboundOrder::STATUS_RESERVED,
        'on_hold' => OutboundOrder::HOLD_STATUS_ON_HOLD,
        'shipped' => OutboundOrder::STATUS_SHIPPED,
        'cancelled' => OutboundOrder::STATUS_CANCELLED,
    ];

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

    #[Url(as: 'date_range', except: SalesOrderFilters::DATE_ALL)]
    public string $dateRange = SalesOrderFilters::DATE_ALL;

    #[Url(as: 'date_from', except: '')]
    public string $dateFrom = '';

    #[Url(as: 'date_to', except: '')]
    public string $dateTo = '';

    /** @var array<int, string> */
    #[Url(as: 'others', except: [])]
    public array $othersFilter = [];

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'detailed', except: false)]
    public bool $detailed = false;

    public array $selectedIds = [];

    public array $visibleOrderIds = [];

    public array $noteDrafts = [];

    public array $trackingDrafts = [];

    public ?string $pendingCourierExportCarrier = null;

    public array $pendingCourierExportOrderIds = [];

    public ?string $pendingExportWarning = null;

    public bool $showTrackingImportModal = false;

    public array $pendingHoldOrderIds = [];

    public ?string $pendingHoldWarning = null;

    private bool $allowedTenantIdsResolved = false;

    private array $allowedTenantIdsCache = [];

    public function mount(): void
    {
        $this->authorizeTenantAccess();
        $this->coerceShippedDateRange();
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
        $this->coerceShippedDateRange();
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

    public function updatedDateRange(): void
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

    public function updatedOthersFilter(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function removeFilterChip(string $group, string $value = ''): void
    {
        match ($group) {
            'tenant' => $this->tenantIds = $this->removeFilterValue($this->tenantIds, $value),
            'warehouse' => $this->warehouseId = '',
            'status' => $this->statusesFilter = $this->removeFilterValue($this->statusesFilter, $value),
            'shipping' => $this->shippingMethodsFilter = $this->removeFilterValue($this->shippingMethodsFilter, $value),
            'date' => $this->resetDateFilter(),
            'other' => $this->othersFilter = $this->removeFilterValue($this->othersFilter, $value),
            'search' => $this->search = '',
            default => null,
        };

        $this->removeShippedIfOnlyRemainingFilter();
        $this->resetPage();
    }

    public function clearAllFilters(): void
    {
        $this->tenantIds = [];
        $this->warehouseId = '';
        $this->statusesFilter = [];
        $this->shippingMethodsFilter = [];
        $this->resetDateFilter();
        $this->othersFilter = [];
        $this->search = '';
        $this->resetPage();
    }

    public function toggleDetailed(): void
    {
        $this->detailed = ! $this->detailed;
    }

    public function statusLabel(string $status): string
    {
        return match ($status) {
            OutboundOrder::STATUS_RESERVED => __('fulfillment.status_reserved'),
            OutboundOrder::STATUS_SHIPPED => __('fulfillment.status_shipped'),
            OutboundOrder::STATUS_CANCELLED => __('fulfillment.status_cancelled'),
            default => $status,
        };
    }

    public function statusColor(string $status): string
    {
        return match ($status) {
            OutboundOrder::STATUS_SHIPPED => 'green',
            OutboundOrder::STATUS_CANCELLED => 'red',
            default => 'blue',
        };
    }

    public function holdStatusLabel(string $holdStatus): string
    {
        return match ($holdStatus) {
            OutboundOrder::HOLD_STATUS_ON_HOLD => __('outbound.on_hold'),
            default => '',
        };
    }

    public function formatWarehouseTime(OutboundOrder $order, ?Carbon $date, string $format): string
    {
        if (! $date) {
            return '-';
        }

        try {
            $warehouse = $order->warehouse;
            $timezone = $warehouse instanceof Warehouse && $warehouse->timezone
                ? $warehouse->timezone
                : config('app.timezone');

            return $date->copy()->timezone($timezone)->format($format);
        } catch (\Throwable) {
            return $date->format($format);
        }
    }

    public function updateNote(int $orderId, string $value): void
    {
        $note = trim($value);
        $note = $note === '' ? null : mb_substr($note, 0, 2000);

        $order = $this->scopedOrderQuery()
            ->whereKey($orderId)
            ->first();

        if (! $order) {
            return;
        }

        $order->update(['note' => $note]);

        $this->noteDrafts[$orderId] = $note ?? '';

        session()->flash('status', __('fulfillment.note_updated'));
    }

    public function updateShippingMethod(int $orderId, string $value): void
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

        $order = $this->scopedOrderQuery()
            ->whereKey($orderId)
            ->first();

        if (! $order) {
            return;
        }

        $updated = $order->update(['shipping_method_id' => $methodId]);

        if ($updated) {
            session()->flash('status', __('fulfillment.shipping_method_updated'));
        }
    }

    public function updateTracking(int $orderId, string $value): void
    {
        $trackingNo = TrackingNumber::normalize(mb_substr($value, 0, 255));

        $order = $this->scopedOrderQuery()
            ->whereKey($orderId)
            ->with(['salesOrders:id'])
            ->first();

        if (! $order) {
            return;
        }

        DB::transaction(function () use ($order, $trackingNo) {
            $order->update(['tracking_no' => $trackingNo]);

            SalesOrder::query()
                ->whereIn('id', $order->salesOrders->pluck('id'))
                ->update(['tracking_no' => $trackingNo]);
        });

        $this->trackingDrafts[$orderId] = $trackingNo ?? '';

        session()->flash('status', __('fulfillment.tracking_updated'));
    }

    public function remapShipping(): void
    {
        $selectedIds = $this->normalizedSelectedIds();

        if ($selectedIds === []) {
            return;
        }

        $orders = $this->scopedOrderQuery()
            ->whereIn('id', $selectedIds)
            ->where('reason', OutboundOrder::REASON_CUSTOMER_ORDER)
            ->with('lines:id,outbound_order_id,parent_line_id,sku_id')
            ->get();

        $updated = 0;
        $skipped = count($selectedIds) - $orders->count();
        $missingSkuIds = [];
        $resolver = app(SkuDefaultShippingMethodResolver::class);

        foreach ($orders as $order) {
            $skuIds = $order->lines
                ->where('parent_line_id', null)
                ->pluck('sku_id')
                ->filter()
                ->unique()
                ->values()
                ->all();

            $resolved = $resolver->resolve((int) $order->tenant_id, $skuIds);

            if ($resolved['status'] !== 'winner') {
                $missingSkuIds = array_merge($missingSkuIds, $skuIds);
                $skipped++;

                continue;
            }

            $order->update(['shipping_method_id' => $resolved['shipping_method_id']]);
            $updated++;
        }

        $this->selectedIds = [];

        if ($updated === 0) {
            session()->flash('error', __('fulfillment.remap_shipping_none', [
                'skus' => $this->shippingRemapSkuList($missingSkuIds),
            ]));

            return;
        }

        session()->flash('status', __('fulfillment.remap_shipping_result', [
            'updated' => $updated,
            'skipped' => $skipped,
        ]));
    }

    public function markShipped(): void
    {
        $selectedIds = $this->normalizedSelectedIds();

        if ($selectedIds === []) {
            return;
        }

        $orders = $this->scopedOrderQuery()
            ->whereIn('id', $selectedIds)
            ->where('status', OutboundOrder::STATUS_RESERVED)
            ->where('hold_status', OutboundOrder::HOLD_STATUS_ACTIVE)
            ->with(['shippingMethod.carrier:id,code,name', 'salesOrders:id'])
            ->get();
        $held = $this->scopedOrderQuery()
            ->whereIn('id', $selectedIds)
            ->where('hold_status', OutboundOrder::HOLD_STATUS_ON_HOLD)
            ->count();

        $updated = 0;

        foreach ($orders as $order) {
            try {
                app(ShipOutboundOrderService::class)->ship($order, [
                    'courier' => $order->shippingMethod?->carrier?->code ?? '',
                    'tracking_no' => (string) ($order->tracking_no ?? ''),
                ]);

                $updated++;
            } catch (InvalidArgumentException) {
                continue;
            }
        }

        $this->selectedIds = [];

        session()->flash('status', __('fulfillment.batch_mark_shipped_result', [
            'updated' => $updated,
            'skipped' => count($selectedIds) - $updated,
            'held' => $held,
        ]));
    }

    public function holdSelected(): void
    {
        $selectedIds = $this->normalizedSelectedIds();

        if ($selectedIds === []) {
            return;
        }

        $packingRefs = $this->packingStartedRefs($selectedIds);

        if ($packingRefs !== []) {
            session()->flash('error', __('outbound.cannot_hold_packing')."\n".implode("\n", $packingRefs));

            return;
        }

        $printedIds = $this->scopedOrderQuery()
            ->whereIn('id', $selectedIds)
            ->where('reason', OutboundOrder::REASON_CUSTOMER_ORDER)
            ->where('status', OutboundOrder::STATUS_RESERVED)
            ->where('hold_status', OutboundOrder::HOLD_STATUS_ACTIVE)
            ->whereNotNull('courier_csv_exported_at')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($printedIds !== []) {
            $this->pendingHoldOrderIds = $selectedIds;
            $this->pendingHoldWarning = $this->printedHoldWarning($printedIds);

            return;
        }

        $this->holdOutboundIds($selectedIds, confirmedPrinted: false);
    }

    public function confirmPrintedHold(): void
    {
        $ids = $this->pendingHoldOrderIds;
        $this->clearPendingHold();

        if ($ids !== []) {
            $this->holdOutboundIds($ids, confirmedPrinted: true);
        }
    }

    public function cancelPrintedHold(): void
    {
        $this->clearPendingHold();
    }

    public function releaseHoldSelected(): void
    {
        $selectedIds = $this->normalizedSelectedIds();

        if ($selectedIds === []) {
            return;
        }

        $orders = $this->scopedOrderQuery()
            ->whereIn('id', $selectedIds)
            ->where('reason', OutboundOrder::REASON_CUSTOMER_ORDER)
            ->where('hold_status', OutboundOrder::HOLD_STATUS_ON_HOLD)
            ->get();

        $updated = 0;
        $service = app(HoldOutboundOrderService::class);

        foreach ($orders as $order) {
            try {
                $service->releaseOutbound($order, source: 'fulfillment');
                $updated++;
            } catch (InvalidArgumentException) {
                continue;
            }
        }

        $this->selectedIds = [];

        session()->flash('status', __('fulfillment.batch_release_hold_result', [
            'updated' => $updated,
            'skipped' => count($selectedIds) - $updated,
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
        $this->pendingCourierExportOrderIds = [];
        $this->pendingExportWarning = null;

        if ($this->selectedIds === []) {
            session()->flash('error', __('fulfillment.courier_export_no_selection'));

            return null;
        }

        $outboundOrderIds = $this->selectedOutboundOrderIds();
        $carrier = $this->normalizeCourierCarrier($carrier);
        $result = app(CourierExportService::class)->validateOrderExport(
            outboundOrderIds: $outboundOrderIds,
            carrier: $carrier,
            allowedTenantIds: $this->allowedTenantIds(),
        );

        if ($result->hasHardBlock()) {
            session()->flash('error', $this->courierExportMessage($result->toArray()));

            return null;
        }

        if ($result->requiresConfirmation) {
            $this->pendingCourierExportCarrier = $carrier;
            $this->pendingCourierExportOrderIds = $outboundOrderIds;
            $this->pendingExportWarning = $this->reExportWarning($result->alreadyExportedOrderIds);

            return null;
        }

        return $this->performCourierExport($carrier, confirmedReExport: false, outboundOrderIds: $outboundOrderIds);
    }

    public function confirmCourierExport(): mixed
    {
        if ($this->pendingCourierExportCarrier === null || $this->pendingCourierExportOrderIds === []) {
            return null;
        }

        $carrier = $this->pendingCourierExportCarrier;
        $outboundOrderIds = $this->pendingCourierExportOrderIds;
        $this->clearPendingExport();

        return $this->performCourierExport($carrier, confirmedReExport: true, outboundOrderIds: $outboundOrderIds);
    }

    public function cancelCourierExport(): void
    {
        $this->clearPendingExport();
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
        $orders = $this->scopedOrderQuery()
            ->where('reason', OutboundOrder::REASON_CUSTOMER_ORDER)
            ->with([
                'tenant:id,code,name',
                'warehouse:id,code,name,timezone',
                'shippingMethod:id,name',
                'salesOrders:id,shop_id,platform_order_id',
                'salesOrders.shop:id,name',
                'salesOrders.lines:id,sales_order_id,sku_id,quantity',
            ])
            ->when($this->detailed, fn ($query) => $query->with([
                'salesOrders.lines.sku' => fn ($sku) => $sku->select(['id', 'sku', 'stock_item_id', ...Sku::DISPLAY_NAME_COLUMNS]),
                'salesOrders.lines.sku.stockItem' => fn ($stockItem) => $stockItem->select(['id', ...StockItem::DISPLAY_NAME_COLUMNS]),
            ]))
            ->when($this->tenantIds !== [], fn ($query) => $query
                ->whereIn('tenant_id', array_map('intval', $this->tenantIds)))
            ->when($this->warehouseId !== '', fn ($query) => $query->where('warehouse_id', (int) $this->warehouseId))
            ->when($this->statusesFilter === [], fn ($query) => $this->applyDefaultStatusFilter($query))
            ->when($this->statusesFilter !== [], fn ($query) => $this->applyStatusFilter($query))
            ->when($this->printWaiting, fn ($query) => $query
                ->whereNull('courier_csv_exported_at')
                ->where('hold_status', OutboundOrder::HOLD_STATUS_ACTIVE)
                ->where('status', '!=', OutboundOrder::STATUS_CANCELLED))
            ->when($this->dateRange !== SalesOrderFilters::DATE_ALL || $this->dateFrom !== '' || $this->dateTo !== '', function ($query) {
                [$from, $toExclusive] = $this->dateWindow();

                if ($from) {
                    $query->where('created_at', '>=', $from);
                }

                if ($toExclusive) {
                    $query->where('created_at', '<', $toExclusive);
                }
            })
            ->when(in_array(SalesOrderFilters::OTHER_PRINTED, $this->othersFilter, true), fn ($query) => $query
                ->whereNotNull('courier_csv_exported_at'))
            ->when(in_array(SalesOrderFilters::OTHER_NOT_PRINTED, $this->othersFilter, true), fn ($query) => $query
                ->whereNull('courier_csv_exported_at'))
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
                    ->whereHas('leafLines', fn ($line) => $line->where('qty', '>', 1))
                    ->orWhereHas('leafLines', fn ($line) => $line, '>=', 2)))
            ->when($this->search !== '', function ($query) {
                $like = '%'.$this->search.'%';

                $query->where(fn ($inner) => $inner
                    ->where('ref', 'like', $like)
                    ->orWhere('recipient_name', 'like', $like)
                    ->orWhere('tracking_no', 'like', $like)
                    ->orWhereHas('salesOrders', fn ($sub) => $sub->where('platform_order_id', 'like', $like)));
            })
            ->orderByRaw('shipped_at is not null')
            ->orderBy('created_at')
            ->orderBy('id')
            ->paginate(30);

        $this->visibleOrderIds = $orders->getCollection()->pluck('id')->map(fn ($id) => (int) $id)->all();

        foreach ($orders as $order) {
            $this->noteDrafts[$order->id] ??= $order->note ?? '';
            $this->trackingDrafts[$order->id] ??= (string) ($order->tracking_no ?? '');
        }

        $tenants = Tenant::query()
            ->whereIn('id', $this->allowedTenantIds())
            ->orderBy('name')
            ->get(['id', 'code', 'name']);
        $warehouses = Warehouse::query()
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'code', 'name']);
        $shippingMethods = ShippingMethod::query()
            ->where('shipping_methods.status', 'active')
            ->ordered()
            ->get()
            ->mapWithKeys(fn (ShippingMethod $method) => [(string) $method->id => $method->name])
            ->all();
        $shippingMethodFilterOptions = $shippingMethods + [
            SalesOrderFilters::EMPTY_SHIPPING => __('fulfillment.shipping_method_unset'),
        ];

        return view('livewire.fulfillment-index', [
            'orders' => $orders,
            'tenants' => $tenants,
            'warehouses' => $warehouses,
            'shippingMethods' => $shippingMethods,
            'shippingMethodFilterOptions' => $shippingMethodFilterOptions,
            'statuses' => $this->statuses(),
            'dateRanges' => $this->dateRanges(),
            'showTenantFilter' => $this->isInternalUser(),
            'visibleOrderIds' => $this->visibleOrderIds,
            'activeFilterChips' => $this->activeFilterChips($tenants, $warehouses, $shippingMethodFilterOptions),
        ])->layout('inventory', [
            'title' => __('fulfillment.page_title'),
            'subtitle' => __('fulfillment.page_subtitle'),
            'pageWide' => true,
        ]);
    }

    private function statuses(): array
    {
        return [
            'reserved' => __('fulfillment.status_reserved'),
            'on_hold' => __('outbound.on_hold'),
            'shipped' => __('fulfillment.status_shipped'),
            'cancelled' => __('fulfillment.status_cancelled'),
        ];
    }

    private function applyDefaultStatusFilter($query): void
    {
        $query->where(function ($inner): void {
            $inner
                ->where(fn ($reserved) => $reserved
                    ->where('status', OutboundOrder::STATUS_RESERVED)
                    ->where('hold_status', OutboundOrder::HOLD_STATUS_ACTIVE))
                ->orWhere('hold_status', OutboundOrder::HOLD_STATUS_ON_HOLD);
        });
    }

    private function applyStatusFilter($query): void
    {
        $statuses = array_values(array_map('strval', (array) $this->statusesFilter));

        $query->where(function ($inner) use ($statuses): void {
            $hasCondition = false;

            if (in_array('reserved', $statuses, true)) {
                $inner
                    ->where('status', OutboundOrder::STATUS_RESERVED)
                    ->where('hold_status', OutboundOrder::HOLD_STATUS_ACTIVE);
                $hasCondition = true;
            }

            if (in_array('on_hold', $statuses, true)) {
                $hasCondition
                    ? $inner->orWhere('hold_status', OutboundOrder::HOLD_STATUS_ON_HOLD)
                    : $inner->where('hold_status', OutboundOrder::HOLD_STATUS_ON_HOLD);
                $hasCondition = true;
            }

            $normalStatuses = collect($statuses)
                ->reject(fn (string $status): bool => in_array($status, ['reserved', 'on_hold'], true))
                ->map(fn (string $status): string => self::STATUS_MAP[$status] ?? $status)
                ->unique()
                ->values()
                ->all();

            if ($normalStatuses !== []) {
                $hasCondition
                    ? $inner->orWhereIn('status', $normalStatuses)
                    : $inner->whereIn('status', $normalStatuses);
            }
        });
    }

    /**
     * @param  Collection<int, Tenant>  $tenants
     * @param  Collection<int, Warehouse>  $warehouses
     * @param  array<string, string>  $shippingMethodFilterOptions
     * @return array<int, array{group: string, value: string, text: string}>
     */
    private function activeFilterChips($tenants, $warehouses, array $shippingMethodFilterOptions): array
    {
        $chips = [];

        $tenantLabels = $tenants
            ->mapWithKeys(fn (Tenant $tenant) => [(string) $tenant->id => $tenant->code.' - '.$tenant->name])
            ->all();
        foreach ((array) $this->tenantIds as $tenantId) {
            $chips[] = $this->chip('tenant', (string) $tenantId, __('fulfillment.field_tenant'), $tenantLabels[(string) $tenantId] ?? (string) $tenantId);
        }

        if ($this->warehouseId !== '') {
            $warehouse = $warehouses->firstWhere('id', (int) $this->warehouseId);
            $chips[] = $this->chip('warehouse', (string) $this->warehouseId, __('fulfillment.field_warehouse'), $warehouse ? $warehouse->code.' - '.$warehouse->name : (string) $this->warehouseId);
        }

        $statusLabels = $this->statuses();
        foreach ((array) $this->statusesFilter as $status) {
            $chips[] = $this->chip('status', (string) $status, __('fulfillment.col_status'), $statusLabels[(string) $status] ?? (string) $status);
        }

        foreach ((array) $this->shippingMethodsFilter as $method) {
            $chips[] = $this->chip('shipping', (string) $method, __('fulfillment.filter_shipping'), $shippingMethodFilterOptions[(string) $method] ?? (string) $method);
        }

        if ($this->dateRange !== SalesOrderFilters::DATE_ALL || $this->dateFrom !== '' || $this->dateTo !== '') {
            $chips[] = $this->chip('date', '', __('fulfillment.filter_order_date'), $this->dateChipLabel());
        }

        $otherLabels = [
            SalesOrderFilters::OTHER_MULTI_ITEM => __('fulfillment.other_multi_item'),
            SalesOrderFilters::OTHER_PRINTED => __('fulfillment.other_printed'),
            SalesOrderFilters::OTHER_NOT_PRINTED => __('fulfillment.other_not_printed'),
        ];
        foreach ((array) $this->othersFilter as $other) {
            $chips[] = $this->chip('other', (string) $other, __('fulfillment.filter_others'), $otherLabels[(string) $other] ?? (string) $other);
        }

        if (trim($this->search) !== '') {
            $chips[] = $this->chip('search', '', __('common.search'), $this->search);
        }

        return $chips;
    }

    /**
     * @return array{group: string, value: string, text: string}
     */
    private function chip(string $group, string $value, string $label, string $text): array
    {
        return [
            'group' => $group,
            'value' => $value,
            'text' => $label.': '.$text,
        ];
    }

    private function removeFilterValue(mixed $values, string $value): array
    {
        return array_values(array_filter((array) $values, fn ($item) => (string) $item !== $value));
    }

    private function removeShippedIfOnlyRemainingFilter(): void
    {
        $statuses = array_values(array_map('strval', (array) $this->statusesFilter));

        if (! in_array('shipped', $statuses, true)) {
            return;
        }

        $nonShippedStatuses = array_values(array_filter($statuses, fn (string $status): bool => $status !== 'shipped'));
        $hasOtherFilter = $this->tenantIds !== []
            || $this->warehouseId !== ''
            || $nonShippedStatuses !== []
            || $this->printWaiting
            || $this->shippingMethodsFilter !== []
            || $this->dateRange !== SalesOrderFilters::DATE_ALL
            || $this->dateFrom !== ''
            || $this->dateTo !== ''
            || $this->othersFilter !== []
            || trim($this->search) !== '';

        if ($hasOtherFilter) {
            return;
        }

        $this->statusesFilter = $nonShippedStatuses;
    }

    private function coerceShippedDateRange(): void
    {
        if (in_array('shipped', $this->statusesFilter, true) && $this->dateRange === SalesOrderFilters::DATE_ALL) {
            $this->dateRange = SalesOrderFilters::DATE_LAST_30_DAYS;
        }
    }

    private function dateRanges(): array
    {
        return [
            SalesOrderFilters::DATE_TODAY => __('sales_orders.date_today'),
            SalesOrderFilters::DATE_LAST_3_DAYS => __('sales_orders.date_last_3_days'),
            SalesOrderFilters::DATE_LAST_7_DAYS => __('sales_orders.date_last_7_days'),
            SalesOrderFilters::DATE_LAST_30_DAYS => __('sales_orders.date_last_30_days'),
            SalesOrderFilters::DATE_LAST_3_MONTHS => __('sales_orders.date_last_3_months'),
            SalesOrderFilters::DATE_LAST_1_YEAR => __('sales_orders.date_last_1_year'),
            SalesOrderFilters::DATE_CUSTOM => __('sales_orders.date_custom'),
        ];
    }

    private function dateChipLabel(): string
    {
        if ($this->dateRange === SalesOrderFilters::DATE_CUSTOM) {
            return match (true) {
                $this->dateFrom !== '' && $this->dateTo !== '' => __('sales_orders.date_custom_between', [
                    'from' => $this->dateFrom,
                    'to' => $this->dateTo,
                ]),
                $this->dateFrom !== '' => __('sales_orders.date_custom_from', ['from' => $this->dateFrom]),
                $this->dateTo !== '' => __('sales_orders.date_custom_to', ['to' => $this->dateTo]),
                default => __('sales_orders.date_custom'),
            };
        }

        return $this->dateRanges()[$this->dateRange] ?? $this->dateRange;
    }

    private function resetDateFilter(): void
    {
        $this->dateRange = SalesOrderFilters::DATE_ALL;
        $this->dateFrom = '';
        $this->dateTo = '';
    }

    private function dateWindow(): array
    {
        return match ($this->dateRange) {
            SalesOrderFilters::DATE_TODAY => [now()->startOfDay(), now()->addDay()->startOfDay()],
            SalesOrderFilters::DATE_LAST_3_DAYS => [now()->subDays(3)->startOfDay(), null],
            SalesOrderFilters::DATE_LAST_7_DAYS => [now()->subDays(7)->startOfDay(), null],
            SalesOrderFilters::DATE_LAST_30_DAYS => [now()->subDays(30)->startOfDay(), null],
            SalesOrderFilters::DATE_LAST_3_MONTHS => [now()->subMonths(3)->startOfDay(), null],
            SalesOrderFilters::DATE_LAST_1_YEAR => [now()->subYear()->startOfDay(), null],
            SalesOrderFilters::DATE_CUSTOM => $this->customDateWindow(),
            default => [null, null],
        };
    }

    private function customDateWindow(): array
    {
        $from = $this->parseDate($this->dateFrom)?->startOfDay();
        $to = $this->parseDate($this->dateTo)?->addDay()->startOfDay();

        return [$from, $to];
    }

    private function parseDate(string $date): ?Carbon
    {
        if ($date === '') {
            return null;
        }

        try {
            return Carbon::parse($date);
        } catch (\Throwable) {
            return null;
        }
    }

    private function performCourierExport(string $carrier, bool $confirmedReExport, ?array $outboundOrderIds = null): mixed
    {
        try {
            $batch = app(CourierExportService::class)->exportOrders(
                outboundOrderIds: $outboundOrderIds ?? $this->selectedOutboundOrderIds(),
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
        $this->pendingCourierExportOrderIds = [];

        return redirect()->route('courier-export-batches.download', $batch);
    }

    private function courierExportMessage(array $result): string
    {
        $parts = [];

        foreach ([
            'wrong_carrier_order_ids' => 'courier_export_wrong_carrier_ids',
            'unsupported_courier_order_ids' => 'courier_export_unsupported_courier_ids',
            'blocked_status_order_ids' => 'courier_export_blocked_status_ids',
            'held_order_ids' => 'courier_export_held_ids',
            'no_ready_lines_order_ids' => 'courier_export_no_ready_lines_ids',
            'mixed_tenant_order_ids' => 'courier_export_mixed_tenant_ids',
            'missing_order_ids' => 'courier_export_missing_ids',
        ] as $key => $translationKey) {
            if ($result[$key] !== []) {
                $parts[] = __(
                    'fulfillment.'.$translationKey,
                    ['ids' => implode(', ', $result[$key])]
                );
            }
        }

        return implode("\n", $parts ?: [$result['message']]);
    }

    private function reExportWarning(array $outboundOrderIds): string
    {
        $refs = OutboundOrder::query()
            ->whereIn('id', $outboundOrderIds)
            ->whereIn('tenant_id', $this->allowedTenantIds())
            ->orderBy('ref')
            ->pluck('ref')
            ->all();

        return __('fulfillment.courier_export_reexport_warning')."\n".implode("\n", $refs);
    }

    private function printedHoldWarning(array $outboundOrderIds): string
    {
        $refs = OutboundOrder::query()
            ->whereIn('id', $outboundOrderIds)
            ->whereIn('tenant_id', $this->allowedTenantIds())
            ->orderBy('ref')
            ->pluck('ref')
            ->all();

        return __('outbound.hold_printed_confirm_body')."\n".implode("\n", $refs);
    }

    /**
     * @param  array<int, int>  $outboundOrderIds
     * @return array<int, string>
     */
    private function packingStartedRefs(array $outboundOrderIds): array
    {
        return $this->scopedOrderQuery()
            ->whereIn('id', $outboundOrderIds)
            ->where('reason', OutboundOrder::REASON_CUSTOMER_ORDER)
            ->where('status', OutboundOrder::STATUS_RESERVED)
            ->where('hold_status', OutboundOrder::HOLD_STATUS_ACTIVE)
            ->whereHas('packScans')
            ->orderBy('ref')
            ->pluck('ref')
            ->filter()
            ->values()
            ->all();
    }

    private function holdOutboundIds(array $outboundOrderIds, bool $confirmedPrinted): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $outboundOrderIds))));
        $orders = $this->scopedOrderQuery()
            ->whereIn('id', $ids)
            ->where('reason', OutboundOrder::REASON_CUSTOMER_ORDER)
            ->where('status', OutboundOrder::STATUS_RESERVED)
            ->get();
        $updated = 0;
        $service = app(HoldOutboundOrderService::class);

        foreach ($orders as $order) {
            try {
                $result = $service->holdOutbound(
                    outbound: $order,
                    source: 'fulfillment',
                    confirmedPrinted: $confirmedPrinted,
                );

                if ($result->held) {
                    $updated++;
                }
            } catch (InvalidArgumentException $exception) {
                if ($exception->getMessage() === __('outbound.cannot_hold_packing')) {
                    session()->flash('error', $exception->getMessage());

                    return;
                }

                continue;
            }
        }

        $this->selectedIds = [];

        session()->flash('status', __('fulfillment.batch_hold_result', [
            'updated' => $updated,
            'skipped' => count($ids) - $updated,
        ]));
    }

    private function clearPendingHold(): void
    {
        $this->pendingHoldOrderIds = [];
        $this->pendingHoldWarning = null;
    }

    private function clearPendingExport(): void
    {
        $this->pendingCourierExportCarrier = null;
        $this->pendingCourierExportOrderIds = [];
        $this->pendingExportWarning = null;

        session()->forget('warning');
    }

    private function shippingRemapSkuList(array $skuIds): string
    {
        $skus = Sku::query()
            ->whereIn('id', array_values(array_unique(array_map('intval', $skuIds))))
            ->orderBy('sku')
            ->pluck('sku')
            ->filter()
            ->values()
            ->all();

        return implode("\n", $skus ?: ['-']);
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

    private function selectedOutboundOrderIds(): array
    {
        return $this->scopedOrderQuery()
            ->whereIn('id', $this->normalizedSelectedIds())
            ->where('reason', OutboundOrder::REASON_CUSTOMER_ORDER)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function scopedOrderQuery()
    {
        return OutboundOrder::query()->whereIn('tenant_id', $this->allowedTenantIds());
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
