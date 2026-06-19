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
        </div>

        @if (count($selectedIds) > 0)
            <div class="active-filter-row">
                <flux:badge color="blue">{{ trans_choice('sales_orders.selected_count', count($selectedIds), ['count' => count($selectedIds)]) }}</flux:badge>
                <flux:button type="button" size="sm" variant="primary" wire:click="bulkMarkReady">
                    {{ __('sales_orders.btn_bulk_mark_ready') }}
                </flux:button>
            </div>
        @endif

        <div class="table-scroll">
            <flux:table :paginate="$orders" class="data-table sales-order-table">
                <flux:table.columns>
                    <flux:table.column></flux:table.column>
                    <flux:table.column>{{ __('sales_orders.col_shop') }}</flux:table.column>
                    <flux:table.column>{{ __('sales_orders.col_platform_order_id') }}</flux:table.column>
                    <flux:table.column>{{ __('sales_orders.col_recipient') }}</flux:table.column>
                    <flux:table.column>{{ __('sales_orders.col_fulfillment_status') }}</flux:table.column>
                    <flux:table.column>{{ __('sales_orders.col_order_status') }}</flux:table.column>
                    <flux:table.column>{{ __('sales_orders.col_created_at') }}</flux:table.column>
                    <flux:table.column>{{ __('sales_orders.col_actions') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @forelse ($orders as $order)
                        <flux:table.row :key="$order->id">
                            <flux:table.cell>
                                <input type="checkbox" wire:model.live="selectedIds" value="{{ $order->id }}" aria-label="{{ __('sales_orders.select_order') }} #{{ $order->id }}">
                            </flux:table.cell>
                            <flux:table.cell class="so-shop-cell">
                                <strong>{{ $order->shop->name }}</strong>
                                <span class="subtle">{{ $order->shop->tenant->code }} / {{ $order->shop->platform }}</span>
                            </flux:table.cell>
                            <flux:table.cell>
                                <strong>{{ $order->platform_order_id ?: '-' }}</strong>
                                <span class="subtle">#{{ $order->id }}</span>
                            </flux:table.cell>
                            <flux:table.cell class="so-recipient-cell">
                                <strong>{{ $order->recipient_name ?: '-' }}</strong>
                                <span class="subtle">{{ $order->recipient_city ?: $order->recipient_postal_code ?: '-' }}</span>
                            </flux:table.cell>
                            <flux:table.cell>
                                <flux:badge color="{{ $this->fulfillmentStatusColor($order->fulfillment_status) }}">
                                    {{ $this->fulfillmentStatusLabel($order->fulfillment_status) }}
                                </flux:badge>
                            </flux:table.cell>
                            <flux:table.cell>
                                <flux:badge color="{{ $this->orderStatusColor($order->order_status) }}">
                                    {{ $this->orderStatusLabel($order->order_status) }}
                                </flux:badge>
                            </flux:table.cell>
                            <flux:table.cell>{{ $order->created_at->format('Y-m-d') }}</flux:table.cell>
                            <flux:table.cell>
                                <flux:button href="{{ route('sales.orders.show', $order) }}" size="xs" variant="outline" wire:navigate>
                                    {{ __('sales_orders.btn_view_order') }}
                                </flux:button>
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="8">
                                <div class="empty-state">{{ __('sales_orders.empty_state') }}</div>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </div>
    </section>
</div>
