<div class="outbound-ship-page">
    @if (session('error'))
        <div class="active-filter-row">
            <flux:badge color="red">{{ session('error') }}</flux:badge>
        </div>
    @endif

    <form wire:submit="save" class="sku-form">
        <section class="table-shell flux-panel form-panel">
            <div class="form-panel-header">
                <div>
                    <strong>{{ __('outbound.section_order_summary') }}</strong>
                </div>
                <flux:button href="{{ route('outbound.index') }}" variant="subtle" wire:navigate>{{ __('outbound.btn_back') }}</flux:button>
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
                    <strong>{{ __('outbound.section_shipment') }}</strong>
                    <span>{{ __('outbound.section_shipment_hint') }}</span>
                </div>
            </div>

            <div class="form-grid three">
                <flux:input wire:model="shippingMethod" :label="__('outbound.field_shipping_method')" />
                <flux:input wire:model="courier" :label="__('outbound.field_courier')" />
                <flux:input wire:model="trackingNo" :label="__('outbound.field_tracking_no')" />
                <flux:input wire:model="packageCount" type="number" min="1" step="1" :label="__('outbound.field_package_count')" />
                <flux:input wire:model="packageWeightG" type="number" min="1" step="1" :label="__('outbound.field_package_weight_g')" />
                <label class="form-grid-wide">
                    <span>{{ __('outbound.field_ship_note') }}</span>
                    <textarea wire:model="shipNote" rows="3"></textarea>
                </label>
            </div>

            @foreach (['shipping_method', 'courier', 'tracking_no', 'package_count', 'package_weight_g', 'ship_note'] as $field)
                @error($field) <p class="form-error">{{ $message }}</p> @enderror
            @endforeach
        </section>

        <div class="form-actions">
            <flux:button href="{{ route('outbound.index') }}" variant="subtle" wire:navigate>{{ __('outbound.btn_cancel') }}</flux:button>
            <flux:button type="submit" variant="primary">{{ __('outbound.btn_submit_ship') }}</flux:button>
        </div>
    </form>
</div>
