@php
    $parsedRowsCollection = collect($parsedRows);
    $orderGroups = $parsedRowsCollection
        ->filter(fn ($row) => filled($row['platform_order_id'] ?? ''))
        ->groupBy('platform_order_id');
    $orderCount = $orderGroups->count();
    $duplicateOrderCount = $orderGroups
        ->filter(fn ($rows) => $rows->contains(fn ($row) => $row['is_duplicate'] ?? false))
        ->count();
    $validOrderCount = $orderGroups
        ->filter(fn ($rows) => ! $rows->contains(fn ($row) => $row['is_duplicate'] ?? false)
            && $rows->every(fn ($row) => ($row['errors'] ?? []) === []))
        ->count();
    $lineCount = count($parsedRows);
@endphp

<div class="sales-order-import-page">
    <x-flash-toast />

    <section class="table-shell flux-panel form-panel">
        <div class="form-panel-header paste-import-actions">
            <div>
                <strong>{{ __('sales_orders.paste_import_grid_title') }}</strong>
                <span>{{ __('sales_orders.paste_import_grid_subtitle') }}</span>
            </div>
            <flux:button href="{{ route('sales.orders.index') }}" variant="outline" wire:navigate>
                {{ __('sales_orders.btn_back_orders') }}
            </flux:button>
        </div>

        <div class="form-grid">
            <flux:select wire:model="shopId" wire:change="selectShop($event.target.value)" required :label="__('sales_orders.field_shop')">
                <flux:select.option value="">{{ __('sales_orders.field_shop') }}</flux:select.option>
                @foreach ($shops as $shop)
                    <flux:select.option value="{{ $shop->id }}">
                        {{ $shop->tenant->code }} / {{ $shop->code }} - {{ $shop->name }}
                    </flux:select.option>
                @endforeach
            </flux:select>
        </div>

        @error('shopId') <p class="form-error">{{ $message }}</p> @enderror
        @error('grid') <p class="form-error">{{ $message }}</p> @enderror

        <div class="paste-mapping-panel">
            <div class="paste-mapping-toolbar">
                <div>
                    <strong>{{ __('sales_orders.paste_import_mapping_heading') }}</strong>
                    <span>{{ __('sales_orders.paste_import_mapping_hint') }}</span>
                </div>
            </div>

            <div class="paste-template-row">
                <flux:select wire:model.live="selectedTemplateId" :label="__('sales_orders.paste_import_template_load')">
                    <flux:select.option value="">{{ __('sales_orders.paste_import_template_select') }}</flux:select.option>
                    @foreach ($templates as $template)
                        <flux:select.option value="{{ $template->id }}">
                            {{ $template->name }}{{ $template->is_default ? ' *' : '' }}
                        </flux:select.option>
                    @endforeach
                </flux:select>
                <flux:button type="button" variant="primary" class="paste-template-action" wire:click="loadTemplate" :disabled="$selectedTemplateId === ''">
                    {{ __('sales_orders.paste_import_template_load_btn') }}
                </flux:button>
                <flux:input wire:model.live="templateName" :label="__('sales_orders.paste_import_template_name')" />
                <flux:button type="button" variant="primary" class="paste-template-action" wire:click="saveTemplate">
                    {{ __('sales_orders.paste_import_template_save_btn') }}
                </flux:button>
            </div>

            <label class="inline-check">
                <input type="checkbox" wire:model.live="saveTemplateAsDefault">
                <span>{{ __('sales_orders.paste_import_template_default') }}</span>
            </label>
        </div>

        <div class="paste-import-instructions">
            <p>{{ __('sales_orders.paste_import_hint') }}</p>
            <p>{{ __('sales_orders.paste_import_column_mapping_hint') }}</p>
        </div>

        <div
            class="paste-grid-shell"
            data-testid="paste-import-grid"
            x-data="{
                pasteCells(event, startRow, startCol) {
                    const text = event.clipboardData.getData('text/plain');
                    if (! text) return;

                    event.preventDefault();

                    const rows = text.replace(/\r\n/g, '\n').replace(/\r/g, '\n').split('\n');
                    if (rows.at(-1) === '') rows.pop();

                    rows.forEach((line, rowOffset) => {
                        this.normalizeWechatRow(line.split('\t')).forEach((value, colOffset) => {
                            const input = this.$root.querySelector(`[data-grid-cell='${startRow + rowOffset}-${startCol + colOffset}']`);
                            if (! input) return;

                            input.value = value;
                            input.dispatchEvent(new Event('input', { bubbles: true }));
                        });
                    });
                },
                normalizeWechatRow(cells) {
                    const hasOrderDate = /^\d{4}\/\d{1,2}\/\d{1,2}$/.test((cells[2] || '').trim());
                    const hasContinuationProduct = !(cells[1] || '').trim() && (cells[3] || '').trim() && ((cells[5] || '').trim() || (cells[6] || '').trim());
                    const looksLikeWechat = cells.length >= 7 && (hasOrderDate || hasContinuationProduct);
                    if (! looksLikeWechat) return cells;

                    const normalized = [...cells];

                    for (let i = 0; i < 3; i++) {
                        const sku = (normalized[4] || '').trim();
                        const nextSku = (normalized[5] || '').trim();
                        const qty = (normalized[5] || '').trim();
                        const nextQty = (normalized[6] || '').trim();

                        if (! sku && nextSku && ! /^[1-9]\d*$/.test(qty)) {
                            normalized.splice(4, 1);
                            normalized.push('');
                            continue;
                        }

                        if (sku && ! qty && /^[1-9]\d*$/.test(nextQty)) {
                            normalized.splice(5, 1);
                            normalized.push('');
                            continue;
                        }

                        break;
                    }

                    return normalized;
                },
            }"
        >
            <table class="paste-grid">
                <colgroup>
                    <col class="paste-grid-row-number">
                    @foreach ($columnLabels as $label)
                        <col class="paste-grid-data-column">
                    @endforeach
                </colgroup>
                <thead>
                    <tr>
                        <th class="paste-grid-corner"></th>
                        @foreach ($columnLabels as $label)
                            <th>{{ $label }}</th>
                        @endforeach
                    </tr>
                    <tr class="paste-grid-map-row">
                        <th>{{ __('sales_orders.paste_import_column_map_short') }}</th>
                        @foreach ($columnLabels as $colIndex => $label)
                            <th>
                                <select
                                    class="paste-column-map-select"
                                    wire:model.live="columnFieldMapping.{{ $colIndex }}"
                                    aria-label="{{ $label }} {{ __('sales_orders.paste_import_column_map_short') }}"
                                >
                                    @foreach ($fieldOptions as $value => $optionLabel)
                                        <option value="{{ $value }}">{{ $optionLabel }}</option>
                                    @endforeach
                                </select>
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($grid as $rowIndex => $row)
                        <tr>
                            <th>{{ $rowIndex + 1 }}</th>
                            @foreach ($row as $colIndex => $value)
                                <td>
                                    <input
                                        type="text"
                                        data-grid-cell="{{ $rowIndex }}-{{ $colIndex }}"
                                        wire:model="grid.{{ $rowIndex }}.{{ $colIndex }}"
                                        x-on:paste="pasteCells($event, {{ $rowIndex }}, {{ $colIndex }})"
                                    >
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="form-actions">
            <flux:button type="button" variant="outline" wire:click="clearGrid">
                {{ __('sales_orders.paste_import_clear_btn') }}
            </flux:button>
            <flux:button type="button" variant="primary" wire:click="preview">
                {{ __('sales_orders.paste_import_preview_btn') }}
            </flux:button>
        </div>
    </section>

    @if ($parsed)
        <section class="table-shell flux-panel form-panel">
            <div class="form-panel-header">
                <div>
                    <strong>{{ __('sales_orders.import_summary', [
                        'total' => $orderCount,
                        'duplicates' => $duplicateOrderCount,
                        'valid' => $validOrderCount,
                        'lines' => $lineCount,
                    ]) }}</strong>
                    <span>{{ $hasErrors ? __('sales_orders.import_has_errors') : __('sales_orders.import_row_ok') }}</span>
                </div>
            </div>

            <flux:table class="data-table">
                <flux:table.columns>
                    <flux:table.column>{{ __('sales_orders.import_col_row') }}</flux:table.column>
                    <flux:table.column>{{ __('sales_orders.import_col_status') }}</flux:table.column>
                    <flux:table.column>{{ __('sales_orders.import_col_order') }}</flux:table.column>
                    <flux:table.column>{{ __('sales_orders.import_col_sku') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('sales_orders.import_col_qty') }}</flux:table.column>
                    <flux:table.column>{{ __('sales_orders.import_col_errors') }}</flux:table.column>
                    <flux:table.column>{{ __('sales_orders.import_col_action') }}</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($parsedRows as $row)
                        <flux:table.row :key="$row['row'].'-'.$row['sku'].'-'.$row['platform_order_id']">
                            <flux:table.cell>{{ $row['row'] }}</flux:table.cell>
                            <flux:table.cell>
                                <flux:badge color="{{ ($row['is_duplicate'] ?? false) ? 'amber' : (($row['sku_not_found'] ?? false) ? 'red' : ($row['errors'] === [] ? 'green' : 'red')) }}">
                                    @if ($row['is_duplicate'] ?? false)
                                        {{ __('sales_orders.import_row_duplicate') }}
                                    @elseif ($row['sku_not_found'] ?? false)
                                        {{ __('sales_orders.import_row_sku_not_found') }}
                                    @else
                                        {{ $row['errors'] === [] ? __('sales_orders.import_row_ok') : __('sales_orders.import_col_errors') }}
                                    @endif
                                </flux:badge>
                            </flux:table.cell>
                            <flux:table.cell>{{ $row['platform_order_id'] ?: '-' }}</flux:table.cell>
                            <flux:table.cell>{{ $row['sku'] ?: '-' }}</flux:table.cell>
                            <flux:table.cell align="end">{{ $row['quantity'] ?: '-' }}</flux:table.cell>
                            <flux:table.cell>
                                @if ($row['is_duplicate'] ?? false)
                                    {{ __('sales_orders.import_duplicate_skip') }}
                                @else
                                    {{ $row['errors'] === [] ? '-' : implode(' ', $row['errors']) }}
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>
                                @if (($row['sku_not_found'] ?? false) && filled($row['sku'] ?? '') && filled($row['shop_id'] ?? null))
                                    <flux:button
                                        as="a"
                                        variant="primary"
                                        size="sm"
                                        href="{{ route('skus.create', [
                                            'tenant_id' => $row['tenant_id'] ?? '',
                                            'shop_id' => $row['shop_id'],
                                            'sku' => $row['sku'],
                                            'name' => $row['platform_product_name'] ?? $row['sku'],
                                            'platform_sku' => $row['sku'],
                                        ]) }}"
                                        target="_blank"
                                        rel="noopener"
                                    >
                                        {{ __('sales_orders.import_add_sku') }}
                                    </flux:button>
                                @else
                                    -
                                @endif
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>

            <div class="form-actions paste-import-confirm-actions">
                <flux:button type="button" variant="primary" wire:click="import" :disabled="$hasErrors">
                    {{ __('sales_orders.import_confirm_btn') }}
                </flux:button>
            </div>
        </section>
    @endif
</div>
