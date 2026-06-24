<div class="outbound-detail-page">
    <x-flash-toast />
<section class="table-shell flux-panel form-panel">
        <div class="form-panel-header">
            <div>
                <strong>{{ $order->ref ?: '-' }}</strong>
                <span>#{{ $order->id }} / {{ $order->created_at->format('Y-m-d H:i') }}</span>
            </div>
            <div class="active-filter-row">
                <flux:badge color="{{ $this->statusColor($order->status) }}">
                    {{ $this->statusLabel($order->status) }}
                </flux:badge>
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
                <span>{{ __('outbound.field_ship_mode') }}</span>
                <strong>{{ $order->shipModeLabel() ?? '-' }}</strong>
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

        @if ($order->status === \App\Models\OutboundOrder::STATUS_PENDING)
            <div class="form-actions outbound-detail-actions">
                <flux:button href="{{ route('outbound.ship', $order) }}" variant="primary" wire:navigate>
                    {{ __('outbound.btn_ship') }}
                </flux:button>
                <flux:button
                    type="button"
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
        </div>

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
    </section>

    <section class="table-shell flux-panel form-panel">
        <div class="form-panel-header">
            <div>
                <strong>{{ __('outbound.section_shipment') }}</strong>
            </div>
        </div>

        <div class="balance-preview-grid">
            <div>
                <span>{{ __('outbound.field_shipping_method') }}</span>
                <strong>{{ $order->shipping_method ?: '-' }}</strong>
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
                            <span class="subtle">{{ $line->sku->name ?: '-' }}</span>
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

    <style>
        .outbound-detail-actions {
            justify-content: space-between;
            margin-top: 0;
        }

        .outbound-child-line {
            padding-left: 18px;
        }
    </style>
</div>
