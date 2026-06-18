<div class="inventory-index">
    <section class="summary-grid" aria-label="Inventory summary">
        <flux:card size="sm" class="summary-card">
            <span>Filtered Stock Items</span>
            <strong>{{ number_format($summary['stock_items']) }}</strong>
        </flux:card>
        <flux:card size="sm" class="summary-card">
            <span>Filtered On Hand</span>
            <strong>{{ number_format($summary['on_hand']) }}</strong>
        </flux:card>
        <flux:card size="sm" class="summary-card">
            <span>Filtered Available</span>
            <strong>{{ number_format($summary['available']) }}</strong>
        </flux:card>
        <flux:card size="sm" class="summary-card">
            <span>Filtered Reserved</span>
            <strong>{{ number_format($summary['reserved']) }}</strong>
        </flux:card>
    </section>

    <section class="table-shell flux-panel">
        <div class="movement-toolbar inventory-toolbar">
            <flux:input
                wire:model.live.debounce.300ms="search"
                type="search"
                label="Search inventory"
                placeholder="Search stock, SKU, barcode..."
            />

            <flux:select wire:model.live="tenantId" label="Tenant">
                <flux:select.option value="">All tenants</flux:select.option>
                @foreach ($tenants as $tenant)
                    <flux:select.option value="{{ $tenant->id }}">{{ $tenant->code }} - {{ $tenant->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="warehouseId" label="Warehouse">
                <flux:select.option value="">All warehouses</flux:select.option>
                @foreach ($warehouses as $warehouse)
                    <flux:select.option value="{{ $warehouse->id }}">{{ $warehouse->code }} - {{ $warehouse->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="shopId" label="Shop">
                <flux:select.option value="">All shops</flux:select.option>
                @foreach ($shops as $shop)
                    <flux:select.option value="{{ $shop->id }}">{{ $shop->code }} - {{ $shop->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="productType" label="Product type">
                <flux:select.option value="">All types</flux:select.option>
                @foreach ($productTypes as $type)
                    <flux:select.option value="{{ $type }}">{{ str_replace('_', ' ', ucfirst($type)) }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="status" label="Status">
                <flux:select.option value="">All statuses</flux:select.option>
                @foreach ($statuses as $statusOption)
                    <flux:select.option value="{{ $statusOption }}">{{ str_replace('_', ' ', ucfirst($statusOption)) }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>

        <flux:table :paginate="$balances" class="inventory-table">
            <flux:table.columns>
                <flux:table.column>Stock Item / SKUs</flux:table.column>
                @if ($showTenantColumn)
                    <flux:table.column><span class="inventory-tenant-column-label">Tenant</span></flux:table.column>
                @endif
                <flux:table.column>Warehouse</flux:table.column>
                <flux:table.column align="end">Available</flux:table.column>
                <flux:table.column align="end">On Hand</flux:table.column>
                <flux:table.column align="end">Reserved</flux:table.column>
                <flux:table.column align="end">Inbound</flux:table.column>
                <flux:table.column>Exceptions</flux:table.column>
                <flux:table.column>Actions</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($balances as $balance)
                    <flux:table.row :key="$balance->id">
                        <flux:table.cell class="stock-item-cell">
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
                                    <flux:badge color="zinc" class="sku-chip">
                                        {{ $sku->sku }}
                                        @if ($isExpanded && $sku->platform_sku)
                                            <small>{{ $sku->platform_sku }}</small>
                                        @endif
                                        @if ($isExpanded && $sku->platform_label_code)
                                            <small>{{ $sku->platform_label_code }}</small>
                                        @endif
                                    </flux:badge>
                                @empty
                                    <span class="subtle">No SKUs</span>
                                @endforelse

                                @if ($hiddenSkuCount > 0)
                                    <flux:button
                                        type="button"
                                        size="xs"
                                        variant="subtle"
                                        class="more-link"
                                        wire:click="toggleSkuList({{ $balance->stock_item_id }})"
                                    >
                                        {{ $isExpanded ? 'Show fewer' : '+'.$hiddenSkuCount.' more' }}
                                    </flux:button>
                                @endif
                            </div>
                        </flux:table.cell>
                        @if ($showTenantColumn)
                            <flux:table.cell>
                                <strong>{{ $balance->tenant->code }}</strong>
                                <span class="subtle">{{ $balance->tenant->name }}</span>
                            </flux:table.cell>
                        @endif
                        <flux:table.cell>
                            <strong>{{ $balance->warehouse->code }}</strong>
                            <span class="subtle">{{ $balance->warehouse->name }}</span>
                        </flux:table.cell>
                        <flux:table.cell align="end">
                            <span class="{{ $this->availableStatusClass($balance->available_qty) }}">
                                {{ number_format($balance->available_qty) }}
                            </span>
                        </flux:table.cell>
                        <flux:table.cell align="end">{{ number_format($balance->on_hand_qty) }}</flux:table.cell>
                        <flux:table.cell align="end">{{ number_format($balance->reserved_qty) }}</flux:table.cell>
                        <flux:table.cell align="end">{{ number_format($balance->inbound_qty) }}</flux:table.cell>
                        <flux:table.cell class="exceptions-cell">
                            @if ($balance->hold_qty > 0)
                                <flux:badge color="amber" class="exception-badge">Hold {{ number_format($balance->hold_qty) }}</flux:badge>
                            @endif
                            @if ($balance->damaged_qty > 0)
                                <flux:badge color="red" class="exception-badge danger">Damaged {{ number_format($balance->damaged_qty) }}</flux:badge>
                            @endif
                            @if ($balance->hold_qty === 0 && $balance->damaged_qty === 0)
                                <span class="muted-dash">-</span>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:button
                                href="/inventory/movements?stock_item_id={{ $balance->stock_item_id }}"
                                size="xs"
                                variant="outline"
                                class="action-link"
                            >
                                Movements
                            </flux:button>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="{{ $showTenantColumn ? 9 : 8 }}">
                            <div class="empty-state">No inventory balances match the current filters.</div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </section>
</div>
