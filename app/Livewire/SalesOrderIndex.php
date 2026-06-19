<?php

namespace App\Livewire;

use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\ShippingMethod;
use App\Models\Shop;
use App\Models\Tenant;
use App\Services\Courier\CourierExportService;
use App\Support\CourierCarrier;
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

    #[Url(as: 'shop', except: '')]
    public string $shopId = '';

    #[Url(as: 'fulfillment', except: '')]
    public string $fulfillmentStatus = '';

    #[Url(as: 'order_status', except: '')]
    public string $orderStatus = '';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public array $selectedIds = [];

    public array $trackingDrafts = [];

    public array $trackingSavedDrafts = [];

    public ?string $pendingCourierExportCarrier = null;

    public array $pendingCourierExportOrderIds = [];

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

    public function render()
    {
        $orders = SalesOrder::query()
            ->with(['shop.tenant', 'shippingMethod.carrier', 'lines.sku.stockItem'])
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

        foreach ($orders as $order) {
            $this->trackingDrafts[$order->id] ??= $order->tracking_no ?? '';
            $this->trackingSavedDrafts[$order->id] ??= $order->tracking_no ?? '';
        }

        return view('livewire.sales-order-index', [
            'orders' => $orders,
            'shops' => $this->shopOptions(),
            'fulfillmentStatuses' => $this->fulfillmentStatuses(),
            'orderStatuses' => $this->orderStatuses(),
            'shippingMethodOptions' => $this->shippingMethodSelectOptions(),
        ])->layout('inventory', [
            'title' => __('sales_orders.index_page_title'),
            'subtitle' => __('sales_orders.index_page_subtitle'),
            'pageWide' => true,
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
            SalesOrder::ORDER_STATUS_CANCEL_REQUESTED => __('sales_orders.order_cancel_requested'),
            SalesOrder::ORDER_STATUS_CANCELLED => __('sales_orders.order_cancelled'),
            SalesOrder::ORDER_STATUS_COMPLETED => __('sales_orders.order_completed'),
        ];
    }

    private function shippingMethodSelectOptions(): array
    {
        return ShippingMethod::query()
            ->where('status', 'active')
            ->with('carrier:id,code,name')
            ->orderBy('name')
            ->get(['id', 'carrier_id', 'name'])
            ->mapWithKeys(fn (ShippingMethod $method) => [
                (string) $method->id => $method->name.' / '.$method->carrier->name,
            ])
            ->all();
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

    private function normalizeCourierCarrier(string $carrier): string
    {
        return in_array($carrier, CourierCarrier::values(), true)
            ? $carrier
            : CourierCarrier::YAMATO;
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
}
