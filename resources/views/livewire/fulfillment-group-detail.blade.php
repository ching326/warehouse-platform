<div class="fulfillment-group-detail-page">
    @if (session('status'))
        <div class="active-filter-row">
            <flux:badge color="green">{{ session('status') }}</flux:badge>
        </div>
    @endif

    <section class="table-shell flux-panel form-panel">
        <div class="form-panel-header">
            <div>
                <strong>{{ $group->reference_no }}</strong>
                <span>{{ $group->tenant->code }} / {{ $group->warehouse->code }} / {{ $group->created_at->format('Y-m-d H:i') }}</span>
            </div>
            <div class="active-filter-row">
                <flux:badge color="{{ $this->statusColor($group->status) }}">
                    {{ $this->statusLabel($group->status) }}
                </flux:badge>
                @if ($group->outboundOrder && $group->outboundOrder->status === 'pending')
                    <flux:button href="{{ route('outbound.ship', $group->outboundOrder) }}" size="xs" variant="outline" wire:navigate>
                        {{ __('fulfillment_groups.btn_go_to_outbound') }}
                    </flux:button>
                @endif
            </div>
        </div>

        <div class="form-grid three">
            <div><span class="subtle">{{ __('fulfillment_groups.field_tenant') }}</span><strong>{{ $group->tenant->name }}</strong></div>
            <div><span class="subtle">{{ __('fulfillment_groups.field_warehouse') }}</span><strong>{{ $group->warehouse->name }}</strong></div>
            <div>
                <span class="subtle">{{ __('fulfillment_groups.section_outbound') }}</span>
                <strong>{{ $group->outboundOrder?->ref ?: '-' }}</strong>
                @if ($group->outboundOrder)
                    <flux:badge color="{{ $group->outboundOrder->status === 'shipped' ? 'green' : ($group->outboundOrder->status === 'cancelled' ? 'red' : 'amber') }}">
                        {{ __('outbound.status_'.$group->outboundOrder->status) }}
                    </flux:badge>
                @endif
            </div>
        </div>
    </section>

    <section class="table-shell flux-panel form-panel">
        <div class="form-panel-header">
            <div>
                <strong>{{ __('fulfillment_groups.section_recipient') }}</strong>
                <span>{{ $group->recipient_city ?: $group->recipient_postal_code ?: '-' }}</span>
            </div>
            @if (! $editingRecipient && $group->status === 'reserved')
                <flux:button type="button" variant="outline" wire:click="editRecipient">{{ __('fulfillment_groups.btn_edit') }}</flux:button>
            @endif
        </div>

        @if ($editingRecipient)
            <div class="form-grid three">
                <flux:input wire:model="recipientName" :label="__('fulfillment_groups.field_recipient_name')" />
                <flux:input wire:model="recipientPhone" :label="__('fulfillment_groups.field_recipient_phone')" />
                <flux:input wire:model="recipientCountryCode" maxlength="2" :label="__('fulfillment_groups.field_country_code')" />
                <flux:input wire:model="recipientPostalCode" :label="__('fulfillment_groups.field_postal_code')" />
                <flux:input wire:model="recipientState" :label="__('fulfillment_groups.field_state')" />
                <flux:input wire:model="recipientCity" :label="__('fulfillment_groups.field_city')" />
                <flux:input wire:model="recipientAddressLine1" :label="__('fulfillment_groups.field_address_line1')" />
                <flux:input wire:model="recipientAddressLine2" :label="__('fulfillment_groups.field_address_line2')" />
            </div>
            @foreach (['recipient_name', 'recipient_phone', 'recipient_country_code', 'recipient_postal_code', 'recipient_state', 'recipient_city', 'recipient_address_line1', 'recipient_address_line2'] as $field)
                @error($field) <p class="form-error">{{ $message }}</p> @enderror
            @endforeach
            <div class="form-actions">
                <flux:button type="button" variant="outline" wire:click="cancelEditRecipient">{{ __('fulfillment_groups.btn_cancel') }}</flux:button>
                <flux:button type="button" variant="primary" wire:click="saveRecipient">{{ __('fulfillment_groups.btn_save') }}</flux:button>
            </div>
        @else
            <div class="form-grid three">
                <div><span class="subtle">{{ __('fulfillment_groups.field_recipient_name') }}</span><strong>{{ $group->recipient_name ?: '-' }}</strong></div>
                <div><span class="subtle">{{ __('fulfillment_groups.field_recipient_phone') }}</span><strong>{{ $group->recipient_phone ?: '-' }}</strong></div>
                <div><span class="subtle">{{ __('fulfillment_groups.field_country_code') }}</span><strong>{{ $group->recipient_country_code ?: '-' }}</strong></div>
                <div><span class="subtle">{{ __('fulfillment_groups.field_postal_code') }}</span><strong>{{ $group->recipient_postal_code ?: '-' }}</strong></div>
                <div><span class="subtle">{{ __('fulfillment_groups.field_state') }}</span><strong>{{ $group->recipient_state ?: '-' }}</strong></div>
                <div><span class="subtle">{{ __('fulfillment_groups.field_city') }}</span><strong>{{ $group->recipient_city ?: '-' }}</strong></div>
                <div><span class="subtle">{{ __('fulfillment_groups.field_address_line1') }}</span><strong>{{ $group->recipient_address_line1 ?: '-' }}</strong></div>
                <div><span class="subtle">{{ __('fulfillment_groups.field_address_line2') }}</span><strong>{{ $group->recipient_address_line2 ?: '-' }}</strong></div>
            </div>
        @endif
    </section>

    <section class="table-shell flux-panel form-panel">
        <div class="form-panel-header">
            <div>
                <strong>{{ __('fulfillment_groups.section_shipping') }}</strong>
                <span>{{ $group->tracking_no ?: '-' }}</span>
            </div>
            @if (! $editingShipping && $group->status === 'reserved')
                <flux:button type="button" variant="outline" wire:click="editShipping">{{ __('fulfillment_groups.btn_edit') }}</flux:button>
            @endif
        </div>

        @if ($editingShipping)
            <div class="form-grid three">
                <flux:input wire:model="courier" :label="__('fulfillment_groups.field_courier')" />
                <flux:input wire:model="trackingNo" :label="__('fulfillment_groups.field_tracking_no')" />
                <label>
                    <span>{{ __('fulfillment_groups.field_note') }}</span>
                    <textarea wire:model="note" rows="3"></textarea>
                </label>
            </div>
            @foreach (['courier', 'tracking_no', 'note'] as $field)
                @error($field) <p class="form-error">{{ $message }}</p> @enderror
            @endforeach
            <div class="form-actions">
                <flux:button type="button" variant="outline" wire:click="cancelEditShipping">{{ __('fulfillment_groups.btn_cancel') }}</flux:button>
                <flux:button type="button" variant="primary" wire:click="saveShipping">{{ __('fulfillment_groups.btn_save') }}</flux:button>
            </div>
        @else
            <div class="form-grid three">
                <div><span class="subtle">{{ __('fulfillment_groups.field_courier') }}</span><strong>{{ $group->courier ?: '-' }}</strong></div>
                <div><span class="subtle">{{ __('fulfillment_groups.field_tracking_no') }}</span><strong>{{ $group->tracking_no ?: '-' }}</strong></div>
                <div><span class="subtle">{{ __('fulfillment_groups.field_note') }}</span><strong>{{ $group->note ?: __('common.no_note') }}</strong></div>
            </div>
        @endif
    </section>

    <section class="table-shell flux-panel form-panel">
        <div class="form-panel-header">
            <div>
                <strong>{{ __('fulfillment_groups.section_orders') }}</strong>
                <span>{{ trans_choice('fulfillment_groups.order_count', $group->orders->count(), ['count' => $group->orders->count()]) }}</span>
            </div>
        </div>

        <flux:table class="movement-table">
            <flux:table.columns>
                <flux:table.column>{{ __('sales_orders.col_platform_order_id') }}</flux:table.column>
                <flux:table.column>{{ __('sales_orders.col_recipient') }}</flux:table.column>
                <flux:table.column align="end">{{ __('sales_orders.col_qty') }}</flux:table.column>
                <flux:table.column>{{ __('sales_orders.col_fulfillment_status') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($group->orders as $order)
                    <flux:table.row :key="$order->id">
                        <flux:table.cell><strong>{{ $order->platform_order_id ?: '#'.$order->id }}</strong></flux:table.cell>
                        <flux:table.cell>
                            <strong>{{ $order->recipient_name ?: '-' }}</strong>
                            <span class="subtle">{{ $order->recipient_city ?: '-' }}</span>
                        </flux:table.cell>
                        <flux:table.cell align="end">{{ number_format($order->lines->count()) }}</flux:table.cell>
                        <flux:table.cell>{{ __('sales_orders.fulfillment_'.$order->fulfillment_status) }}</flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </section>

    <section class="table-shell flux-panel form-panel">
        <div class="form-panel-header">
            <div>
                <strong>{{ __('fulfillment_groups.section_lines') }}</strong>
                <span>{{ count($combinedLines) }} SKU</span>
            </div>
        </div>

        <flux:table class="movement-table">
            <flux:table.columns>
                <flux:table.column>{{ __('sales_orders.col_sku') }}</flux:table.column>
                <flux:table.column align="end">{{ __('sales_orders.col_qty') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($combinedLines as $line)
                    <flux:table.row :key="$line['sku']->id">
                        <flux:table.cell>
                            <strong>{{ $line['sku']->sku }}</strong>
                            <span class="subtle">{{ $line['sku']->name }} / {{ $line['stockItem']?->code ?? '-' }}</span>
                        </flux:table.cell>
                        <flux:table.cell align="end">{{ number_format($line['quantity']) }}</flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </section>

    <div class="form-actions">
        <flux:button href="{{ route('fulfillment-groups.index') }}" variant="outline" wire:navigate>
            {{ __('fulfillment_groups.btn_back') }}
        </flux:button>
    </div>
</div>
