<div class="outbound-detail-page">
    <x-flash-toast />

    @if ($pendingExportWarning)
        <div class="app-toast app-toast-warning app-toast-confirm" role="alert">
            <div class="app-toast-body">
                <strong class="app-toast-title">{{ __('common.toast.warning') }}</strong>
                <span class="app-toast-text">{{ $pendingExportWarning }}</span>
            @if ($pendingCourierExportCarrier)
                <div class="app-toast-actions">
                    <flux:button type="button" size="sm" variant="outline" wire:click="cancelCourierExport">
                        {{ __('common.cancel') }}
                    </flux:button>
                    <flux:button type="button" size="sm" variant="primary" wire:click="confirmCourierExport">
                        {{ __('fulfillment.courier_export_confirm_btn') }}
                    </flux:button>
                </div>
            @endif
            </div>
        </div>
    @endif

    @if ($pendingPrintedHoldConfirmation)
        <div class="app-toast app-toast-warning app-toast-confirm" role="alert">
            <div class="app-toast-body">
                <strong class="app-toast-title">{{ __('common.toast.warning') }}</strong>
                <span class="app-toast-text">{{ $pendingHoldWarning }}</span>
                <div class="app-toast-actions">
                    <flux:button type="button" size="sm" variant="outline" wire:click="cancelPrintedHold">
                        {{ __('common.cancel') }}
                    </flux:button>
                    <flux:button type="button" size="sm" variant="primary" wire:click="confirmPrintedHold">
                        {{ __('outbound.hold') }}
                    </flux:button>
                </div>
            </div>
        </div>
    @endif

<section class="table-shell flux-panel form-panel">
        <div class="form-panel-header">
            <div>
                <strong>{{ $order->ref ?: '-' }}</strong>
                <span>#{{ $order->id }} / {{ $order->created_at->format('Y-m-d H:i') }}</span>
            </div>
            <div class="active-filter-row">
                <x-status-badge :status="$order->status" :label="$this->statusLabel($order->status)" />
                @if ($order->hold_status === \App\Models\OutboundOrder::HOLD_STATUS_ON_HOLD)
                    <flux:badge color="amber">{{ __('outbound.on_hold') }}</flux:badge>
                @endif
                <flux:button href="{{ route('outbound.index') }}" variant="outline" wire:navigate>
                    {{ __('outbound.btn_back_to_index') }}
                </flux:button>
            </div>
        </div>

        <div class="balance-preview-grid">
            <div>
                <span>{{ __('outbound.field_tenant') }}</span>
                <strong>{{ $order->tenant->code }} - {{ $order->tenant->name }}</strong>
            </div>
            <div>
                <span>{{ __('outbound.field_warehouse') }}</span>
                <strong>{{ $order->warehouse->code }} - {{ $order->warehouse->name }}</strong>
            </div>
            <div>
                <span>{{ __('outbound.field_reason') }}</span>
                <strong>{{ $order->reasonLabel() ?? '-' }}</strong>
            </div>
            <div>
                <span>{{ __('outbound.col_shipped_at') }}</span>
                <strong>{{ $order->shipped_at ? $order->shipped_at->format('Y-m-d H:i') : '-' }}</strong>
            </div>
            <div>
                <span>{{ __('outbound.field_note') }}</span>
                <strong>{{ $order->note ?: '-' }}</strong>
            </div>
            <div>
                <span>{{ __('outbound.created_at') }}</span>
                <strong>{{ $order->created_at->format('Y-m-d H:i') }}</strong>
            </div>
            <div>
                <span>{{ __('outbound.created_by') }}</span>
                <strong>{{ $order->createdBy?->name ?: '-' }}</strong>
            </div>
        </div>
    </section>

    <section class="table-shell flux-panel form-panel">
        <div class="form-panel-header">
            <div>
                <strong>{{ __('outbound.section_actions') }}</strong>
                <span>{{ __('outbound.detail_page_subtitle') }}</span>
            </div>
        </div>

        @if ($order->status === \App\Models\OutboundOrder::STATUS_RESERVED && $order->hold_status === \App\Models\OutboundOrder::HOLD_STATUS_ACTIVE)
            <div class="form-actions outbound-detail-actions">
                @if ($order->reason === \App\Models\OutboundOrder::REASON_CUSTOMER_ORDER)
                    <flux:button class="action-button-md" href="{{ route('outbound.pack', $order) }}" size="sm" variant="primary" wire:navigate>
                        {{ __('fulfillment_pack.page_title') }}
                    </flux:button>
                @endif
                <flux:button class="action-button-md" href="{{ route('outbound.ship', $order) }}" size="sm" variant="primary" wire:navigate>
                    {{ __('outbound.btn_direct_pack') }}
                </flux:button>
                <flux:button class="action-button-md" type="button" size="sm" variant="primary" wire:click="exportYamato">
                    {{ __('fulfillment.batch_export_yamato') }}
                </flux:button>
                <flux:button class="action-button-md" type="button" size="sm" variant="primary" wire:click="exportSagawa">
                    {{ __('fulfillment.batch_export_sagawa') }}
                </flux:button>
                <flux:button class="action-button-md outbound-hold-action" type="button" size="sm" variant="primary" wire:click="holdOutbound">
                    {{ __('outbound.hold') }}
                </flux:button>
                <flux:button
                    class="action-button-md outbound-cancel-action"
                    type="button"
                    size="sm"
                    variant="danger"
                    wire:click="cancel"
                    wire:confirm="{{ __('outbound.confirm_cancel') }}"
                >
                    {{ __('outbound.btn_cancel_order') }}
                </flux:button>
            </div>
        @elseif ($order->hold_status === \App\Models\OutboundOrder::HOLD_STATUS_ON_HOLD)
            <div class="form-actions outbound-detail-actions">
                <flux:button class="action-button-md outbound-hold-action" type="button" size="sm" variant="primary" wire:click="releaseHold">
                    {{ __('outbound.release_hold') }}
                </flux:button>
                <flux:button
                    class="action-button-md outbound-cancel-action"
                    type="button"
                    size="sm"
                    variant="danger"
                    wire:click="cancel"
                    wire:confirm="{{ __('outbound.confirm_cancel') }}"
                >
                    {{ __('outbound.btn_cancel_order') }}
                </flux:button>
            </div>
        @else
            <span class="muted-dash">-</span>
        @endif
    </section>

    <section class="table-shell flux-panel form-panel">
        <div class="form-panel-header">
            <div>
                <strong>{{ __('outbound.section_recipient') }}</strong>
            </div>
            @if (! $editingRecipient && $order->status === \App\Models\OutboundOrder::STATUS_RESERVED)
                <flux:button type="button" variant="outline" wire:click="editRecipient">{{ __('fulfillment.btn_edit') }}</flux:button>
            @endif
        </div>

        @if ($editingRecipient)
            <div class="form-grid three">
                <flux:input wire:model="recipientName" :label="__('outbound.field_recipient_name')" />
                <flux:input wire:model="recipientPhone" :label="__('outbound.field_recipient_phone')" />
                <flux:input wire:model="recipientCountryCode" maxlength="2" :label="__('outbound.field_country_code')" />
                <flux:input wire:model="recipientPostalCode" :label="__('outbound.field_postal_code')" />
                <flux:input wire:model="recipientState" :label="__('outbound.field_state')" />
                <flux:input wire:model="recipientCity" :label="__('outbound.field_city')" />
                <flux:input wire:model="recipientAddressLine1" :label="__('outbound.field_address_line1')" />
                <flux:input wire:model="recipientAddressLine2" :label="__('outbound.field_address_line2')" />
            </div>
            @foreach (['recipient_name', 'recipient_phone', 'recipient_country_code', 'recipient_postal_code', 'recipient_state', 'recipient_city', 'recipient_address_line1', 'recipient_address_line2'] as $field)
                @error($field) <p class="form-error">{{ $message }}</p> @enderror
            @endforeach
            <div class="form-actions">
                <flux:button type="button" variant="outline" wire:click="cancelEditRecipient">{{ __('fulfillment.btn_cancel') }}</flux:button>
                <flux:button type="button" variant="primary" wire:click="saveRecipient">{{ __('fulfillment.btn_save') }}</flux:button>
            </div>
        @else
            <div class="balance-preview-grid">
                <div>
                    <span>{{ __('outbound.field_recipient_name') }}</span>
                    <strong>{{ $order->recipient_name ?: '-' }}</strong>
                </div>
                <div>
                    <span>{{ __('outbound.field_recipient_phone') }}</span>
                    <strong>{{ $order->recipient_phone ?: '-' }}</strong>
                </div>
                <div>
                    <span>{{ __('outbound.field_country_code') }}</span>
                    <strong>{{ $order->recipient_country_code ?: '-' }}</strong>
                </div>
                <div>
                    <span>{{ __('outbound.field_postal_code') }}</span>
                    <strong>{{ $order->recipient_postal_code ?: '-' }}</strong>
                </div>
                <div>
                    <span>{{ __('outbound.field_state') }}</span>
                    <strong>{{ $order->recipient_state ?: '-' }}</strong>
                </div>
                <div>
                    <span>{{ __('outbound.field_city') }}</span>
                    <strong>{{ $order->recipient_city ?: '-' }}</strong>
                </div>
                <div>
                    <span>{{ __('outbound.field_address_line1') }}</span>
                    <strong>{{ $order->recipient_address_line1 ?: '-' }}</strong>
                </div>
                <div>
                    <span>{{ __('outbound.field_address_line2') }}</span>
                    <strong>{{ $order->recipient_address_line2 ?: '-' }}</strong>
                </div>
            </div>
        @endif
    </section>

    <section class="table-shell flux-panel form-panel">
        <div class="form-panel-header">
            <div>
                <strong>{{ __('outbound.section_shipment') }}</strong>
            </div>
            @if (! $editingShipping && $order->status === \App\Models\OutboundOrder::STATUS_RESERVED)
                <flux:button type="button" variant="outline" wire:click="editShipping">{{ __('fulfillment.btn_edit') }}</flux:button>
            @endif
        </div>

        @if ($editingShipping)
            <div class="form-grid three">
                <flux:select wire:model="shippingMethodId" :label="__('outbound.field_shipping_method')">
                    <flux:select.option value="">{{ __('sales_orders.shipping_method_unset') }}</flux:select.option>
                    @foreach ($shippingMethods as $method)
                        <flux:select.option value="{{ $method->id }}">
                            {{ $method->name }} / {{ $method->carrier->name }}
                        </flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="courier" :label="__('outbound.field_courier')" />
                <flux:input wire:model="trackingNo" :label="__('outbound.field_tracking_no')" />
                <label>
                    <span>{{ __('outbound.field_note') }}</span>
                    <textarea wire:model="note" rows="3"></textarea>
                </label>
            </div>
            @foreach (['shipping_method_id', 'tracking_no', 'note'] as $field)
                @error($field) <p class="form-error">{{ $message }}</p> @enderror
            @endforeach
            <div class="form-actions">
                <flux:button type="button" variant="outline" wire:click="cancelEditShipping">{{ __('fulfillment.btn_cancel') }}</flux:button>
                <flux:button type="button" variant="primary" wire:click="saveShipping">{{ __('fulfillment.btn_save') }}</flux:button>
            </div>
        @else
        <div class="balance-preview-grid">
            <div>
                <span>{{ __('outbound.field_shipping_method') }}</span>
                <strong>{{ $order->shippingMethod?->name ?: '-' }}</strong>
            </div>
            <div>
                <span>{{ __('outbound.field_courier') }}</span>
                <strong>{{ $order->courier ?: '-' }}</strong>
            </div>
            <div>
                <span>{{ __('outbound.field_tracking_no') }}</span>
                <strong>{{ $order->tracking_no ?: '-' }}</strong>
            </div>
            <div>
                <span>{{ __('outbound.field_package_count') }}</span>
                <strong>{{ $order->package_count !== null ? number_format($order->package_count) : '-' }}</strong>
            </div>
            <div>
                <span>{{ __('outbound.field_package_weight_g') }}</span>
                <strong>{{ $order->package_weight_g !== null ? number_format($order->package_weight_g) : '-' }}</strong>
            </div>
            <div>
                <span>{{ __('outbound.shipped_at') }}</span>
                <strong>{{ $order->shipped_at ? $order->shipped_at->format('Y-m-d H:i') : '-' }}</strong>
            </div>
            <div>
                <span>{{ __('outbound.shipped_by') }}</span>
                <strong>{{ $order->shippedBy?->name ?: '-' }}</strong>
            </div>
            <div>
                <span>{{ __('outbound.field_ship_note') }}</span>
                <strong>{{ $order->ship_note ?: '-' }}</strong>
            </div>
        </div>
        @endif
    </section>

    <section class="table-shell flux-panel form-panel">
        <div class="form-panel-header">
            <div>
                <strong>{{ __('outbound.section_lines') }}</strong>
            </div>
        </div>

        <flux:table class="data-table">
            <flux:table.columns>
                <flux:table.column>{{ __('outbound.field_sku') }}</flux:table.column>
                <flux:table.column>{{ __('outbound.stock_item') }}</flux:table.column>
                <flux:table.column>{{ __('outbound.field_qty') }}</flux:table.column>
                <flux:table.column>{{ __('outbound.field_line_note') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($order->parentLines as $line)
                    <flux:table.row :key="'parent-'.$line->id">
                        <flux:table.cell>
                            <strong>{{ $line->sku->sku }}</strong>
                            <span class="subtle">{{ $line->sku->displayName() ?: '-' }}</span>
                        </flux:table.cell>
                        <flux:table.cell>
                            @if ($line->stockItem)
                                <strong>{{ $line->stockItem->code }}</strong>
                                <span class="subtle">{{ $line->stockItem->name }}</span>
                            @else
                                <strong>{{ __('outbound.bundle_components_label') }}</strong>
                                <span class="subtle">{{ __('outbound.virtual_bundle') }}</span>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>{{ number_format($line->qty) }}</flux:table.cell>
                        <flux:table.cell>{{ $line->note ?: '-' }}</flux:table.cell>
                    </flux:table.row>

                    @foreach ($line->childLines as $childLine)
                        <flux:table.row :key="'child-'.$childLine->id">
                            <flux:table.cell>
                                <span class="subtle outbound-child-line">{{ __('outbound.bundle_components_label') }}</span>
                            </flux:table.cell>
                            <flux:table.cell>
                                <strong class="outbound-child-line">{{ $childLine->stockItem->code }}</strong>
                                <span class="subtle">{{ $childLine->stockItem->name }}</span>
                            </flux:table.cell>
                            <flux:table.cell>{{ number_format($childLine->qty) }}</flux:table.cell>
                            <flux:table.cell>{{ $childLine->note ?: '-' }}</flux:table.cell>
                        </flux:table.row>
                    @endforeach
                @endforeach
            </flux:table.rows>
        </flux:table>
    </section>

    @if ($order->salesOrders->isNotEmpty())
        <section class="table-shell flux-panel form-panel">
            <div class="form-panel-header">
                <div>
                    <strong>{{ __('fulfillment.section_orders') }}</strong>
                    <span>{{ trans_choice('fulfillment.order_count', $order->salesOrders->count(), ['count' => $order->salesOrders->count()]) }}</span>
                </div>
            </div>

            <flux:table class="data-table">
                <flux:table.columns>
                    <flux:table.column>{{ __('sales_orders.col_platform_order_id') }}</flux:table.column>
                    <flux:table.column>{{ __('sales_orders.col_recipient') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('sales_orders.col_qty') }}</flux:table.column>
                    <flux:table.column>{{ __('sales_orders.col_fulfillment_status') }}</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($order->salesOrders as $salesOrder)
                        <flux:table.row :key="$salesOrder->id">
                            <flux:table.cell><strong>{{ $salesOrder->platform_order_id ?: '#'.$salesOrder->id }}</strong></flux:table.cell>
                            <flux:table.cell>
                                <strong>{{ $salesOrder->recipient_name ?: '-' }}</strong>
                                <span class="subtle">{{ $salesOrder->recipient_city ?: '-' }}</span>
                            </flux:table.cell>
                            <flux:table.cell align="end">{{ number_format($salesOrder->lines->count()) }}</flux:table.cell>
                            <flux:table.cell>{{ __('sales_orders.fulfillment_'.$salesOrder->fulfillment_status) }}</flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </section>
    @endif

    @if ($order->packScans->isNotEmpty())
        <section class="table-shell flux-panel form-panel">
            <div class="form-panel-header">
                <div>
                    <strong>{{ __('fulfillment_pack.scan_history_title') }}</strong>
                    <span>{{ __('fulfillment_pack.scan_history_recent_hint') }}</span>
                </div>
                <flux:button href="{{ route('fulfillment.pack-scans.index', ['outbound_order_id' => $order->id]) }}" variant="primary" wire:navigate>
                    {{ __('fulfillment_pack.view_all_scan_history') }}
                </flux:button>
            </div>

            <flux:table class="data-table">
                <flux:table.columns>
                    <flux:table.column>{{ __('fulfillment_pack.scan_time') }}</flux:table.column>
                    <flux:table.column>{{ __('fulfillment_pack.scan_result') }}</flux:table.column>
                    <flux:table.column>{{ __('fulfillment_pack.barcode') }}</flux:table.column>
                    <flux:table.column>{{ __('fulfillment_pack.matched_item') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('fulfillment_pack.qty') }}</flux:table.column>
                    <flux:table.column>{{ __('fulfillment_pack.scanned_by') }}</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($order->packScans as $scan)
                        <flux:table.row :key="$scan->id">
                            <flux:table.cell>{{ $scan->created_at?->format('Y-m-d H:i:s') ?: '-' }}</flux:table.cell>
                            <flux:table.cell>
                                <flux:badge color="{{ $scan->result === 'accepted' ? 'green' : (in_array($scan->result, ['wrong_item', 'over_scan'], true) ? 'red' : 'amber') }}">
                                    {{ __('fulfillment_pack.scan_result_'.$scan->result) }}
                                </flux:badge>
                            </flux:table.cell>
                            <flux:table.cell>{{ $scan->barcode_scanned }}</flux:table.cell>
                            <flux:table.cell>
                                <strong>{{ $scan->sku?->sku ?? $scan->stockItem?->code ?? '-' }}</strong>
                                @if ($scan->stockItem)
                                    <span class="subtle">{{ $scan->stockItem->code }} / {{ $scan->stockItem->displayName() }}</span>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell align="end">{{ number_format($scan->quantity) }}</flux:table.cell>
                            <flux:table.cell>{{ $scan->scannedBy?->name ?: '-' }}</flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </section>
    @endif

    <style>
        .outbound-detail-actions {
            justify-content: flex-start;
            margin-top: 0;
        }

        .outbound-detail-actions .outbound-hold-action {
            margin-left: auto;
        }

        .outbound-child-line {
            padding-left: 18px;
        }
    </style>
</div>
