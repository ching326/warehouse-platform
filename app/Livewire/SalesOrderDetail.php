<?php

namespace App\Livewire;

use App\Models\Issue;
use App\Models\OutboundOrder;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\ShippingMethod;
use App\Models\Sku;
use App\Models\Tenant;
use App\Models\Warehouse;
use App\Services\Outbound\HoldOutboundOrderService;
use App\Services\Outbound\ReshipSalesOrderService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Component;

class SalesOrderDetail extends Component
{
    public int $orderId = 0;

    public bool $editingRecipient = false;

    public string $editRecipientName = '';

    public string $editRecipientPhone = '';

    public string $editRecipientCountryCode = '';

    public string $editRecipientPostalCode = '';

    public string $editRecipientState = '';

    public string $editRecipientCity = '';

    public string $editRecipientAddressLine1 = '';

    public string $editRecipientAddressLine2 = '';

    public bool $editingLines = false;

    public array $draftLines = [];

    public array $draftLineSkuSearches = [];

    public bool $showHoldChoicePrompt = false;

    public bool $showReshipModal = false;

    public string $reshipSourceOutboundId = '';

    public string $reshipWarehouseId = '';

    public string $reshipReason = '';

    public string $reshipNote = '';

    public array $reshipLines = [];

    private bool $allowedTenantIdsResolved = false;

    private array $allowedTenantIdsCache = [];

    public function mount(SalesOrder $order): void
    {
        if (! in_array($order->tenant_id, $this->allowedTenantIds(), true)) {
            abort(403);
        }

        $this->orderId = $order->id;
    }

    public function editRecipient(): void
    {
        $order = SalesOrder::query()
            ->whereIn('tenant_id', $this->allowedTenantIds())
            ->findOrFail($this->orderId);

        $this->editRecipientName = (string) $order->recipient_name;
        $this->editRecipientPhone = (string) $order->recipient_phone;
        $this->editRecipientCountryCode = (string) $order->recipient_country_code;
        $this->editRecipientPostalCode = (string) $order->recipient_postal_code;
        $this->editRecipientState = (string) $order->recipient_state;
        $this->editRecipientCity = (string) $order->recipient_city;
        $this->editRecipientAddressLine1 = (string) $order->recipient_address_line1;
        $this->editRecipientAddressLine2 = (string) $order->recipient_address_line2;
        $this->editingRecipient = true;
    }

    public function cancelEditRecipient(): void
    {
        $this->editingRecipient = false;
    }

    public function saveRecipient(): void
    {
        $order = SalesOrder::query()
            ->whereIn('tenant_id', $this->allowedTenantIds())
            ->findOrFail($this->orderId);

        $this->editRecipientCountryCode = strtoupper(trim($this->editRecipientCountryCode));

        validator([
            'recipient_name' => $this->editRecipientName,
            'recipient_phone' => $this->editRecipientPhone,
            'recipient_country_code' => $this->editRecipientCountryCode,
            'recipient_postal_code' => $this->editRecipientPostalCode,
            'recipient_state' => $this->editRecipientState,
            'recipient_city' => $this->editRecipientCity,
            'recipient_address_line1' => $this->editRecipientAddressLine1,
            'recipient_address_line2' => $this->editRecipientAddressLine2,
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

        $order->update([
            'recipient_name' => $this->nullableString($this->editRecipientName),
            'recipient_phone' => $this->nullableString($this->editRecipientPhone),
            'recipient_country_code' => $this->nullableString($this->editRecipientCountryCode),
            'recipient_postal_code' => $this->nullableString($this->editRecipientPostalCode),
            'recipient_state' => $this->nullableString($this->editRecipientState),
            'recipient_city' => $this->nullableString($this->editRecipientCity),
            'recipient_address_line1' => $this->nullableString($this->editRecipientAddressLine1),
            'recipient_address_line2' => $this->nullableString($this->editRecipientAddressLine2),
        ]);

        $this->editingRecipient = false;
        session()->flash('status', __('sales_orders.recipient_updated'));
    }

    public function cancelOrder(): void
    {
        $order = SalesOrder::query()
            ->whereIn('tenant_id', $this->allowedTenantIds())
            ->findOrFail($this->orderId);

        if ($order->order_status === SalesOrder::ORDER_STATUS_CANCELLED) {
            return;
        }

        if ($order->order_status === SalesOrder::ORDER_STATUS_COMPLETED) {
            session()->flash('error', __('sales_orders.cannot_cancel_completed'));

            return;
        }

        if (! in_array($order->fulfillment_status, [
            SalesOrder::FULFILLMENT_STATUS_UNFULFILLED,
            SalesOrder::FULFILLMENT_STATUS_READY,
        ], true)) {
            session()->flash('error', __('sales_orders.cannot_cancel_arranged'));

            return;
        }

        $order->update([
            'order_status' => SalesOrder::ORDER_STATUS_CANCELLED,
            'fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_CANCELLED,
        ]);

        $order->lines()->update(['line_status' => SalesOrderLine::STATUS_CANCELLED]);
        session()->flash('status', __('sales_orders.order_cancelled_msg'));
    }

    public function deleteOrder()
    {
        $order = SalesOrder::query()
            ->whereIn('tenant_id', $this->allowedTenantIds())
            ->findOrFail($this->orderId);

        if (
            ! in_array($order->order_status, [
                SalesOrder::ORDER_STATUS_PENDING,
                SalesOrder::ORDER_STATUS_ON_HOLD,
                SalesOrder::ORDER_STATUS_BACKORDER,
            ], true)
            || ! in_array($order->fulfillment_status, [
                SalesOrder::FULFILLMENT_STATUS_UNFULFILLED,
                SalesOrder::FULFILLMENT_STATUS_READY,
            ], true)
        ) {
            session()->flash('error', __('sales_orders.cannot_delete_order'));

            return null;
        }

        DB::transaction(function () use ($order): void {
            $order->delete();
        });

        return redirect()
            ->route('sales.orders.index')
            ->with('status', __('sales_orders.order_deleted_msg'));
    }

    public function markReady(): void
    {
        $order = SalesOrder::query()
            ->whereIn('tenant_id', $this->allowedTenantIds())
            ->with('lines.sku.bundleComponents.componentStockItem')
            ->findOrFail($this->orderId);

        if (
            $order->order_status !== SalesOrder::ORDER_STATUS_PENDING
            || $order->fulfillment_status !== SalesOrder::FULFILLMENT_STATUS_UNFULFILLED
        ) {
            if ($order->order_status === SalesOrder::ORDER_STATUS_ON_HOLD) {
                session()->flash('error', __('sales_orders.release_hold_before_mark_ready')."\n".($order->platform_order_id ?: '#'.$order->id));

                return;
            }

            session()->flash('error', __('sales_orders.cannot_mark_ready'));

            return;
        }

        if (! $order->ship_together_key) {
            session()->flash('error', __('sales_orders.ready_requires_address'));

            return;
        }

        $readyLines = $order->lines->where('line_status', SalesOrderLine::STATUS_READY);

        if ($readyLines->isEmpty()) {
            session()->flash('error', __('sales_orders.ready_requires_shippable_line'));

            return;
        }

        foreach ($readyLines as $line) {
            if (! $this->isLineShippable($line, $order->tenant_id)) {
                session()->flash('error', __('sales_orders.ready_requires_shippable_line'));

                return;
            }
        }

        $order->update(['fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_READY]);
        session()->flash('status', __('sales_orders.marked_ready'));
    }

    public function unmarkReady(): void
    {
        $order = SalesOrder::query()
            ->whereIn('tenant_id', $this->allowedTenantIds())
            ->findOrFail($this->orderId);

        if (
            $order->order_status !== SalesOrder::ORDER_STATUS_PENDING
            || $order->fulfillment_status !== SalesOrder::FULFILLMENT_STATUS_READY
        ) {
            session()->flash('error', __('sales_orders.cannot_unmark_ready'));

            return;
        }

        $order->update(['fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_UNFULFILLED]);
        session()->flash('status', __('sales_orders.unmarked_ready'));
    }

    public function hold(): void
    {
        $order = SalesOrder::query()
            ->whereIn('tenant_id', $this->allowedTenantIds())
            ->with(['activeOutboundOrders.salesOrders:id'])
            ->findOrFail($this->orderId);

        if (
            $order->order_status !== SalesOrder::ORDER_STATUS_PENDING
            || ! $this->canPutOnHold($order)
        ) {
            session()->flash('error', __('sales_orders.cannot_hold'));

            return;
        }

        if ($order->fulfillment_status === SalesOrder::FULFILLMENT_STATUS_ARRANGED) {
            $outbound = $this->customerOutboundFor($order);

            if (! $outbound) {
                session()->flash('error', __('sales_orders.cannot_hold'));

                return;
            }

            if ($outbound->courier_csv_exported_at !== null) {
                session()->flash('error', __('outbound.hold_printed_sales_blocked'));

                return;
            }

            if ($outbound->salesOrders->count() > 1) {
                $this->showHoldChoicePrompt = true;

                return;
            }
        }

        try {
            if ($order->fulfillment_status === SalesOrder::FULFILLMENT_STATUS_ARRANGED) {
                $outbound = $this->customerOutboundFor($order);

                if (! $outbound) {
                    session()->flash('error', __('sales_orders.cannot_hold'));

                    return;
                }

                app(HoldOutboundOrderService::class)->holdOutbound($outbound, 'sales_order');
            } else {
                $order->update(['order_status' => SalesOrder::ORDER_STATUS_ON_HOLD]);
            }
        } catch (InvalidArgumentException $exception) {
            session()->flash('error', $exception->getMessage());

            return;
        }

        session()->flash('status', __('sales_orders.put_on_hold'));
    }

    public function holdWholeShipment(): void
    {
        $order = SalesOrder::query()
            ->whereIn('tenant_id', $this->allowedTenantIds())
            ->with(['activeOutboundOrders.salesOrders:id'])
            ->findOrFail($this->orderId);
        $outbound = $this->customerOutboundFor($order);

        if (! $outbound) {
            $this->showHoldChoicePrompt = false;
            session()->flash('error', __('sales_orders.cannot_hold'));

            return;
        }

        try {
            app(HoldOutboundOrderService::class)->holdOutbound($outbound, 'sales_order');
        } catch (InvalidArgumentException $exception) {
            session()->flash('error', $exception->getMessage());
            $this->showHoldChoicePrompt = false;

            return;
        }

        $this->showHoldChoicePrompt = false;
        session()->flash('status', __('sales_orders.put_on_hold'));
    }

    public function splitAndRebuildHeldShipment(): void
    {
        $order = SalesOrder::query()
            ->whereIn('tenant_id', $this->allowedTenantIds())
            ->findOrFail($this->orderId);

        try {
            app(HoldOutboundOrderService::class)->splitAndRebuildForSalesOrderHold($order);
        } catch (InvalidArgumentException $exception) {
            session()->flash('error', $exception->getMessage());
            $this->showHoldChoicePrompt = false;

            return;
        }

        $this->showHoldChoicePrompt = false;
        session()->flash('status', __('outbound.split_rebuild_done'));
    }

    public function cancelHoldChoice(): void
    {
        $this->showHoldChoicePrompt = false;
    }

    public function reship(): void
    {
        $this->authorizeInternalUser();

        $order = $this->reshipOrderQuery()->findOrFail($this->orderId);
        $shippedOutbounds = $order->outboundOrders
            ->filter(fn (OutboundOrder $outbound): bool => $outbound->status === OutboundOrder::STATUS_SHIPPED)
            ->values();

        if ($shippedOutbounds->isEmpty()) {
            session()->flash('error', __('outbound.reship_not_shipped'));

            return;
        }

        $selectedOutbound = $shippedOutbounds->count() === 1 ? $shippedOutbounds->first() : null;
        $this->reshipSourceOutboundId = $selectedOutbound ? (string) $selectedOutbound->id : '';
        $this->reshipWarehouseId = $selectedOutbound ? (string) $selectedOutbound->warehouse_id : '';
        $this->reshipReason = Issue::TYPE_MISSING;
        $this->reshipNote = '';
        $this->reshipLines = $order->lines
            ->map(fn (SalesOrderLine $line): array => [
                'sales_order_line_id' => (int) $line->id,
                'qty' => 0,
            ])
            ->all();
        $this->showReshipModal = true;
    }

    public function closeReshipModal(): void
    {
        $this->resetReshipModal();
    }

    public function confirmReship(ReshipSalesOrderService $service): void
    {
        $this->authorizeInternalUser();

        $order = $this->reshipOrderQuery()->findOrFail($this->orderId);
        $lineMax = $order->lines
            ->mapWithKeys(fn (SalesOrderLine $line): array => [(int) $line->id => (int) $line->quantity])
            ->all();

        $validator = validator([
            'reshipSourceOutboundId' => $this->reshipSourceOutboundId,
            'reshipWarehouseId' => $this->reshipWarehouseId,
            'reshipReason' => $this->reshipReason,
            'reshipNote' => $this->reshipNote,
            'reshipLines' => $this->reshipLines,
        ], [
            'reshipSourceOutboundId' => ['required', 'integer'],
            'reshipWarehouseId' => ['nullable', 'integer', Rule::exists('warehouses', 'id')->where('status', 'active')],
            'reshipReason' => ['required', Rule::in(array_keys(ReshipSalesOrderService::reshipReasonOptions()))],
            'reshipNote' => ['nullable', 'string', 'max:2000'],
            'reshipLines' => ['required', 'array'],
            'reshipLines.*.sales_order_line_id' => ['required', 'integer'],
            'reshipLines.*.qty' => ['nullable', 'integer', 'min:0'],
        ]);

        $validator->after(function ($validator) use ($lineMax): void {
            $selected = 0;
            $lineTotals = [];
            $lineFirstIndexes = [];

            foreach ($this->reshipLines as $index => $line) {
                $lineId = (int) ($line['sales_order_line_id'] ?? 0);
                $qty = (int) ($line['qty'] ?? 0);

                if ($qty <= 0) {
                    continue;
                }

                $selected++;

                if (! isset($lineMax[$lineId])) {
                    $validator->errors()->add("reshipLines.{$index}.qty", __('outbound.reship_qty_exceeds_line'));

                    continue;
                }

                $lineTotals[$lineId] = ($lineTotals[$lineId] ?? 0) + $qty;
                $lineFirstIndexes[$lineId] ??= $index;
            }

            foreach ($lineTotals as $lineId => $qty) {
                if ($qty > $lineMax[$lineId]) {
                    $validator->errors()->add("reshipLines.{$lineFirstIndexes[$lineId]}.qty", __('outbound.reship_qty_exceeds_line'));
                }
            }

            if ($selected === 0) {
                $validator->errors()->add('reshipLines', __('outbound.reship_no_lines_selected'));
            }
        });

        $validator->validate();

        $sourceOutbound = $order->outboundOrders->firstWhere('id', (int) $this->reshipSourceOutboundId);

        if (! $sourceOutbound) {
            throw ValidationException::withMessages(['reshipSourceOutboundId' => __('outbound.reship_not_shipped')]);
        }

        $service->reship(
            salesOrder: $order,
            originalOutbound: $sourceOutbound,
            warehouseId: $this->reshipWarehouseId === '' ? null : (int) $this->reshipWarehouseId,
            issueType: $this->reshipReason,
            lines: collect($this->reshipLines)
                ->map(fn (array $line): array => [
                    'sales_order_line_id' => (int) $line['sales_order_line_id'],
                    'qty' => (int) ($line['qty'] ?? 0),
                ])
                ->all(),
            note: $this->reshipNote,
        );

        $this->resetReshipModal();
        session()->flash('status', __('outbound.reship_done'));
    }

    public function releaseHold(): void
    {
        $order = SalesOrder::query()
            ->whereIn('tenant_id', $this->allowedTenantIds())
            ->with('customerOutboundOrders')
            ->findOrFail($this->orderId);

        if (
            $order->order_status !== SalesOrder::ORDER_STATUS_ON_HOLD
        ) {
            session()->flash('error', __('sales_orders.not_on_hold'));

            return;
        }

        $outbound = $this->customerOutboundFor($order);

        if ($outbound && $outbound->hold_status === OutboundOrder::HOLD_STATUS_ON_HOLD) {
            try {
                app(HoldOutboundOrderService::class)->releaseOutbound($outbound, 'sales_order');
            } catch (InvalidArgumentException $exception) {
                session()->flash('error', $exception->getMessage());

                return;
            }
        } elseif ($this->hasManualFulfillmentStatus($order)) {
            $order->update(['order_status' => SalesOrder::ORDER_STATUS_PENDING]);
        } else {
            session()->flash('error', __('sales_orders.not_on_hold'));

            return;
        }

        session()->flash('status', __('sales_orders.hold_released'));
    }

    public function markBackorder(): void
    {
        $order = SalesOrder::query()
            ->whereIn('tenant_id', $this->allowedTenantIds())
            ->findOrFail($this->orderId);

        if (
            $order->order_status !== SalesOrder::ORDER_STATUS_PENDING
            || ! $this->hasManualFulfillmentStatus($order)
        ) {
            session()->flash('error', __('sales_orders.cannot_backorder'));

            return;
        }

        $order->update([
            'order_status' => SalesOrder::ORDER_STATUS_BACKORDER,
            'fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_UNFULFILLED,
        ]);
        session()->flash('status', __('sales_orders.marked_backorder'));
    }

    public function releaseBackorder(): void
    {
        $order = SalesOrder::query()
            ->whereIn('tenant_id', $this->allowedTenantIds())
            ->findOrFail($this->orderId);

        if (
            $order->order_status !== SalesOrder::ORDER_STATUS_BACKORDER
            || ! $this->hasManualFulfillmentStatus($order)
        ) {
            session()->flash('error', __('sales_orders.not_on_backorder'));

            return;
        }

        $order->update(['order_status' => SalesOrder::ORDER_STATUS_PENDING]);
        session()->flash('status', __('sales_orders.backorder_released'));
    }

    public function editLines(): void
    {
        $order = $this->loadEditableOrder();

        $this->draftLines = $order->lines
            ->where('line_status', SalesOrderLine::STATUS_READY)
            ->values()
            ->map(fn (SalesOrderLine $line) => [
                'sku_id' => (string) $line->sku_id,
                'quantity' => $line->quantity,
                'note' => (string) $line->note,
            ])
            ->all();
        $skuLabels = Sku::query()
            ->whereIn('id', collect($this->draftLines)->pluck('sku_id')->filter()->map(fn ($id) => (int) $id))
            ->pluck('sku', 'id');

        $this->draftLineSkuSearches = collect($this->draftLines)
            ->map(fn (array $line) => (string) ($skuLabels[(int) $line['sku_id']] ?? ''))
            ->all();
        $this->editingLines = true;
    }

    public function addDraftLine(): void
    {
        $this->draftLines[] = ['sku_id' => '', 'quantity' => 1, 'note' => ''];
        $this->draftLineSkuSearches[] = '';
    }

    public function removeDraftLine(int $index): void
    {
        unset($this->draftLines[$index]);
        unset($this->draftLineSkuSearches[$index]);
        $this->draftLines = array_values($this->draftLines);
        $this->draftLineSkuSearches = array_values($this->draftLineSkuSearches);
    }

    public function cancelEditLines(): void
    {
        $this->editingLines = false;
        $this->draftLines = [];
        $this->draftLineSkuSearches = [];
    }

    public function updatedDraftLineSkuSearches(mixed $_value, mixed $key): void
    {
        $index = (int) $key;

        if (isset($this->draftLines[$index])) {
            $this->draftLines[$index]['sku_id'] = '';
        }
    }

    public function saveLines(): void
    {
        $order = $this->loadEditableOrder();
        $tenantId = $order->tenant_id;

        validator(['lines' => $this->draftLines], [
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.sku_id' => ['required', 'integer', Rule::exists('skus', 'id')->where('tenant_id', $tenantId)->where('status', 'active')],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:9999'],
            'lines.*.note' => ['nullable', 'string', 'max:500'],
        ])->validate();

        DB::transaction(function () use ($order) {
            $order->lines()->where('line_status', SalesOrderLine::STATUS_READY)->update([
                'line_status' => SalesOrderLine::STATUS_CANCELLED,
            ]);

            foreach ($this->draftLines as $draft) {
                $order->lines()->create([
                    'sku_id' => (int) $draft['sku_id'],
                    'quantity' => (int) $draft['quantity'],
                    'note' => $this->nullableString($draft['note'] ?? ''),
                    'line_status' => SalesOrderLine::STATUS_READY,
                ]);
            }

            if ($order->fulfillment_status === SalesOrder::FULFILLMENT_STATUS_READY) {
                $order->update(['fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_UNFULFILLED]);
            }
        });

        $this->editingLines = false;
        session()->flash('status', __('sales_orders.lines_updated'));
    }

    public function updateShippingMethod(string $value): void
    {
        $order = SalesOrder::query()
            ->whereIn('tenant_id', $this->allowedTenantIds())
            ->findOrFail($this->orderId);

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

        $order->update([
            'shipping_method_id' => $method?->id,
            'shipping_method' => $method?->carrier?->code,
        ]);

        session()->flash('status', __('sales_orders.shipping_method_updated'));
    }

    public function updateNote(string $value): void
    {
        $note = trim($value);
        $note = $note === '' ? null : mb_substr($note, 0, 2000);

        SalesOrder::query()
            ->whereIn('tenant_id', $this->allowedTenantIds())
            ->whereKey($this->orderId)
            ->update(['note' => $note]);

        session()->flash('status', __('sales_orders.note_updated'));
    }

    public function fulfillmentStatusLabel(string $status): string
    {
        return [
            SalesOrder::FULFILLMENT_STATUS_UNFULFILLED => __('sales_orders.fulfillment_unfulfilled'),
            SalesOrder::FULFILLMENT_STATUS_READY => __('sales_orders.fulfillment_ready'),
            SalesOrder::FULFILLMENT_STATUS_ARRANGED => __('sales_orders.fulfillment_arranged'),
            SalesOrder::FULFILLMENT_STATUS_SHIPPED => __('sales_orders.fulfillment_shipped'),
            SalesOrder::FULFILLMENT_STATUS_CANCELLED => __('sales_orders.fulfillment_cancelled'),
        ][$status] ?? $status;
    }

    public function orderStatusLabel(string $status): string
    {
        return [
            SalesOrder::ORDER_STATUS_PENDING => __('sales_orders.order_pending'),
            SalesOrder::ORDER_STATUS_ON_HOLD => __('sales_orders.order_on_hold'),
            SalesOrder::ORDER_STATUS_BACKORDER => __('sales_orders.order_backorder'),
            SalesOrder::ORDER_STATUS_CANCEL_REQUESTED => __('sales_orders.order_cancel_requested'),
            SalesOrder::ORDER_STATUS_CANCELLED => __('sales_orders.order_cancelled'),
            SalesOrder::ORDER_STATUS_COMPLETED => __('sales_orders.order_completed'),
        ][$status] ?? $status;
    }

    public function fulfillmentStatusColor(string $status): string
    {
        return SalesOrder::fulfillmentStatusColorFor($status);
    }

    public function orderStatusColor(string $status): string
    {
        return SalesOrder::orderStatusColorFor($status);
    }

    public function lineStatusLabel(string $status): string
    {
        return $status === SalesOrderLine::STATUS_CANCELLED
            ? __('sales_orders.line_cancelled')
            : __('sales_orders.line_ready');
    }

    public function render()
    {
        $order = SalesOrder::query()
            ->whereIn('tenant_id', $this->allowedTenantIds())
            ->with([
                'shop.tenant',
                'shippingMethod.carrier',
                'lines.sku.stockItem',
                'createdBy',
                'issues.lines',
                'activeOutboundOrders',
                'customerOutboundOrders',
                'outboundOrders.issue:id,issue_no',
                'outboundOrders.warehouse:id,code,name',
            ])
            ->findOrFail($this->orderId);

        $relatedOrders = collect();

        if ($order->ship_together_key) {
            $relatedOrders = SalesOrder::query()
                ->whereIn('tenant_id', $this->allowedTenantIds())
                ->where('ship_together_key', $order->ship_together_key)
                ->where('id', '!=', $order->id)
                ->whereIn('fulfillment_status', [
                    SalesOrder::FULFILLMENT_STATUS_UNFULFILLED,
                    SalesOrder::FULFILLMENT_STATUS_READY,
                ])
                ->with('shop:id,name,platform')
                ->orderBy('created_at')
                ->get();
        }

        $activities = $order->activities()->with('causer')->latest()->get();

        return view('livewire.sales-order-detail', [
            'order' => $order,
            'relatedOrders' => $relatedOrders,
            'activities' => $activities,
            'skuOptionsByLine' => $this->skuOptionsByLine($order->tenant_id),
            'shippingMethods' => $this->shippingMethodOptions($order),
            'shippedOutbounds' => $order->outboundOrders->where('status', OutboundOrder::STATUS_SHIPPED)->values(),
            'reshipReasonOptions' => ReshipSalesOrderService::reshipReasonOptions(),
            'reshipWarehouseOptions' => $this->warehouseOptions(),
            'canReship' => $this->isInternalUser() && $order->outboundOrders->contains(fn (OutboundOrder $outbound): bool => $outbound->status === OutboundOrder::STATUS_SHIPPED),
        ])->layout('inventory', [
            'title' => __('sales_orders.detail_page_title'),
            'subtitle' => $order->platform_order_id ?? "#{$order->id}",
        ]);
    }

    private function isInternalUser(): bool
    {
        $user = Auth::user();

        return $user?->user_type === 'internal';
    }

    private function authorizeInternalUser(): void
    {
        if (! $this->isInternalUser()) {
            abort(403);
        }
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

    private function loadEditableOrder(): SalesOrder
    {
        $order = SalesOrder::query()
            ->whereIn('tenant_id', $this->allowedTenantIds())
            ->with('lines.sku')
            ->findOrFail($this->orderId);

        if (
            ! in_array($order->order_status, [SalesOrder::ORDER_STATUS_PENDING, SalesOrder::ORDER_STATUS_ON_HOLD], true)
            || ! $this->hasManualFulfillmentStatus($order)
        ) {
            abort(403);
        }

        return $order;
    }

    private function skuOptionsByLine(int $tenantId): array
    {
        return collect($this->draftLines)
            ->keys()
            ->mapWithKeys(fn ($index) => [$index => $this->skuOptions($tenantId, (int) $index)])
            ->all();
    }

    private function skuOptions(int $tenantId, int $lineIndex)
    {
        $searchTerm = trim((string) ($this->draftLineSkuSearches[$lineIndex] ?? ''));
        $search = '%'.$searchTerm.'%';

        return Sku::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->when($searchTerm !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query
                        ->where('sku', 'like', $search)
                        ->orWhere('platform_sku', 'like', $search)
                        ->orWhere('platform_label_code', 'like', $search)
                        ->orWhereHas('stockItem', function ($query) use ($search): void {
                            $query
                                ->where('code', 'like', $search)
                                ->orWhere('name', 'like', $search)
                                ->orWhereHas('barcodeAliases', fn ($query) => $query
                                    ->where('is_active', true)
                                    ->where('barcode', 'like', $search));
                        });
                });
            })
            ->with('stockItem:id,code,name,short_name,name_en,name_ja,name_zh_tw,name_zh_cn')
            ->orderBy('sku')
            ->limit(50)
            ->get(['id', 'sku', 'platform_sku', 'platform_label_code', 'stock_item_id', 'sku_type']);
    }

    private function shippingMethodOptions(SalesOrder $order)
    {
        return ShippingMethod::query()
            ->where(function ($query) use ($order) {
                $query->where('shipping_methods.status', 'active')
                    ->when($order->shipping_method_id, fn ($query) => $query->orWhere('shipping_methods.id', $order->shipping_method_id));
            })
            ->with('carrier:id,code,name')
            ->ordered()
            ->get();
    }

    private function hasManualFulfillmentStatus(SalesOrder $order): bool
    {
        return in_array($order->fulfillment_status, [
            SalesOrder::FULFILLMENT_STATUS_UNFULFILLED,
            SalesOrder::FULFILLMENT_STATUS_READY,
        ], true);
    }

    private function canPutOnHold(SalesOrder $order): bool
    {
        if ($this->hasManualFulfillmentStatus($order)) {
            return true;
        }

        if ($order->fulfillment_status !== SalesOrder::FULFILLMENT_STATUS_ARRANGED) {
            return false;
        }

        return $order->customerOutboundOrders
            ->contains(fn ($outbound) => $outbound->reason === OutboundOrder::REASON_CUSTOMER_ORDER
                && $outbound->status === OutboundOrder::STATUS_RESERVED);
    }

    private function customerOutboundFor(SalesOrder $order): ?OutboundOrder
    {
        if (! $order->relationLoaded('customerOutboundOrders')) {
            $order->load('customerOutboundOrders.salesOrders:id');
        }

        $outbound = $order->customerOutboundOrders
            ->first(fn (OutboundOrder $outbound): bool => $outbound->reason === OutboundOrder::REASON_CUSTOMER_ORDER
                && $outbound->status === OutboundOrder::STATUS_RESERVED);

        return $outbound instanceof OutboundOrder ? $outbound : null;
    }

    private function reshipOrderQuery()
    {
        return SalesOrder::query()
            ->whereIn('tenant_id', $this->allowedTenantIds())
            ->with([
                'lines.sku.bundleComponents',
                'lines.sku.stockItem',
                'outboundOrders',
            ]);
    }

    private function warehouseOptions()
    {
        return Warehouse::query()
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'code', 'name']);
    }

    private function resetReshipModal(): void
    {
        $this->showReshipModal = false;
        $this->reshipSourceOutboundId = '';
        $this->reshipWarehouseId = '';
        $this->reshipReason = '';
        $this->reshipNote = '';
        $this->reshipLines = [];
    }

    private function isLineShippable(SalesOrderLine $line, int $tenantId): bool
    {
        $sku = $line->sku;

        if (! $sku) {
            return false;
        }

        if ($sku->stock_item_id !== null) {
            return true;
        }

        if ($sku->sku_type !== 'virtual_bundle' || $sku->bundleComponents->isEmpty()) {
            return false;
        }

        return $sku->bundleComponents->every(fn ($component) => $component->componentStockItem
            && $component->componentStockItem->tenant_id === $tenantId);
    }

    private function nullableString(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
