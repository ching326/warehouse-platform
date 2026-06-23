<div class="fulfillment-group-pack-page">
    <x-flash-toast />

    <section class="table-shell flux-panel form-panel pack-station-header">
        <div class="form-panel-header">
            <div>
                <strong>{{ $group->reference_no }}</strong>
                <span>{{ $group->tenant->code }} / {{ $group->recipient_name ?: '-' }}</span>
            </div>
            <div class="active-filter-row">
                <flux:badge color="{{ $group->status === 'shipped' ? 'green' : ($group->status === 'cancelled' ? 'red' : 'blue') }}">
                    {{ __('fulfillment_groups.status_'.$group->status) }}
                </flux:badge>
                <flux:button href="{{ route('fulfillment-groups.issues.create', $group) }}" variant="outline" wire:navigate>
                    {{ __('issues.btn_create') }}
                </flux:button>
                <flux:button href="{{ route('fulfillment.pack-scans.index', ['fulfillment_group_id' => $group->id]) }}" variant="outline" wire:navigate>
                    {{ __('fulfillment_pack.scan_history_title') }}
                </flux:button>
                <flux:button href="{{ route('fulfillment-groups.show', $group) }}" variant="outline" wire:navigate>
                    {{ __('fulfillment_groups.btn_back') }}
                </flux:button>
            </div>
        </div>

        <div class="form-grid three">
            <div><span class="subtle">{{ __('fulfillment_groups.col_status') }}</span><strong>{{ __('fulfillment_groups.status_'.$group->status) }}</strong></div>
            <div><span class="subtle">{{ __('fulfillment_groups.field_recipient_name') }}</span><strong>{{ $group->recipient_name ?: '-' }}</strong></div>
            <div><span class="subtle">{{ __('fulfillment_groups.field_tracking_no') }}</span><strong>{{ $group->tracking_no ?: $group->outboundOrder?->tracking_no ?: '-' }}</strong></div>
            <div><span class="subtle">{{ __('fulfillment_groups.col_shipping') }}</span><strong>{{ $group->shippingMethod?->name ?: $group->courier ?: '-' }}</strong></div>
            <div><span class="subtle">{{ __('fulfillment_groups.col_orders') }}</span><strong>{{ number_format($group->orders->count()) }}</strong></div>
            <div><span class="subtle">{{ __('fulfillment_pack.overall_progress') }}</span><strong>{{ number_format($progress['qty_scanned']) }} / {{ number_format($progress['qty_required']) }} {{ __('fulfillment_pack.scanned_short') }}</strong></div>
        </div>
    </section>

    <section class="table-shell flux-panel form-panel pack-scan-panel" x-data x-on:click.self="$dispatch('pack-scan-focus')">
        @if (! $readOnly)
            <div class="pack-mode-row">
                <span class="pack-mode-label">{{ __('fulfillment_pack.pack_mode') }}</span>
                <div class="pack-mode-control" role="group" aria-label="{{ __('fulfillment_pack.pack_mode') }}">
                    <button
                        type="button"
                        wire:click="$set('packMode', 'normal')"
                        @class(['active' => $packMode === 'normal'])
                    >
                        {{ __('fulfillment_pack.mode_normal') }}
                    </button>
                    <button
                        type="button"
                        wire:click="$set('packMode', 'strict')"
                        @class(['active' => $packMode === 'strict'])
                    >
                        {{ __('fulfillment_pack.mode_strict') }}
                    </button>
                </div>
            </div>

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

            @if ($pendingQuantityScan)
                <form
                    wire:submit="confirmPendingQuantity"
                    class="pack-quantity-panel"
                    x-data
                    x-on:keydown.escape.window="$wire.cancelPendingQuantity()"
                >
                    <div>
                        <span>{{ __('fulfillment_pack.pending_scanned') }}</span>
                        <strong>{{ $pendingQuantityScan['display'] }}</strong>
                    </div>
                    <div>
                        <span>{{ __('fulfillment_pack.remaining_qty') }}</span>
                        <strong>{{ number_format($pendingQuantityScan['remaining_qty']) }}</strong>
                    </div>
                    <flux:input
                        x-data
                        x-on:pack-quantity-focus.window="$nextTick(() => $el.querySelector('input')?.focus())"
                        wire:model="pendingQuantity"
                        type="number"
                        min="1"
                        max="{{ $pendingQuantityScan['remaining_qty'] }}"
                        step="1"
                        :label="__('fulfillment_pack.quantity_label')"
                    />
                    <div class="pack-quantity-actions">
                        <flux:button type="submit" variant="primary">{{ __('fulfillment_pack.add_quantity') }}</flux:button>
                        <flux:button type="button" variant="outline" wire:click="cancelPendingQuantity">{{ __('fulfillment_pack.cancel_quantity') }}</flux:button>
                    </div>
                </form>
            @endif
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
    </section>

    <section class="table-shell flux-panel form-panel">
        <div class="pack-progress-summary">
            <div><span>{{ __('fulfillment_pack.lines_complete') }}</span><strong>{{ number_format($progress['lines_complete']) }} / {{ number_format($progress['lines_total']) }}</strong></div>
            <div><span>{{ __('fulfillment_pack.qty_scanned') }}</span><strong>{{ number_format($progress['qty_scanned']) }} / {{ number_format($progress['qty_required']) }}</strong></div>
            <div><span>{{ __('fulfillment_pack.qty_remaining') }}</span><strong>{{ number_format($progress['qty_remaining']) }}</strong></div>
            <div><span>{{ __('fulfillment_pack.scan_exceptions') }}</span><strong>{{ number_format($progress['exceptions']) }}</strong></div>
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
                <flux:table.column>{{ __('common.actions') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($lines as $line)
                    @php
                        $issueQuery = array_filter([
                            'sku_id' => $line['sku_id'],
                            'stock_item_id' => $line['stock_item_id'],
                            'qty' => max(1, (int) $line['remaining_qty']),
                        ], fn ($value) => $value !== null && $value !== '');
                    @endphp
                    <flux:table.row
                        :key="$line['key']"
                        @class([
                            'pack-line-row',
                            'is-complete' => $line['remaining_qty'] <= 0,
                            'is-in-progress' => $line['remaining_qty'] > 0 && $line['scanned_qty'] > 0,
                            'is-last-scan' => $lastScannedLineKey === $line['key'],
                        ])
                    >
                        <flux:table.cell>
                            <strong>{{ $line['sku']?->sku ?: '-' }}</strong>
                            @if ($lastScannedLineKey === $line['key'])
                                <flux:badge color="green">{{ __('fulfillment_pack.last_scan_marker') }}</flux:badge>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>{{ $line['stock_item']?->code ?: '-' }}</flux:table.cell>
                        <flux:table.cell>{{ $line['sku']?->barcode ?: $line['stock_item']?->barcode ?: '-' }}</flux:table.cell>
                        <flux:table.cell>{{ $line['stock_item']?->short_name ?: $line['stock_item']?->name ?: $line['sku']?->name ?: '-' }}</flux:table.cell>
                        <flux:table.cell align="end">{{ number_format($line['required_qty']) }}</flux:table.cell>
                        <flux:table.cell align="end">{{ number_format($line['scanned_qty']) }}</flux:table.cell>
                        <flux:table.cell align="end">
                            <span class="{{ $line['remaining_qty'] > 0 ? 'pack-remaining-open' : 'pack-remaining-complete' }}">
                                {{ number_format($line['remaining_qty']) }}
                            </span>
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:badge color="{{ $line['status'] === 'complete' ? 'green' : ($line['status'] === 'in_progress' ? 'amber' : 'zinc') }}">
                                {{ __('fulfillment_pack.status_'.$line['status']) }}
                            </flux:badge>
                            @if ($line['strict_only'])
                                <flux:badge color="red">{{ __('fulfillment_pack.strict_scan') }}</flux:badge>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:button
                                size="xs"
                                variant="outline"
                                href="{{ route('fulfillment-groups.issues.create', ['group' => $group] + $issueQuery) }}"
                                wire:navigate
                            >
                                {{ __('issues.section_issue') }}
                            </flux:button>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </section>

    <div class="form-actions pack-actions">
        @if ($pendingQuantityScan)
            <div class="pack-waiting">{{ __('fulfillment_pack.confirm_quantity_before_shipping') }}</div>
        @elseif ($allComplete)
            <div class="pack-ready">{{ __('fulfillment_pack.ready_to_mark_shipped') }}</div>
        @else
            <div class="pack-waiting">{{ __('fulfillment_pack.scan_all_before_marking_shipped') }}</div>
        @endif
        <flux:button type="button" variant="primary" wire:click="markShipped" :disabled="! $allComplete || $readOnly || (bool) $pendingQuantityScan">
            {{ __('fulfillment_pack.mark_shipped') }}
        </flux:button>
    </div>

    <style>
        .pack-station-header .form-grid > div {
            min-width: 0;
        }

        .pack-station-header strong {
            overflow-wrap: anywhere;
        }

        .pack-scan-panel {
            position: sticky;
            top: 10px;
            z-index: 20;
            box-shadow: 0 10px 28px rgb(15 23 42 / 8%);
        }

        .pack-mode-row {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
            margin-bottom: 12px;
        }

        .pack-mode-label {
            color: #475569;
            font-size: 12px;
            font-weight: 700;
        }

        .pack-mode-control {
            display: inline-flex;
            overflow: hidden;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            background: #ffffff;
        }

        .pack-mode-control button {
            min-width: 78px;
            border: 0;
            border-right: 1px solid #cbd5e1;
            padding: 8px 12px;
            background: transparent;
            color: #334155;
            font-size: 12px;
            font-weight: 800;
            cursor: pointer;
        }

        .pack-mode-control button:last-child {
            border-right: 0;
        }

        .pack-mode-control button.active {
            background: #0f172a;
            color: #ffffff;
        }

        .pack-scan-form {
            margin-bottom: 12px;
        }

        .pack-scan-form input {
            min-height: 48px;
            font-size: 18px;
            font-weight: 700;
        }

        .pack-quantity-panel {
            display: grid;
            grid-template-columns: minmax(140px, 1fr) minmax(92px, auto) minmax(110px, 160px) auto;
            gap: 10px;
            align-items: end;
            margin-bottom: 12px;
            border: 1px solid #bfdbfe;
            border-radius: 8px;
            padding: 10px;
            background: #eff6ff;
        }

        .pack-quantity-panel span {
            display: block;
            color: #475569;
            font-size: 12px;
            font-weight: 700;
        }

        .pack-quantity-panel strong {
            display: block;
            color: #0f172a;
            font-size: 14px;
        }

        .pack-quantity-actions {
            display: flex;
            gap: 8px;
            align-items: center;
        }

        .pack-feedback {
            min-height: 68px;
            margin-bottom: 12px;
            border-radius: 8px;
            padding: 16px 18px;
            font-size: 20px;
            font-weight: 700;
            display: flex;
            align-items: center;
        }

        .pack-feedback.success,
        .pack-ready {
            color: #166534;
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
        }

        .pack-feedback.prompt {
            color: #1d4ed8;
            background: #eff6ff;
            border: 1px solid #bfdbfe;
        }

        .pack-feedback.error,
        .pack-waiting {
            color: #991b1b;
            background: #fef2f2;
            border: 1px solid #fecaca;
        }

        .pack-feedback.idle {
            color: #475569;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
        }

        .pack-progress-summary {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 10px;
            margin-bottom: 14px;
        }

        .pack-progress-summary > div {
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 10px 12px;
            background: #ffffff;
        }

        .pack-progress-summary span {
            display: block;
            color: #475569;
            font-size: 12px;
            font-weight: 700;
        }

        .pack-progress-summary strong {
            display: block;
            margin-top: 2px;
            color: #0f172a;
            font-size: 18px;
        }

        .pack-line-row.is-complete {
            background: #f0fdf4;
        }

        .pack-line-row.is-in-progress {
            background: #fffbeb;
        }

        .pack-line-row.is-last-scan {
            outline: 2px solid #86efac;
            outline-offset: -2px;
        }

        .pack-remaining-open {
            color: #b45309;
            font-weight: 900;
        }

        .pack-remaining-complete {
            color: #15803d;
            font-weight: 800;
        }

        .pack-actions {
            align-items: center;
        }

        .pack-ready,
        .pack-waiting {
            border-radius: 8px;
            padding: 12px 14px;
            font-size: 13px;
            font-weight: 700;
        }

        @media (max-width: 760px) {
            .pack-scan-panel {
                top: 0;
            }

            .pack-quantity-panel {
                grid-template-columns: 1fr;
                align-items: stretch;
            }

            .pack-quantity-actions {
                justify-content: flex-start;
            }

            .pack-progress-summary {
                grid-template-columns: 1fr 1fr;
            }

            .pack-feedback {
                min-height: 64px;
                font-size: 18px;
            }
        }

        @media (max-width: 560px) {
            .pack-progress-summary {
                grid-template-columns: 1fr;
            }
        }
    </style>
</div>
