<div class="inventory-index">
    <section class="summary-grid" aria-label="Inventory summary">
        <div class="summary-card">
            <span>Filtered Stock Items</span>
            <strong>{{ number_format($summary['stock_items']) }}</strong>
        </div>
        <div class="summary-card">
            <span>Filtered On Hand</span>
            <strong>{{ number_format($summary['on_hand']) }}</strong>
        </div>
        <div class="summary-card">
            <span>Filtered Available</span>
            <strong>{{ number_format($summary['available']) }}</strong>
        </div>
        <div class="summary-card">
            <span>Filtered Reserved</span>
            <strong>{{ number_format($summary['reserved']) }}</strong>
        </div>
    </section>

    <section class="table-shell">
        <div class="table-toolbar inventory-toolbar">
            <label class="search-field">
                <span>Search inventory</span>
                <input
                    type="search"
                    wire:model.live.debounce.300ms="search"
                    placeholder="Search stock, SKU, barcode..."
                >
            </label>

            <label>
                <span>Tenant</span>
                <select wire:model.live="tenantId">
                    <option value="">All tenants</option>
                    @foreach ($tenants as $tenant)
                        <option value="{{ $tenant->id }}">{{ $tenant->code }} - {{ $tenant->name }}</option>
                    @endforeach
                </select>
            </label>

            <label>
                <span>Warehouse</span>
                <select wire:model.live="warehouseId">
                    <option value="">All warehouses</option>
                    @foreach ($warehouses as $warehouse)
                        <option value="{{ $warehouse->id }}">{{ $warehouse->code }} - {{ $warehouse->name }}</option>
                    @endforeach
                </select>
            </label>

            <label>
                <span>Shop</span>
                <select wire:model.live="shopId">
                    <option value="">All shops</option>
                    @foreach ($shops as $shop)
                        <option value="{{ $shop->id }}">{{ $shop->code }} - {{ $shop->name }}</option>
                    @endforeach
                </select>
            </label>

            <label>
                <span>Product type</span>
                <select wire:model.live="productType">
                    <option value="">All types</option>
                    @foreach ($productTypes as $type)
                        <option value="{{ $type }}">{{ str_replace('_', ' ', ucfirst($type)) }}</option>
                    @endforeach
                </select>
            </label>

            <label>
                <span>Status</span>
                <select wire:model.live="status">
                    <option value="">All statuses</option>
                    @foreach ($statuses as $statusOption)
                        <option value="{{ $statusOption }}">{{ str_replace('_', ' ', ucfirst($statusOption)) }}</option>
                    @endforeach
                </select>
            </label>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Stock Item / SKUs</th>
                        @if ($showTenantColumn)
                            <th>Tenant</th>
                        @endif
                        <th>Warehouse</th>
                        <th class="numeric">Available</th>
                        <th class="numeric">On Hand</th>
                        <th class="numeric">Reserved</th>
                        <th class="numeric">Inbound</th>
                        <th>Exceptions</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($balances as $balance)
                        <tr wire:key="inventory-balance-{{ $balance->id }}">
                            <td class="stock-item-cell">
                                <strong>{{ $balance->stockItem->name }}</strong>
                                <span class="subtle">{{ $balance->stockItem->code }}</span>
                                @if ($balance->stockItem->barcode)
                                    <span class="subtle">Barcode {{ $balance->stockItem->barcode }}</span>
                                @endif

                                <div class="sku-list">
                                    @php
                                        $allSkus = $balance->stockItem->skus;
                                        $isExpanded = $this->isSkuListExpanded($balance->stock_item_id);
                                        $visibleSkus = $isExpanded ? $allSkus : $allSkus->take(3);
                                        $hiddenSkuCount = max(0, $allSkus->count() - 3);
                                    @endphp

                                    @forelse ($visibleSkus as $sku)
                                        <span class="sku-chip">
                                            {{ $sku->sku }}
                                            @if ($isExpanded && $sku->platform_sku)
                                                <small>{{ $sku->platform_sku }}</small>
                                            @endif
                                            @if ($isExpanded && $sku->platform_label_code)
                                                <small>{{ $sku->platform_label_code }}</small>
                                            @endif
                                        </span>
                                    @empty
                                        <span class="subtle">No SKUs</span>
                                    @endforelse

                                    @if ($hiddenSkuCount > 0)
                                        <button
                                            type="button"
                                            class="more-link"
                                            wire:click="toggleSkuList({{ $balance->stock_item_id }})"
                                        >
                                            {{ $isExpanded ? 'Show fewer' : '+'.$hiddenSkuCount.' more' }}
                                        </button>
                                    @endif
                                </div>
                            </td>
                            @if ($showTenantColumn)
                                <td>
                                    <strong>{{ $balance->tenant->code }}</strong>
                                    <span class="subtle">{{ $balance->tenant->name }}</span>
                                </td>
                            @endif
                            <td>
                                <strong>{{ $balance->warehouse->code }}</strong>
                                <span class="subtle">{{ $balance->warehouse->name }}</span>
                            </td>
                            <td class="numeric">
                                <span class="{{ $this->availableStatusClass($balance->available_qty) }}">
                                    {{ number_format($balance->available_qty) }}
                                </span>
                            </td>
                            <td class="numeric">{{ number_format($balance->on_hand_qty) }}</td>
                            <td class="numeric">{{ number_format($balance->reserved_qty) }}</td>
                            <td class="numeric">{{ number_format($balance->inbound_qty) }}</td>
                            <td class="exceptions-cell">
                                @if ($balance->hold_qty > 0)
                                    <span class="exception-badge">Hold {{ number_format($balance->hold_qty) }}</span>
                                @endif
                                @if ($balance->damaged_qty > 0)
                                    <span class="exception-badge danger">Damaged {{ number_format($balance->damaged_qty) }}</span>
                                @endif
                                @if ($balance->hold_qty === 0 && $balance->damaged_qty === 0)
                                    <span class="muted-dash">-</span>
                                @endif
                            </td>
                            <td>
                                <a
                                    class="action-link"
                                    href="/inventory/movements?stock_item_id={{ $balance->stock_item_id }}"
                                >
                                    Movements
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td class="empty-state" colspan="{{ $showTenantColumn ? 9 : 8 }}">No inventory balances match the current filters.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="pagination-row">
            {{ $balances->links() }}
        </div>
    </section>
</div>
