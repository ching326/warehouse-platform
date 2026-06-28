<div class="inventory-index">
    <section class="summary-grid" aria-label="Inventory summary">
        <flux:card size="sm" class="summary-card">
            <span>{{ __('inventory.summary_stock_items') }}</span>
            <strong>{{ number_format($summary['stock_items']) }}</strong>
        </flux:card>
        <flux:card size="sm" class="summary-card">
            <span>{{ __('inventory.summary_on_hand') }}</span>
            <strong>{{ number_format($summary['on_hand']) }}</strong>
        </flux:card>
        <flux:card size="sm" class="summary-card">
            <span>{{ __('inventory.summary_available') }}</span>
            <strong>{{ number_format($summary['available']) }}</strong>
        </flux:card>
        <flux:card size="sm" class="summary-card">
            <span>{{ __('inventory.summary_reserved') }}</span>
            <strong>{{ number_format($summary['reserved']) }}</strong>
        </flux:card>
    </section>

    <section class="table-shell flux-panel">
        <div class="movement-toolbar inventory-toolbar">
            <flux:input
                wire:model.live.debounce.300ms="search"
                type="search"
                :label="__('inventory.search_label')"
                :placeholder="__('inventory.search_placeholder')"
            />

            <div class="inventory-filter-row">
                <label class="default-view-toggle">
                    <input type="checkbox" wire:model.live="showTenantItemCode">
                    <span>{{ __('skus.tenant_code_toggle') }}</span>
                </label>

                <flux:select wire:model.live="tenantId" :label="__('common.tenant')">
                    <flux:select.option value="">{{ __('common.all_tenants') }}</flux:select.option>
                    @foreach ($tenants as $tenant)
                        <flux:select.option value="{{ $tenant->id }}">{{ $tenant->code }} - {{ $tenant->name }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select wire:model.live="warehouseId" :label="__('common.warehouse')">
                    <flux:select.option value="">{{ __('common.all_warehouses') }}</flux:select.option>
                    @foreach ($warehouses as $warehouse)
                        <flux:select.option value="{{ $warehouse->id }}">{{ $warehouse->code }} - {{ $warehouse->name }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select wire:model.live="shopId" :label="__('common.shop')">
                    <flux:select.option value="">{{ __('common.all_shops') }}</flux:select.option>
                    @foreach ($shops as $shop)
                        <flux:select.option value="{{ $shop->id }}">{{ $shop->code }} - {{ $shop->name }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select wire:model.live="productType" :label="__('skus.field_product_type')">
                    <flux:select.option value="">{{ __('common.all_types') }}</flux:select.option>
                    @foreach ($productTypes as $type)
                        <flux:select.option value="{{ $type->slug }}">{{ $type->name }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select wire:model.live="status" :label="__('common.status')">
                    <flux:select.option value="">{{ __('common.all_statuses') }}</flux:select.option>
                    @foreach ($statuses as $statusOption)
                        <flux:select.option value="{{ $statusOption }}">{{ $this->statusLabel($statusOption) }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
        </div>

        <flux:table :paginate="$balances" class="inventory-table">
            <flux:table.columns>
                <flux:table.column>{{ __('inventory.col_stock_item_skus') }}</flux:table.column>
                @if ($showTenantColumn)
                    <flux:table.column><span class="inventory-tenant-column-label">{{ __('common.tenant') }}</span></flux:table.column>
                @endif
                <flux:table.column align="end" class="inventory-number-column">{{ __('inventory.col_available') }}</flux:table.column>
                <flux:table.column align="end" class="inventory-number-column">{{ __('inventory.col_on_hand') }}</flux:table.column>
                <flux:table.column align="end" class="inventory-number-column">{{ __('inventory.col_reserved') }}</flux:table.column>
                <flux:table.column align="end" class="inventory-number-column">{{ __('inventory.col_inbound') }}</flux:table.column>
                <flux:table.column>{{ __('inventory.col_exceptions') }}</flux:table.column>
                <flux:table.column>{{ __('inventory.col_actions') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($balances as $balance)
                    <flux:table.row :key="$balance->id">
                        <flux:table.cell class="stock-item-cell">
                            <div class="stock-item-summary">
                                @include('livewire.partials.stock-item-thumbnail', ['stockItem' => $balance->stockItem])
                                <div>
                                    <strong>{{ $balance->stockItem->name }}</strong>
                                    <span class="subtle">{{ $this->stockItemPrimaryCode($balance->stockItem) }}</span>
                                    @if ($this->stockItemSecondaryCode($balance->stockItem))
                                        <span class="subtle">{{ $this->stockItemSecondaryCode($balance->stockItem) }}</span>
                                    @endif
                                    @if ($balance->stockItem->barcode)
                                        <span class="subtle">{{ __('inventory.barcode_label', ['barcode' => $balance->stockItem->barcode]) }}</span>
                                    @endif
                                </div>
                            </div>

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
                                    <span class="subtle">{{ __('inventory.no_skus') }}</span>
                                @endforelse

                                @if ($hiddenSkuCount > 0)
                                    <flux:button
                                        type="button"
                                        size="xs"
                                        variant="subtle"
                                        class="more-link"
                                        wire:click="toggleSkuList({{ $balance->stock_item_id }})"
                                    >
                                        {{ $isExpanded
                                            ? __('inventory.show_fewer')
                                            : __('inventory.more_skus', ['count' => $hiddenSkuCount]) }}
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
                        <flux:table.cell align="end" class="inventory-number-cell">
                            <span class="{{ $this->availableStatusClass($balance->available_qty) }}">
                                {{ number_format($balance->available_qty) }}
                            </span>
                        </flux:table.cell>
                        <flux:table.cell align="end" class="inventory-number-cell">{{ number_format($balance->on_hand_qty) }}</flux:table.cell>
                        <flux:table.cell align="end" class="inventory-number-cell">{{ number_format($balance->reserved_qty) }}</flux:table.cell>
                        <flux:table.cell align="end" class="inventory-number-cell">{{ number_format($balance->inbound_qty) }}</flux:table.cell>
                        <flux:table.cell class="exceptions-cell">
                            @if ($balance->hold_qty > 0)
                                <flux:badge color="amber" class="exception-badge">{{ __('inventory.exception_hold', ['qty' => number_format($balance->hold_qty)]) }}</flux:badge>
                            @endif
                            @if ($balance->damaged_qty > 0)
                                <flux:badge color="red" class="exception-badge danger">{{ __('inventory.exception_damaged', ['qty' => number_format($balance->damaged_qty)]) }}</flux:badge>
                            @endif
                            @if ($balance->hold_qty === 0 && $balance->damaged_qty === 0)
                                <span class="muted-dash">-</span>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>
                            <div class="standard-row-actions inventory-row-actions">
                                <flux:button
                                    class="action-button-md"
                                    href="/inventory/movements?stock_item_id={{ $balance->stock_item_id }}"
                                    size="sm"
                                    variant="primary"
                                >
                                    {{ __('inventory.btn_movements') }}
                                </flux:button>
                                <flux:button
                                    class="action-button-md"
                                    href="{{ route('stock-adjustments.create', [
                                        'tenant_id' => $balance->tenant_id,
                                        'warehouse_id' => $balance->warehouse_id,
                                        'stock_item_id' => $balance->stock_item_id,
                                    ]) }}"
                                    size="sm"
                                    variant="primary"
                                >
                                    {{ __('stock_adjustments.btn_adjust') }}
                                </flux:button>
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="{{ $showTenantColumn ? 8 : 7 }}">
                            <div class="empty-state">{{ __('inventory.empty_state') }}</div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </section>
</div>
