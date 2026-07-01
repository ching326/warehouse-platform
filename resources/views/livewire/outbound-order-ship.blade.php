<div class="outbound-ship-page">
    <x-flash-toast />

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

<form wire:submit="save" class="sku-form">
        <section class="table-shell flux-panel form-panel">
            <div class="form-panel-header">
                <div>
                    <strong>{{ __('outbound.section_order_summary') }}</strong>
                </div>
                <flux:button href="{{ route('outbound.show', $order) }}" variant="outline" wire:navigate>{{ __('outbound.btn_back_to_detail') }}</flux:button>
            </div>

            <div class="balance-preview-grid">
                <div>
                    <span>{{ __('outbound.field_tenant') }}</span>
                    <strong>{{ $order->tenant->code }}</strong>
                </div>
                <div>
                    <span>{{ __('outbound.field_warehouse') }}</span>
                    <strong>{{ $order->warehouse->code }}</strong>
                </div>
                <div>
                    <span>{{ __('outbound.field_ref') }}</span>
                    <strong>{{ $order->ref ?: '-' }}</strong>
                </div>
                <div>
                    <span>{{ __('outbound.field_recipient_name') }}</span>
                    <strong>{{ $order->recipient_name ?: '-' }}</strong>
                </div>
                <div>
                    <span>{{ __('outbound.field_address_line1') }}</span>
                    <strong>{{ $order->recipient_address_line1 ?: '-' }}</strong>
                </div>
            </div>

            <div class="receive-line-panel">
                @foreach ($order->parentLines as $line)
                    <div>
                        <strong>{{ $line->sku->sku }} x{{ number_format($line->qty) }}</strong>
                        @if ($line->stockItem)
                            <span class="subtle">{{ $line->stockItem->code }} - {{ $line->stockItem->name }}</span>
                        @else
                            <span class="subtle">{{ __('outbound.bundle_components_label') }}</span>
                            @foreach ($line->childLines as $childLine)
                                <span class="subtle">{{ $childLine->stockItem->code }} - {{ $childLine->stockItem->name }} x{{ number_format($childLine->qty) }}</span>
                            @endforeach
                        @endif
                    </div>
                @endforeach
            </div>
        </section>

        <section class="table-shell flux-panel form-panel">
            <div class="form-panel-header">
                <div>
                    <strong>{{ __('outbound.section_actions') }}</strong>
                    @if ($order->hold_status === \App\Models\OutboundOrder::HOLD_STATUS_ON_HOLD)
                        <span>{{ __('outbound.cannot_ship_on_hold') }}</span>
                    @else
                        <span>{{ __('outbound.section_shipment_hint') }}</span>
                    @endif
                </div>
            </div>

            <div class="form-actions outbound-ship-actions">
                @if ($order->status === \App\Models\OutboundOrder::STATUS_RESERVED && $order->hold_status !== \App\Models\OutboundOrder::HOLD_STATUS_ON_HOLD)
                    <flux:button class="action-button-md" type="submit" size="sm" variant="primary">
                        {{ __('fulfillment_pack.mark_shipped') }}
                    </flux:button>
                @endif

                <div class="outbound-ship-actions-right">
                    @if ($order->status === \App\Models\OutboundOrder::STATUS_RESERVED && $order->hold_status !== \App\Models\OutboundOrder::HOLD_STATUS_ON_HOLD && $order->reason === \App\Models\OutboundOrder::REASON_CUSTOMER_ORDER)
                        <flux:button class="action-button-md" href="{{ route('outbound.pack', $order) }}" size="sm" variant="primary" wire:navigate>
                            {{ __('fulfillment_pack.page_title') }}
                        </flux:button>
                    @endif

                    @if ($order->status === \App\Models\OutboundOrder::STATUS_RESERVED && $order->hold_status === \App\Models\OutboundOrder::HOLD_STATUS_ACTIVE)
                        <flux:button class="action-button-md" type="button" size="sm" variant="primary" wire:click="holdOutbound">
                            {{ __('outbound.hold') }}
                        </flux:button>
                    @elseif ($order->status === \App\Models\OutboundOrder::STATUS_RESERVED && $order->hold_status === \App\Models\OutboundOrder::HOLD_STATUS_ON_HOLD)
                        <flux:button class="action-button-md" type="button" size="sm" variant="primary" wire:click="releaseHold">
                            {{ __('outbound.release_hold') }}
                        </flux:button>
                    @endif
                </div>
            </div>
        </section>

        <section class="table-shell flux-panel form-panel">
            <div class="form-panel-header">
                <div>
                    <strong>{{ __('outbound.section_shipment') }}</strong>
                    <span>{{ __('outbound.section_shipment_hint') }}</span>
                </div>
            </div>

            <div class="form-grid three">
                <flux:select wire:model="shippingMethodId" :label="__('outbound.field_shipping_method')">
                    <flux:select.option value="">{{ __('sales_orders.shipping_method_unset') }}</flux:select.option>
                    @foreach ($shippingMethods as $method)
                        <flux:select.option value="{{ $method->id }}">
                            {{ $method->name }}
                            @if ($method->status !== 'active')
                                ({{ __('shipping.status_inactive') }})
                            @endif
                        </flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="trackingNo" :label="__('outbound.field_tracking_no')" />
                <flux:input wire:model="packageCount" type="number" min="1" step="1" :label="__('outbound.field_package_count')" />
                <flux:input wire:model="packageWeightKg" type="number" min="0" step="0.01" :label="__('outbound.field_package_weight_kg')" />
                <flux:input wire:model="courierCost" type="number" min="0" step="0.01" :label="__('outbound.field_courier_cost')" />
                <flux:input wire:model="courierCostCurrency" maxlength="3" :label="__('outbound.field_courier_cost_currency')" />
                <label class="form-grid-wide">
                    <span>{{ __('outbound.field_ship_note') }}</span>
                    <textarea wire:model="shipNote" rows="3"></textarea>
                </label>
            </div>

            @foreach (['shipping_method_id', 'tracking_no', 'package_count', 'package_weight_kg', 'ship_note'] as $field)
                @error($field) <p class="form-error">{{ $message }}</p> @enderror
            @endforeach
        </section>

    </form>

    <style>
        .outbound-ship-actions {
            justify-content: flex-start;
            margin-top: 0;
        }

        .outbound-ship-actions-right {
            display: inline-flex;
            gap: 8px;
            margin-left: auto;
        }
    </style>
</div>
