<div class="issue-create-page">
    <section class="table-shell flux-panel form-panel">
        <div class="form-panel-header">
            <div>
                <strong>{{ __('issues.section_issue') }}</strong>
                <span>{{ __('issues.no_inventory_hint') }}</span>
            </div>
        </div>

        <div class="form-grid three">
            @if ($showTenantSelect)
                <flux:select wire:model.live="tenantId" required :label="__('issues.field_tenant')">
                    <flux:select.option value="">{{ __('common.select_tenant') }}</flux:select.option>
                    @foreach ($tenants as $tenant)
                        <flux:select.option value="{{ $tenant->id }}">{{ $tenant->code }} - {{ $tenant->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endif

            <flux:select wire:model.live="issueType" required :label="__('issues.field_issue_type')">
                @foreach ($types as $value => $label)
                    <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="status" required :label="__('issues.field_status')">
                @foreach ($statuses as $value => $label)
                    <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:input wire:model="reportedAt" type="datetime-local" :label="__('issues.field_reported_at')" />
            <flux:input wire:model="reportedBy" :label="__('issues.field_reported_by')" />
        </div>


        <div class="issue-picker-grid">
            <div class="issue-picker">
                <flux:input
                    wire:model.live.debounce.300ms="salesOrderSearch"
                    :label="__('issues.field_sales_order')"
                    :placeholder="__('issues.sales_order_search_placeholder')"
                />

                @if ($selectedSalesOrder)
                    <div class="issue-selected-order">
                        <div>
                            <strong>{{ $selectedSalesOrder->platform_order_id ?: '#'.$selectedSalesOrder->id }}</strong>
                            <span>{{ $selectedSalesOrder->recipient_name ?: '-' }} / {{ $selectedSalesOrder->tracking_no ?: '-' }}</span>
                        </div>
                        <flux:button type="button" size="xs" variant="outline" wire:click="clearSalesOrder">{{ __('common.clear') }}</flux:button>
                    </div>
                @endif

                @if (strlen(trim($salesOrderSearch)) >= 2)
                    <div class="issue-picker-results">
                        @forelse ($salesOrderResults as $order)
                            <button type="button" class="issue-picker-result" wire:click="selectSalesOrder({{ $order->id }})">
                                <strong>{{ $order->platform_order_id ?: '#'.$order->id }}</strong>
                                <span>{{ $order->recipient_name ?: '-' }} / {{ $order->tracking_no ?: '-' }}</span>
                            </button>
                        @empty
                            <div class="issue-picker-empty">{{ __('issues.no_order_results') }}</div>
                        @endforelse
                    </div>
                @endif
            </div>

            <div class="issue-picker">
                <flux:input
                    wire:model.live.debounce.300ms="outboundOrderSearch"
                    :label="__('issues.field_outbound_order')"
                    :placeholder="__('issues.outbound_order_search_placeholder')"
                />

                @if ($selectedOutboundOrder)
                    <div class="issue-selected-order">
                        <div>
                            <strong>{{ $selectedOutboundOrder->ref ?: '#'.$selectedOutboundOrder->id }}</strong>
                            <span>{{ $selectedOutboundOrder->warehouse?->code ?: '-' }} / {{ $selectedOutboundOrder->status }}</span>
                        </div>
                        <flux:button type="button" size="xs" variant="outline" wire:click="clearOutboundOrder">{{ __('common.clear') }}</flux:button>
                    </div>
                @endif

                @if (strlen(trim($outboundOrderSearch)) >= 2)
                    <div class="issue-picker-results">
                        @forelse ($outboundOrderResults as $order)
                            <button type="button" class="issue-picker-result" wire:click="selectOutboundOrder({{ $order->id }})">
                                <strong>{{ $order->ref ?: '#'.$order->id }}</strong>
                                <span>
                                    {{ $order->warehouse?->code ?: '-' }} / {{ $order->status }}
                                </span>
                            </button>
                        @empty
                            <div class="issue-picker-empty">{{ __('issues.no_order_results') }}</div>
                        @endforelse
                    </div>
                @endif
            </div>
        </div>

        <label class="form-grid-wide">
            <span>{{ __('issues.field_note') }}</span>
            <textarea wire:model="note" rows="3"></textarea>
        </label>

        @foreach (['salesOrderId', 'outboundOrderId', 'issue_type', 'lines', 'manualLines', 'unknownIssue'] as $field)
            @error($field) <p class="form-error">{{ $message }}</p> @enderror
        @endforeach
    </section>

    @if ($salesOrderLines)
        <section class="table-shell flux-panel form-panel">
            <div class="form-panel-header">
                <div>
                    <strong>{{ __('issues.section_sales_order_lines') }}</strong>
                    <span>{{ __('issues.sales_order_lines_hint') }}</span>
                </div>
            </div>

            <flux:table class="data-table">
                <flux:table.columns>
                    <flux:table.column>{{ __('issues.col_select') }}</flux:table.column>
                    <flux:table.column>{{ __('issues.col_sku_stock') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('issues.col_source_qty') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('issues.field_qty') }}</flux:table.column>
                    <flux:table.column>{{ __('issues.field_condition') }}</flux:table.column>
                    <flux:table.column>{{ __('issues.field_action') }}</flux:table.column>
                    <flux:table.column>{{ __('issues.field_line_note') }}</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($salesOrderLines as $index => $line)
                        <flux:table.row :key="$line['sales_order_line_id']">
                            <flux:table.cell><input type="checkbox" wire:model.live="salesOrderLines.{{ $index }}.selected"></flux:table.cell>
                            <flux:table.cell>
                                <strong>{{ $line['label'] }}</strong>
                                <span class="subtle">{{ $line['stock_item'] ?: __('common.sku_types.virtual_bundle') }}</span>
                            </flux:table.cell>
                            <flux:table.cell align="end">{{ number_format($line['max_qty']) }}</flux:table.cell>
                            <flux:table.cell align="end"><input type="number" min="1" max="{{ $line['max_qty'] }}" wire:model="salesOrderLines.{{ $index }}.qty"></flux:table.cell>
                            <flux:table.cell>
                                <select class="table-control" wire:model="salesOrderLines.{{ $index }}.condition">
                                    @foreach ($conditions as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </flux:table.cell>
                            <flux:table.cell>
                                <select class="table-control" wire:model="salesOrderLines.{{ $index }}.action">
                                    @foreach ($actions as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </flux:table.cell>
                            <flux:table.cell><input type="text" wire:model="salesOrderLines.{{ $index }}.note"></flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </section>
    @endif

    <section class="table-shell flux-panel form-panel">
        <div class="form-panel-header">
            <div>
                <strong>{{ __('issues.section_manual_lines') }}</strong>
                <span>{{ __('issues.manual_lines_hint') }}</span>
            </div>
            <flux:button type="button" variant="outline" wire:click="addManualLine">{{ __('issues.btn_add_line') }}</flux:button>
        </div>

        @foreach ($manualLines as $index => $line)
            @php
                $stockItem = ($line['sku_id'] ?? '') === '' ? $manualLineStockItems->get((int) ($line['stock_item_id'] ?? 0)) : null;
                $skuOptions = collect($skuOptionsByLine[$index] ?? [])->map(fn ($sku) => [
                    'value' => $sku->id,
                    'label' => $sku->sku,
                    'meta' => trim(($sku->stockItem?->code ? $sku->stockItem->code.' / ' : '').($sku->displayName() ?: '')),
                ]);
                $selectedSku = $skuOptions->firstWhere('value', (int) ($line['sku_id'] ?? 0));
            @endphp
            <div class="line-row">
                <x-searchable-select
                    wire:key="issue-manual-sku-picker-{{ $index }}-{{ md5($tenantId.'|'.($line['sku_id'] ?? '')) }}"
                    :label="__('issues.field_sku')"
                    model="manualLines.{{ $index }}.sku_id"
                    search-model="manualSkuSearches.{{ $index }}"
                    :options="$skuOptions"
                    :selected-label="$selectedSku['label'] ?? ($manualSkuSearches[$index] ?? '')"
                    :placeholder="$tenantId === '' ? __('common.select_tenant') : __('inventory.search_placeholder')"
                    empty-label="No results"
                    :disabled="$tenantId === ''"
                />
                @if ($stockItem)
                    <div class="issue-stock-context">
                        <span>{{ __('issues.field_stock_item') }}</span>
                        <strong>{{ $stockItem->code }}</strong>
                        <small>{{ $stockItem->displayName() }}</small>
                    </div>
                @endif
                <flux:input wire:model="manualLines.{{ $index }}.qty" type="number" min="1" step="1" :label="__('issues.field_qty')" />
                <flux:select wire:model="manualLines.{{ $index }}.condition" :label="__('issues.field_condition')">
                    @foreach ($conditions as $value => $label)
                        <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>
                <button type="button" class="remove-line-btn {{ count($manualLines) <= 1 ? 'invisible' : '' }}" wire:click="removeManualLine({{ $index }})">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z"/></svg>
                </button>
            </div>
            <div class="form-grid two-one">
                <flux:select wire:model="manualLines.{{ $index }}.action" :label="__('issues.field_action')">
                    @foreach ($actions as $value => $label)
                        <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="manualLines.{{ $index }}.note" :label="__('issues.field_line_note')" />
            </div>
        @endforeach
    </section>

    <div class="form-actions">
        <flux:button href="{{ route('issues.index') }}" variant="outline" wire:navigate>{{ __('common.cancel') }}</flux:button>
        <flux:button type="button" variant="primary" wire:click="save">{{ __('issues.btn_save') }}</flux:button>
    </div>

    <style>
        .issue-picker-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
            margin-top: 16px;
        }

        .issue-picker {
            display: grid;
            gap: 8px;
            min-width: 0;
        }

        .issue-context {
            display: inline-grid;
            gap: 2px;
            margin-top: 12px;
            border: 1px solid var(--border);
            border-radius: 7px;
            padding: 8px 10px;
            background: var(--surface);
        }

        .issue-context span {
            color: var(--muted);
            font-size: 12px;
            font-weight: 700;
        }

        .issue-stock-context {
            display: grid;
            gap: 2px;
            align-self: end;
            border: 1px solid var(--border);
            border-radius: 7px;
            padding: 7px 9px;
            background: var(--surface);
        }

        .issue-stock-context span,
        .issue-stock-context small {
            color: var(--muted);
            font-size: 12px;
            font-weight: 700;
        }

        .issue-selected-order,
        .issue-picker-result,
        .issue-picker-empty {
            border: 1px solid var(--border);
            border-radius: 7px;
            padding: 9px 10px;
            background: var(--surface);
        }

        .issue-selected-order {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }

        .issue-selected-order strong,
        .issue-selected-order span,
        .issue-picker-result strong,
        .issue-picker-result span {
            display: block;
        }

        .issue-selected-order span,
        .issue-picker-result span,
        .issue-picker-empty {
            color: var(--muted);
            font-size: 12px;
        }

        .issue-picker-results {
            display: grid;
            gap: 6px;
        }

        .issue-picker-result {
            width: 100%;
            text-align: left;
            cursor: pointer;
        }

        .issue-picker-result:hover {
            border-color: var(--accent);
        }

        @media (max-width: 760px) {
            .issue-picker-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</div>
