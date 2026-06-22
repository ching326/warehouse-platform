<div class="fulfillment-group-pack-page">
    <x-flash-toast />

    <section class="table-shell flux-panel form-panel">
        <div class="form-panel-header">
            <div>
                <strong>{{ $group->reference_no }}</strong>
                <span>{{ $group->tenant->code }} / {{ $group->recipient_name ?: '-' }}</span>
            </div>
            <div class="active-filter-row">
                <flux:badge color="{{ $group->status === 'shipped' ? 'green' : ($group->status === 'cancelled' ? 'red' : 'blue') }}">
                    {{ __('fulfillment_groups.status_'.$group->status) }}
                </flux:badge>
                <flux:button href="{{ route('fulfillment-groups.show', $group) }}" variant="outline" wire:navigate>
                    {{ __('fulfillment_groups.btn_back') }}
                </flux:button>
            </div>
        </div>

        <div class="form-grid three">
            <div><span class="subtle">{{ __('fulfillment_groups.field_recipient_name') }}</span><strong>{{ $group->recipient_name ?: '-' }}</strong></div>
            <div><span class="subtle">{{ __('fulfillment_groups.field_tracking_no') }}</span><strong>{{ $group->tracking_no ?: $group->outboundOrder?->tracking_no ?: '-' }}</strong></div>
            <div><span class="subtle">{{ __('fulfillment_groups.col_shipping') }}</span><strong>{{ $group->shippingMethod?->name ?: $group->courier ?: '-' }}</strong></div>
            <div><span class="subtle">{{ __('fulfillment_groups.col_orders') }}</span><strong>{{ number_format($group->orders->count()) }}</strong></div>
            <div><span class="subtle">{{ __('sales_orders.col_qty') }}</span><strong>{{ number_format(collect($lines)->sum('required_qty')) }}</strong></div>
            <div><span class="subtle">{{ __('fulfillment_groups.col_status') }}</span><strong>{{ __('fulfillment_groups.status_'.$group->status) }}</strong></div>
        </div>
    </section>

    <section class="table-shell flux-panel form-panel">
        @if (! $readOnly)
            <form wire:submit="scan" class="pack-scan-form">
                <flux:input
                    x-data
                    x-init="$nextTick(() => $el.querySelector('input')?.focus())"
                    x-on:pack-scan-focus.window="$nextTick(() => $el.querySelector('input')?.focus())"
                    wire:model="barcode"
                    :label="__('fulfillment_pack.scan_product_label')"
                    :placeholder="__('fulfillment_pack.scan_product_placeholder')"
                    autocomplete="off"
                />
            </form>
        @endif

        <div class="pack-feedback {{ $feedbackMessage ? $feedbackType : 'idle' }}">
            @if ($feedbackMessage)
                {{ $feedbackMessage }}
            @elseif ($readOnly && $group->status === 'shipped')
                {{ __('fulfillment_pack.already_shipped') }}
            @elseif ($readOnly)
                {{ __('fulfillment_pack.cancelled_group') }}
            @else
                &nbsp;
            @endif
        </div>

        <flux:table class="data-table">
            <flux:table.columns>
                <flux:table.column>{{ __('sales_orders.col_sku') }}</flux:table.column>
                <flux:table.column>{{ __('fulfillment_pack.stock_item') }}</flux:table.column>
                <flux:table.column>{{ __('fulfillment_pack.barcode') }}</flux:table.column>
                <flux:table.column>{{ __('fulfillment_pack.product_name') }}</flux:table.column>
                <flux:table.column align="end">{{ __('fulfillment_pack.required_qty') }}</flux:table.column>
                <flux:table.column align="end">{{ __('fulfillment_pack.scanned_qty') }}</flux:table.column>
                <flux:table.column align="end">{{ __('fulfillment_pack.remaining_qty') }}</flux:table.column>
                <flux:table.column>{{ __('fulfillment_groups.col_status') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($lines as $line)
                    <flux:table.row :key="$line['key']">
                        <flux:table.cell>
                            <strong>{{ $line['sku']?->sku ?: '-' }}</strong>
                        </flux:table.cell>
                        <flux:table.cell>{{ $line['stock_item']?->code ?: '-' }}</flux:table.cell>
                        <flux:table.cell>{{ $line['sku']?->barcode ?: $line['stock_item']?->barcode ?: '-' }}</flux:table.cell>
                        <flux:table.cell>{{ $line['stock_item']?->short_name ?: $line['stock_item']?->name ?: $line['sku']?->name ?: '-' }}</flux:table.cell>
                        <flux:table.cell align="end">{{ number_format($line['required_qty']) }}</flux:table.cell>
                        <flux:table.cell align="end">{{ number_format($line['scanned_qty']) }}</flux:table.cell>
                        <flux:table.cell align="end">{{ number_format($line['remaining_qty']) }}</flux:table.cell>
                        <flux:table.cell>
                            <flux:badge color="{{ $line['status'] === 'complete' ? 'green' : ($line['status'] === 'in_progress' ? 'amber' : 'zinc') }}">
                                {{ __('fulfillment_pack.status_'.$line['status']) }}
                            </flux:badge>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </section>

    <div class="form-actions pack-actions">
        @if ($allComplete)
            <div class="pack-ready">{{ __('fulfillment_pack.ready_to_ship') }}</div>
        @else
            <div class="pack-waiting">{{ __('fulfillment_pack.scan_all_before_shipping') }}</div>
        @endif
        <flux:button type="button" variant="primary" wire:click="markShipped" :disabled="! $allComplete || $readOnly">
            {{ __('fulfillment_pack.mark_shipped') }}
        </flux:button>
    </div>

    <style>
        .pack-scan-form {
            margin-bottom: 12px;
        }

        .pack-scan-form input {
            min-height: 48px;
            font-size: 18px;
            font-weight: 700;
        }

        .pack-feedback {
            min-height: 44px;
            margin-bottom: 12px;
            border-radius: 8px;
            padding: 12px 14px;
            font-weight: 700;
        }

        .pack-feedback.success,
        .pack-ready {
            color: #166534;
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
        }

        .pack-feedback.error,
        .pack-waiting {
            color: #991b1b;
            background: #fef2f2;
            border: 1px solid #fecaca;
        }

        .pack-feedback.idle {
            border: 1px solid transparent;
        }

        .pack-actions {
            align-items: center;
        }

        .pack-ready,
        .pack-waiting {
            border-radius: 8px;
            padding: 10px 12px;
            font-size: 12px;
            font-weight: 700;
        }
    </style>
</div>
