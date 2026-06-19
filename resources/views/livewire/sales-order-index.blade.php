<div class="sales-order-index-page">
    @if (session('status'))
        <div class="active-filter-row">
            <flux:badge color="green">{{ session('status') }}</flux:badge>
        </div>
    @endif

    <section class="table-shell flux-panel">
        <div class="movement-toolbar">
            <flux:select wire:model.live="shopId" :label="__('sales_orders.field_shop')">
                <flux:select.option value="">{{ __('sales_orders.all_shops') }}</flux:select.option>
                @foreach ($shops as $shop)
                    <flux:select.option value="{{ $shop->id }}">
                        {{ $shop->tenant->code }} / {{ $shop->name }} ({{ $shop->platform }})
                    </flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="fulfillmentStatus" :label="__('sales_orders.field_fulfillment_status')">
                <flux:select.option value="">{{ __('sales_orders.all_fulfillment_status') }}</flux:select.option>
                @foreach ($fulfillmentStatuses as $status => $label)
                    <flux:select.option value="{{ $status }}">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="orderStatus" :label="__('sales_orders.field_order_status')">
                <flux:select.option value="">{{ __('sales_orders.all_order_status') }}</flux:select.option>
                @foreach ($orderStatuses as $status => $label)
                    <flux:select.option value="{{ $status }}">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:input
                wire:model.live.debounce.300ms="search"
                :label="__('common.search')"
                :placeholder="__('sales_orders.search_placeholder')"
            />

            <flux:button href="{{ route('sales.orders.create') }}" variant="primary" wire:navigate>
                {{ __('sales_orders.btn_create_order') }}
            </flux:button>
            <flux:button href="{{ route('sales.orders.import') }}" variant="outline" wire:navigate>
                {{ __('sales_orders.import_btn') }}
            </flux:button>
            <flux:button
                as="a"
                href="{{ route('sales.orders.export', [
                    'shop' => $shopId ?: null,
                    'fulfillment' => $fulfillmentStatus ?: null,
                    'order_status' => $orderStatus ?: null,
                    'q' => $search ?: null,
                    'format' => 'csv',
                ]) }}"
                variant="ghost"
            >
                {{ __('sales_orders.export_csv_btn') }}
            </flux:button>
            <flux:button
                as="a"
                href="{{ route('sales.orders.export', [
                    'shop' => $shopId ?: null,
                    'fulfillment' => $fulfillmentStatus ?: null,
                    'order_status' => $orderStatus ?: null,
                    'q' => $search ?: null,
                    'format' => 'xlsx',
                ]) }}"
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
                    href="{{ route('sales.orders.export', [
                        'ids' => implode(',', $selectedIds),
                        'shop' => $shopId ?: null,
                        'fulfillment' => $fulfillmentStatus ?: null,
                        'order_status' => $orderStatus ?: null,
                        'q' => $search ?: null,
                        'format' => 'csv',
                    ]) }}"
                >
                    {{ __('sales_orders.btn_bulk_export_csv') }}
                </flux:button>
                <flux:button
                    as="a"
                    size="sm"
                    variant="ghost"
                    href="{{ route('sales.orders.export', [
                        'ids' => implode(',', $selectedIds),
                        'shop' => $shopId ?: null,
                        'fulfillment' => $fulfillmentStatus ?: null,
                        'order_status' => $orderStatus ?: null,
                        'q' => $search ?: null,
                        'format' => 'xlsx',
                    ]) }}"
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
        </div>

        <div class="table-scroll">
            <flux:table :paginate="$orders" class="data-table sales-order-table">
                <flux:table.columns>
                    <flux:table.column></flux:table.column>
                    <flux:table.column>{{ __('sales_orders.col_platform_order_id') }}</flux:table.column>
                    <flux:table.column>{{ __('sales_orders.col_address') }}</flux:table.column>
                    <flux:table.column>{{ __('sales_orders.col_recipient') }}</flux:table.column>
                    <flux:table.column>{{ __('sales_orders.col_items') }}</flux:table.column>
                    <flux:table.column>{{ __('sales_orders.col_shipping_method') }}</flux:table.column>
                    <flux:table.column>{{ __('sales_orders.col_tracking_no') }}</flux:table.column>
                    <flux:table.column>{{ __('sales_orders.col_order_status') }}</flux:table.column>
                    <flux:table.column>{{ __('sales_orders.col_created_at') }}</flux:table.column>
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
                                        $itemLabel = trim((string) ($line->sku?->stockItem?->short_name ?: $line->sku?->name ?: ''));
                                    @endphp
                                    <div class="so-item-line">
                                        @if ($line->quantity > 1)
                                            <strong class="danger-text">{{ $line->quantity }}</strong>
                                        @else
                                            <span class="subtle">{{ $line->quantity }}</span>
                                        @endif
                                        <span class="subtle">x</span>
                                        <strong>{{ $skuCode }}</strong>
                                        @if ($itemLabel !== '')
                                            <span class="subtle">- {{ $itemLabel }}</span>
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
                                    @foreach ($shippingMethodOptions as $value => $label)
                                        <option value="{{ $value }}" @selected(($order->shipping_method ?? '') === $value)>
                                            {{ $label }}
                                        </option>
                                    @endforeach
                                </select>
                            </flux:table.cell>
                            <flux:table.cell class="so-control-cell">
                                @php
                                    $trackingDraft = (string) ($trackingDrafts[$order->id] ?? '');
                                    $trackingSavedDraft = (string) ($trackingSavedDrafts[$order->id] ?? ($order->tracking_no ?? ''));
                                    $trackingServerDirty = trim($trackingDraft) !== trim($trackingSavedDraft);
                                @endphp

                                <input
                                    type="text"
                                    class="table-control"
                                    wire:key="tracking-{{ $order->id }}"
                                    wire:model.live.debounce.800ms="trackingDrafts.{{ $order->id }}"
                                    placeholder="{{ __('sales_orders.tracking_no_placeholder') }}"
                                    aria-label="{{ __('sales_orders.col_tracking_no') }} {{ $order->platform_order_id ?: $order->id }}"
                                >
                                <span class="so-unsaved" wire:dirty wire:target="trackingDrafts.{{ $order->id }}">
                                    {{ __('sales_orders.tracking_unsaved') }}
                                </span>
                                @if ($trackingServerDirty)
                                    <span class="so-unsaved">
                                        {{ __('sales_orders.tracking_unsaved') }}
                                    </span>
                                @endif
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
                                <strong>{{ $order->created_at->format('Y-m-d') }}</strong>
                                @if ($order->courier_csv_exported_at)
                                    <span class="subtle">
                                        {{ __('sales_orders.printed_date_label') }} {{ $order->courier_csv_exported_at->format('Y-m-d') }}
                                    </span>
                                @endif
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
    </section>
</div>
