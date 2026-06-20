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

        <div class="sales-order-filter-grid">
            <div class="filter-box">
                <strong>{{ __('sales_orders.field_platform') }}</strong>
                <div class="filter-options compact">
                    @forelse ($platformOptions as $platform)
                        <label><input type="checkbox" wire:model.live="platforms" value="{{ $platform }}"> {{ $platform }}</label>
                    @empty
                        <span class="subtle">{{ __('sales_orders.all_platforms') }}</span>
                    @endforelse
                </div>
            </div>

            <div class="filter-box">
                <strong>{{ __('sales_orders.field_shop') }}</strong>
                <div class="filter-options">
                    @foreach ($shops as $shop)
                        <label>
                            <input type="checkbox" wire:model.live="shopIds" value="{{ $shop->id }}">
                            {{ $shop->tenant->code }} / {{ $shop->name }}
                        </label>
                    @endforeach
                </div>
            </div>

            <div class="filter-box">
                <strong>{{ __('sales_orders.field_fulfillment_status') }}</strong>
                <div class="filter-options compact">
                    @foreach ($fulfillmentStatuses as $status => $label)
                        <label><input type="checkbox" wire:model.live="fulfillmentStatusesFilter" value="{{ $status }}"> {{ $label }}</label>
                    @endforeach
                </div>
            </div>

            <div class="filter-box">
                <strong>{{ __('sales_orders.field_order_status') }}</strong>
                <div class="filter-options compact">
                    @foreach ($orderStatuses as $status => $label)
                        <label><input type="checkbox" wire:model.live="orderStatusesFilter" value="{{ $status }}"> {{ $label }}</label>
                    @endforeach
                </div>
            </div>

            <div class="filter-box">
                <strong>{{ __('sales_orders.field_shipping_method') }}</strong>
                <div class="filter-options compact">
                    @foreach ($shippingMethodFilterOptions as $method => $label)
                        <label><input type="checkbox" wire:model.live="shippingMethodsFilter" value="{{ $method }}"> {{ $label }}</label>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="sales-order-search-row">
            <flux:input
                wire:model.live.debounce.300ms="search"
                :label="__('common.search')"
                :placeholder="__('sales_orders.search_placeholder')"
            />
        </div>

        <div class="sales-order-date-row">
            <label class="active-only-toggle">
                <input type="checkbox" wire:model.live="activeOnly">
                {{ __('sales_orders.active_orders') }}
            </label>

            <div class="date-range-options">
                @foreach ($dateRanges as $range => $label)
                    <label>
                        <input type="radio" wire:model.live="dateRange" value="{{ $range }}">
                        {{ $label }}
                    </label>
                @endforeach
            </div>

            @if ($dateRange === \App\Support\SalesOrderFilters::DATE_CUSTOM)
                <flux:input type="date" wire:model.live="dateFrom" :label="__('sales_orders.field_date_from')" />
                <flux:input type="date" wire:model.live="dateTo" :label="__('sales_orders.field_date_to')" />
            @endif
        </div>

        <div class="movement-toolbar sales-order-toolbar">

            <flux:button href="{{ route('sales.orders.create') }}" variant="primary" wire:navigate>
                {{ __('sales_orders.btn_create_order') }}
            </flux:button>
            <flux:button href="{{ route('sales.orders.import') }}" variant="outline" wire:navigate>
                {{ __('sales_orders.import_btn') }}
            </flux:button>
            <flux:button
                as="a"
                href="{{ route('sales.orders.export', array_filter(array_merge($exportFilters, ['format' => 'csv']), fn ($value) => $value !== null)) }}"
                variant="ghost"
            >
                {{ __('sales_orders.export_csv_btn') }}
            </flux:button>
            <flux:button
                as="a"
                href="{{ route('sales.orders.export', array_filter(array_merge($exportFilters, ['format' => 'xlsx']), fn ($value) => $value !== null)) }}"
                variant="ghost"
            >
                {{ __('sales_orders.export_xlsx_btn') }}
            </flux:button>
        </div>

        @php
            $hasSelection = count($selectedIds) > 0;
        @endphp

        <div class="active-filter-row sales-order-action-row">
            <flux:badge color="{{ $hasSelection ? 'blue' : 'zinc' }}">{{ trans_choice('sales_orders.selected_count', count($selectedIds), ['count' => count($selectedIds)]) }}</flux:badge>
            <flux:button type="button" size="sm" variant="primary" wire:click="bulkMarkReady" :disabled="! $hasSelection">
                {{ __('sales_orders.btn_bulk_mark_ready') }}
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

            @if ($hasSelection)
                <flux:button
                    as="a"
                    size="sm"
                    variant="ghost"
                    href="{{ route('sales.orders.export', array_filter(array_merge($exportFilters, ['ids' => implode(',', $selectedIds), 'format' => 'csv']), fn ($value) => $value !== null)) }}"
                >
                    {{ __('sales_orders.btn_bulk_export_csv') }}
                </flux:button>
                <flux:button
                    as="a"
                    size="sm"
                    variant="ghost"
                    href="{{ route('sales.orders.export', array_filter(array_merge($exportFilters, ['ids' => implode(',', $selectedIds), 'format' => 'xlsx']), fn ($value) => $value !== null)) }}"
                >
                    {{ __('sales_orders.btn_bulk_export_xlsx') }}
                </flux:button>
            @else
                <flux:button type="button" size="sm" variant="ghost" disabled aria-disabled="true">
                    {{ __('sales_orders.btn_bulk_export_csv') }}
                </flux:button>
                <flux:button type="button" size="sm" variant="ghost" disabled aria-disabled="true">
                    {{ __('sales_orders.btn_bulk_export_xlsx') }}
                </flux:button>
            @endif
            <flux:button
                type="button"
                size="sm"
                variant="outline"
                wire:click="validateCourierExport('yamato')"
                :disabled="! $hasSelection"
            >
                {{ __('sales_orders.btn_export_yamato_csv') }}
            </flux:button>
            <flux:button
                type="button"
                size="sm"
                variant="outline"
                wire:click="validateCourierExport('sagawa')"
                :disabled="! $hasSelection"
            >
                {{ __('sales_orders.btn_export_sagawa_csv') }}
            </flux:button>
        </div>

        <div class="table-scroll">
            <flux:table :paginate="$orders" class="data-table sales-order-table">
                <flux:table.columns>
                    <flux:table.column></flux:table.column>
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
                            <flux:table.cell>
                                <input type="checkbox" wire:model.live="selectedIds" value="{{ $order->id }}" aria-label="{{ __('sales_orders.select_order') }} {{ $order->platform_order_id ?: $order->id }}">
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
