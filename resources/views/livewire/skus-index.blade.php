<div class="skus-page">
    <section class="table-shell flux-panel">
        <div class="sku-page-actions">
            <div>
                <strong>SKU Master</strong>
                <span>Sales listings and their stock item links.</span>
            </div>
            <flux:button href="{{ route('skus.create') }}" variant="primary">Create SKU</flux:button>
        </div>

        @if (session('status'))
            <div class="status-message">{{ session('status') }}</div>
        @endif

        <div class="sku-toolbar">
            <flux:input
                wire:model.live.debounce.300ms="search"
                label="Search SKUs"
                placeholder="SKU, stock item, barcode, platform IDs..."
            />

            @if ($showTenantFilter)
                <flux:select wire:model.live="tenantId" label="Tenant">
                    <flux:select.option value="">All tenants</flux:select.option>
                    @foreach ($tenants as $tenant)
                        <flux:select.option value="{{ $tenant->id }}">{{ $tenant->code }} - {{ $tenant->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endif

            <flux:select wire:model.live="shopId" label="Shop">
                <flux:select.option value="">All shops</flux:select.option>
                @foreach ($shops as $shop)
                    <flux:select.option value="{{ $shop->id }}">{{ $shop->code }} - {{ $shop->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="status" label="Status">
                <flux:select.option value="">All statuses</flux:select.option>
                @foreach ($statuses as $option)
                    <flux:select.option value="{{ $option }}">{{ str($option)->title() }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="skuType" label="SKU type">
                <flux:select.option value="">All types</flux:select.option>
                @foreach ($skuTypes as $option)
                    <flux:select.option value="{{ $option }}">{{ str($option)->replace('_', ' ')->title() }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="productType" label="Product type">
                <flux:select.option value="">All product types</flux:select.option>
                @foreach ($productTypes as $option)
                    <flux:select.option value="{{ $option }}">{{ str($option)->replace('_', ' ')->title() }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>

        <flux:table :paginate="$skus" class="sku-table">
            <flux:table.columns>
                <flux:table.column>SKU</flux:table.column>
                <flux:table.column>Stock Item</flux:table.column>
                <flux:table.column>Shop</flux:table.column>
                <flux:table.column>Platform IDs</flux:table.column>
                <flux:table.column>Packaging</flux:table.column>
                <flux:table.column>Status</flux:table.column>
                <flux:table.column>Actions</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($skus as $sku)
                    <flux:table.row :key="$sku->id">
                        <flux:table.cell class="sku-primary-cell">
                            <strong>{{ $sku->sku }}</strong>
                            <span>{{ $sku->name }}</span>
                            <small>{{ str($sku->sku_type)->replace('_', ' ')->title() }}</small>
                        </flux:table.cell>
                        <flux:table.cell class="sku-stock-cell">
                            @if ($sku->stockItem)
                                <strong>{{ $sku->stockItem->code }}</strong>
                                <span>{{ $sku->stockItem->name }}</span>
                                <small>{{ $sku->stockItem->barcode ?? 'No barcode' }}</small>
                            @elseif ($sku->sku_type === 'virtual_bundle')
                                <strong>Virtual bundle</strong>
                                <span title="{{ $this->bundleComposition($sku, 999) }}">{{ $this->bundleComposition($sku) }}</span>
                            @else
                                <flux:badge color="amber">Missing stock item</flux:badge>
                                <span>Link a stock item before inventory use.</span>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell class="sku-muted-cell">
                            @if ($sku->shop)
                                <strong>{{ $sku->shop->code }}</strong>
                                <span>{{ $sku->shop->name }}</span>
                            @else
                                <span class="muted-dash">No shop</span>
                            @endif
                            <small>{{ $sku->tenant->code }} / {{ $sku->tenant->name }}</small>
                        </flux:table.cell>
                        <flux:table.cell class="sku-platform-cell">
                            <span>{{ $sku->platform_sku ?: '-' }}</span>
                            <small>{{ $sku->platform_product_id ?: '-' }} / {{ $sku->platform_variant_id ?: '-' }}</small>
                            <small>{{ $sku->platform_label_code ?: '-' }}</small>
                        </flux:table.cell>
                        <flux:table.cell class="sku-muted-cell">
                            @if ($sku->defaultPackagingMaterial)
                                <strong>{{ $sku->defaultPackagingMaterial->code }}</strong>
                                <span>{{ $sku->defaultPackagingMaterial->name }}</span>
                            @else
                                <span class="muted-dash">-</span>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:badge color="{{ $sku->status === 'active' ? 'green' : 'zinc' }}">{{ str($sku->status)->title() }}</flux:badge>
                        </flux:table.cell>
                        <flux:table.cell>
                            <span class="muted-dash">Read only</span>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="7">
                            <div class="empty-state">No SKUs match the current filters.</div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </section>
</div>
