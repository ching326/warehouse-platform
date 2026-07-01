@props([
    'title',
    'subtitle' => null,
    'showNav' => true,
])

@php
    $shipToFbaQuery = [];
    $currentWarehouseId = request()->query('warehouse_id');
    if (is_scalar($currentWarehouseId) && trim((string) $currentWarehouseId) !== '') {
        $shipToFbaQuery['warehouse_id'] = (string) $currentWarehouseId;
    }
    $shipToFbaQuery['reason'] = \App\Models\OutboundOrder::REASON_FBA;
    $shipToFbaHref = route('outbound.create', $shipToFbaQuery);

    $sectionNavLinks = match (true) {
        request()->routeIs('inventory.*', 'stock-adjustments.*', 'stock-counts.*') => [
            ['label' => __('common.nav_inventory_overview'), 'href' => route('inventory.index'), 'active' => request()->routeIs('inventory.index')],
            ['label' => __('common.nav_movements'), 'href' => route('inventory.movements.index'), 'active' => request()->routeIs('inventory.movements.index')],
            ['label' => __('common.nav_stock_adjustment'), 'href' => route('stock-adjustments.create'), 'active' => request()->routeIs('stock-adjustments.*')],
            ['label' => __('common.nav_stock_count'), 'href' => route('stock-counts.create'), 'active' => request()->routeIs('stock-counts.*')],
        ],
        request()->routeIs('outbound.*', 'fulfillment.*') => [
            ['label' => __('common.nav_outbound_orders'), 'href' => route('outbound.index'), 'active' => request()->routeIs('outbound.index', 'outbound.create', 'outbound.show', 'outbound.ship') && request()->query('reason') !== \App\Models\OutboundOrder::REASON_FBA],
            ['label' => __('common.nav_fulfillment'), 'href' => route('fulfillment.index'), 'active' => request()->routeIs('fulfillment.index')],
            ['label' => __('common.nav_pick_summary'), 'href' => route('fulfillment.pick-summary'), 'active' => request()->routeIs('fulfillment.pick-summary')],
            ['label' => __('common.nav_ship_to_fba'), 'href' => $shipToFbaHref, 'active' => request()->routeIs('outbound.create') && request()->query('reason') === \App\Models\OutboundOrder::REASON_FBA],
            ['label' => __('common.nav_scan_pack'), 'href' => route('fulfillment.pack.start'), 'active' => request()->routeIs('fulfillment.pack.*', 'fulfillment.pack-scans.*', 'outbound.pack')],
        ],
        request()->routeIs('setup.*') => [
            ['label' => __('common.nav_tenants'), 'href' => route('setup.tenants.index'), 'active' => request()->routeIs('setup.tenants.*')],
            ['label' => __('common.nav_shops'), 'href' => route('setup.shops.index'), 'active' => request()->routeIs('setup.shops.*')],
            ['label' => __('common.nav_shipping_methods'), 'href' => route('setup.shipping-methods.index'), 'active' => request()->routeIs('setup.shipping-methods.*')],
            ['label' => __('common.nav_locations'), 'href' => route('setup.locations.index'), 'active' => request()->routeIs('setup.locations.*')],
            ['label' => __('common.nav_packagings'), 'href' => route('setup.packagings.index'), 'active' => request()->routeIs('setup.packagings.*')],
            ['label' => __('common.nav_other_settings'), 'href' => route('setup.other-settings'), 'active' => request()->routeIs('setup.other-settings', 'setup.warehouses.*', 'setup.product-types.*', 'setup.fba-warehouses.*', 'setup.fee-rates.*', 'setup.billing.*')],
        ],
        default => [],
    };
@endphp

<div {{ $attributes->class('page-panel-header') }}>
    <div>
        <div class="page-title-row">
            <strong>{{ $title }}</strong>
            {{ $actions ?? '' }}
        </div>
        @if ($subtitle)
            <span>{{ $subtitle }}</span>
        @endif
    </div>

    @if ($showNav && $sectionNavLinks)
        <nav class="section-nav">
            @foreach ($sectionNavLinks as $link)
                <a
                    href="{{ $link['href'] }}"
                    class="section-nav-link {{ $link['active'] ? 'is-active' : '' }}"
                    wire:navigate
                >{{ $link['label'] }}</a>
            @endforeach
        </nav>
    @endif
</div>
