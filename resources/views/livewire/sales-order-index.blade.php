<div class="sales-order-index-page">
    @if (session('status'))
        <div class="active-filter-row">
            <flux:badge color="green">{{ session('status') }}</flux:badge>
        </div>
    @endif
    @if (session('warning'))
        <div class="active-filter-row">
            <flux:badge color="amber">{{ session('warning') }}</flux:badge>
            @if ($pendingCourierExportCarrier)
                <flux:button
                    type="button"
                    size="sm"
                    variant="primary"
                    wire:click="confirmCourierExport"
                    wire:confirm="{{ __('sales_orders.courier_export_confirm_reexport') }}"
                >
                    {{ __('sales_orders.courier_export_confirm_btn') }}
                </flux:button>
            @endif
        </div>
    @endif
    @if (session('error'))
        <div class="active-filter-row">
            <flux:badge color="red">{{ session('error') }}</flux:badge>
        </div>
    @endif

    <section class="table-shell flux-panel">
        @if ($filterWarning)
            <div class="active-filter-row">
                <flux:badge color="red">{{ $filterWarning }}</flux:badge>
            </div>
        @endif

        <div
            class="sales-order-filter-grid sales-order-filter-toolbar"
            data-testid="sales-order-filter-row"
            x-data="{ openFilter: null }"
            x-on:keydown.escape.window="openFilter = null"
        >
            <div class="sales-order-search-row">
                <span class="sales-order-search-icon" aria-hidden="true"></span>
                <input
                    type="text"
                    class="sales-order-search-input"
                    wire:model.live.debounce.300ms="search"
                    aria-label="{{ __('common.search') }}"
                    placeholder="{{ __('sales_orders.search_placeholder') }}"
                >
            </div>

            <details
                @class(['filter-menu', 'is-active' => count((array) $platforms) > 0])
                wire:ignore.self
                x-bind:open="openFilter === 'platform'"
                x-on:click.outside="if (openFilter === 'platform') openFilter = null"
            >
                <summary
                    x-on:click.prevent="openFilter = openFilter === 'platform' ? null : 'platform'"
                    x-bind:aria-expanded="openFilter === 'platform'"
                >
                    <span>{{ __('sales_orders.field_platform') }}</span>
                </summary>
                <div class="filter-panel compact">
                    @forelse ($platformFilterOptions as $platform => $label)
                        <label><input type="checkbox" wire:model.live="platforms" value="{{ $platform }}"> {{ $label }}</label>
                    @empty
                        <span class="subtle">{{ __('sales_orders.all_platforms') }}</span>
                    @endforelse
                </div>
            </details>

            <details
                @class(['filter-menu', 'is-active' => count((array) $shopIds) > 0])
                wire:ignore.self
                x-bind:open="openFilter === 'shop'"
                x-on:click.outside="if (openFilter === 'shop') openFilter = null"
            >
                <summary
                    x-on:click.prevent="openFilter = openFilter === 'shop' ? null : 'shop'"
                    x-bind:aria-expanded="openFilter === 'shop'"
                >
                    <span>{{ __('sales_orders.field_shop') }}</span>
                </summary>
                <div class="filter-panel">
                    @foreach ($shopFilterOptions as $shopId => $label)
                        <label><input type="checkbox" wire:model.live="shopIds" value="{{ $shopId }}"> {{ $label }}</label>
                    @endforeach
                </div>
            </details>

            <details
                @class(['filter-menu', 'is-active' => count((array) $fulfillmentStatusesFilter) > 0])
                wire:ignore.self
                x-bind:open="openFilter === 'fulfillment'"
                x-on:click.outside="if (openFilter === 'fulfillment') openFilter = null"
            >
                <summary
                    x-on:click.prevent="openFilter = openFilter === 'fulfillment' ? null : 'fulfillment'"
                    x-bind:aria-expanded="openFilter === 'fulfillment'"
                >
                    <span>{{ __('sales_orders.filter_fulfillment') }}</span>
                </summary>
                <div class="filter-panel compact">
                    @foreach ($fulfillmentStatuses as $status => $label)
                        <label><input type="checkbox" wire:model.live="fulfillmentStatusesFilter" value="{{ $status }}"> {{ $label }}</label>
                    @endforeach
                </div>
            </details>

            <details
                @class(['filter-menu', 'is-active' => count((array) $orderStatusesFilter) > 0])
                wire:ignore.self
                x-bind:open="openFilter === 'order-status'"
                x-on:click.outside="if (openFilter === 'order-status') openFilter = null"
            >
                <summary
                    x-on:click.prevent="openFilter = openFilter === 'order-status' ? null : 'order-status'"
                    x-bind:aria-expanded="openFilter === 'order-status'"
                >
                    <span>{{ __('sales_orders.filter_order_status') }}</span>
                </summary>
                <div class="filter-panel compact">
                    @foreach ($orderStatuses as $status => $label)
                        <label><input type="checkbox" wire:model.live="orderStatusesFilter" value="{{ $status }}"> {{ $label }}</label>
                    @endforeach
                </div>
            </details>

            <details
                @class(['filter-menu', 'is-active' => count((array) $shippingMethodsFilter) > 0])
                wire:ignore.self
                x-bind:open="openFilter === 'shipping'"
                x-on:click.outside="if (openFilter === 'shipping') openFilter = null"
            >
                <summary
                    x-on:click.prevent="openFilter = openFilter === 'shipping' ? null : 'shipping'"
                    x-bind:aria-expanded="openFilter === 'shipping'"
                >
                    <span>{{ __('sales_orders.filter_shipping') }}</span>
                </summary>
                <div class="filter-panel compact">
                    @foreach ($shippingMethodFilterOptions as $method => $label)
                        <label><input type="checkbox" wire:model.live="shippingMethodsFilter" value="{{ $method }}"> {{ $label }}</label>
                    @endforeach
                </div>
            </details>

            <details
                @class(['filter-menu', 'is-active' => $dateRange !== \App\Support\SalesOrderFilters::DATE_ALL || $dateFrom !== '' || $dateTo !== ''])
                wire:ignore.self
                x-bind:open="openFilter === 'date'"
                x-on:click.outside="if (openFilter === 'date') openFilter = null"
            >
                <summary
                    x-on:click.prevent="openFilter = openFilter === 'date' ? null : 'date'"
                    x-bind:aria-expanded="openFilter === 'date'"
                >
                    <span>{{ __('sales_orders.filter_order_date') }}</span>
                </summary>
                <div class="filter-panel date-filter-panel">
                    @foreach ($dateRanges as $range => $label)
                        <label>
                            <input type="radio" wire:model.live="dateRange" value="{{ $range }}">
                            {{ $label }}
                        </label>
                    @endforeach

                    @if ($dateRange === \App\Support\SalesOrderFilters::DATE_CUSTOM)
                        <div class="date-custom-grid">
                            <flux:input type="date" wire:model.live="dateFrom" :label="__('sales_orders.field_date_from')" />
                            <flux:input type="date" wire:model.live="dateTo" :label="__('sales_orders.field_date_to')" />
                        </div>
                    @endif
                </div>
            </details>

            <details
                @class(['filter-menu', 'is-active' => count((array) $othersFilter) > 0])
                wire:ignore.self
                x-bind:open="openFilter === 'others'"
                x-on:click.outside="if (openFilter === 'others') openFilter = null"
            >
                <summary
                    x-on:click.prevent="openFilter = openFilter === 'others' ? null : 'others'"
                    x-bind:aria-expanded="openFilter === 'others'"
                >
                    <span>{{ __('sales_orders.filter_others') }}</span>
                </summary>
                <div class="filter-panel compact">
                    <label>
                        <input type="checkbox" wire:click="toggleOtherFilter('{{ \App\Support\SalesOrderFilters::OTHER_MULTI_ITEM }}')" @checked(in_array(\App\Support\SalesOrderFilters::OTHER_MULTI_ITEM, (array) $othersFilter, true))>
                        {{ __('sales_orders.other_multi_item') }}
                    </label>
                    <small class="filter-helper">{{ __('sales_orders.other_multi_item_hint') }}</small>
                    <label @class(['is-disabled' => $printWaiting]) title="{{ $printWaiting ? __('sales_orders.print_waiting_printed_conflict') : '' }}">
                        <input type="checkbox" wire:click="toggleOtherFilter('{{ \App\Support\SalesOrderFilters::OTHER_PRINTED }}')" @checked(in_array(\App\Support\SalesOrderFilters::OTHER_PRINTED, (array) $othersFilter, true)) @disabled($printWaiting)>
                        {{ __('sales_orders.other_printed') }}
                    </label>
                    <label>
                        <input type="checkbox" wire:click="toggleOtherFilter('{{ \App\Support\SalesOrderFilters::OTHER_NOT_PRINTED }}')" @checked(in_array(\App\Support\SalesOrderFilters::OTHER_NOT_PRINTED, (array) $othersFilter, true))>
                        {{ __('sales_orders.other_not_printed') }}
                    </label>
                </div>
            </details>

            <label @class(['print-waiting-toggle', 'print-ready-pill', 'compact-filter-toggle', 'is-active' => $printWaiting])>
                <input class="print-ready-toggle-input" type="checkbox" wire:model.live="printWaiting">
                <span class="print-ready-label">{{ __('sales_orders.print_waiting') }}</span>
            </label>
        </div>

        @if ($activeFilterChips !== [])
            <div class="filter-chip-row" data-testid="sales-order-filter-chips">
                @foreach ($activeFilterChips as $chip)
                    <button type="button" class="filter-chip" wire:click="removeFilterChip('{{ $chip['group'] }}', '{{ $chip['value'] }}')">
                        <span>{{ $chip['text'] }}</span>
                        <strong aria-hidden="true">x</strong>
                    </button>
                @endforeach
                <button type="button" class="filter-chip-clear" wire:click="clearAllFilters">
                    {{ __('sales_orders.clear_all_filters') }}
                </button>
            </div>
        @endif

        <div class="sales-order-page-actions" data-testid="sales-order-page-actions">
            <flux:button href="{{ route('sales.orders.import') }}" variant="outline" wire:navigate>
                {{ __('sales_orders.import_btn') }}
            </flux:button>
            <details class="action-menu" data-testid="sales-order-page-export-menu">
                <summary>{{ __('sales_orders.export_menu') }}</summary>
                <div class="action-menu-panel">
                    <a href="{{ route('sales.orders.export', array_filter(array_merge($exportFilters, ['format' => 'csv']), fn ($value) => $value !== null)) }}">
                        {{ __('sales_orders.export_all_csv') }}
                    </a>
                    <a href="{{ route('sales.orders.export', array_filter(array_merge($exportFilters, ['format' => 'xlsx']), fn ($value) => $value !== null)) }}">
                        {{ __('sales_orders.export_all_xlsx') }}
                    </a>
                </div>
            </details>
            <flux:button href="{{ route('sales.orders.create') }}" variant="primary" wire:navigate>
                {{ __('sales_orders.btn_create_order') }}
            </flux:button>
        </div>

        @php
            $hasSelection = count($selectedIds) > 0;
        @endphp

        <div class="sales-order-action-row" data-testid="sales-order-selection-actions">
            <flux:badge color="{{ $hasSelection ? 'blue' : 'zinc' }}">{{ trans_choice('sales_orders.selected_count', count($selectedIds), ['count' => count($selectedIds)]) }}</flux:badge>
            <div class="selection-action-group" data-testid="sales-order-status-actions">
                <span>{{ __('sales_orders.bulk_status_group') }}</span>
                <flux:button type="button" size="sm" variant="outline" wire:click="bulkMarkReady" :disabled="! $hasSelection">
                    {{ __('sales_orders.btn_bulk_mark_ready') }}
                </flux:button>
                <flux:button type="button" size="sm" variant="outline" wire:click="bulkMarkShipped" :disabled="! $hasSelection">
                    {{ __('sales_orders.btn_mark_shipped') }}
                </flux:button>
                <flux:button type="button" size="sm" variant="outline" wire:click="bulkHold" :disabled="! $hasSelection">
                    {{ __('sales_orders.btn_bulk_hold') }}
                </flux:button>
                <flux:button type="button" size="sm" variant="outline" wire:click="bulkReleaseHold" :disabled="! $hasSelection">
                    {{ __('sales_orders.btn_bulk_release_hold') }}
                </flux:button>
                <flux:button
                    type="button"
                    size="sm"
                    variant="danger"
                    wire:click="bulkCancel"
                    wire:confirm="{{ __('sales_orders.bulk_cancel_confirm') }}"
                    :disabled="! $hasSelection"
                >
                    {{ __('sales_orders.btn_bulk_cancel') }}
                </flux:button>
            </div>

            <div class="selection-action-divider"></div>

            <div class="selection-action-group" data-testid="sales-order-selection-export-actions">
                <span>{{ __('sales_orders.bulk_export_group') }}</span>
                @if ($hasSelection)
                    <details class="action-menu small" data-testid="sales-order-selected-export-menu">
                        <summary>{{ __('sales_orders.selected_export_menu') }}</summary>
                        <div class="action-menu-panel">
                            <a href="{{ route('sales.orders.export', array_filter(array_merge($exportFilters, ['ids' => implode(',', $selectedIds), 'format' => 'csv']), fn ($value) => $value !== null)) }}">
                                {{ __('sales_orders.btn_bulk_export_csv') }}
                            </a>
                            <a href="{{ route('sales.orders.export', array_filter(array_merge($exportFilters, ['ids' => implode(',', $selectedIds), 'format' => 'xlsx']), fn ($value) => $value !== null)) }}">
                                {{ __('sales_orders.btn_bulk_export_xlsx') }}
                            </a>
                        </div>
                    </details>
                    <details class="action-menu small" data-testid="sales-order-courier-export-menu">
                        <summary>{{ __('sales_orders.courier_export_menu') }}</summary>
                        <div class="action-menu-panel">
                            <button type="button" wire:click="validateCourierExport('yamato')">
                                {{ __('sales_orders.btn_export_yamato_csv') }}
                            </button>
                            <button type="button" wire:click="validateCourierExport('sagawa')">
                                {{ __('sales_orders.btn_export_sagawa_csv') }}
                            </button>
                        </div>
                    </details>
                @else
                    <button type="button" class="action-menu-disabled" disabled aria-disabled="true">
                        {{ __('sales_orders.selected_export_menu') }}
                    </button>
                    <button type="button" class="action-menu-disabled" disabled aria-disabled="true">
                        {{ __('sales_orders.courier_export_menu') }}
                    </button>
                @endif
            </div>
        </div>

        @php
            $visibleIds = array_map('intval', $visibleOrderIds);
            $selectedLookup = array_flip(array_map('intval', $selectedIds));
            $visibleSelectedCount = count(array_filter($visibleIds, fn ($id) => isset($selectedLookup[$id])));
            $allVisibleSelected = $visibleIds !== [] && $visibleSelectedCount === count($visibleIds);
            $someVisibleSelected = $visibleSelectedCount > 0 && ! $allVisibleSelected;
        @endphp

        <div class="table-scroll">
            <flux:table :paginate="$orders" class="data-table sales-order-table">
                <flux:table.columns>
                    <flux:table.column>
                        <label class="so-checkbox-hitbox so-checkbox-hitbox-header" title="{{ __('sales_orders.select_visible_orders') }}">
                            <input
                                type="checkbox"
                                wire:click="toggleVisibleSelection"
                                @checked($allVisibleSelected)
                                x-data="{ visibleIds: @js($visibleIds) }"
                                x-effect="
                                    const selected = ($wire.selectedIds || []).map((id) => Number(id));
                                    const visibleSelectedCount = visibleIds.filter((id) => selected.includes(Number(id))).length;
                                    $el.checked = visibleIds.length > 0 && visibleSelectedCount === visibleIds.length;
                                    $el.indeterminate = visibleSelectedCount > 0 && visibleSelectedCount < visibleIds.length;
                                "
                                data-indeterminate="{{ $someVisibleSelected ? 'true' : 'false' }}"
                                aria-label="{{ __('sales_orders.select_visible_orders') }}"
                            >
                        </label>
                    </flux:table.column>
                    <flux:table.column>{{ __('sales_orders.col_platform_order_id') }}</flux:table.column>
                    <flux:table.column>{{ __('sales_orders.col_address') }}</flux:table.column>
                    <flux:table.column>{{ __('sales_orders.col_recipient') }}</flux:table.column>
                    <flux:table.column>{{ __('sales_orders.field_sku') }}</flux:table.column>
                    <flux:table.column>{{ __('sales_orders.col_shipping_method') }}</flux:table.column>
                    <flux:table.column>{{ __('sales_orders.col_tracking_no') }}</flux:table.column>
                    <flux:table.column>{{ __('sales_orders.col_order_status') }}</flux:table.column>
                    <flux:table.column>{{ __('sales_orders.col_created_at') }}</flux:table.column>
                    <flux:table.column>{{ __('sales_orders.col_note') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @forelse ($orders as $order)
                        <flux:table.row :key="$order->id">
                            <flux:table.cell class="so-select-cell">
                                <label class="so-checkbox-hitbox">
                                    <input type="checkbox" wire:model.live="selectedIds" value="{{ $order->id }}" aria-label="{{ __('sales_orders.select_order') }} {{ $order->platform_order_id ?: $order->id }}">
                                </label>
                            </flux:table.cell>
                            <flux:table.cell class="so-order-cell">
                                <flux:link href="{{ route('sales.orders.show', $order) }}" wire:navigate>
                                    <strong>{{ $order->platform_order_id ?: '-' }}</strong>
                                </flux:link>
                                <span class="subtle">{{ $order->shop->name }}</span>
                                <span class="subtle">{{ $order->shop->tenant->code }} / {{ $order->shop->platform }}</span>
                            </flux:table.cell>
                            <flux:table.cell class="so-address-cell">
                                <span class="subtle">{{ $order->recipient_postal_code ?: '-' }}</span>
                                <span class="subtle">{{ trim(($order->recipient_state ?? '').' '.($order->recipient_city ?? '')) ?: '-' }}</span>
                                <span class="subtle">{{ $order->recipient_address_line1 ?: '-' }}</span>
                                @if ($order->recipient_address_line2)
                                    <span class="subtle">{{ $order->recipient_address_line2 }}</span>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell class="so-recipient-cell">
                                <strong>{{ $order->recipient_name ?: '-' }}</strong>
                                <span class="subtle">{{ $order->recipient_phone ?: '-' }}</span>
                            </flux:table.cell>
                            <flux:table.cell class="so-items-cell">
                                @php
                                    $readyLines = $order->lines->where('line_status', \App\Models\SalesOrderLine::STATUS_READY);
                                @endphp

                                @forelse ($readyLines as $line)
                                    @php
                                        $skuCode = $line->sku?->sku ?? '-';
                                        $skuLabel = trim((string) ($line->sku?->stockItem?->short_name ?: $line->sku?->name ?: $line->sku?->stockItem?->name ?: ''));
                                    @endphp
                                    <div class="so-item-line">
                                        <div class="so-sku-line">
                                            @if ($line->quantity > 1)
                                                <strong class="danger-text">{{ $line->quantity }}</strong>
                                            @else
                                                <span class="subtle">{{ $line->quantity }}</span>
                                            @endif
                                            <span class="subtle">x</span>
                                            <strong>{{ $skuCode }}</strong>
                                        </div>
                                        @if ($skuLabel !== '')
                                            <span class="subtle so-sku-label" title="{{ $skuLabel }}">{{ $skuLabel }}</span>
                                        @endif
                                    </div>
                                @empty
                                    <span class="subtle">{{ __('sales_orders.no_lines') }}</span>
                                @endforelse
                            </flux:table.cell>
                            <flux:table.cell class="so-control-cell">
                                <select
                                    class="table-control"
                                    aria-label="{{ __('sales_orders.col_shipping_method') }} {{ $order->platform_order_id ?: $order->id }}"
                                    x-on:change="$wire.updateShippingMethod({{ $order->id }}, $event.target.value)"
                                >
                                    <option value="">{{ __('sales_orders.shipping_method_unset') }}</option>
                                    @foreach ($shippingMethodOptions as $value => $label)
                                        <option value="{{ $value }}" @selected((string) ($order->shipping_method_id ?? '') === (string) $value)>
                                            {{ $label }}
                                        </option>
                                    @endforeach
                                </select>
                            </flux:table.cell>
                            <flux:table.cell class="so-control-cell">
                                <div class="tracking-field">
                                    <input
                                        type="text"
                                        class="table-control"
                                        wire:key="tracking-{{ $order->id }}"
                                        wire:model.live.debounce.800ms="trackingDrafts.{{ $order->id }}"
                                        placeholder="{{ __('sales_orders.tracking_no_placeholder') }}"
                                        aria-label="{{ __('sales_orders.col_tracking_no') }} {{ $order->platform_order_id ?: $order->id }}"
                                    >

                                    <span
                                        class="tracking-unsaved"
                                        wire:dirty
                                        wire:target="trackingDrafts.{{ $order->id }}"
                                    >
                                        {{ __('sales_orders.tracking_unsaved') }}
                                    </span>
                                </div>
                            </flux:table.cell>
                            <flux:table.cell>
                                <div class="status-stack">
                                    <flux:badge color="{{ $this->fulfillmentStatusColor($order->fulfillment_status) }}">
                                        {{ $this->fulfillmentStatusLabel($order->fulfillment_status) }}
                                    </flux:badge>
                                    <flux:badge color="{{ $this->orderStatusColor($order->order_status) }}">
                                        {{ $this->orderStatusLabel($order->order_status) }}
                                    </flux:badge>
                                </div>
                            </flux:table.cell>
                            <flux:table.cell>
                                <strong>{{ $order->order_date?->format('Y-m-d') ?? $order->created_at->format('Y-m-d') }}</strong>
                                @if ($order->courier_csv_exported_at)
                                    <span class="subtle">
                                        {{ __('sales_orders.printed_date_label') }} {{ $order->courier_csv_exported_at->timezone('Asia/Tokyo')->format('Y-m-d') }}
                                    </span>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell class="so-note-cell">
                                @if ($order->note)
                                    <span title="{{ $order->note }}">{{ $order->note }}</span>
                                @else
                                    <span class="subtle">-</span>
                                @endif
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="10">
                                <div class="empty-state">{{ __('sales_orders.empty_state') }}</div>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </div>
    </section>
</div>
