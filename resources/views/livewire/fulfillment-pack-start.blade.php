<div class="fulfillment-pack-start-page">
    <section class="table-shell flux-panel form-panel pack-start-panel">
        <form wire:submit="search" class="pack-scan-form">
            <div class="pack-station-grid">
                <flux:select wire:model.live="warehouseId" :label="__('fulfillment_pack.warehouse_label')">
                    <flux:select.option value="">{{ __('fulfillment_pack.select_warehouse') }}</flux:select.option>
                    @foreach ($warehouses as $warehouse)
                        <flux:select.option value="{{ $warehouse->id }}">{{ $warehouse->code }} - {{ $warehouse->name }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select wire:model.live="shippingMethodId" :label="__('fulfillment_pack.shipping_method_label')">
                    <flux:select.option value="">{{ __('fulfillment_pack.select_shipping_method') }}</flux:select.option>
                    @foreach ($shippingMethods as $method)
                        <flux:select.option value="{{ $method->id }}">
                            {{ $method->name }}
                        </flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <flux:input
                x-data
                x-init="$nextTick(() => {{ $filtersReady ? '$el.querySelector(\'input\')?.focus()' : 'null' }})"
                x-on:pack-scan-focus.window="$nextTick(() => $el.querySelector('input')?.focus())"
                wire:model="scan"
                :label="__('fulfillment_pack.scan_tracking_label')"
                :placeholder="__('fulfillment_pack.scan_tracking_placeholder')"
                autocomplete="off"
                :disabled="! $filtersReady"
            />
            <p class="subtle">{{ __('fulfillment_pack.scan_tracking_helper') }}</p>
            @if ($lastScan)
                <p class="pack-last-scan">{{ __('fulfillment_pack.last_scan', ['scan' => $lastScan]) }}</p>
            @endif

            @if ($message)
                <div class="pack-feedback error">{{ $message }}</div>
            @else
                <div class="pack-feedback idle">&nbsp;</div>
            @endif
        </form>
    </section>

    @if ($filtersReady)
        <section class="table-shell flux-panel form-panel pack-queue-panel">
            <div class="pack-station-summary">
                <div><span>{{ __('fulfillment_pack.queue_waiting_groups') }}</span><strong>{{ number_format($summary['waiting_groups'] ?? 0) }}</strong></div>
                <div><span>{{ __('fulfillment_pack.queue_waiting_orders') }}</span><strong>{{ number_format($summary['waiting_orders'] ?? 0) }}</strong></div>
                <div><span>{{ __('fulfillment_pack.queue_required_qty_page') }}</span><strong>{{ number_format($summary['required_qty_page'] ?? 0) }}</strong></div>
                <div><span>{{ __('fulfillment_pack.queue_exceptions_today') }}</span><strong>{{ number_format($summary['exception_scans_today'] ?? 0) }}</strong></div>
            </div>

            <div class="pack-queue-toolbar">
                <flux:input
                    wire:model.live.debounce.300ms="queueSearch"
                    :label="__('fulfillment_pack.queue_search_label')"
                    :placeholder="__('fulfillment_pack.queue_search_placeholder')"
                />
            </div>

            <flux:table :paginate="$queue" class="data-table pack-queue-table">
                <flux:table.columns>
                    <flux:table.column>{{ __('fulfillment_pack.queue_ref') }}</flux:table.column>
                    <flux:table.column>{{ __('fulfillment_pack.queue_tenant') }}</flux:table.column>
                    <flux:table.column>{{ __('fulfillment_pack.queue_recipient') }}</flux:table.column>
                    <flux:table.column>{{ __('fulfillment_pack.queue_tracking') }}</flux:table.column>
                    <flux:table.column>{{ __('fulfillment_pack.queue_orders') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('fulfillment_pack.queue_qty') }}</flux:table.column>
                    <flux:table.column>{{ __('fulfillment_pack.queue_progress') }}</flux:table.column>
                    <flux:table.column>{{ __('fulfillment_pack.queue_action') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @forelse ($queue as $outbound)
                        @php
                            $progress = $queueProgress[$outbound->id] ?? ['required_qty' => 0, 'scanned_qty' => 0];
                            $reference = $outbound->ref;
                            $orderIds = $outbound->salesOrders->pluck('platform_order_id')->filter()->values();
                        @endphp
                        <flux:table.row :key="$outbound->id">
                            <flux:table.cell>
                                <a href="{{ route('outbound.pack', $outbound) }}" wire:navigate><strong>{{ $reference }}</strong></a>
                            </flux:table.cell>
                            <flux:table.cell>{{ $outbound->tenant?->code ?: '-' }}</flux:table.cell>
                            <flux:table.cell>{{ $outbound->recipient_name ?: '-' }}</flux:table.cell>
                            <flux:table.cell>{{ $outbound->tracking_no ?: '-' }}</flux:table.cell>
                            <flux:table.cell>
                                @if ($orderIds->isNotEmpty())
                                    {{ $orderIds->take(2)->implode(', ') }}
                                    @if ($orderIds->count() > 2)
                                        <span class="pack-order-more">+{{ $orderIds->count() - 2 }}</span>
                                    @endif
                                @else
                                    -
                                @endif
                            </flux:table.cell>
                            <flux:table.cell align="end">{{ number_format($progress['required_qty']) }}</flux:table.cell>
                            <flux:table.cell>{{ number_format($progress['scanned_qty']) }} / {{ number_format($progress['required_qty']) }}</flux:table.cell>
                            <flux:table.cell>
                                <flux:button href="{{ route('outbound.pack', $outbound) }}" size="xs" variant="primary" wire:navigate>
                                    {{ __('fulfillment_pack.queue_pack') }}
                                </flux:button>
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="8">
                                <div class="empty-state">{{ __('fulfillment_pack.queue_empty') }}</div>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </section>
    @endif

    <style>
        .pack-start-panel {
            max-width: 760px;
            margin: 0 auto;
        }

        .pack-scan-form {
            display: grid;
            gap: 10px;
        }

        .pack-station-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }

        .pack-scan-form input {
            min-height: 52px;
            font-size: 20px;
            font-weight: 700;
        }

        .pack-feedback {
            min-height: 44px;
            border-radius: 8px;
            padding: 12px 14px;
            font-weight: 700;
        }

        .pack-last-scan {
            min-height: 20px;
            color: var(--muted);
            font-size: 12px;
            font-weight: 700;
        }

        .pack-feedback.error {
            color: #991b1b;
            background: #fef2f2;
            border: 1px solid #fecaca;
        }

        .pack-feedback.idle {
            border: 1px solid transparent;
        }

        .pack-queue-panel {
            margin-top: 16px;
        }

        .pack-station-summary {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 10px;
            margin-bottom: 14px;
        }

        .pack-station-summary > div {
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 10px 12px;
            background: #fff;
        }

        .pack-station-summary span {
            display: block;
            color: #475569;
            font-size: 12px;
            font-weight: 700;
        }

        .pack-station-summary strong {
            display: block;
            margin-top: 2px;
            color: #0f172a;
            font-size: 18px;
        }

        .pack-queue-toolbar {
            max-width: 360px;
            margin-bottom: 12px;
        }

        .pack-queue-table a {
            color: #1d4ed8;
            text-decoration: none;
        }

        .pack-order-more {
            color: #475569;
            font-weight: 700;
        }

        @media (max-width: 720px) {
            .pack-station-grid {
                grid-template-columns: 1fr;
            }

            .pack-station-summary {
                grid-template-columns: 1fr 1fr;
            }
        }

        @media (max-width: 520px) {
            .pack-station-summary {
                grid-template-columns: 1fr;
            }
        }
    </style>
</div>
