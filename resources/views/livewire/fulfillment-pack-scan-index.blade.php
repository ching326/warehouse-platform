<div class="pack-scan-history-page">
    <section class="table-shell flux-panel">
        <div class="pack-scan-filter-stack">
            <div class="pack-scan-filter-row">
                @if ($showTenantFilter)
                    <flux:select wire:model.live="tenantId" :label="__('issues.field_tenant')">
                        <flux:select.option value="">{{ __('common.all_tenants') }}</flux:select.option>
                        @foreach ($tenants as $tenant)
                            <flux:select.option value="{{ $tenant->id }}">{{ $tenant->code }} - {{ $tenant->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @endif

                <flux:select wire:model.live="result" :label="__('fulfillment_pack.scan_result')">
                    <flux:select.option value="">{{ __('fulfillment_pack.all_scan_results') }}</flux:select.option>
                    @foreach ($results as $value => $label)
                        <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:input wire:model.live.debounce.300ms="search" :label="__('common.search')" :placeholder="__('fulfillment_pack.scan_history_search_placeholder')" />
                <flux:input wire:model.live.debounce.300ms="fulfillmentGroupId" type="number" min="1" :label="__('issues.field_fulfillment_group')" />
                <flux:input wire:model.live.debounce.300ms="scannedByUserId" type="number" min="1" :label="__('fulfillment_pack.scanned_by')" />
                <flux:input wire:model.live="dateFrom" type="date" :label="__('fulfillment_pack.date_from')" />
                <flux:input wire:model.live="dateTo" type="date" :label="__('fulfillment_pack.date_to')" />
            </div>
        </div>

        <div class="pack-scan-summary">
            <div><span>{{ __('fulfillment_pack.filtered_scans') }}</span><strong>{{ number_format($summary['filtered_scans']) }}</strong></div>
            <div><span>{{ __('fulfillment_pack.accepted_quantity') }}</span><strong>{{ number_format($summary['accepted_quantity']) }}</strong></div>
            <div><span>{{ __('fulfillment_pack.scan_exceptions') }}</span><strong>{{ number_format($summary['exceptions']) }}</strong></div>
            <div><span>{{ __('fulfillment_pack.latest_scan') }}</span><strong>{{ $summary['latest_scan'] ? \Illuminate\Support\Carbon::parse($summary['latest_scan'])->format('Y-m-d H:i') : '-' }}</strong></div>
        </div>

        <flux:table :paginate="$scans" class="data-table pack-scan-table">
            <flux:table.columns>
                <flux:table.column>{{ __('fulfillment_pack.scan_time') }}</flux:table.column>
                <flux:table.column>{{ __('issues.field_fulfillment_group') }}</flux:table.column>
                <flux:table.column>{{ __('issues.field_sales_order') }}</flux:table.column>
                <flux:table.column>{{ __('fulfillment_pack.scan_result') }}</flux:table.column>
                <flux:table.column>{{ __('fulfillment_pack.barcode') }}</flux:table.column>
                <flux:table.column>{{ __('fulfillment_pack.matched_item') }}</flux:table.column>
                <flux:table.column align="end">{{ __('fulfillment_pack.qty') }}</flux:table.column>
                <flux:table.column>{{ __('fulfillment_pack.scanned_by') }}</flux:table.column>
                <flux:table.column>{{ __('fulfillment_pack.scan_message') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($scans as $scan)
                    <flux:table.row :key="$scan->id">
                        <flux:table.cell>{{ $scan->created_at?->format('Y-m-d H:i:s') ?: '-' }}</flux:table.cell>
                        <flux:table.cell>
                            @if ($scan->fulfillmentGroup?->outboundOrder)
                                <a href="{{ route('outbound.show', $scan->fulfillmentGroup->outboundOrder) }}" wire:navigate><strong>{{ $scan->fulfillmentGroup->reference_no }}</strong></a>
                            @elseif ($scan->fulfillmentGroup)
                                <strong>{{ $scan->fulfillmentGroup->reference_no }}</strong>
                            @else
                                <span class="muted-dash">-</span>
                            @endif
                            <span class="subtle">{{ $scan->tenant?->code }}</span>
                        </flux:table.cell>
                        <flux:table.cell>
                            @if ($scan->salesOrder)
                                <a href="{{ route('sales.orders.show', $scan->salesOrder) }}" wire:navigate><strong>{{ $scan->salesOrder->platform_order_id ?: '#'.$scan->salesOrder->id }}</strong></a>
                            @else
                                <span class="muted-dash">-</span>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:badge color="{{ $scan->result === 'accepted' ? 'green' : (in_array($scan->result, ['wrong_item', 'over_scan'], true) ? 'red' : 'amber') }}">
                                {{ $results[$scan->result] ?? $scan->result }}
                            </flux:badge>
                        </flux:table.cell>
                        <flux:table.cell>
                            <strong class="pack-scan-text">{{ $scan->barcode_scanned }}</strong>
                            @if ($scan->normalized_barcode !== $scan->barcode_scanned)
                                <span class="subtle pack-scan-text">{{ $scan->normalized_barcode }}</span>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>
                            <strong class="pack-scan-text">{{ $scan->sku?->sku ?? $scan->stockItem?->code ?? '-' }}</strong>
                            @if ($scan->stockItem)
                                <span class="subtle pack-scan-text">{{ $scan->stockItem->code }} / {{ $scan->stockItem->short_name ?: $scan->stockItem->name }}</span>
                            @elseif ($scan->sku)
                                <span class="subtle pack-scan-text">{{ $scan->sku->name }}</span>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell align="end">{{ number_format($scan->quantity) }}</flux:table.cell>
                        <flux:table.cell>{{ $scan->scannedBy?->name ?: '-' }}</flux:table.cell>
                        <flux:table.cell><span class="pack-scan-text">{{ $scan->message ?: '-' }}</span></flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="9"><div class="empty-state">{{ __('fulfillment_pack.no_pack_scans') }}</div></flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </section>

    <style>
        .pack-scan-filter-stack {
            display: grid;
            gap: 12px;
            margin-bottom: 14px;
        }

        .pack-scan-filter-row {
            display: grid;
            grid-template-columns: repeat(2, minmax(150px, 190px)) minmax(240px, 1fr) repeat(4, minmax(130px, 160px));
            gap: 12px;
            align-items: end;
        }

        .pack-scan-summary {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 10px;
            margin-bottom: 14px;
        }

        .pack-scan-summary > div {
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 10px 12px;
            background: var(--surface);
        }

        .pack-scan-summary span {
            display: block;
            color: var(--muted);
            font-size: 12px;
            font-weight: 700;
        }

        .pack-scan-summary strong {
            display: block;
            margin-top: 2px;
            font-size: 18px;
        }

        .pack-scan-text {
            display: block;
            max-width: 240px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        @media (max-width: 1100px) {
            .pack-scan-filter-row,
            .pack-scan-summary {
                grid-template-columns: 1fr 1fr;
            }
        }

        @media (max-width: 720px) {
            .pack-scan-filter-row,
            .pack-scan-summary {
                grid-template-columns: 1fr;
            }
        }
    </style>
</div>
