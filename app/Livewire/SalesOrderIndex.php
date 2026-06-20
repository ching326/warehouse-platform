<?php

namespace App\Livewire;

use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\ShippingMethod;
use App\Models\Shop;
use App\Models\Tenant;
use App\Services\Courier\CourierExportService;
use App\Services\MarketplaceShippingNotice\MarketplaceShippingNoticeExportService;
use App\Support\CourierCarrier;
use App\Support\SalesOrderFilters;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class SalesOrderIndex extends Component
{
    use WithPagination;

    #[Url(as: 'platforms', except: [])]
    public $platforms = [];

    #[Url(as: 'shops', except: [])]
    public $shopIds = [];

    #[Url(as: 'fulfillment', except: [])]
    public $fulfillmentStatusesFilter = [];

    #[Url(as: 'order_status', except: [])]
    public $orderStatusesFilter = [];

    #[Url(as: 'shipping', except: [])]
    public $shippingMethodsFilter = [];

    #[Url(as: 'others', except: [])]
    public $othersFilter = [];

    #[Url(as: 'date_range', except: SalesOrderFilters::DATE_ALL)]
    public string $dateRange = SalesOrderFilters::DATE_ALL;

    #[Url(as: 'active_only', except: true)]
    public bool $activeOnly = true;

    #[Url(as: 'print_waiting', except: false)]
    public bool $printWaiting = false;

    #[Url(as: 'date_from', except: '')]
    public string $dateFrom = '';

    #[Url(as: 'date_to', except: '')]
    public string $dateTo = '';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public array $selectedIds = [];

    public array $visibleOrderIds = [];

    public array $trackingDrafts = [];

    public array $trackingSavedDrafts = [];

    public ?string $pendingCourierExportCarrier = null;

    public array $pendingCourierExportOrderIds = [];

    public ?string $pendingMarketplaceNoticePlatform = null;

    public array $pendingMarketplaceNoticeOrderIds = [];

    public ?string $filterWarning = null;

    private bool $allowedTenantIdsResolved = false;

    private array $allowedTenantIdsCache = [];

    public function mount(): void
    {
        $this->authorizeTenantAccess();
        $this->normalizeFilterState();
        $this->coerceHistoricalDateRange();
    }

    public function updatedPlatforms(): void
    {
        $this->filterChanged();
    }

    public function updatedShopIds(): void
    {
        $this->filterChanged();
    }

    public function updatedFulfillmentStatusesFilter(): void
    {
        $this->coerceHistoricalDateRange();
        $this->filterChanged();
    }

    public function updatedOrderStatusesFilter(): void
    {
        $this->coerceHistoricalDateRange();
        $this->filterChanged();
    }

    public function updatedShippingMethodsFilter(): void
    {
        $this->filterChanged();
    }

    public function updatedOthersFilter(): void
    {
        $this->filterChanged();
    }

    public function updatedDateRange(): void
    {
        $this->coerceHistoricalDateRange();
        $this->filterChanged();
    }

    public function updatedDateFrom(): void
    {
        $this->filterChanged();
    }

    public function updatedDateTo(): void
    {
        $this->filterChanged();
    }

    public function updatedActiveOnly(): void
    {
        $this->filterChanged();
    }

    public function updatedPrintWaiting(): void
    {
        $this->filterChanged();
    }

    public function updatedSearch(): void
    {
        $this->filterChanged();
    }

    public function toggleOtherFilter(string $filter): void
    {
        if (! array_key_exists($filter, $this->otherFilterOptions())) {
            return;
        }

        $others = array_values(array_map('strval', (array) $this->othersFilter));

        if (in_array($filter, $others, true)) {
            $this->othersFilter = array_values(array_diff($others, [$filter]));
            $this->filterChanged();

            return;
        }

        if ($filter === SalesOrderFilters::OTHER_PRINTED) {
            $others = array_values(array_diff($others, [SalesOrderFilters::OTHER_NOT_PRINTED]));
        }

        if ($filter === SalesOrderFilters::OTHER_NOT_PRINTED) {
            $others = array_values(array_diff($others, [SalesOrderFilters::OTHER_PRINTED]));
        }

        $this->othersFilter = array_values(array_unique([...$others, $filter]));
        $this->filterChanged();
    }

    public function removeFilterChip(string $group, string $value = ''): void
    {
        match ($group) {
            'platform' => $this->platforms = $this->removeFilterValue($this->platforms, $value),
            'shop' => $this->shopIds = $this->removeFilterValue($this->shopIds, $value),
            'fulfillment' => $this->fulfillmentStatusesFilter = $this->removeFilterValue($this->fulfillmentStatusesFilter, $value),
            'order_status' => $this->orderStatusesFilter = $this->removeFilterValue($this->orderStatusesFilter, $value),
            'shipping' => $this->shippingMethodsFilter = $this->removeFilterValue($this->shippingMethodsFilter, $value),
            'other' => $this->othersFilter = $this->removeFilterValue($this->othersFilter, $value),
            'date' => $this->resetDateFilter(),
            'search' => $this->search = '',
            'print_waiting' => $this->printWaiting = false,
            default => null,
        };

        $this->filterChanged();
    }

    public function clearAllFilters(): void
    {
        $this->platforms = [];
        $this->shopIds = [];
        $this->fulfillmentStatusesFilter = [];
        $this->orderStatusesFilter = [];
        $this->shippingMethodsFilter = [];
        $this->othersFilter = [];
        $this->dateRange = SalesOrderFilters::DATE_ALL;
        $this->dateFrom = '';
        $this->dateTo = '';
        $this->search = '';
        $this->printWaiting = false;

        $this->filterChanged();
    }

    public function updatedTrackingDrafts(mixed $value, string|int $key): void
    {
        if (! is_numeric($key)) {
            return;
        }

        $this->saveTrackingDraft((int) $key);
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

    public function bulkHold(): void
    {
        if ($this->selectedIds === []) {
            return;
        }

        $selectedIds = $this->normalizedSelectedIds();

        $updated = SalesOrder::query()
            ->whereIn('id', $selectedIds)
            ->whereIn('tenant_id', $this->allowedTenantIds())
            ->where('order_status', SalesOrder::ORDER_STATUS_PENDING)
            ->whereIn('fulfillment_status', [
                SalesOrder::FULFILLMENT_STATUS_UNFULFILLED,
                SalesOrder::FULFILLMENT_STATUS_READY,
            ])
            ->update([
                'order_status' => SalesOrder::ORDER_STATUS_ON_HOLD,
                'fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_UNFULFILLED,
            ]);

        $this->finishBulk('sales_orders.bulk_hold_result', $updated, count($selectedIds));
    }

    public function bulkMarkShipped(): void
    {
        if ($this->selectedIds === []) {
            return;
        }

        $selectedIds = $this->normalizedSelectedIds();

        $updated = SalesOrder::query()
            ->whereIn('id', $selectedIds)
            ->whereIn('tenant_id', $this->allowedTenantIds())
            ->where('order_status', SalesOrder::ORDER_STATUS_PENDING)
            ->where('fulfillment_status', SalesOrder::FULFILLMENT_STATUS_READY)
            ->update([
                'order_status' => SalesOrder::ORDER_STATUS_COMPLETED,
                'fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_SHIPPED,
                'shipped_at' => now(),
            ]);

        $this->finishBulk('sales_orders.bulk_shipped_result', $updated, count($selectedIds));
    }

    public function bulkReleaseHold(): void
    {
        if ($this->selectedIds === []) {
            return;
        }

        $selectedIds = $this->normalizedSelectedIds();

        $updated = SalesOrder::query()
            ->whereIn('id', $selectedIds)
            ->whereIn('tenant_id', $this->allowedTenantIds())
            ->where('order_status', SalesOrder::ORDER_STATUS_ON_HOLD)
            ->whereIn('fulfillment_status', [
                SalesOrder::FULFILLMENT_STATUS_UNFULFILLED,
                SalesOrder::FULFILLMENT_STATUS_READY,
            ])
            ->update(['order_status' => SalesOrder::ORDER_STATUS_PENDING]);

        $this->finishBulk('sales_orders.bulk_release_hold_result', $updated, count($selectedIds));
    }

    public function bulkCancel(): void
    {
        if ($this->selectedIds === []) {
            return;
        }

        $selectedIds = $this->normalizedSelectedIds();

        $eligibleIds = SalesOrder::query()
            ->whereIn('id', $selectedIds)
            ->whereIn('tenant_id', $this->allowedTenantIds())
            ->whereNotIn('order_status', [
                SalesOrder::ORDER_STATUS_CANCELLED,
                SalesOrder::ORDER_STATUS_COMPLETED,
            ])
            ->whereIn('fulfillment_status', [
                SalesOrder::FULFILLMENT_STATUS_UNFULFILLED,
                SalesOrder::FULFILLMENT_STATUS_READY,
            ])
            ->pluck('id')
            ->all();

        DB::transaction(function () use ($eligibleIds) {
            if ($eligibleIds === []) {
                return;
            }

            SalesOrder::query()
                ->whereIn('id', $eligibleIds)
                ->update([
                    'order_status' => SalesOrder::ORDER_STATUS_CANCELLED,
                    'fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_CANCELLED,
                ]);

            SalesOrderLine::query()
                ->whereIn('sales_order_id', $eligibleIds)
                ->update(['line_status' => SalesOrderLine::STATUS_CANCELLED]);
        });

        $this->finishBulk('sales_orders.bulk_cancel_result', count($eligibleIds), count($selectedIds));
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
            SalesOrder::ORDER_STATUS_CANCEL_REQUESTED => 'red',
            SalesOrder::ORDER_STATUS_CANCELLED => 'red',
            SalesOrder::ORDER_STATUS_COMPLETED => 'green',
            default => 'zinc',
        };
    }

    public function updateShippingMethod(int $orderId, string $value): void
    {
        $methodId = $value === '' ? null : (int) $value;

        if ($value !== '' && $methodId <= 0) {
            return;
        }

        $method = $methodId
            ? ShippingMethod::query()->where('status', 'active')->with('carrier:id,code')->find($methodId)
            : null;

        if ($methodId && ! $method) {
            return;
        }

        SalesOrder::query()
            ->whereIn('tenant_id', $this->allowedTenantIds())
            ->whereKey($orderId)
            ->update([
                'shipping_method_id' => $method?->id,
                'shipping_method' => $method?->carrier?->code,
            ]);
    }

    public function updateTrackingNo(int $orderId, string $value): bool
    {
        $trackingNo = trim($value);
        $trackingNo = $trackingNo === '' ? null : mb_substr($trackingNo, 0, 255);

        $order = SalesOrder::query()
            ->whereIn('tenant_id', $this->allowedTenantIds())
            ->whereKey($orderId)
            ->first();

        if (! $order) {
            return false;
        }

        $order->update(['tracking_no' => $trackingNo]);

        return true;
    }

    public function saveTrackingDraft(int $orderId): void
    {
        $trackingNo = trim((string) ($this->trackingDrafts[$orderId] ?? ''));
        $trackingNo = $trackingNo === '' ? '' : mb_substr($trackingNo, 0, 255);

        $this->trackingDrafts[$orderId] = $trackingNo;
        $saved = $this->updateTrackingNo($orderId, $trackingNo);

        if ($saved) {
            $this->trackingSavedDrafts[$orderId] = $trackingNo;
        }
    }

    public function toggleVisibleSelection(): void
    {
        $visibleIds = array_values(array_unique(array_map('intval', $this->visibleOrderIds)));

        if ($visibleIds === []) {
            return;
        }

        $selectedIds = $this->normalizedSelectedIds();
        $selectedLookup = array_flip($selectedIds);
        $allVisibleSelected = collect($visibleIds)->every(fn (int $id): bool => isset($selectedLookup[$id]));

        if ($allVisibleSelected) {
            $this->selectedIds = array_values(array_diff($selectedIds, $visibleIds));

            return;
        }

        $this->selectedIds = array_values(array_unique(array_merge($selectedIds, $visibleIds)));
    }

    public function validateCourierExport(string $carrier): mixed
    {
        $this->pendingCourierExportCarrier = null;
        $this->pendingCourierExportOrderIds = [];

        if ($this->selectedIds === []) {
            session()->flash('error', __('sales_orders.courier_export_no_selection'));

            return null;
        }

        $carrier = $this->normalizeCourierCarrier($carrier);
        $result = app(CourierExportService::class)->validateExport(
            salesOrderIds: $this->normalizedSelectedIds(),
            carrier: $carrier,
            allowedTenantIds: $this->allowedTenantIds(),
        );

        if ($result->hasHardBlock()) {
            session()->flash('error', $this->courierExportMessage($result->toArray()));

            return null;
        }

        if ($result->requiresConfirmation) {
            $this->pendingCourierExportCarrier = $carrier;
            $this->pendingCourierExportOrderIds = $this->normalizedSelectedIds();
            session()->flash('warning', $result->message);

            return null;
        }

        return $this->performCourierExport($carrier, confirmedReExport: false);
    }

    public function confirmCourierExport(): mixed
    {
        if ($this->pendingCourierExportCarrier === null || $this->pendingCourierExportOrderIds === []) {
            return null;
        }

        return $this->performCourierExport($this->pendingCourierExportCarrier, confirmedReExport: true, orderIds: $this->pendingCourierExportOrderIds);
    }

    public function validateMarketplaceShippingNoticeExport(string $platform): mixed
    {
        $this->pendingMarketplaceNoticePlatform = null;
        $this->pendingMarketplaceNoticeOrderIds = [];

        if ($this->selectedIds === []) {
            session()->flash('error', __('sales_orders.marketplace_notice_export_no_selection'));

            return null;
        }

        try {
            $result = app(MarketplaceShippingNoticeExportService::class)->validateExport(
                salesOrderIds: $this->normalizedSelectedIds(),
                platform: $platform,
                allowedTenantIds: $this->allowedTenantIds(),
            );
        } catch (\InvalidArgumentException) {
            session()->flash('error', __('sales_orders.marketplace_notice_export_wrong_platform', ['platform' => ucfirst($platform)]));

            return null;
        }

        if ($result->hasHardBlock()) {
            session()->flash('error', $this->marketplaceNoticeExportMessage($result->toArray()));

            return null;
        }

        if ($result->requiresConfirmation) {
            $this->pendingMarketplaceNoticePlatform = strtolower($platform);
            $this->pendingMarketplaceNoticeOrderIds = $this->normalizedSelectedIds();
            session()->flash('warning', $result->message);

            return null;
        }

        return $this->performMarketplaceShippingNoticeExport($platform, confirmedReExport: false);
    }

    public function confirmMarketplaceShippingNoticeExport(): mixed
    {
        if ($this->pendingMarketplaceNoticePlatform === null || $this->pendingMarketplaceNoticeOrderIds === []) {
            return null;
        }

        return $this->performMarketplaceShippingNoticeExport(
            $this->pendingMarketplaceNoticePlatform,
            confirmedReExport: true,
            orderIds: $this->pendingMarketplaceNoticeOrderIds,
        );
    }

    public function render()
    {
        $filters = $this->filters();
        $this->filterWarning = SalesOrderFilters::dateRangeError($filters);
        $platformOptions = $this->platformOptions();
        $shops = $this->shopOptions();

        $orders = SalesOrder::query()
            ->with(['shop.tenant', 'shippingMethod.carrier', 'lines.sku.stockItem'])
            ->tap(fn ($query) => $this->filterWarning
                ? $query->whereRaw('1 = 0')
                : SalesOrderFilters::applyToOrderQuery($query, $filters))
            ->orderByDesc('order_date')
            ->orderByDesc('id')
            ->simplePaginate(30);

        foreach ($orders as $order) {
            $this->trackingDrafts[$order->id] ??= $order->tracking_no ?? '';
            $this->trackingSavedDrafts[$order->id] ??= $order->tracking_no ?? '';
        }

        $this->visibleOrderIds = $orders->getCollection()->pluck('id')->map(fn ($id) => (int) $id)->all();

        return view('livewire.sales-order-index', [
            'orders' => $orders,
            'platformOptions' => $platformOptions,
            'shops' => $shops,
            'platformFilterOptions' => $platformOptions->mapWithKeys(fn (string $platform) => [$platform => $platform])->all(),
            'shopFilterOptions' => $shops->mapWithKeys(fn (Shop $shop) => [
                (string) $shop->id => $shop->tenant->code.' / '.$shop->name,
            ])->all(),
            'fulfillmentStatuses' => $this->fulfillmentStatuses(),
            'orderStatuses' => $this->orderStatuses(),
            'shippingMethodOptions' => $this->shippingMethodSelectOptions(),
            'shippingMethodFilterOptions' => $this->shippingMethodFilterOptions(),
            'otherFilterOptions' => $this->otherFilterOptions(),
            'dateRanges' => $this->dateRanges(),
            'activeFilterChips' => $this->activeFilterChips(),
            'exportFilters' => $this->exportFilterParams(),
            'visibleOrderIds' => $this->visibleOrderIds,
        ])->layout('inventory', [
            'title' => __('sales_orders.index_page_title'),
            'subtitle' => __('sales_orders.index_page_subtitle'),
            'pageWide' => true,
            'hidePageHeader' => true,
        ]);
    }

    private function platformOptions(): Collection
    {
        return Shop::query()
            ->whereIn('tenant_id', $this->allowedTenantIds())
            ->where('status', 'active')
            ->whereNotNull('platform')
            ->distinct()
            ->orderBy('platform')
            ->pluck('platform');
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
            SalesOrder::ORDER_STATUS_CANCEL_REQUESTED => __('sales_orders.order_cancel_requested'),
            SalesOrder::ORDER_STATUS_CANCELLED => __('sales_orders.order_cancelled'),
            SalesOrder::ORDER_STATUS_COMPLETED => __('sales_orders.order_completed'),
        ];
    }

    private function shippingMethodSelectOptions(): array
    {
        return ShippingMethod::query()
            ->where('shipping_methods.status', 'active')
            ->ordered()
            ->get()
            ->mapWithKeys(fn (ShippingMethod $method) => [
                (string) $method->id => $method->name,
            ])
            ->all();
    }

    private function shippingMethodFilterOptions(): array
    {
        return $this->shippingMethodSelectOptions() + [
            SalesOrderFilters::EMPTY_SHIPPING => __('sales_orders.shipping_method_unset'),
        ];
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

    private function performCourierExport(string $carrier, bool $confirmedReExport, ?array $orderIds = null): mixed
    {
        try {
            $batch = app(CourierExportService::class)->export(
                salesOrderIds: $orderIds ?? $this->normalizedSelectedIds(),
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
        $parts = [$result['message']];

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
                    'sales_orders.'.$translationKey,
                    ['ids' => implode(', ', $result[$key])]
                );
            }
        }

        return implode(' ', $parts);
    }

    private function performMarketplaceShippingNoticeExport(string $platform, bool $confirmedReExport, ?array $orderIds = null): mixed
    {
        try {
            $batch = app(MarketplaceShippingNoticeExportService::class)->export(
                salesOrderIds: $orderIds ?? $this->normalizedSelectedIds(),
                platform: $platform,
                allowedTenantIds: $this->allowedTenantIds(),
                user: Auth::user(),
                confirmedReExport: $confirmedReExport,
            );
        } catch (RuntimeException $exception) {
            session()->flash('error', $exception->getMessage());

            return null;
        }

        $this->selectedIds = [];
        $this->pendingMarketplaceNoticePlatform = null;
        $this->pendingMarketplaceNoticeOrderIds = [];

        return redirect()->route('marketplace-shipping-notice-batches.download', $batch);
    }

    private function marketplaceNoticeExportMessage(array $result): string
    {
        $parts = [$result['message']];

        foreach ([
            'missing_order_ids' => 'marketplace_notice_export_missing_ids',
            'mixed_tenant_order_ids' => 'marketplace_notice_export_mixed_tenant_ids',
            'mixed_platform_order_ids' => 'marketplace_notice_export_mixed_platform_ids',
            'wrong_platform_order_ids' => 'marketplace_notice_export_wrong_platform_ids',
            'blocked_status_order_ids' => 'marketplace_notice_export_blocked_status_ids',
            'missing_platform_order_ids' => 'marketplace_notice_export_missing_platform_order_ids',
            'missing_shipping_method_order_ids' => 'marketplace_notice_export_missing_shipping_method_ids',
            'missing_tracking_order_ids' => 'marketplace_notice_export_missing_tracking_ids',
            'missing_mapping_order_ids' => 'marketplace_notice_export_missing_mapping_ids',
            'missing_carrier_code_order_ids' => 'marketplace_notice_export_missing_carrier_code_ids',
            'no_ready_lines_order_ids' => 'marketplace_notice_export_no_ready_lines_ids',
        ] as $key => $translationKey) {
            if ($result[$key] !== []) {
                $parts[] = __(
                    'sales_orders.'.$translationKey,
                    ['ids' => implode(', ', $result[$key])]
                );
            }
        }

        return implode(' ', $parts);
    }

    private function normalizeCourierCarrier(string $carrier): string
    {
        return in_array($carrier, CourierCarrier::values(), true)
            ? $carrier
            : CourierCarrier::YAMATO;
    }

    private function filters(): array
    {
        return SalesOrderFilters::normalize([
            'allowed_tenant_ids' => $this->allowedTenantIds(),
            'platforms' => $this->platforms,
            'shops' => $this->shopIds,
            'fulfillment' => $this->fulfillmentStatusesFilter,
            'order_status' => $this->orderStatusesFilter,
            'shipping' => $this->shippingMethodsFilter,
            'others' => $this->othersFilter,
            'date_range' => $this->dateRange,
            'active_only' => $this->activeOnly,
            'date_from' => $this->dateFrom,
            'date_to' => $this->dateTo,
            'print_waiting' => $this->printWaiting,
            'search' => $this->search,
        ]);
    }

    private function normalizeFilterState(bool $useRequestFallback = true): void
    {
        $query = request()->query();
        $fallback = fn (string $key, mixed $default = []) => $useRequestFallback ? ($query[$key] ?? $default) : $default;
        $filters = SalesOrderFilters::normalize([
            'allowed_tenant_ids' => $this->allowedTenantIds(),
            'platforms' => $this->platforms ?: $fallback('platforms'),
            'shops' => $this->shopIds ?: ($useRequestFallback ? ($query['shops'] ?? $query['shop'] ?? []) : []),
            'fulfillment' => $this->fulfillmentStatusesFilter ?: $fallback('fulfillment'),
            'order_status' => $this->orderStatusesFilter ?: $fallback('order_status'),
            'shipping' => $this->shippingMethodsFilter ?: $fallback('shipping'),
            'others' => $this->othersFilter ?: $fallback('others'),
            'date_range' => $this->dateRange,
            'active_only' => $this->activeOnly,
            'date_from' => $this->dateFrom,
            'date_to' => $this->dateTo,
            'print_waiting' => $useRequestFallback ? ($query['print_waiting'] ?? $this->printWaiting) : $this->printWaiting,
            'search' => $this->search,
        ]);

        $this->platforms = $filters['platforms'];
        $this->shopIds = $filters['shops'];
        $this->fulfillmentStatusesFilter = $filters['fulfillment'];
        $this->orderStatusesFilter = $filters['order_status'];
        $this->shippingMethodsFilter = $filters['shipping'];
        $this->othersFilter = $filters['others'];
        $this->dateRange = $filters['date_range'];
        $this->activeOnly = $filters['active_only'];
        $this->dateFrom = $filters['date_from'];
        $this->dateTo = $filters['date_to'];
        $this->printWaiting = $filters['print_waiting'];
        $this->search = $filters['search'];
    }

    private function coerceHistoricalDateRange(): void
    {
        $filters = $this->filters();

        if (SalesOrderFilters::hasHistoricalStatus($filters) && $this->dateRange === SalesOrderFilters::DATE_ALL) {
            $this->activeOnly = false;
            $this->dateRange = SalesOrderFilters::DATE_LAST_30_DAYS;
        }
    }

    private function filterChanged(): void
    {
        $this->normalizeFilterState(false);
        $this->selectedIds = [];
        $this->resetPage();
    }

    private function exportFilterParams(): array
    {
        return [
            'platforms' => $this->platforms ?: null,
            'shops' => $this->shopIds ?: null,
            'fulfillment' => $this->fulfillmentStatusesFilter ?: null,
            'order_status' => $this->orderStatusesFilter ?: null,
            'shipping' => $this->shippingMethodsFilter ?: null,
            'others' => $this->othersFilter ?: null,
            'date_range' => $this->dateRange !== SalesOrderFilters::DATE_ALL ? $this->dateRange : null,
            'active_only' => $this->activeOnly ? null : '0',
            'date_from' => $this->dateFrom ?: null,
            'date_to' => $this->dateTo ?: null,
            'print_waiting' => $this->printWaiting ? '1' : null,
            'q' => $this->search ?: null,
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

    private function normalizedSelectedIds(): array
    {
        return array_values(array_unique(array_map('intval', $this->selectedIds)));
    }

    private function finishBulk(string $messageKey, int $updated, int $selectedCount): void
    {
        $this->selectedIds = [];

        session()->flash('status', __($messageKey, [
            'updated' => $updated,
            'skipped' => $selectedCount - $updated,
        ]));
    }

    private function otherFilterOptions(): array
    {
        return [
            SalesOrderFilters::OTHER_MULTI_ITEM => __('sales_orders.other_multi_item'),
            SalesOrderFilters::OTHER_PRINTED => __('sales_orders.other_printed'),
            SalesOrderFilters::OTHER_NOT_PRINTED => __('sales_orders.other_not_printed'),
        ];
    }

    private function activeFilterChips(): array
    {
        $chips = [];

        foreach ((array) $this->platforms as $platform) {
            $chips[] = $this->chip('platform', (string) $platform, __('sales_orders.chip_platform'), (string) $platform);
        }

        $shopLabels = $this->shopOptions()->mapWithKeys(fn (Shop $shop) => [
            (string) $shop->id => $shop->tenant->code.' / '.$shop->name,
        ])->all();
        foreach ((array) $this->shopIds as $shopId) {
            $chips[] = $this->chip('shop', (string) $shopId, __('sales_orders.chip_shop'), $shopLabels[(string) $shopId] ?? (string) $shopId);
        }

        foreach ((array) $this->fulfillmentStatusesFilter as $status) {
            $chips[] = $this->chip('fulfillment', (string) $status, __('sales_orders.chip_fulfillment'), $this->fulfillmentStatusLabel((string) $status));
        }

        foreach ((array) $this->orderStatusesFilter as $status) {
            $chips[] = $this->chip('order_status', (string) $status, __('sales_orders.chip_order_status'), $this->orderStatusLabel((string) $status));
        }

        $shippingLabels = $this->shippingMethodFilterOptions();
        foreach ((array) $this->shippingMethodsFilter as $method) {
            $chips[] = $this->chip('shipping', (string) $method, __('sales_orders.chip_shipping'), $shippingLabels[(string) $method] ?? (string) $method);
        }

        $otherLabels = $this->otherFilterOptions();
        foreach ((array) $this->othersFilter as $other) {
            $chips[] = $this->chip('other', (string) $other, __('sales_orders.chip_others'), $otherLabels[(string) $other] ?? (string) $other);
        }

        if ($this->dateRange !== SalesOrderFilters::DATE_ALL || $this->dateFrom !== '' || $this->dateTo !== '') {
            $chips[] = $this->chip('date', '', __('sales_orders.chip_order_date'), $this->dateChipLabel());
        }

        if (trim($this->search) !== '') {
            $chips[] = $this->chip('search', '', __('common.search'), $this->search);
        }

        if ($this->printWaiting) {
            $chips[] = [
                'group' => 'print_waiting',
                'value' => '',
                'text' => __('sales_orders.print_waiting'),
            ];
        }

        return $chips;
    }

    private function chip(string $group, string $value, string $label, string $text): array
    {
        return [
            'group' => $group,
            'value' => $value,
            'text' => $label.': '.$text,
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

    private function removeFilterValue(mixed $values, string $value): array
    {
        return array_values(array_filter((array) $values, fn ($item) => (string) $item !== $value));
    }

    private function resetDateFilter(): void
    {
        $this->dateRange = SalesOrderFilters::DATE_ALL;
        $this->dateFrom = '';
        $this->dateTo = '';
    }
}
