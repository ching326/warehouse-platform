@php
    $inventoryActive = request()->routeIs('inventory.*', 'stock-adjustments.*');
    $skusActive      = request()->routeIs('skus.*');
    $inboundActive   = request()->routeIs('inbound.*');
    $outboundActive  = request()->routeIs('outbound.*');
    $setupActive     = request()->routeIs('setup.*');
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
            <a
                href="{{ route('outbound.index') }}"
                class="top-nav-btn {{ $outboundActive ? 'is-active' : '' }}"
                wire:navigate
            >
                {{ __('common.nav_outbound') }}
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
                        href="{{ route('setup.warehouses.index') }}"
                        class="{{ request()->routeIs('setup.warehouses.*') ? 'is-active' : '' }}"
                        wire:navigate
                        @click="open = false"
                    >
                        {{ __('common.nav_warehouses') }}
                    </a>
                    <a
                        href="{{ route('setup.locations.index') }}"
                        class="{{ request()->routeIs('setup.locations.*') ? 'is-active' : '' }}"
                        wire:navigate
                        @click="open = false"
                    >
                        {{ __('common.nav_locations') }}
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
