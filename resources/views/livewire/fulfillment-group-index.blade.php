<div class="fulfillment-group-index-page">
    <x-flash-toast />

    @if ($pendingExportWarning)
        <div class="active-filter-row">
            <div class="export-warning-message">{{ $pendingExportWarning }}</div>
            @if ($pendingCourierExportCarrier)
                <flux:button type="button" size="sm" variant="primary" wire:click="confirmCourierExport">
                    {{ __('fulfillment_groups.courier_export_confirm_btn') }}
                </flux:button>
            @endif
        </div>
    @endif

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
                        <span>{{ __('fulfillment_groups.field_tenant') }}</span>
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
                    <span>{{ __('fulfillment_groups.field_warehouse') }}</span>
                </summary>
                <div class="filter-panel">
                    <label><input type="radio" wire:model.live="warehouseId" value=""> {{ __('fulfillment_groups.all_warehouses') }}</label>
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
                    <span>{{ __('fulfillment_groups.col_status') }}</span>
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
                    <span>{{ __('fulfillment_groups.filter_shipping') }}</span>
                </summary>
                <div class="filter-panel compact">
                    @foreach ($shippingMethodFilterOptions as $method => $label)
                        <label><input type="checkbox" wire:model.live="shippingMethodsFilter" value="{{ $method }}"> {{ $label }}</label>
                    @endforeach
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
                    <span>{{ __('fulfillment_groups.filter_others') }}</span>
                </summary>
                <div class="filter-panel compact">
                    <label>
                        <input type="checkbox" wire:model.live="othersFilter" value="{{ \App\Support\SalesOrderFilters::OTHER_MULTI_ITEM }}">
                        {{ __('fulfillment_groups.other_multi_item') }}
                    </label>
                    <small class="filter-helper">{{ __('fulfillment_groups.other_multi_item_hint') }}</small>
                    <label>
                        <input type="checkbox" wire:model.live="othersFilter" value="{{ \App\Support\SalesOrderFilters::OTHER_PRINTED }}">
                        {{ __('fulfillment_groups.other_printed') }}
                    </label>
                    <label>
                        <input type="checkbox" wire:model.live="othersFilter" value="{{ \App\Support\SalesOrderFilters::OTHER_NOT_PRINTED }}">
                        {{ __('fulfillment_groups.other_not_printed') }}
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
                <span class="print-ready-label">{{ __('fulfillment_groups.btn_details') }}</span>
            </button>

            <label @class(['filter-button', 'print-ready-pill', 'is-active' => $printWaiting])>
                <input class="print-ready-toggle-input" type="checkbox" wire:model.live="printWaiting">
                <flux:icon.printer class="print-ready-icon" />
                <span class="print-ready-label">{{ __('fulfillment_groups.filter_print_waiting') }}</span>
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
                    placeholder="{{ __('fulfillment_groups.search_placeholder') }}"
                >
            </div>
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
        <div class="sales-order-page-actions" data-testid="fulfillment-group-page-actions">
            <flux:button href="{{ $this->pickSummaryUrl() }}" variant="outline" wire:navigate>
                {{ __('fulfillment_groups.btn_pick_summary') }}
            </flux:button>

            <flux:button href="{{ route('fulfillment-groups.create') }}" variant="primary" wire:navigate>
                {{ __('fulfillment_groups.btn_create') }}
            </flux:button>

            <details
                class="action-menu primary action-menu-align-left"
                x-bind:open="openActionMenu === 'import'"
                x-on:click.outside="if (openActionMenu === 'import') openActionMenu = null"
            >
                <summary x-on:click.prevent="openActionMenu = openActionMenu === 'import' ? null : 'import'">
                    <span class="action-menu-label">
                        <flux:icon.arrow-up-tray />
                        {{ __('fulfillment_groups.bulk_import_group') }}
                    </span>
                </summary>
                <div class="action-menu-panel action-menu-panel-sectioned">
                    <div class="action-menu-section">
                        <span>{{ __('fulfillment_groups.tracking_import_menu') }}</span>
                        <button type="button" wire:click="openTrackingImportModal" x-on:click="openActionMenu = null">
                            {{ __('fulfillment_groups.batch_import_tracking') }}
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
                        {{ __('fulfillment_groups.bulk_export_group') }}
                    </span>
                </summary>
                <div class="action-menu-panel action-menu-panel-sectioned">
                    <div class="action-menu-section">
                        <span>{{ __('fulfillment_groups.courier_export_menu') }}</span>
                        <button type="button" wire:click="exportYamato" x-on:click="openActionMenu = null">
                            {{ __('fulfillment_groups.batch_export_yamato') }}
                        </button>
                        <button type="button" wire:click="exportSagawa" x-on:click="openActionMenu = null">
                            {{ __('fulfillment_groups.batch_export_sagawa') }}
                        </button>
                    </div>
                </div>
            </details>
        </div>

        <div class="sales-order-action-row" data-testid="fulfillment-group-selection-actions">
            <div class="selection-count-slot" aria-live="polite">
                <flux:badge color="blue" x-show="has()" x-cloak>
                    <span x-text="selectedList().length"></span>
                </flux:badge>
            </div>

            <div class="selection-action-group" data-testid="fulfillment-group-status-actions">
                <flux:button type="button" size="sm" variant="outline" disabled x-show="! has()">
                    {{ __('fulfillment_groups.btn_mark_shipped') }}
                </flux:button>
                <flux:button type="button" size="sm" variant="primary" wire:click="markShipped" x-show="has()" x-cloak>
                    {{ __('fulfillment_groups.btn_mark_shipped') }}
                </flux:button>
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
                <flux:table.column>{{ __('fulfillment_groups.col_reference_no') }}</flux:table.column>
                <flux:table.column>{{ __('fulfillment_groups.col_shop') }}</flux:table.column>
                <flux:table.column>{{ __('fulfillment_groups.col_recipient') }}</flux:table.column>
                <flux:table.column :align="$detailed ? 'start' : 'end'">{{ __('fulfillment_groups.col_items') }}</flux:table.column>
                <flux:table.column>{{ __('fulfillment_groups.col_shipping') }}</flux:table.column>
                <flux:table.column>{{ __('fulfillment_groups.col_tracking') }}</flux:table.column>
                <flux:table.column>{{ __('fulfillment_groups.col_note') }}</flux:table.column>
                <flux:table.column>{{ __('fulfillment_groups.col_added') }}</flux:table.column>
                <flux:table.column>{{ __('fulfillment_groups.col_status') }}</flux:table.column>
                <flux:table.column>{{ __('fulfillment_groups.col_actions') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($orders as $order)
                    @php
                        $salesOrders = $order->salesOrders;
                        $reference = $order->fulfillmentGroup?->reference_no ?? $order->ref;
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
                        $printed = $order->courier_csv_exported_at;
                    @endphp
                    <flux:table.row :key="$order->id">
                        <flux:table.cell class="fg-col-select">
                            <label class="so-checkbox-hitbox">
                                <input
                                    type="checkbox"
                                    x-bind:checked="isSelected({{ $order->id }})"
                                    x-on:change="toggleRow({{ $order->id }})"
                                    aria-label="{{ __('fulfillment_groups.select_group') }} {{ $reference }}"
                                >
                            </label>
                        </flux:table.cell>

                        <flux:table.cell class="so-order-cell">
                            <flux:link href="{{ route('outbound.show', $order) }}" wire:navigate>
                                <strong>{{ $reference }}</strong>
                            </flux:link>
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
                                    {{ __('fulfillment_groups.shops_count', ['count' => $shops->count()]) }}
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
                                        @endforeach
                                    @endforeach
                                </div>
                            @else
                                {{ number_format($itemQty) }}
                            @endif
                        </flux:table.cell>

                        <flux:table.cell>
                            <select
                                class="fg-inline-input"
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
                                class="fg-inline-input"
                                value="{{ $trackingDrafts[$order->id] ?? '' }}"
                                placeholder="{{ __('fulfillment_groups.tracking_placeholder') }}"
                                wire:change="updateTracking({{ $order->id }}, $event.target.value)"
                            />
                        </flux:table.cell>

                        <flux:table.cell>
                            <input
                                type="text"
                                class="fg-inline-input"
                                value="{{ $noteDrafts[$order->id] ?? '' }}"
                                placeholder="{{ __('fulfillment_groups.note_placeholder') }}"
                                wire:change="updateNote({{ $order->id }}, $event.target.value)"
                            />
                        </flux:table.cell>

                        <flux:table.cell class="fg-added-cell">
                            @php($dateFormat = $detailed ? 'Y-m-d H:i' : 'm-d H:i')
                            <div>{{ $arranged ? $arranged->format($dateFormat) : '-' }}</div>
                            <div class="fg-subtle">
                                {{ $printed ? __('fulfillment_groups.printed_at', ['time' => $printed->format($dateFormat)]) : __('fulfillment_groups.not_printed') }}
                            </div>
                        </flux:table.cell>

                        <flux:table.cell>
                            <flux:badge color="{{ $this->statusColor($order->status) }}">
                                {{ $this->statusLabel($order->status) }}
                            </flux:badge>
                        </flux:table.cell>

                        <flux:table.cell>
                            @if ($order->status === \App\Models\OutboundOrder::STATUS_PENDING && $order->fulfillmentGroup)
                                <div class="fg-row-action">
                                    <flux:button href="{{ route('fulfillment-groups.pack', $order->fulfillmentGroup) }}" size="sm" variant="primary" class="fg-scan-pack-button" wire:navigate>
                                        {{ __('fulfillment_pack.page_title') }}
                                    </flux:button>
                                </div>
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="11">
                            <div class="empty-state">{{ __('fulfillment_groups.empty_state') }}</div>
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
                        <h2>{{ __('fulfillment_groups.tracking_import_title') }}</h2>
                        <p>{{ __('fulfillment_groups.tracking_import_subtitle') }}</p>
                    </div>
                    <flux:button type="button" variant="ghost" size="sm" wire:click="closeTrackingImportModal">
                        {{ __('fulfillment_groups.tracking_import_close_btn') }}
                    </flux:button>
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
                        <strong>{{ __('fulfillment_groups.tracking_import_drop_title') }}</strong>
                        <span>{{ __('fulfillment_groups.tracking_import_drop_hint') }}</span>
                        <span class="tracking-import-file-name" x-show="fileName" x-text="fileName"></span>
                    </label>

                    <footer class="tracking-import-footer">
                        <flux:button type="submit" variant="primary">
                            {{ __('fulfillment_groups.tracking_import_confirm_btn') }}
                        </flux:button>
                    </footer>
                </form>
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

        .fg-inline-input {
            width: 100%;
            min-width: 120px;
            height: 30px;
            padding: 4px 8px;
            border: 1px solid var(--line);
            border-radius: 6px;
            font-size: 13px;
            background: #fff;
            color: var(--ink);
        }

        .fg-inline-input:focus {
            outline: none;
            border-color: var(--accent);
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
    </style>
</div>
