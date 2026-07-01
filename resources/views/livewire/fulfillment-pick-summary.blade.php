<div class="fulfillment-pick-summary-page">
    <x-page-panel-header
        :title="__('fulfillment_pick.page_title')"
        :subtitle="__('fulfillment_pick.page_subtitle')"
        class="no-print"
    />

    <section class="table-shell flux-panel form-panel pick-filter-panel no-print">
        <div class="pick-filter-grid">
            <flux:select wire:model.live="warehouseId" :label="__('fulfillment_pick.field_warehouse')">
                <flux:select.option value="">{{ __('common.all_warehouses') }}</flux:select.option>
                @foreach ($warehouses as $warehouse)
                    <flux:select.option value="{{ $warehouse->id }}">{{ $warehouse->code }} - {{ $warehouse->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:select wire:model.live="shippingMethodId" :label="__('fulfillment_pick.field_shipping_method')">
                <flux:select.option value="">{{ __('fulfillment_pick.all_shipping_methods') }}</flux:select.option>
                @foreach ($shippingMethods as $method)
                    <flux:select.option value="{{ $method->id }}">{{ $method->displayName() }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:select wire:model.live="tenantId" :label="__('fulfillment_pick.field_tenant')">
                <flux:select.option value="">{{ __('common.all_tenants') }}</flux:select.option>
                @foreach ($tenants as $tenant)
                    <flux:select.option value="{{ $tenant->id }}">{{ $tenant->code }} - {{ $tenant->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:input wire:model.live="dateFrom" type="date" :label="__('fulfillment_pick.field_date_from')" />
            <flux:input wire:model.live="dateTo" type="date" :label="__('fulfillment_pick.field_date_to')" />
            <flux:input wire:model.live.debounce.300ms="q" :label="__('common.search')" :placeholder="__('fulfillment_pick.search_placeholder')" />
        </div>

        @if ($filterChips !== [])
            <div class="filter-chip-row pick-filter-chips" data-testid="pick-summary-filter-chips">
                @foreach ($filterChips as $chip)
                    @if ($chip['action'])
                        <button type="button" class="filter-chip" wire:click="{{ $chip['action'] }}">
                            <span>{{ $chip['label'] }}</span>
                            <strong>&times;</strong>
                        </button>
                    @else
                        <span class="filter-chip">
                            <span>{{ $chip['label'] }}</span>
                        </span>
                    @endif
                @endforeach
            </div>
        @endif
    </section>

    <div class="print-heading">
        <strong>{{ __('fulfillment_pick.page_title') }}</strong>
        <span>{{ $filterSummary }}</span>
    </div>

    <section class="pick-summary-cards no-print">
        <div><span>{{ __('fulfillment_pick.summary_pick_rows') }}</span><strong>{{ number_format($summary['pick_rows']) }}</strong></div>
        <div><span>{{ __('fulfillment_pick.summary_required_qty') }}</span><strong>{{ number_format($summary['required_qty']) }}</strong></div>
        <div><span>{{ __('fulfillment_pick.summary_shortage_rows') }}</span><strong>{{ number_format($summary['shortage_rows']) }}</strong></div>
        <div><span>{{ __('fulfillment_pick.summary_groups_included') }}</span><strong>{{ number_format($summary['groups_included']) }}</strong></div>
    </section>

    <section class="table-shell flux-panel form-panel pick-table-panel screen-pick-table">
        <div class="form-panel-header no-print">
            <div></div>
            <flux:button type="button" variant="primary" icon="printer" x-data @click="window.print()">
                {{ __('fulfillment_pick.print_button') }}
            </flux:button>
        </div>

        <flux:table class="data-table pick-summary-table">
            <flux:table.columns>
                    <flux:table.column>{{ __('fulfillment_pick.col_stock_item') }}</flux:table.column>
                    <flux:table.column>{{ __('fulfillment_pick.col_skus') }}</flux:table.column>
                    <flux:table.column>{{ __('fulfillment_pick.col_product_name') }}</flux:table.column>
                    <flux:table.column>{{ __('fulfillment_pick.col_barcode') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('fulfillment_pick.col_required_qty') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('fulfillment_pick.col_pickable_qty') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('fulfillment_pick.col_difference') }}</flux:table.column>
                    <flux:table.column>{{ __('fulfillment_pick.col_location') }}</flux:table.column>
                    <flux:table.column>{{ __('fulfillment_pick.col_groups_orders') }}</flux:table.column>
                    <flux:table.column class="no-print">{{ __('fulfillment_pick.col_actions') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @forelse ($rows as $row)
                        @php($stockItem = $row['stock_item'])
                        @php($outbounds = collect($row['outbounds']))
                        @php($orders = collect($row['orders']))
                        @php($locationHint = $row['location_hint'])
                        @php($previousLocation = $loop->first ? null : ($rows->get($loop->index - 1)['location_hint'] ?? null))
                        @php($isNewLocation = $loop->first || $previousLocation !== $locationHint)
                        @php($displayLocation = $locationHint === '-' ? __('fulfillment_pick.no_location') : $locationHint)
                        <flux:table.row :key="($row['sku_id'] ?? 'component').'-'.($row['stock_item_id'] ?? 'none')" class="{{ $isNewLocation ? 'pick-location-start' : '' }}">
                            <flux:table.cell>
                                <strong>{{ $stockItem?->code ?: '-' }}</strong>
                                <div class="pick-badges">
                                    @if ($stockItem?->product_type)
                                        <flux:badge color="zinc">{{ $stockItem->product_type }}</flux:badge>
                                    @endif
                                    @if ($row['is_strict'])
                                        <flux:badge color="amber">{{ __('fulfillment_pick.strict_risk') }}</flux:badge>
                                    @endif
                                </div>
                            </flux:table.cell>
                            <flux:table.cell><span class="pick-sku-list">{{ implode(', ', $row['sku_codes']) ?: '-' }}</span></flux:table.cell>
                            <flux:table.cell><span class="pick-name">{{ $stockItem?->displayName() ?: collect($row['sku_names'])->first() ?: '-' }}</span></flux:table.cell>
                            <flux:table.cell>
                                @if (count($row['barcodes']) > 0)
                                    <span class="pick-barcode-list">
                                        @foreach ($row['barcodes'] as $barcode)
                                            <span>{{ $barcode }}</span>
                                        @endforeach
                                    </span>
                                @else
                                    -
                                @endif
                            </flux:table.cell>
                            <flux:table.cell align="end">{{ number_format($row['required_qty']) }}</flux:table.cell>
                            <flux:table.cell align="end">{{ number_format($row['pickable_qty']) }}</flux:table.cell>
                            <flux:table.cell align="end">
                                <span @class(['pick-diff', 'is-short' => $row['difference'] < 0, 'is-low' => $row['difference'] >= 0 && $row['difference'] <= 2, 'is-enough' => $row['difference'] > 2])>
                                    {{ number_format($row['difference']) }}
                                </span>
                            </flux:table.cell>
                            <flux:table.cell><span class="pick-location-badge">{{ $displayLocation }}</span></flux:table.cell>
                            <flux:table.cell>
                                <strong>{{ trans_choice('fulfillment_pick.group_count', $outbounds->count(), ['count' => $outbounds->count()]) }}</strong>
                                <span>{{ trans_choice('fulfillment_pick.order_count', $orders->count(), ['count' => $orders->count()]) }}</span>
                                <small>
                                    @foreach ($outbounds->take(3) as $outbound)
                                        <a href="{{ route('outbound.show', $outbound) }}" wire:navigate>{{ $outbound->ref }}</a>@if (! $loop->last), @endif
                                    @endforeach
                                    @if ($outbounds->count() > 3)
                                        <span>{{ __('fulfillment_pick.more_groups', ['count' => $outbounds->count() - 3]) }}</span>
                                    @endif
                                </small>
                            </flux:table.cell>
                            <flux:table.cell class="no-print">
                                @php($firstOutbound = $outbounds->first())
                                @if ($firstOutbound)
                                    <div class="pick-actions">
                                        @if ($outbounds->count() > 1)
                                            <flux:button href="{{ route('fulfillment.index', ['search' => $firstOutbound->ref]) }}" size="xs" variant="subtle" wire:navigate>{{ __('fulfillment_pick.view_groups') }}</flux:button>
                                        @endif
                                        <flux:button href="{{ route('outbound.pack', $firstOutbound) }}" size="xs" variant="primary" wire:navigate>{{ __('fulfillment_pick.pack_first') }}</flux:button>
                                    </div>
                                @endif
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="10"><div class="empty-state">{{ __('fulfillment_pick.empty_state') }}</div></flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
        </flux:table>
    </section>

    <section class="print-pick-table" data-testid="pick-summary-print-table">
            <table>
                <thead>
                    <tr>
                        <th>{{ __('fulfillment_pick.print_col_location') }}</th>
                        <th>{{ __('fulfillment_pick.col_stock_item') }}</th>
                        <th>{{ __('fulfillment_pick.col_skus') }}</th>
                        <th>{{ __('fulfillment_pick.print_col_product') }}</th>
                        <th>{{ __('fulfillment_pick.col_barcode') }}</th>
                        <th>{{ __('fulfillment_pick.print_col_pick_qty') }}</th>
                        <th>{{ __('fulfillment_pick.print_col_notes') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @if ($rows->isEmpty())
                        <tr>
                            <td colspan="7">{{ __('fulfillment_pick.empty_state') }}</td>
                        </tr>
                    @else
                        @foreach ($rows->groupBy('location_hint') as $locationHint => $locationRows)
                            @php($displayLocation = $locationHint === '-' ? __('fulfillment_pick.no_location') : $locationHint)
                            <tr class="print-location-row">
                                <td colspan="7">{{ $locationHint === '-' ? $displayLocation : __('fulfillment_pick.print_location_group', ['location' => $displayLocation]) }}</td>
                            </tr>
                            @foreach ($locationRows as $row)
                                @php($stockItem = $row['stock_item'])
                                <tr>
                                    <td>{{ $displayLocation }}</td>
                                    <td>{{ $stockItem?->code ?: '-' }}</td>
                                    <td>{{ implode(', ', $row['sku_codes']) ?: '-' }}</td>
                                    <td>{{ $stockItem?->displayName() ?: collect($row['sku_names'])->first() ?: '-' }}</td>
                                    <td>
                                        @if ($row['print_barcode_svg'])
                                            <div class="print-barcode-image">{!! $row['print_barcode_svg'] !!}</div>
                                        @endif

                                        @if (count($row['barcodes']) > 0)
                                            <div class="print-barcode-text">
                                                @foreach ($row['barcodes'] as $barcode)
                                                    <div>{{ $barcode }}</div>
                                                @endforeach
                                            </div>
                                        @else
                                            <span class="print-barcode-empty">-</span>
                                        @endif
                                    </td>
                                    <td>{{ number_format($row['required_qty']) }}</td>
                                    <td></td>
                                </tr>
                            @endforeach
                        @endforeach
                    @endif
                </tbody>
            </table>
    </section>

    <style>
        .pick-filter-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 12px;
        }

        .pick-warehouse-filter {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .print-heading {
            display: none;
        }

        .print-pick-table {
            display: none;
        }

        .pick-filter-chips {
            margin-top: 12px;
        }

        .pick-summary-cards {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 10px;
            margin-bottom: 14px;
        }

        .pick-summary-cards > div {
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 10px 12px;
            background: #fff;
        }

        .pick-summary-cards span,
        .pick-summary-table span,
        .pick-summary-table small {
            display: block;
            color: #64748b;
        }

        .pick-summary-cards strong {
            display: block;
            margin-top: 2px;
            color: #0f172a;
            font-size: 18px;
        }

        .pick-name,
        .pick-sku-list {
            display: block;
            max-width: 240px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .pick-sku-list {
            max-width: 180px;
        }

        .pick-badges,
        .pick-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }

        .pick-barcode-list {
            display: grid;
            gap: 2px;
            font-size: 12px;
            line-height: 1.25;
        }

        .pick-barcode-list span {
            display: block;
            max-width: 170px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .pick-location-start td {
            border-top: 2px solid #cbd5e1 !important;
        }

        .pick-location-badge {
            display: inline-block;
            color: #334155;
            background: #f8fafc;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            padding: 2px 6px;
            font-size: 12px;
            font-weight: 800;
            white-space: nowrap;
        }

        .pick-diff {
            font-weight: 900;
        }

        .pick-diff.is-short {
            color: #b91c1c;
            background: #fee2e2;
            border-radius: 6px;
            padding: 2px 6px;
        }

        .pick-diff.is-low {
            color: #b45309;
        }

        .pick-diff.is-enough {
            color: #15803d;
        }

        @media (max-width: 920px) {
            .pick-filter-grid,
            .pick-summary-cards {
                grid-template-columns: 1fr 1fr;
            }
        }

        @media print {
            .no-print,
            .screen-pick-table,
            .pick-summary-cards,
            nav,
            header {
                display: none !important;
            }

            .print-heading {
                display: grid;
                gap: 4px;
                margin-bottom: 12px;
            }

            .print-pick-table {
                display: block;
            }

            .print-pick-table table {
                width: 100%;
                border-collapse: collapse;
                font-size: 11px;
            }

            .print-pick-table th,
            .print-pick-table td {
                border: 1px solid #111827;
                padding: 5px 6px;
                text-align: left;
                vertical-align: top;
            }

            .print-pick-table th {
                background: #f1f5f9;
            }

            .print-pick-table .print-location-row td {
                background: #e2e8f0;
                font-weight: 900;
            }

            .print-barcode-image {
                display: block;
                width: auto;
                max-width: 100%;
                margin-bottom: 3px;
            }

            .print-barcode-image svg {
                display: block;
                width: auto;
                max-width: 100%;
                height: 36px;
            }

            .print-barcode-text {
                font-size: 9px;
                line-height: 1.2;
                word-break: break-all;
            }

            .table-shell,
            .flux-panel,
            .form-panel {
                box-shadow: none !important;
                border: 0 !important;
            }

            .pick-summary-table {
                font-size: 11px;
                white-space: normal;
            }
        }
    </style>
</div>
