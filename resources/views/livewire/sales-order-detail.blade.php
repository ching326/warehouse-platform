<div class="sales-order-detail-page">
    @if (session('status'))
        <div class="active-filter-row">
            <flux:badge color="green">{{ session('status') }}</flux:badge>
        </div>
    @endif

    @if (session('error'))
        <div class="active-filter-row">
            <flux:badge color="red">{{ session('error') }}</flux:badge>
        </div>
    @endif

    <section class="table-shell flux-panel form-panel">
        <div class="form-panel-header">
            <div>
                <strong>{{ $order->platform_order_id ?: '#'.$order->id }}</strong>
                <span>{{ $order->shop->name }} / {{ $order->shop->platform }} / {{ $order->created_at->format('Y-m-d H:i') }}</span>
            </div>
            <div class="active-filter-row">
                <flux:badge color="{{ $this->orderStatusColor($order->order_status) }}">
                    {{ $this->orderStatusLabel($order->order_status) }}
                </flux:badge>
                <flux:badge color="{{ $this->fulfillmentStatusColor($order->fulfillment_status) }}">
                    {{ $this->fulfillmentStatusLabel($order->fulfillment_status) }}
                </flux:badge>
            </div>
        </div>

        <div class="form-grid three">
            <div>
                <span class="subtle">{{ __('common.tenant') }}</span>
                <strong>{{ $order->shop->tenant->code }}</strong>
            </div>
            <div>
                <span class="subtle">{{ __('sales_orders.field_source') }}</span>
                <strong>{{ __('sales_orders.source_'.$order->source) }}</strong>
            </div>
            <div>
                <span class="subtle">{{ __('sales_orders.field_platform_order_id') }}</span>
                <strong>{{ $order->platform_order_id ?: '-' }}</strong>
            </div>
        </div>
    </section>

    @if ($relatedOrders->isNotEmpty())
        <section class="table-shell flux-panel form-panel">
            <div class="form-panel-header">
                <div>
                    <strong>{{ __('sales_orders.related_orders_label') }}</strong>
                    <span>{{ $relatedOrders->count() }} related</span>
                </div>
            </div>
            <div class="active-filter-row">
                @foreach ($relatedOrders as $relatedOrder)
                    <flux:button href="{{ route('sales.orders.show', $relatedOrder) }}" size="xs" variant="outline" wire:navigate>
                        {{ $relatedOrder->platform_order_id ?: '#'.$relatedOrder->id }}
                    </flux:button>
                @endforeach
            </div>
        </section>
    @endif

    <section class="table-shell flux-panel form-panel">
        <div class="form-panel-header">
            <div>
                <strong>{{ __('sales_orders.field_recipient') }}</strong>
                <span>{{ __('sales_orders.related_orders_none') }}</span>
            </div>
            @if (! $editingRecipient)
                <flux:button type="button" variant="outline" wire:click="editRecipient">
                    {{ __('sales_orders.btn_edit_recipient') }}
                </flux:button>
            @endif
        </div>

        @if ($editingRecipient)
            <div class="form-grid three">
                <flux:input wire:model="editRecipientName" :label="__('sales_orders.field_recipient_name')" />
                <flux:input wire:model="editRecipientPhone" :label="__('sales_orders.field_recipient_phone')" />
                <flux:input wire:model="editRecipientCountryCode" maxlength="2" :label="__('sales_orders.field_country_code')" />
                <flux:input wire:model="editRecipientPostalCode" :label="__('sales_orders.field_postal_code')" />
                <flux:input wire:model="editRecipientState" :label="__('sales_orders.field_state')" />
                <flux:input wire:model="editRecipientCity" :label="__('sales_orders.field_city')" />
                <flux:input wire:model="editRecipientAddressLine1" :label="__('sales_orders.field_address_line1')" />
                <flux:input wire:model="editRecipientAddressLine2" :label="__('sales_orders.field_address_line2')" />
            </div>

            @foreach (['recipient_name', 'recipient_phone', 'recipient_country_code', 'recipient_postal_code', 'recipient_state', 'recipient_city', 'recipient_address_line1', 'recipient_address_line2'] as $field)
                @error($field) <p class="form-error">{{ $message }}</p> @enderror
            @endforeach

            <div class="form-actions">
                <flux:button type="button" variant="outline" wire:click="cancelEditRecipient">
                    {{ __('sales_orders.btn_cancel_edit') }}
                </flux:button>
                <flux:button type="button" variant="primary" wire:click="saveRecipient">
                    {{ __('sales_orders.btn_save_recipient') }}
                </flux:button>
            </div>
        @else
            <div class="form-grid three">
                <div><span class="subtle">{{ __('sales_orders.field_recipient_name') }}</span><strong>{{ $order->recipient_name ?: '-' }}</strong></div>
                <div><span class="subtle">{{ __('sales_orders.field_recipient_phone') }}</span><strong>{{ $order->recipient_phone ?: '-' }}</strong></div>
                <div><span class="subtle">{{ __('sales_orders.field_country_code') }}</span><strong>{{ $order->recipient_country_code ?: '-' }}</strong></div>
                <div><span class="subtle">{{ __('sales_orders.field_postal_code') }}</span><strong>{{ $order->recipient_postal_code ?: '-' }}</strong></div>
                <div><span class="subtle">{{ __('sales_orders.field_state') }}</span><strong>{{ $order->recipient_state ?: '-' }}</strong></div>
                <div><span class="subtle">{{ __('sales_orders.field_city') }}</span><strong>{{ $order->recipient_city ?: '-' }}</strong></div>
                <div><span class="subtle">{{ __('sales_orders.field_address_line1') }}</span><strong>{{ $order->recipient_address_line1 ?: '-' }}</strong></div>
                <div><span class="subtle">{{ __('sales_orders.field_address_line2') }}</span><strong>{{ $order->recipient_address_line2 ?: '-' }}</strong></div>
            </div>
        @endif
    </section>

    <section class="table-shell flux-panel form-panel">
        <flux:table class="movement-table">
            <flux:table.columns>
                <flux:table.column>{{ __('sales_orders.col_sku') }}</flux:table.column>
                <flux:table.column align="end">{{ __('sales_orders.col_qty') }}</flux:table.column>
                <flux:table.column>{{ __('sales_orders.col_line_status') }}</flux:table.column>
                <flux:table.column>{{ __('sales_orders.field_note') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($order->lines as $line)
                    <flux:table.row :key="$line->id">
                        <flux:table.cell>
                            <strong>{{ $line->sku->sku }}</strong>
                            <span class="subtle">{{ $line->sku->name }} / {{ $line->sku->stockItem?->code ?? __('common.sku_types.virtual_bundle') }}</span>
                        </flux:table.cell>
                        <flux:table.cell align="end">{{ number_format($line->quantity) }}</flux:table.cell>
                        <flux:table.cell>
                            <flux:badge color="{{ $line->line_status === 'cancelled' ? 'red' : 'blue' }}">
                                {{ $this->lineStatusLabel($line->line_status) }}
                            </flux:badge>
                        </flux:table.cell>
                        <flux:table.cell>{{ $line->note ?: '-' }}</flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="4">{{ __('sales_orders.no_lines') }}</flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </section>

    <section class="table-shell flux-panel form-panel">
        <div class="form-grid">
            <div>
                <span class="subtle">{{ __('sales_orders.field_note') }}</span>
                <strong>{{ $order->note ?: __('common.no_note') }}</strong>
            </div>
            <div>
                <span class="subtle">{{ __('sales_orders.field_source') }}</span>
                <strong>{{ $order->createdBy?->name ?: '-' }}</strong>
            </div>
        </div>
    </section>

    @if ($activities->isNotEmpty())
        <section class="table-shell flux-panel form-panel">
            <div class="form-panel-header">
                <div>
                    <strong>Activity</strong>
                    <span>{{ $activities->count() }} entries</span>
                </div>
            </div>
            @foreach ($activities as $activity)
                <p class="subtle">{{ $activity->created_at->format('Y-m-d H:i') }} - {{ $activity->description }} - {{ $activity->causer?->name ?? '-' }}</p>
            @endforeach
        </section>
    @endif

    <div class="form-actions">
        <flux:button href="{{ route('sales.orders.index') }}" variant="outline" wire:navigate>
            {{ __('sales_orders.btn_back_orders') }}
        </flux:button>
        @if ($order->order_status === 'pending' && in_array($order->fulfillment_status, ['unfulfilled', 'ready'], true))
            <flux:button type="button" variant="danger" wire:click="cancelOrder" wire:confirm="{{ __('sales_orders.btn_cancel_order') }}?">
                {{ __('sales_orders.btn_cancel_order') }}
            </flux:button>
        @endif
    </div>
</div>
