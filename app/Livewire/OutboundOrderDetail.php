<?php

namespace App\Livewire;

use App\Models\OutboundOrder;
use App\Models\ShippingMethod;
use App\Models\StockItem;
use App\Models\Tenant;
use App\Services\Courier\CourierExportService;
use App\Services\InventoryService;
use App\Services\Labels\AddressLabelExportService;
use App\Services\Outbound\HoldOutboundOrderService;
use App\Support\BulkActionMessage;
use App\Support\CourierCarrier;
use App\Support\TrackingNumber;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Livewire\Component;
use RuntimeException;

class OutboundOrderDetail extends Component
{
    public int $orderId = 0;

    public bool $editingRecipient = false;

    public bool $editingShipping = false;

    public bool $editingCourierCost = false;

    public string $recipientName = '';

    public string $recipientPhone = '';

    public string $recipientCountryCode = '';

    public string $recipientPostalCode = '';

    public string $recipientState = '';

    public string $recipientCity = '';

    public string $recipientAddressLine1 = '';

    public string $recipientAddressLine2 = '';

    public string $shippingMethodId = '';

    public string $courier = '';

    public string $trackingNo = '';

    public string $courierCost = '';

    public string $courierCostCurrency = '';

    public string $note = '';

    public ?string $pendingCourierExportCarrier = null;

    public array $pendingCourierExportOrderIds = [];

    public ?string $pendingExportWarning = null;

    public bool $pendingPrintedHoldConfirmation = false;

    public ?string $pendingHoldWarning = null;

    private bool $visibleTenantIdsResolved = false;

    private array $visibleTenantIdsCache = [];

    public function mount(OutboundOrder $order): void
    {
        $this->orderId = $this->scopedOrderQuery()->findOrFail($order->id)->id;
    }

    public function editRecipient(): void
    {
        $order = $this->loadPendingOrder();
        $this->recipientName = (string) $order->recipient_name;
        $this->recipientPhone = (string) $order->recipient_phone;
        $this->recipientCountryCode = (string) $order->recipient_country_code;
        $this->recipientPostalCode = (string) $order->recipient_postal_code;
        $this->recipientState = (string) $order->recipient_state;
        $this->recipientCity = (string) $order->recipient_city;
        $this->recipientAddressLine1 = (string) $order->recipient_address_line1;
        $this->recipientAddressLine2 = (string) $order->recipient_address_line2;
        $this->editingRecipient = true;
    }

    public function cancelEditRecipient(): void
    {
        $this->editingRecipient = false;
    }

    public function saveRecipient(): void
    {
        $order = $this->loadPendingOrder();
        $this->recipientCountryCode = strtoupper(trim($this->recipientCountryCode));

        validator([
            'recipient_name' => $this->recipientName,
            'recipient_phone' => $this->recipientPhone,
            'recipient_country_code' => $this->recipientCountryCode,
            'recipient_postal_code' => $this->recipientPostalCode,
            'recipient_state' => $this->recipientState,
            'recipient_city' => $this->recipientCity,
            'recipient_address_line1' => $this->recipientAddressLine1,
            'recipient_address_line2' => $this->recipientAddressLine2,
        ], [
            'recipient_name' => ['nullable', 'string', 'max:255'],
            'recipient_phone' => ['nullable', 'string', 'max:50'],
            'recipient_country_code' => ['nullable', 'string', 'regex:/^[A-Z]{2}$/'],
            'recipient_postal_code' => ['nullable', 'string', 'max:20'],
            'recipient_state' => ['nullable', 'string', 'max:100'],
            'recipient_city' => ['nullable', 'string', 'max:100'],
            'recipient_address_line1' => ['nullable', 'string', 'max:255'],
            'recipient_address_line2' => ['nullable', 'string', 'max:255'],
        ])->validate();

        $data = [
            'recipient_name' => $this->nullableString($this->recipientName),
            'recipient_phone' => $this->nullableString($this->recipientPhone),
            'recipient_country_code' => $this->nullableString($this->recipientCountryCode),
            'recipient_postal_code' => $this->nullableString($this->recipientPostalCode),
            'recipient_state' => $this->nullableString($this->recipientState),
            'recipient_city' => $this->nullableString($this->recipientCity),
            'recipient_address_line1' => $this->nullableString($this->recipientAddressLine1),
            'recipient_address_line2' => $this->nullableString($this->recipientAddressLine2),
        ];

        $order->update($data);

        $this->editingRecipient = false;
        session()->flash('status', __('fulfillment.recipient_updated'));
    }

    public function editShipping(): void
    {
        $order = $this->loadPendingOrder();
        $this->shippingMethodId = (string) ($order->shipping_method_id ?? '');
        $this->courier = (string) $order->courier;
        $this->trackingNo = (string) $order->tracking_no;
        $this->note = (string) $order->note;
        $this->editingShipping = true;
    }

    public function cancelEditShipping(): void
    {
        $this->editingShipping = false;
    }

    public function editCourierCost(): void
    {
        if (! $this->isInternalUser()) {
            abort(403);
        }

        $order = $this->scopedOrderQuery()->findOrFail($this->orderId);

        $this->courierCost = $order->courier_cost !== null ? (string) $order->courier_cost : '';
        $this->courierCostCurrency = (string) ($order->courier_cost_currency ?? 'JPY');
        $this->editingCourierCost = true;
    }

    public function cancelEditCourierCost(): void
    {
        $this->editingCourierCost = false;
    }

    public function saveCourierCost(): void
    {
        if (! $this->isInternalUser()) {
            abort(403);
        }

        $order = $this->scopedOrderQuery()->findOrFail($this->orderId);
        $this->courierCostCurrency = strtoupper(trim($this->courierCostCurrency));

        validator([
            'courier_cost' => $this->courierCost,
            'courier_cost_currency' => $this->courierCostCurrency,
        ], [
            'courier_cost' => ['nullable', 'numeric', 'min:0'],
            'courier_cost_currency' => ['required_with:courier_cost', 'nullable', 'string', 'size:3'],
        ])->validate();

        $cost = $this->courierCost !== ''
            ? number_format((float) $this->courierCost, 2, '.', '')
            : null;
        $currency = $cost !== null ? $this->courierCostCurrency : null;

        $order->update($order->courierCostAttributes($cost, $currency, Auth::id()));

        $this->editingCourierCost = false;
        session()->flash('status', __('outbound.courier_cost_updated'));
    }

    public function saveShipping(): void
    {
        $order = $this->loadPendingOrder();

        validator([
            'shipping_method_id' => $this->shippingMethodId,
            'courier' => $this->courier,
            'tracking_no' => $this->trackingNo,
            'note' => $this->note,
        ], [
            'shipping_method_id' => ['nullable', 'integer', Rule::exists('shipping_methods', 'id')->where('status', 'active')],
            'courier' => ['nullable', 'string', 'max:100'],
            'tracking_no' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:2000'],
        ])->validate();

        $methodId = $this->shippingMethodId !== '' ? (int) $this->shippingMethodId : null;
        $trackingNo = TrackingNumber::normalize($this->trackingNo);

        $order->update([
            'shipping_method_id' => $methodId,
            'courier' => $this->nullableString($this->courier),
            'tracking_no' => $trackingNo,
            'note' => $this->nullableString($this->note),
        ]);

        $this->editingShipping = false;
        session()->flash('status', __('fulfillment.shipping_updated'));
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

        $carrier = CourierCarrier::normalize($carrier);
        $outboundOrderIds = [$this->orderId];

        $result = app(CourierExportService::class)->validateOrderExport(
            outboundOrderIds: $outboundOrderIds,
            carrier: $carrier,
            allowedTenantIds: $this->visibleTenantIds(),
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

    public function holdOutbound(HoldOutboundOrderService $service): void
    {
        $order = $this->loadPendingOrder();

        try {
            $result = $service->holdOutbound(
                outbound: $order,
                source: 'fulfillment',
                confirmedPrinted: false,
            );
        } catch (InvalidArgumentException $exception) {
            session()->flash('error', $exception->getMessage());

            return;
        }

        if ($result->requiresConfirmation) {
            $this->pendingPrintedHoldConfirmation = true;
            $this->pendingHoldWarning = __('outbound.hold_printed_confirm_body');

            return;
        }

        session()->flash('status', BulkActionMessage::make('fulfillment.batch_hold_result', 1, 0));
    }

    public function confirmPrintedHold(HoldOutboundOrderService $service): void
    {
        $this->pendingPrintedHoldConfirmation = false;
        $this->pendingHoldWarning = null;

        try {
            $service->holdOutbound(
                outbound: $this->loadPendingOrder(),
                source: 'fulfillment',
                confirmedPrinted: true,
            );
        } catch (InvalidArgumentException $exception) {
            session()->flash('error', $exception->getMessage());

            return;
        }

        session()->flash('status', BulkActionMessage::make('fulfillment.batch_hold_result', 1, 0));
    }

    public function cancelPrintedHold(): void
    {
        $this->pendingPrintedHoldConfirmation = false;
        $this->pendingHoldWarning = null;
    }

    public function releaseHold(HoldOutboundOrderService $service): void
    {
        try {
            $service->releaseOutbound($this->loadPendingOrder(), source: 'fulfillment');
        } catch (InvalidArgumentException $exception) {
            session()->flash('error', $exception->getMessage());

            return;
        }

        session()->flash('status', BulkActionMessage::make('fulfillment.batch_release_hold_result', 1, 0));
    }

    public function cancel(): void
    {
        $order = $this->scopedOrderQuery()
            ->with('leafLines')
            ->findOrFail($this->orderId);

        if ($order->status !== OutboundOrder::STATUS_RESERVED) {
            return;
        }

        DB::transaction(function () use ($order): void {
            foreach ($order->leafLines as $line) {
                app(InventoryService::class)->releaseReserve(
                    tenantId: $order->tenant_id,
                    warehouseId: $order->warehouse_id,
                    stockItemId: $line->stock_item_id,
                    quantity: $line->qty,
                    context: [
                        'ref_type' => 'outbound_order',
                        'ref_id' => (string) $order->id,
                        'user_id' => Auth::id(),
                    ],
                );
            }

            $order->status = OutboundOrder::STATUS_CANCELLED;
            $order->cancelled_at = now();
            $order->cancelled_by_user_id = Auth::id();
            $order->save();
        });

        session()->flash('status', __('outbound.order_cancelled'));
    }

    public function statusLabel(string $status): string
    {
        return match ($status) {
            OutboundOrder::STATUS_DRAFT => __('outbound.status_draft'),
            OutboundOrder::STATUS_PENDING => __('outbound.status_pending'),
            OutboundOrder::STATUS_RESERVED => __('outbound.status_reserved'),
            OutboundOrder::STATUS_SHIPPED => __('outbound.status_shipped'),
            OutboundOrder::STATUS_CANCELLED => __('outbound.status_cancelled'),
            default => $status,
        };
    }

    public function statusColor(string $status): string
    {
        return OutboundOrder::statusColorFor($status);
    }

    public function render()
    {
        $order = $this->scopedOrderQuery()
            ->with([
                'tenant:id,code,name',
                'warehouse:id,code,name',
                'shippingMethod:id,name,name_ja,name_zh_tw,name_zh_cn',
                'createdBy:id,name',
                'shippedBy:id,name',
                'courierCostUpdatedBy:id,name',
                'cancelledBy:id,name',
                'parentLines.sku:id,sku,sku_type,name',
                'parentLines.stockItem:id,code,name',
                'parentLines.childLines.stockItem:id,code,name',
                'salesOrders:id,platform_order_id,recipient_name,recipient_city,fulfillment_status',
                'salesOrders.lines:id,sales_order_id',
                'courierExportBatchOrders' => fn ($query) => $query
                    ->with('batch.exportedBy:id,name')
                    ->latest('exported_at')
                    ->latest('id'),
                'packScans' => fn ($query) => $query
                    ->with([
                        'sku:id,sku,name',
                        'stockItem' => fn ($q) => $q->select(['id', 'code', ...StockItem::DISPLAY_NAME_COLUMNS]),
                        'scannedBy:id,name',
                    ])
                    ->latest('id')
                    ->limit(10),
            ])
            ->findOrFail($this->orderId);

        return view('livewire.outbound-order-detail', [
            'order' => $order,
            'shippingMethods' => $this->shippingMethodOptions(),
            'courierLabelExports' => $this->courierLabelExports($order),
            'isInternal' => $this->isInternalUser(),
        ])->layout('inventory', [
            'title' => __('outbound.detail_page_title'),
            'subtitle' => __('outbound.detail_page_subtitle'),
            'pageWide' => true,
        ]);
    }

    private function performCourierExport(string $carrier, bool $confirmedReExport, array $outboundOrderIds): mixed
    {
        try {
            $batch = app(CourierExportService::class)->exportOrders(
                outboundOrderIds: $outboundOrderIds,
                carrier: $carrier,
                allowedTenantIds: $this->visibleTenantIds(),
                user: Auth::user(),
                confirmedReExport: $confirmedReExport,
            );
        } catch (RuntimeException $exception) {
            session()->flash('error', $exception->getMessage());

            return null;
        }

        $this->pendingCourierExportCarrier = null;
        $this->pendingCourierExportOrderIds = [];

        return redirect()->route('courier-export-batches.download', $batch);
    }

    private function clearPendingExport(): void
    {
        $this->pendingCourierExportCarrier = null;
        $this->pendingCourierExportOrderIds = [];
        $this->pendingExportWarning = null;
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
            ->whereIn('tenant_id', $this->visibleTenantIds())
            ->orderBy('ref')
            ->pluck('ref')
            ->all();

        return __('fulfillment.courier_export_reexport_warning')."\n".implode("\n", $refs);
    }

    private function shippingMethodOptions(): Collection
    {
        return ShippingMethod::query()
            ->where('shipping_methods.status', 'active')
            ->with('carrier:id,code,name')
            ->ordered()
            ->get(['shipping_methods.id', 'shipping_methods.carrier_id', 'shipping_methods.code', 'shipping_methods.name', 'shipping_methods.name_ja', 'shipping_methods.name_zh_tw', 'shipping_methods.name_zh_cn']);
    }

    private function courierLabelExports(OutboundOrder $order): Collection
    {
        $timezone = $order->warehouse?->timezone ?: config('app.timezone');
        $exports = $order->courierExportBatchOrders
            ->filter(fn ($row): bool => $row->batch !== null)
            ->sortBy([
                ['exported_at', 'asc'],
                ['id', 'asc'],
            ])
            ->unique('courier_export_batch_id')
            ->values();

        return $exports
            ->map(function ($row, int $index) use ($timezone): array {
                $batch = $row->batch;
                $exportedAt = $row->exported_at ?: $batch->exported_at;

                return [
                    'id' => $row->id,
                    'batch_id' => $batch->id,
                    'type' => $this->courierLabelExportTypeLabel((string) $batch->carrier),
                    'carrier' => $batch->carrier,
                    'file_name' => $batch->file_name,
                    'exported_at' => $exportedAt?->copy()->timezone($timezone)->format('Y-m-d H:i') ?? '-',
                    'exported_by' => $batch->exportedBy?->name ?: '-',
                    'is_reexport' => $index > 0,
                    'download_url' => route('courier-export-batches.download', $batch),
                ];
            })
            ->reverse()
            ->values();
    }

    private function courierLabelExportTypeLabel(string $carrier): string
    {
        return match ($carrier) {
            CourierCarrier::YAMATO => __('outbound.courier_label_export_type_yamato'),
            CourierCarrier::SAGAWA => __('outbound.courier_label_export_type_sagawa'),
            AddressLabelExportService::EXPORT_TYPE_LABEL10 => __('outbound.courier_label_export_type_label10'),
            default => strtoupper($carrier),
        };
    }

    private function loadPendingOrder(): OutboundOrder
    {
        $order = $this->scopedOrderQuery()->findOrFail($this->orderId);

        if ($order->status !== OutboundOrder::STATUS_RESERVED) {
            abort(403);
        }

        return $order;
    }

    private function nullableString(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function scopedOrderQuery()
    {
        return OutboundOrder::query()->whereIn('tenant_id', $this->visibleTenantIds());
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
}
