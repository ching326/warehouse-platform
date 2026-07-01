<div class="fulfillment-group-index-page">
    <x-flash-toast />

    @if ($pendingExportWarning)
        <div class="app-toast app-toast-warning app-toast-confirm" role="alert" x-data="{ visible: true }" x-show="visible">
            <div class="app-toast-body">
                <strong class="app-toast-title">{{ __('common.toast.warning') }}</strong>
                <span class="app-toast-text">{{ $pendingExportWarning }}</span>
            @if ($pendingCourierExportCarrier)
                <div class="app-toast-actions">
                    <flux:button type="button" size="sm" variant="outline" wire:click="cancelCourierExport">
                        {{ __('common.cancel') }}
                    </flux:button>
                    <flux:button type="button" size="sm" variant="primary" x-on:click="visible = false" wire:click="confirmCourierExport">
                        {{ __('fulfillment.courier_export_confirm_btn') }}
                    </flux:button>
                </div>
            @elseif ($pendingAddressLabelOrderIds !== [])
                <div class="app-toast-actions">
                    <flux:button type="button" size="sm" variant="outline" wire:click="cancelAddressLabelExport">
                        {{ __('common.cancel') }}
                    </flux:button>
                    <flux:button type="button" size="sm" variant="primary" x-on:click="visible = false" wire:click="confirmAddressLabelExport">
                        {{ __('fulfillment.address_label_confirm_btn') }}
                    </flux:button>
                </div>
            @endif
            </div>
        </div>
    @endif

    @if ($pendingHoldWarning)
        <div class="app-toast app-toast-warning app-toast-confirm" role="alert">
            <div class="app-toast-body">
                <strong class="app-toast-title">{{ __('outbound.hold_printed_confirm_title') }}</strong>
                <span class="app-toast-text">{{ $pendingHoldWarning }}</span>
                <div class="app-toast-actions">
                    <flux:button type="button" size="sm" variant="outline" wire:click="cancelPrintedHold">
                        {{ __('common.cancel') }}
                    </flux:button>
                    <flux:button type="button" size="sm" variant="primary" wire:click="confirmPrintedHold">
                        {{ __('outbound.hold') }}
                    </flux:button>
                </div>
            </div>
        </div>
    @endif

    <x-page-panel-header
        :title="__('fulfillment.page_title')"
        :subtitle="__('fulfillment.page_subtitle')"
    />

    <section class="table-shell flux-panel">
        <div
            class="sales-order-filter-grid sales-order-filter-toolbar"
            data-testid="fulfillment-group-filter-row"
            x-data="{ openFilter: null }"
            x-on:keydown.escape.window="openFilter = null"
        >
            @if ($showTenantFilter)
                <details
                    @class(['filter-menu', 'is-active' => count((array) $tenantIds) > 0])
                    x-bind:class="{ 'is-active': $wire.tenantIds.length > 0 }"
                    wire:ignore.self
                    x-bind:open="openFilter === 'tenant'"
                    x-on:click.outside="if (openFilter === 'tenant') openFilter = null"
                >
                    <summary
                        class="filter-button"
                        x-on:click.prevent="openFilter = openFilter === 'tenant' ? null : 'tenant'"
                        x-bind:aria-expanded="openFilter === 'tenant'"
                    >
                        <span>{{ __('fulfillment.field_tenant') }}</span>
                    </summary>
                    <div class="filter-panel">
                        @foreach ($tenants as $tenant)
                            <label><input type="checkbox" wire:model.live="tenantIds" value="{{ $tenant->id }}"> {{ $tenant->code }} - {{ $tenant->name }}</label>
                        @endforeach
                    </div>
                </details>
            @endif

            <details
                @class(['filter-menu', 'is-active' => $warehouseId !== ''])
                x-bind:class="{ 'is-active': $wire.warehouseId !== '' }"
                wire:ignore.self
                x-bind:open="openFilter === 'warehouse'"
                x-on:click.outside="if (openFilter === 'warehouse') openFilter = null"
            >
                <summary
                    class="filter-button"
                    x-on:click.prevent="openFilter = openFilter === 'warehouse' ? null : 'warehouse'"
                    x-bind:aria-expanded="openFilter === 'warehouse'"
                >
                    <span>{{ __('fulfillment.field_warehouse') }}</span>
                </summary>
                <div class="filter-panel">
                    <label><input type="radio" wire:model.live="warehouseId" value=""> {{ __('fulfillment.all_warehouses') }}</label>
                    @foreach ($warehouses as $warehouse)
                        <label><input type="radio" wire:model.live="warehouseId" value="{{ $warehouse->id }}"> {{ $warehouse->code }} - {{ $warehouse->name }}</label>
                    @endforeach
                </div>
            </details>

            <details
                @class(['filter-menu', 'is-active' => count((array) $statusesFilter) > 0])
                x-bind:class="{ 'is-active': $wire.statusesFilter.length > 0 }"
                wire:ignore.self
                x-bind:open="openFilter === 'status'"
                x-on:click.outside="if (openFilter === 'status') openFilter = null"
            >
                <summary
                    class="filter-button"
                    x-on:click.prevent="openFilter = openFilter === 'status' ? null : 'status'"
                    x-bind:aria-expanded="openFilter === 'status'"
                >
                    <span>{{ __('fulfillment.col_status') }}</span>
                </summary>
                <div class="filter-panel compact">
                    @foreach ($statuses as $status => $label)
                        <label><input type="checkbox" wire:model.live="statusesFilter" value="{{ $status }}"> {{ $label }}</label>
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
                    <span>{{ __('fulfillment.filter_shipping') }}</span>
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
                    <span>{{ __('fulfillment.filter_order_date') }}</span>
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
                    <span>{{ __('fulfillment.filter_others') }}</span>
                </summary>
                <div class="filter-panel compact">
                    <label>
                        <input type="checkbox" wire:model.live="othersFilter" value="{{ \App\Support\SalesOrderFilters::OTHER_MULTI_ITEM }}">
                        {{ __('fulfillment.other_multi_item') }}
                    </label>
                    <small class="filter-helper">{{ __('fulfillment.other_multi_item_hint') }}</small>
                    <label>
                        <input type="checkbox" wire:model.live="othersFilter" value="{{ \App\Support\SalesOrderFilters::OTHER_PRINTED }}">
                        {{ __('fulfillment.other_printed') }}
                    </label>
                    <label>
                        <input type="checkbox" wire:model.live="othersFilter" value="{{ \App\Support\SalesOrderFilters::OTHER_NOT_PRINTED }}">
                        {{ __('fulfillment.other_not_printed') }}
                    </label>
                </div>
            </details>

            <button
                type="button"
                @class(['filter-button', 'print-ready-pill', 'is-active' => $detailed])
                aria-pressed="{{ $detailed ? 'true' : 'false' }}"
                wire:click="toggleDetailed"
            >
                <flux:icon.list-bullet class="print-ready-icon" />
                <span class="print-ready-label">{{ __('fulfillment.btn_details') }}</span>
            </button>

            <label @class(['filter-button', 'print-ready-pill', 'is-active' => $printWaiting])>
                <input class="print-ready-toggle-input" type="checkbox" wire:model.live="printWaiting">
                <flux:icon.printer class="print-ready-icon" />
                <span class="print-ready-label">{{ __('fulfillment.filter_print_waiting') }}</span>
            </label>
        </div>

        <div class="sales-order-search-bar-row">
            <div class="sales-order-search-row">
                <flux:icon.magnifying-glass class="sales-order-search-icon" />
                <input
                    type="text"
                    class="sales-order-search-input"
                    wire:model.live.debounce.300ms="search"
                    aria-label="{{ __('common.search') }}"
                    placeholder="{{ __('fulfillment.search_placeholder') }}"
                >
            </div>

            @if ($activeFilterChips !== [])
                <div class="filter-chip-row" data-testid="fulfillment-filter-chips">
                    @foreach ($activeFilterChips as $chip)
                        <button type="button" class="filter-chip" wire:click="removeFilterChip('{{ $chip['group'] }}', '{{ $chip['value'] }}')">
                            <span>{{ $chip['text'] }}</span>
                            <strong aria-hidden="true">x</strong>
                        </button>
                    @endforeach
                    <button type="button" class="filter-chip-clear" wire:click="clearAllFilters">
                        {{ __('fulfillment.clear_all_filters') }}
                    </button>
                </div>
            @endif
        </div>

        <div
            x-data="{
                selected: $wire.entangle('selectedIds'),
                visible: $wire.entangle('visibleOrderIds'),
                openActionMenu: null,
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
        <div class="table-action-row" data-testid="fulfillment-group-selection-actions">
            <div class="selection-count-slot" aria-live="polite">
                <flux:badge color="blue" x-show="has()" x-cloak>
                    <span x-text="selectedList().length"></span>
                </flux:badge>
            </div>

            <div class="selection-action-group" data-testid="fulfillment-group-status-actions">
                <flux:button type="button" size="sm" variant="outline" disabled x-show="! has()">
                    {{ __('fulfillment.btn_mark_shipped') }}
                </flux:button>
                <flux:button type="button" size="sm" variant="primary" wire:click="markShipped" x-show="has()" x-cloak>
                    {{ __('fulfillment.btn_mark_shipped') }}
                </flux:button>
                <flux:button type="button" size="sm" variant="outline" disabled x-show="! has()">
                    {{ __('outbound.hold') }}
                </flux:button>
                <flux:button type="button" size="sm" variant="primary" wire:click="holdSelected" x-show="has()" x-cloak>
                    {{ __('outbound.hold') }}
                </flux:button>
                <flux:button type="button" size="sm" variant="outline" disabled x-show="! has()">
                    {{ __('outbound.release_hold') }}
                </flux:button>
                <flux:button type="button" size="sm" variant="primary" wire:click="releaseHoldSelected" x-show="has()" x-cloak>
                    {{ __('outbound.release_hold') }}
                </flux:button>
                <flux:button type="button" size="sm" variant="outline" disabled x-show="! has()">
                    {{ __('fulfillment.btn_remap_shipping') }}
                </flux:button>
                <flux:button type="button" size="sm" variant="primary" wire:click="remapShipping" x-show="has()" x-cloak>
                    {{ __('fulfillment.btn_remap_shipping') }}
                </flux:button>
            </div>

            <div class="sales-order-page-actions inline-page-actions" data-testid="fulfillment-group-page-actions">
                <details
                    class="action-menu primary action-menu-align-left"
                    x-bind:open="openActionMenu === 'import'"
                    x-on:click.outside="if (openActionMenu === 'import') openActionMenu = null"
                >
                    <summary x-on:click.prevent="openActionMenu = openActionMenu === 'import' ? null : 'import'">
                        <span class="action-menu-label">
                            <flux:icon.arrow-up-tray />
                            {{ __('fulfillment.bulk_import_group') }}
                        </span>
                    </summary>
                    <div class="action-menu-panel action-menu-panel-sectioned">
                        <div class="action-menu-section">
                            <span>{{ __('fulfillment.tracking_import_menu') }}</span>
                            <button type="button" wire:click="openTrackingImportModal" x-on:click="openActionMenu = null">
                                {{ __('fulfillment.batch_import_tracking') }}
                            </button>
                        </div>
                    </div>
                </details>

                <details
                    class="action-menu primary action-menu-align-right"
                    x-bind:open="openActionMenu === 'export'"
                    x-on:click.outside="if (openActionMenu === 'export') openActionMenu = null"
                >
                    <summary x-on:click.prevent="openActionMenu = openActionMenu === 'export' ? null : 'export'">
                        <span class="action-menu-label">
                            <flux:icon.arrow-down-tray />
                            {{ __('fulfillment.bulk_export_group') }}
                        </span>
                    </summary>
                    <div class="action-menu-panel action-menu-panel-sectioned">
                        <div class="action-menu-section">
                            <span>{{ __('fulfillment.courier_export_menu') }}</span>
                            <button type="button" wire:click="exportYamato" x-on:click="openActionMenu = null">
                                {{ __('fulfillment.batch_export_yamato') }}
                            </button>
                            <button type="button" wire:click="exportSagawa" x-on:click="openActionMenu = null">
                                {{ __('fulfillment.batch_export_sagawa') }}
                            </button>
                            <button type="button" wire:click="openAddressLabelModal" x-on:click="openActionMenu = null">
                                {{ __('fulfillment.batch_export_label10') }}
                            </button>
                        </div>
                    </div>
                </details>
            </div>
        </div>

        <flux:table :paginate="$orders" class="data-table">
            <flux:table.columns>
                <flux:table.column class="fg-col-select">
                    <label class="so-checkbox-hitbox so-checkbox-hitbox-header" title="{{ __('sales_orders.select_visible_orders') }}">
                        <input
                            type="checkbox"
                            x-bind:checked="allVisibleSelected"
                            x-bind:data-indeterminate="someVisibleSelected"
                            x-effect="$el.indeterminate = someVisibleSelected"
                            x-on:change="toggleAll()"
                        >
                    </label>
                </flux:table.column>
                <flux:table.column>{{ __('fulfillment.col_reference_no') }}</flux:table.column>
                <flux:table.column>{{ __('fulfillment.col_shop') }}</flux:table.column>
                <flux:table.column>{{ __('fulfillment.col_recipient') }}</flux:table.column>
                <flux:table.column :align="$detailed ? 'start' : 'end'">{{ __('fulfillment.col_items') }}</flux:table.column>
                <flux:table.column>{{ __('fulfillment.col_shipping') }}</flux:table.column>
                <flux:table.column>{{ __('fulfillment.col_tracking') }}</flux:table.column>
                <flux:table.column>{{ __('fulfillment.col_note') }}</flux:table.column>
                <flux:table.column>{{ __('fulfillment.col_added') }}</flux:table.column>
                <flux:table.column>{{ __('fulfillment.col_status') }}</flux:table.column>
                <flux:table.column>{{ __('fulfillment.col_actions') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($orders as $order)
                    @php
                        $salesOrders = $order->salesOrders;
                        $reference = $order->ref;
                        $orderIds = $salesOrders
                            ->map(fn ($so) => $so->platform_order_id)
                            ->filter()
                            ->values();
                        $shops = $salesOrders
                            ->map(fn ($so) => $so->shop?->name)
                            ->filter()
                            ->unique()
                            ->values();
                        $itemQty = $salesOrders->sum(fn ($so) => (int) $so->lines->sum('quantity'));
                        $arranged = $order->created_at;
                        $printed = $order->courier_label_exported_at;
                    @endphp
                    <flux:table.row :key="$order->id">
                        <flux:table.cell class="fg-col-select">
                            <label class="so-checkbox-hitbox">
                                <input
                                    type="checkbox"
                                    x-bind:checked="isSelected({{ $order->id }})"
                                    x-on:change="toggleRow({{ $order->id }})"
                                    aria-label="{{ __('fulfillment.select_group') }} {{ $reference }}"
                                >
                            </label>
                        </flux:table.cell>

                        <flux:table.cell class="so-order-cell">
                            <x-record-ref-link
                                :href="route('outbound.show', $order)"
                                :value="$reference"
                                :copy-label="__('fulfillment.copy_reference_no')"
                                :copied-label="__('fulfillment.reference_copied')"
                            />
                            @forelse ($orderIds as $orderId)
                                <span class="subtle">{{ $orderId }}</span>
                            @empty
                                <span class="subtle">-</span>
                            @endforelse
                        </flux:table.cell>

                        <flux:table.cell>
                            <strong>{{ $order->tenant->code }}</strong>
                            <div class="fg-subtle">
                                @if ($shops->count() === 1)
                                    {{ $shops->first() }}
                                @elseif ($shops->count() > 1)
                                    {{ __('fulfillment.shops_count', ['count' => $shops->count()]) }}
                                @else
                                    -
                                @endif
                            </div>
                        </flux:table.cell>

                        <flux:table.cell>
                            <strong>{{ $order->recipient_name ?: '-' }}</strong>
                            @if ($detailed)
                                @php
                                    $addressParts = array_values(array_filter([
                                        trim(($order->recipient_state ?? '').' '.($order->recipient_city ?? '')),
                                        $order->recipient_address_line1,
                                        $order->recipient_address_line2,
                                    ], fn ($part) => trim((string) $part) !== ''));
                                    $address = implode(' ', $addressParts);
                                @endphp
                                <div class="fg-recipient-detail">
                                    <span class="fg-subtle">{{ $order->recipient_phone ?: '-' }}</span>
                                    <span class="fg-subtle">{{ $order->recipient_postal_code ?: '-' }}</span>
                                    <span class="fg-subtle fg-address-clamp" title="{{ $address }}">{{ $address !== '' ? $address : '-' }}</span>
                                </div>
                            @else
                                <div class="fg-subtle">{{ $order->recipient_city ?: $order->recipient_postal_code ?: '-' }}</div>
                            @endif
                        </flux:table.cell>

                        <flux:table.cell :align="$detailed ? 'start' : 'end'">
                            @if ($detailed)
                                <div class="fg-items-detail">
                                    @foreach ($salesOrders as $salesOrder)
                                        @foreach (($salesOrder->lines ?? []) as $line)
                                            @php
                                                $skuCode = $line->sku?->sku ?? '-';
                                                $skuLabel = trim((string) ($line->sku?->displayName() ?? ''));
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
                                        @endforeach
                                    @endforeach
                                </div>
                            @else
                                {{ number_format($itemQty) }}
                            @endif
                        </flux:table.cell>

                        <flux:table.cell>
                            <select
                                class="table-control"
                                wire:change="updateShippingMethod({{ $order->id }}, $event.target.value)"
                            >
                                <option value="">-</option>
                                @foreach ($shippingMethods as $methodId => $methodName)
                                    <option value="{{ $methodId }}" @selected((string) $order->shipping_method_id === (string) $methodId)>{{ $methodName }}</option>
                                @endforeach
                            </select>
                        </flux:table.cell>

                        <flux:table.cell>
                            <input
                                type="text"
                                class="table-control"
                                value="{{ $trackingDrafts[$order->id] ?? '' }}"
                                placeholder="{{ __('fulfillment.tracking_placeholder') }}"
                                wire:change="updateTracking({{ $order->id }}, $event.target.value)"
                            />
                        </flux:table.cell>

                        <flux:table.cell>
                            <input
                                type="text"
                                class="table-control"
                                value="{{ $noteDrafts[$order->id] ?? '' }}"
                                placeholder="{{ __('fulfillment.note_placeholder') }}"
                                wire:change="updateNote({{ $order->id }}, $event.target.value)"
                            />
                        </flux:table.cell>

                        <flux:table.cell class="fg-added-cell">
                            <div>{{ $this->formatWarehouseTime($order, $arranged, 'Y-m-d') }}</div>
                            <div class="fg-subtle">
                                @if ($printed)
                                    <span class="fg-printed-stack">
                                        <span>{{ __('fulfillment.other_printed') }}</span>
                                        <span>{{ $this->formatWarehouseTime($order, $printed, 'Y-m-d H:i') }}</span>
                                    </span>
                                @else
                                    {{ __('fulfillment.not_printed') }}
                                @endif
                            </div>
                        </flux:table.cell>

                        <flux:table.cell>
                            <div class="record-status-stack">
                                <x-status-badge :status="$order->status" :label="$this->statusLabel($order->status)" />
                                @if ($order->hold_status === \App\Models\OutboundOrder::HOLD_STATUS_ON_HOLD)
                                    <x-status-badge :status="$order->hold_status" :label="$this->holdStatusLabel($order->hold_status)" />
                                @endif
                            </div>
                        </flux:table.cell>

                        <flux:table.cell>
                            @if ($order->status === \App\Models\OutboundOrder::STATUS_RESERVED && $order->hold_status === \App\Models\OutboundOrder::HOLD_STATUS_ACTIVE)
                                <div class="fg-row-action">
                                    <flux:button href="{{ route('outbound.pack', $order) }}" size="sm" variant="primary" class="fg-scan-pack-button" wire:navigate>
                                        {{ __('fulfillment_pack.page_title') }}
                                    </flux:button>
                                </div>
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="11">
                            <div class="empty-state">{{ __('fulfillment.empty_state') }}</div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
        </div>
    </section>

    @if ($showTrackingImportModal)
        <div class="modal-backdrop tracking-import-backdrop" wire:key="fulfillment-tracking-import-modal">
            <section class="tracking-import-modal flux-panel">
                <header class="tracking-import-header">
                    <div>
                        <h2>{{ __('fulfillment.tracking_import_title') }}</h2>
                        <p>{{ __('fulfillment.tracking_import_subtitle') }}</p>
                    </div>
                    <button type="button" class="modal-icon-close" wire:click="closeTrackingImportModal" aria-label="{{ __('fulfillment.tracking_import_close_btn') }}">&times;</button>
                </header>

                <form
                    method="POST"
                    action="{{ route('fulfillment.tracking-import') }}"
                    enctype="multipart/form-data"
                    x-data="{ dragging: false, fileName: '' }"
                >
                    @csrf

                    <label
                        class="tracking-import-dropzone"
                        x-bind:class="{ 'is-dragging': dragging }"
                        x-on:dragover.prevent="dragging = true"
                        x-on:dragleave.prevent="dragging = false"
                        x-on:drop.prevent="
                            dragging = false;
                            const input = $refs.trackingFile;
                            input.files = $event.dataTransfer.files;
                            fileName = input.files.length ? input.files[0].name : '';
                        "
                    >
                        <input
                            x-ref="trackingFile"
                            class="tracking-import-file-input"
                            type="file"
                            name="tracking_file"
                            accept=".csv,.txt,text/csv,text/plain"
                            x-on:change="fileName = $event.target.files.length ? $event.target.files[0].name : ''"
                        >
                        <strong>{{ __('fulfillment.tracking_import_drop_title') }}</strong>
                        <span>{{ __('fulfillment.tracking_import_drop_hint') }}</span>
                        <span class="tracking-import-file-name" x-show="fileName" x-text="fileName"></span>
                    </label>

                    <footer class="tracking-import-footer">
                        <flux:button type="submit" variant="primary">
                            {{ __('fulfillment.tracking_import_confirm_btn') }}
                        </flux:button>
                    </footer>
                </form>
            </section>
        </div>
    @endif

    @if ($showAddressLabelModal)
        <div class="modal-backdrop tracking-import-backdrop" wire:key="fulfillment-address-label-modal">
            <section class="tracking-import-modal flux-panel address-label-modal">
                <header class="tracking-import-header">
                    <div>
                        <h2>{{ __('fulfillment.address_label_skip_title') }}</h2>
                        <p>{{ __('fulfillment.address_label_skip_hint') }}</p>
                    </div>
                    <button type="button" class="modal-icon-close" wire:click="closeAddressLabelModal" aria-label="{{ __('common.toast.close') }}">&times;</button>
                </header>

                <div class="address-label-page-grid">
                    @for ($page = 1; $page <= 3; $page++)
                        <section class="address-label-page">
                            <strong>{{ __('fulfillment.address_label_page', ['page' => $page]) }}</strong>
                            <div class="address-label-cell-grid">
                                @for ($cell = (($page - 1) * 10) + 1; $cell <= $page * 10; $cell++)
                                    @php($willPrint = ! in_array($cell, $label10SkipCells, true))
                                    <label @class(['address-label-cell', 'is-skipped' => ! $willPrint])>
                                        <input
                                            type="checkbox"
                                            @checked($willPrint)
                                            wire:click="toggleLabel10SkipCell({{ $cell }})"
                                        >
                                        <span>{{ __('fulfillment.address_label_cell_print') }}</span>
                                    </label>
                                @endfor
                            </div>
                        </section>
                    @endfor
                </div>

                <footer class="tracking-import-footer">
                    <flux:button type="button" variant="outline" wire:click="closeAddressLabelModal">
                        {{ __('common.cancel') }}
                    </flux:button>
                    <flux:button type="button" variant="primary" wire:click="exportLabel10">
                        {{ __('fulfillment.address_label_generate_pdf') }}
                    </flux:button>
                </footer>
            </section>
        </div>
    @endif

    <style>
        .active-filter-row {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 12px;
        }

        .export-warning-message {
            white-space: pre-line;
            color: var(--ink);
            background: #fff7ed;
            border: 1px solid #fed7aa;
            border-radius: 8px;
            padding: 10px 12px;
            font-size: 12px;
        }

        .fg-ref-link {
            color: var(--accent);
            font-weight: 700;
            font-size: 13px;
            text-decoration: none;
        }

        .fg-ref-link:hover {
            text-decoration: underline;
        }

        .fg-subtle {
            color: var(--muted);
            font-size: 11px;
        }

        .print-ready-pill.is-active {
            border-color: color-mix(in oklab, var(--color-teal-600), transparent 70%);
        }

        .fg-recipient-detail {
            display: grid;
            gap: 1px;
            max-width: 220px;
        }

        .fg-address-clamp {
            display: -webkit-box;
            -webkit-box-orient: vertical;
            -webkit-line-clamp: 2;
            overflow: hidden;
            white-space: normal;
            overflow-wrap: anywhere;
        }

        .fg-items-detail {
            display: grid;
            gap: 6px;
            max-width: 200px;
            font-size: 12px;
            text-align: left;
        }

        .fg-items-detail .so-sku-label {
            font-size: 11px;
        }

        .fg-col-select {
            width: 34px;
        }

        .fg-row-action {
            display: inline-flex;
        }

        .fg-scan-pack-button {
            height: 28px !important;
            min-height: 28px !important;
            padding-top: 4px !important;
            padding-bottom: 4px !important;
            font-size: 12px;
            line-height: 1;
        }

        .fg-added-cell {
            font-size: 13px;
            line-height: 1.25;
        }

        .fg-printed-stack {
            display: grid;
            gap: 2px;
        }

        .tracking-import-backdrop {
            align-items: flex-start;
            padding-top: 72px;
        }

        .tracking-import-modal {
            width: min(1040px, calc(100vw - 48px));
            padding: 20px;
        }

        .tracking-import-header {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            padding-bottom: 14px;
            border-bottom: 1px solid var(--line);
        }

        .tracking-import-header h2 {
            margin: 0 0 6px;
            font-size: 20px;
        }

        .tracking-import-header p {
            margin: 0;
            color: var(--muted);
            font-size: 13px;
        }

        .tracking-import-dropzone {
            display: grid;
            place-items: center;
            gap: 10px;
            min-height: 138px;
            margin: 16px 0;
            border: 1px dashed var(--line);
            border-radius: 8px;
            background: #f8fafc;
            cursor: pointer;
            color: var(--muted);
        }

        .tracking-import-dropzone strong {
            color: var(--ink);
            font-size: 16px;
        }

        .tracking-import-dropzone.is-dragging {
            border-color: var(--accent);
            background: #eefaf8;
        }

        .tracking-import-file-input {
            display: none;
        }

        .tracking-import-file-name {
            color: var(--accent);
            font-size: 12px;
            font-weight: 700;
        }

        .tracking-import-footer {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }

        .address-label-modal {
            width: min(780px, calc(100vw - 48px));
        }

        .address-label-page-grid {
            display: grid;
            grid-template-columns: minmax(0, 1fr);
            gap: 14px;
            margin: 16px 0;
        }

        .address-label-page {
            display: grid;
            gap: 10px;
        }

        .address-label-page strong {
            font-size: 13px;
        }

        .address-label-cell-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 8px;
        }

        .address-label-cell {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            height: 42px;
            border: 1px solid var(--line);
            border-radius: 0;
            background: #fafafa;
            color: var(--muted);
            font-size: 12px;
            cursor: pointer;
        }

        .address-label-cell input {
            width: 18px;
            height: 18px;
            accent-color: var(--accent);
        }

        .address-label-cell.is-skipped {
            background: #fff;
            color: color-mix(in oklab, var(--muted), white 20%);
        }
    </style>
</div>
