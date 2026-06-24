<div class="sales-order-index-page">
    <x-flash-toast />

@if ($pendingExportWarning)
        <div class="active-filter-row">
            <div class="export-warning-message">{{ $pendingExportWarning }}</div>
            @if ($pendingMarketplaceNoticePlatform)
                <flux:button
                    type="button"
                    size="sm"
                    variant="primary"
                    wire:click="confirmMarketplaceShippingNoticeExport"
                >
                    {{ __('sales_orders.marketplace_notice_export_confirm_btn') }}
                </flux:button>
            @endif
            @if ($pendingMarketplaceNoticePlatform)
                <flux:button
                    type="button"
                    size="sm"
                    variant="outline"
                    wire:click="cancelPendingExport"
                >
                    {{ __('sales_orders.btn_cancel_pending_export') }}
                </flux:button>
            @endif
        </div>
    @endif
    @if ($showReadyCombinePrompt)
        <div class="tracking-import-backdrop" wire:key="sales-order-ready-combine-modal">
            <section class="tracking-import-modal flux-panel">
                <header class="tracking-import-header">
                    <div>
                        <h2>{{ __('sales_orders.ready_combine_title') }}</h2>
                        <p>
                            {{ __('sales_orders.ready_combine_message') }}
                            @if ($pendingReadySuggestionCount > 0)
                                {{ __('sales_orders.ready_unfulfilled_suggestion', ['count' => $pendingReadySuggestionCount]) }}
                            @endif
                        </p>
                    </div>
                    <flux:button
                        type="button"
                        variant="ghost"
                        size="sm"
                        icon="x-mark"
                        inset
                        :aria-label="__('sales_orders.btn_cancel_edit')"
                        wire:click="cancelReadyCombine"
                    >
                    </flux:button>
                </header>

                <div class="ready-combine-body">
                    @if ($readyWarehouseOptions->count() > 1)
                        <flux:select wire:model.live="readyWarehouseId" :label="__('fulfillment_groups.field_warehouse')">
                            <flux:select.option value="">{{ __('fulfillment_groups.select_warehouse') }}</flux:select.option>
                            @foreach ($readyWarehouseOptions as $warehouse)
                                <flux:select.option value="{{ $warehouse->id }}">{{ $warehouse->code }} / {{ $warehouse->name }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    @else
                        <div>
                            <span class="subtle">{{ __('fulfillment_groups.field_warehouse') }}</span>
                            <strong>{{ $readyWarehouseOptions->first()?->code }} / {{ $readyWarehouseOptions->first()?->name }}</strong>
                        </div>
                    @endif
                </div>

                <footer class="tracking-import-footer ready-combine-footer">
                    @if ($pendingReadyJoinableGroupCount > 0 || $pendingReadyCombineCandidateCount > 0)
                        <flux:button type="button" variant="primary" class="ready-combine-action" wire:click="confirmReadyCombine">
                            {{ __('sales_orders.ready_combine_confirm') }}
                        </flux:button>
                    @else
                        <flux:button type="button" variant="primary" class="ready-combine-action" wire:click="confirmReadyCombine">
                            {{ __('sales_orders.ready_arrange_confirm') }}
                        </flux:button>
                    @endif
                    <flux:button type="button" variant="primary" class="ready-combine-action" wire:click="declineReadyCombine">
                        {{ __('sales_orders.ready_combine_decline') }}
                    </flux:button>
                </footer>
            </section>
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
            <details
                @class(['filter-menu', 'is-active' => count((array) $platforms) > 0])
                x-bind:class="{ 'is-active': $wire.platforms.length > 0 }"
                wire:ignore.self
                x-bind:open="openFilter === 'platform'"
                x-on:click.outside="if (openFilter === 'platform') openFilter = null"
            >
                <summary
                    class="filter-button"
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
                x-bind:class="{ 'is-active': $wire.shopIds.length > 0 }"
                wire:ignore.self
                x-bind:open="openFilter === 'shop'"
                x-on:click.outside="if (openFilter === 'shop') openFilter = null"
            >
                <summary
                    class="filter-button"
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
                x-bind:class="{ 'is-active': $wire.fulfillmentStatusesFilter.length > 0 }"
                wire:ignore.self
                x-bind:open="openFilter === 'fulfillment'"
                x-on:click.outside="if (openFilter === 'fulfillment') openFilter = null"
            >
                <summary
                    class="filter-button"
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
                x-bind:class="{ 'is-active': $wire.orderStatusesFilter.length > 0 }"
                wire:ignore.self
                x-bind:open="openFilter === 'order-status'"
                x-on:click.outside="if (openFilter === 'order-status') openFilter = null"
            >
                <summary
                    class="filter-button"
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
                x-bind:class="{ 'is-active': $wire.shippingMethodsFilter.length > 0 }"
                wire:ignore.self
                x-bind:open="openFilter === 'shipping'"
                x-on:click.outside="if (openFilter === 'shipping') openFilter = null"
            >
                <summary
                    class="filter-button"
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
                x-bind:class="{ 'is-active': $wire.dateRange !== '{{ \App\Support\SalesOrderFilters::DATE_ALL }}' || $wire.dateFrom !== '' || $wire.dateTo !== '' }"
                wire:ignore.self
                x-bind:open="openFilter === 'date'"
                x-on:click.outside="if (openFilter === 'date') openFilter = null"
            >
                <summary
                    class="filter-button"
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
                x-bind:class="{ 'is-active': $wire.othersFilter.length > 0 }"
                wire:ignore.self
                x-bind:open="openFilter === 'others'"
                x-on:click.outside="if (openFilter === 'others') openFilter = null"
            >
                <summary
                    class="filter-button"
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
                </div>
            </details>

        </div>

        <div class="sales-order-search-bar-row">
            <div class="sales-order-search-row">
                <flux:icon.magnifying-glass class="sales-order-search-icon" />
                <input
                    type="text"
                    class="sales-order-search-input"
                    wire:model.live.debounce.300ms="search"
                    aria-label="{{ __('common.search') }}"
                    placeholder="{{ __('sales_orders.search_placeholder') }}"
                >
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
        </div>

        <div
            x-data="{
                selected: $wire.entangle('selectedIds'),
                visible: $wire.entangle('visibleOrderIds'),
                selectedList() { return (this.selected || []).map(String); },
                visibleList() { return (this.visible || []).map(String); },
                has() { return this.selectedList().length > 0; },
                isSelected(id) { return this.selectedList().includes(String(id)); },
                toggleRow(id) {
                    id = String(id);
                    const list = this.selectedList();
                    const i = list.indexOf(id);

                    if (i === -1) {
                        this.selected = list.concat([id]);
                        return;
                    }

                    list.splice(i, 1);
                    this.selected = list;
                },
                get allVisibleSelected() {
                    const v = this.visibleList();
                    const selected = this.selectedList();
                    return v.length > 0 && v.every((id) => selected.includes(id));
                },
                get someVisibleSelected() {
                    const v = this.visibleList();
                    const selected = this.selectedList();
                    const n = v.filter((id) => selected.includes(id)).length;
                    return n > 0 && n < v.length;
                },
                toggleAll() {
                    const v = this.visibleList();

                    if (this.allVisibleSelected) {
                        this.selected = this.selectedList().filter((id) => ! v.includes(id));
                        return;
                    }

                    this.selected = Array.from(new Set(this.selectedList().concat(v)));
                },
            }"
        >
        <div class="sales-order-page-actions" data-testid="sales-order-page-actions" x-data="{ openActionMenu: null }">
            <details
                class="action-menu primary action-menu-align-left"
                data-testid="sales-order-page-import-menu"
                x-bind:open="openActionMenu === 'import'"
                x-on:click.outside="if (openActionMenu === 'import') openActionMenu = null"
            >
                <summary x-on:click.prevent="openActionMenu = openActionMenu === 'import' ? null : 'import'"><span class="action-menu-label"><flux:icon.arrow-up-tray />{{ __('sales_orders.import_btn') }}</span></summary>
                <div class="action-menu-panel action-menu-panel-sectioned">
                    <div class="action-menu-section" data-testid="sales-order-orders-import-menu">
                        <span>{{ __('sales_orders.import_orders_menu') }}</span>
                        <a href="{{ route('sales.orders.import') }}" wire:navigate>
                            {{ __('sales_orders.import_file_upload') }}
                        </a>
                        <a href="{{ route('sales.orders.import.amazon-api') }}" wire:navigate>
                            {{ __('sales_orders.import_amazon_api') }}
                        </a>
                        <a href="{{ route('sales.orders.create') }}" wire:navigate>
                            {{ __('sales_orders.import_manual_input') }}
                        </a>
                    </div>

                </div>
            </details>
            <details
                class="action-menu primary action-menu-align-right"
                data-testid="sales-order-page-export-menu"
                x-bind:open="openActionMenu === 'export'"
                x-on:click.outside="if (openActionMenu === 'export') openActionMenu = null"
            >
                <summary x-on:click.prevent="openActionMenu = openActionMenu === 'export' ? null : 'export'"><span class="action-menu-label"><flux:icon.arrow-down-tray />{{ __('sales_orders.export_menu') }}</span></summary>
                <div class="action-menu-panel action-menu-panel-sectioned">
                    <div class="action-menu-section" data-testid="sales-order-shipping-notice-export-menu">
                        <span>{{ __('sales_orders.shipping_notice_menu') }}</span>
                        <button type="button" wire:click="validateMarketplaceShippingNoticeExport('amazon')">
                            {{ __('sales_orders.btn_export_amazon_ship_notice') }}
                        </button>
                        <button type="button" wire:click="validateMarketplaceShippingNoticeExport('rakuten')">
                            {{ __('sales_orders.btn_export_rakuten_ship_notice') }}
                        </button>
                    </div>
                </div>
            </details>
        </div>

        <div class="sales-order-action-row" data-testid="sales-order-selection-actions">
            <div class="selection-count-slot" aria-live="polite">
                <flux:badge color="blue" x-show="has()" x-cloak>
                    <span x-text="selectedList().length"></span>
                </flux:badge>
            </div>
            <div class="selection-action-group" data-testid="sales-order-status-actions">
                <span>{{ __('sales_orders.bulk_status_group') }}</span>
                <flux:button type="button" size="sm" variant="outline" disabled x-show="! has()">
                    {{ __('sales_orders.btn_bulk_mark_ready') }}
                </flux:button>
                <flux:button type="button" size="sm" variant="primary" wire:click="bulkMarkReady" x-show="has()" x-cloak>
                    {{ __('sales_orders.btn_bulk_mark_ready') }}
                </flux:button>

                <flux:button type="button" size="sm" variant="outline" disabled x-show="! has()">
                    {{ __('sales_orders.btn_bulk_hold') }}
                </flux:button>
                <flux:button type="button" size="sm" variant="primary" wire:click="bulkHold" x-show="has()" x-cloak>
                    {{ __('sales_orders.btn_bulk_hold') }}
                </flux:button>

                <flux:button type="button" size="sm" variant="outline" disabled x-show="! has()">
                    {{ __('sales_orders.btn_bulk_release_hold') }}
                </flux:button>
                <flux:button type="button" size="sm" variant="primary" wire:click="bulkReleaseHold" x-show="has()" x-cloak>
                    {{ __('sales_orders.btn_bulk_release_hold') }}
                </flux:button>

                <flux:button
                    type="button"
                    size="sm"
                    variant="outline"
                    disabled
                    x-show="! has()"
                >
                    {{ __('sales_orders.btn_bulk_cancel') }}
                </flux:button>
                <flux:button
                    type="button"
                    size="sm"
                    variant="danger"
                    wire:click="bulkCancel"
                    wire:confirm="{{ __('sales_orders.bulk_cancel_confirm') }}"
                    x-show="has()"
                    x-cloak
                >
                    {{ __('sales_orders.btn_bulk_cancel') }}
                </flux:button>

                <flux:button
                    type="button"
                    size="sm"
                    variant="outline"
                    disabled
                    x-show="! has()"
                >
                    {{ __('sales_orders.btn_bulk_delete') }}
                </flux:button>
                <flux:button
                    type="button"
                    size="sm"
                    variant="danger"
                    wire:click="bulkDelete"
                    wire:confirm="{{ __('sales_orders.bulk_delete_confirm') }}"
                    x-show="has()"
                    x-cloak
                >
                    {{ __('sales_orders.btn_bulk_delete') }}
                </flux:button>
            </div>
        </div>

        <div class="table-scroll">
            <flux:table :paginate="$orders" class="data-table sales-order-table">
                <flux:table.columns>
                    <flux:table.column>
                        <label class="so-checkbox-hitbox so-checkbox-hitbox-header" title="{{ __('sales_orders.select_visible_orders') }}">
                            <input
                                type="checkbox"
                                x-bind:checked="allVisibleSelected"
                                x-bind:indeterminate.prop="someVisibleSelected"
                                x-on:change="toggleAll()"
                                aria-label="{{ __('sales_orders.select_visible_orders') }}"
                            >
                        </label>
                    </flux:table.column>
                    <flux:table.column>{{ __('sales_orders.col_platform_order_id') }}</flux:table.column>
                    <flux:table.column>{{ __('sales_orders.col_address') }}</flux:table.column>
                    <flux:table.column>{{ __('sales_orders.col_recipient') }}</flux:table.column>
                    <flux:table.column>{{ __('sales_orders.field_sku') }}</flux:table.column>
                    <flux:table.column>{{ __('sales_orders.col_shipping_tracking') }}</flux:table.column>
                    <flux:table.column>{{ __('sales_orders.col_order_status') }}</flux:table.column>
                    <flux:table.column>{{ __('sales_orders.col_created_at') }}</flux:table.column>
                    <flux:table.column>{{ __('sales_orders.col_note') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @forelse ($orders as $order)
                        <flux:table.row :key="$order->id">
                            <flux:table.cell class="so-select-cell">
                                <label class="so-checkbox-hitbox">
                                    <input
                                        type="checkbox"
                                        x-bind:checked="isSelected({{ $order->id }})"
                                        x-on:change="toggleRow({{ $order->id }})"
                                        aria-label="{{ __('sales_orders.select_order') }} {{ $order->platform_order_id ?: $order->id }}"
                                    >
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
                                                <strong class="danger-text so-sku-qty">{{ $line->quantity }}</strong>
                                            @else
                                                <span class="subtle so-sku-qty">{{ $line->quantity }}</span>
                                            @endif
                                            <span class="subtle so-sku-x">x</span>
                                            <strong class="so-sku-code" title="{{ $skuCode }}">{{ $skuCode }}</strong>
                                        </div>
                                        @if ($skuLabel !== '')
                                            <span class="subtle so-sku-label" title="{{ $skuLabel }}">{{ $skuLabel }}</span>
                                        @endif
                                    </div>
                                @empty
                                    <span class="subtle">{{ __('sales_orders.no_lines') }}</span>
                                @endforelse
                            </flux:table.cell>
                            <flux:table.cell class="so-control-cell so-shipping-cell">
                                <div class="shipping-tracking-stack">
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

                                    <span class="tracking-readonly" title="{{ $order->tracking_no ?? '' }}">
                                        {{ $order->tracking_no ?: '-' }}
                                    </span>
                                </div>
                            </flux:table.cell>
                            <flux:table.cell>
                                <div class="status-stack">
                                    <flux:badge color="{{ $this->fulfillmentStatusColor($order->fulfillment_status) }}">
                                        {{ $this->fulfillmentStatusLabel($order->fulfillment_status) }}
                                    </flux:badge>
                                    @if ($order->order_status !== \App\Models\SalesOrder::ORDER_STATUS_PENDING)
                                        <flux:badge color="{{ $this->orderStatusColor($order->order_status) }}">
                                            {{ $this->orderStatusLabel($order->order_status) }}
                                        </flux:badge>
                                    @endif
                                </div>
                            </flux:table.cell>
                            <flux:table.cell class="so-created-cell">
                                <strong>{{ $order->order_date?->format('Y-m-d') ?? $order->created_at->format('Y-m-d') }}</strong>
                                @if ($order->courier_csv_exported_at)
                                    <span class="subtle">
                                        {{ __('sales_orders.printed_date_label') }} {{ $order->courier_csv_exported_at->timezone('Asia/Tokyo')->format('Y-m-d') }}
                                    </span>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell class="so-note-cell">
                                <textarea
                                    class="table-control so-note-input"
                                    rows="3"
                                    aria-label="{{ __('sales_orders.col_note') }} {{ $order->platform_order_id ?: $order->id }}"
                                    x-on:change="$wire.updateNote({{ $order->id }}, $event.target.value)"
                                >{{ $order->note }}</textarea>
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="9">
                                <div class="empty-state">{{ __('sales_orders.empty_state') }}</div>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </div>
        </div>
    </section>

</div>
