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
use App\Services\Outbound\ShipOutboundOrderService;
use App\Support\CourierCarrier;
use App\Support\SalesOrderFilters;
use App\Support\TrackingNumber;
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
        'reserved' => OutboundOrder::STATUS_PENDING,
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

    public function removeFilterChip(string $group, string $value = ''): void
    {
        match ($group) {
            'tenant' => $this->tenantIds = $this->removeFilterValue($this->tenantIds, $value),
            'warehouse' => $this->warehouseId = '',
            'status' => $this->statusesFilter = $this->removeFilterValue($this->statusesFilter, $value),
            'shipping' => $this->shippingMethodsFilter = $this->removeFilterValue($this->shippingMethodsFilter, $value),
            'other' => $this->othersFilter = $this->removeFilterValue($this->othersFilter, $value),
            'search' => $this->search = '',
            default => null,
        };

        $this->resetPage();
    }

    public function clearAllFilters(): void
    {
        $this->tenantIds = [];
        $this->warehouseId = '';
        $this->statusesFilter = [];
        $this->shippingMethodsFilter = [];
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
            OutboundOrder::STATUS_PENDING => __('fulfillment.status_reserved'),
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

    public function pickSummaryUrl(): string
    {
        $query = [];

        if ($this->warehouseId !== '') {
            $query['warehouse_id'] = $this->warehouseId;
        }

        $tenantIds = array_values(array_filter($this->tenantIds, fn ($id): bool => ctype_digit((string) $id)));
        if (count($tenantIds) === 1) {
            $query['tenant_id'] = $tenantIds[0];
        }

        $shippingMethodIds = array_values(array_filter($this->shippingMethodsFilter, fn ($id): bool => ctype_digit((string) $id)));
        if (count($shippingMethodIds) === 1) {
            $query['shipping_method_id'] = $shippingMethodIds[0];
        }

        return route('fulfillment.pick-summary', $query);
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

        $order->update(['shipping_method_id' => $methodId]);
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
    }

    public function markShipped(): void
    {
        $selectedIds = $this->normalizedSelectedIds();

        if ($selectedIds === []) {
            return;
        }

        $orders = $this->scopedOrderQuery()
            ->whereIn('id', $selectedIds)
            ->where('status', OutboundOrder::STATUS_PENDING)
            ->with(['shippingMethod.carrier:id,code,name', 'salesOrders:id'])
            ->get();

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
                'warehouse:id,code,name',
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
            ->when($this->statusesFilter !== [], fn ($query) => $query
                ->whereIn('status', array_map(fn ($status) => self::STATUS_MAP[$status] ?? $status, $this->statusesFilter)))
            ->when($this->printWaiting, fn ($query) => $query->whereNull('courier_csv_exported_at'))
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
            'shipped' => __('fulfillment.status_shipped'),
            'cancelled' => __('fulfillment.status_cancelled'),
        ];
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

    private function clearPendingExport(): void
    {
        $this->pendingCourierExportCarrier = null;
        $this->pendingCourierExportOrderIds = [];
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
