@php
    $inventoryActive = request()->routeIs('inventory.*', 'stock-adjustments.*');
    $skusActive      = request()->routeIs('skus.*');
    $inboundActive   = request()->routeIs('inbound.*');
    $returnOrdersActive = request()->routeIs('return-orders.*');
    $outboundActive  = request()->routeIs('outbound.*');
    $salesActive     = request()->routeIs('sales.*');
    $fulfillmentActive = request()->routeIs('fulfillment.*');
    $issuesActive = request()->routeIs('issues.*');
    $setupActive     = request()->routeIs('setup.*');
    $shipToFbaQuery = [];
    $currentWarehouseId = request()->query('warehouse_id');
    if (is_scalar($currentWarehouseId) && trim((string) $currentWarehouseId) !== '') {
        $shipToFbaQuery['warehouse_id'] = (string) $currentWarehouseId;
    }
    $shipToFbaQuery['reason'] = \App\Models\OutboundOrder::REASON_FBA;
    $shipToFbaHref = route('outbound.create', $shipToFbaQuery);
@endphp

<nav class="top-nav" aria-label="{{ __('common.app_eyebrow') }}">
    <div class="top-nav-inner">
        <a href="{{ route('inventory.index') }}" class="top-nav-brand" wire:navigate>
            {{ __('common.app_eyebrow') }}
        </a>

        <div class="top-nav-items">
            {{-- Inventory (with dropdown) --}}
            <div
                class="top-nav-item"
                x-data="{ open: false }"
                @click.outside="open = false"
                @keydown.escape.window="open = false"
            >
                <button
                    type="button"
                    class="top-nav-btn {{ $inventoryActive ? 'is-active' : '' }}"
                    @click="open = !open"
                    :aria-expanded="open"
                >
                    {{ __('common.nav_inventory') }}
                    <svg
                        class="top-nav-chevron"
                        :class="{ 'is-open': open }"
                        viewBox="0 0 12 12"
                        fill="none"
                        xmlns="http://www.w3.org/2000/svg"
                        aria-hidden="true"
                    >
                        <path d="M2.5 4.5L6 8L9.5 4.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </button>

                <div class="top-nav-dropdown" x-show="open" x-cloak>
                    <a
                        href="{{ route('inventory.index') }}"
                        class="{{ request()->routeIs('inventory.index') ? 'is-active' : '' }}"
                        wire:navigate
                        @click="open = false"
                    >
                        {{ __('common.nav_inventory_overview') }}
                    </a>
                    <a
                        href="{{ route('inventory.movements.index') }}"
                        class="{{ request()->routeIs('inventory.movements.index') ? 'is-active' : '' }}"
                        wire:navigate
                        @click="open = false"
                    >
                        {{ __('common.nav_movements') }}
                    </a>
                    <a
                        href="{{ route('stock-adjustments.create') }}"
                        class="{{ request()->routeIs('stock-adjustments.*') ? 'is-active' : '' }}"
                        wire:navigate
                        @click="open = false"
                    >
                        {{ __('common.nav_stock_adjustment') }}
                    </a>
                </div>
            </div>

            {{-- SKUs --}}
            <a
                href="{{ route('skus.index') }}"
                class="top-nav-btn {{ $skusActive ? 'is-active' : '' }}"
                wire:navigate
            >
                {{ __('common.nav_skus') }}
            </a>

            {{-- Inbound --}}
            <a
                href="{{ route('inbound.index') }}"
                class="top-nav-btn {{ $inboundActive ? 'is-active' : '' }}"
                wire:navigate
            >
                {{ __('common.nav_inbound') }}
            </a>

            {{-- Outbound --}}
            <div
                class="top-nav-item"
                x-data="{ open: false }"
                @click.outside="open = false"
                @keydown.escape.window="open = false"
            >
                <button
                    type="button"
                    class="top-nav-btn {{ $outboundActive || $fulfillmentActive ? 'is-active' : '' }}"
                    @click="open = !open"
                    :aria-expanded="open"
                >
                    {{ __('common.nav_outbound') }}
                    <svg
                        class="top-nav-chevron"
                        :class="{ 'is-open': open }"
                        viewBox="0 0 12 12"
                        fill="none"
                        xmlns="http://www.w3.org/2000/svg"
                        aria-hidden="true"
                    >
                        <path d="M2.5 4.5L6 8L9.5 4.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </button>

                <div class="top-nav-dropdown" x-show="open" x-cloak>
                    <a
                        href="{{ route('outbound.index') }}"
                        class="{{ request()->routeIs('outbound.*') ? 'is-active' : '' }}"
                        wire:navigate
                        @click="open = false"
                    >
                        {{ __('common.nav_outbound_orders') }}
                    </a>
                    <a
                        href="{{ route('fulfillment.index') }}"
                        class="{{ request()->routeIs('fulfillment.index', 'fulfillment.pack.start', 'fulfillment.pack-scans.index') ? 'is-active' : '' }}"
                        wire:navigate
                        @click="open = false"
                    >
                        {{ __('common.nav_fulfillment') }}
                    </a>
                    <a
                        href="{{ route('fulfillment.pick-summary') }}"
                        class="{{ request()->routeIs('fulfillment.pick-summary') ? 'is-active' : '' }}"
                        wire:navigate
                        @click="open = false"
                    >
                        {{ __('common.nav_pick_summary') }}
                    </a>
                    <a
                        href="{{ $shipToFbaHref }}"
                        class="{{ request()->routeIs('outbound.create') && request()->query('reason') === \App\Models\OutboundOrder::REASON_FBA ? 'is-active' : '' }}"
                        wire:navigate
                        @click="open = false"
                    >
                        {{ __('common.nav_ship_to_fba') }}
                    </a>
                </div>
            </div>

            {{-- Sales Orders --}}
            <a
                href="{{ route('sales.orders.index') }}"
                class="top-nav-btn {{ $salesActive ? 'is-active' : '' }}"
                wire:navigate
            >
                {{ __('common.nav_sales_orders') }}
            </a>

            {{-- Returns --}}
            <a
                href="{{ route('return-orders.index') }}"
                class="top-nav-btn {{ $returnOrdersActive ? 'is-active' : '' }}"
                wire:navigate
            >
                {{ __('common.nav_return_orders') }}
            </a>

            {{-- Issues --}}
            <a
                href="{{ route('issues.index') }}"
                class="top-nav-btn {{ $issuesActive ? 'is-active' : '' }}"
                wire:navigate
            >
                {{ __('common.nav_issues') }}
            </a>

            {{-- Setup --}}
            <div
                class="top-nav-item"
                x-data="{ open: false }"
                @click.outside="open = false"
                @keydown.escape.window="open = false"
            >
                <button
                    type="button"
                    class="top-nav-btn {{ $setupActive ? 'is-active' : '' }}"
                    @click="open = !open"
                    :aria-expanded="open"
                >
                    {{ __('common.nav_setup') }}
                    <svg
                        class="top-nav-chevron"
                        :class="{ 'is-open': open }"
                        viewBox="0 0 12 12"
                        fill="none"
                        xmlns="http://www.w3.org/2000/svg"
                        aria-hidden="true"
                    >
                        <path d="M2.5 4.5L6 8L9.5 4.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </button>

                <div class="top-nav-dropdown" x-show="open" x-cloak>
                    <a
                        href="{{ route('setup.tenants.index') }}"
                        class="{{ request()->routeIs('setup.tenants.*') ? 'is-active' : '' }}"
                        wire:navigate
                        @click="open = false"
                    >
                        {{ __('common.nav_tenants') }}
                    </a>
                    <a
                        href="{{ route('setup.shops.index') }}"
                        class="{{ request()->routeIs('setup.shops.*') ? 'is-active' : '' }}"
                        wire:navigate
                        @click="open = false"
                    >
                        {{ __('common.nav_shops') }}
                    </a>
                    <a
                        href="{{ route('setup.shipping-methods.index') }}"
                        class="{{ request()->routeIs('setup.shipping-methods.*') ? 'is-active' : '' }}"
                        wire:navigate
                        @click="open = false"
                    >
                        {{ __('common.nav_shipping_methods') }}
                    </a>
                    <a
                        href="{{ route('setup.locations.index') }}"
                        class="{{ request()->routeIs('setup.locations.*') ? 'is-active' : '' }}"
                        wire:navigate
                        @click="open = false"
                    >
                        {{ __('common.nav_locations') }}
                    </a>
                    <a
                        href="{{ route('setup.packagings.index') }}"
                        class="{{ request()->routeIs('setup.packagings.*') ? 'is-active' : '' }}"
                        wire:navigate
                        @click="open = false"
                    >
                        {{ __('common.nav_packagings') }}
                    </a>
                    <a
                        href="{{ route('setup.other-settings') }}"
                        class="{{ request()->routeIs('setup.other-settings', 'setup.warehouses.*', 'setup.product-types.*', 'setup.fba-warehouses.*') ? 'is-active' : '' }}"
                        wire:navigate
                        @click="open = false"
                    >
                        {{ __('common.nav_other_settings') }}
                    </a>
                </div>
            </div>
        </div>

        {{-- Locale switcher --}}
        <div class="locale-switcher" aria-label="{{ __('common.locale_switcher') }}">
            @foreach (['en' => 'EN', 'zh_TW' => '&#32321;', 'zh_CN' => '&#31616;', 'ja' => '&#26085;'] as $locale => $label)
                <form method="POST" action="{{ route('locale.switch', $locale) }}">
                    @csrf
                    <button
                        type="submit"
                        class="locale-btn {{ app()->getLocale() === $locale ? 'locale-btn--active' : '' }}"
                    >
                        {!! $label !!}
                    </button>
                </form>
            @endforeach
        </div>
    </div>
</nav>


