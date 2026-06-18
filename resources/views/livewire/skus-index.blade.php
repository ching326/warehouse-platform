<div class="skus-page">
    <section class="table-shell flux-panel">
        <div class="sku-page-actions">
            <div>
                <strong>{{ __('skus.page_title') }}</strong>
                <span>{{ __('skus.page_subtitle') }}</span>
            </div>
            <flux:button href="{{ route('skus.create') }}" variant="primary">{{ __('skus.btn_create') }}</flux:button>
        </div>

        @if (session('status'))
            <div class="status-message">{{ session('status') }}</div>
        @endif

        <div class="sku-toolbar">
            <flux:input
                wire:model.live.debounce.300ms="search"
                :label="__('skus.search_label')"
                :placeholder="__('skus.search_placeholder')"
            />

            @if ($showTenantFilter)
                <flux:select wire:model.live="tenantId" :label="__('common.tenant')">
                    <flux:select.option value="">{{ __('common.all_tenants') }}</flux:select.option>
                    @foreach ($tenants as $tenant)
                        <flux:select.option value="{{ $tenant->id }}">{{ $tenant->code }} - {{ $tenant->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endif

            <flux:select wire:model.live="shopId" :label="__('common.shop')">
                <flux:select.option value="">{{ __('skus.all_shops') }}</flux:select.option>
                @foreach ($shops as $shop)
                    <flux:select.option value="{{ $shop->id }}">{{ $shop->code }} - {{ $shop->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="status" :label="__('common.status')">
                <flux:select.option value="">{{ __('skus.all_statuses') }}</flux:select.option>
                @foreach ($statuses as $option)
                    <flux:select.option value="{{ $option }}">{{ $this->statusLabel($option) }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="skuType" :label="__('skus.field_sku_type')">
                <flux:select.option value="">{{ __('skus.all_types') }}</flux:select.option>
                @foreach ($skuTypes as $option)
                    <flux:select.option value="{{ $option }}">{{ $this->skuTypeLabel($option) }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="productType" :label="__('skus.field_product_type')">
                <flux:select.option value="">{{ __('skus.all_product_types') }}</flux:select.option>
                @foreach ($productTypes as $option)
                    <flux:select.option value="{{ $option }}">{{ $this->productTypeLabel($option) }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>

        <flux:table :paginate="$skus" class="sku-table">
            <flux:table.columns>
                <flux:table.column>{{ __('skus.col_sku') }}</flux:table.column>
                <flux:table.column>{{ __('skus.col_stock_item') }}</flux:table.column>
                <flux:table.column>{{ __('skus.col_shop') }}</flux:table.column>
                <flux:table.column>{{ __('skus.col_platform_ids') }}</flux:table.column>
                <flux:table.column>{{ __('skus.col_packaging') }}</flux:table.column>
                <flux:table.column>{{ __('skus.col_status') }}</flux:table.column>
                <flux:table.column>{{ __('skus.col_actions') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($skus as $sku)
                    <flux:table.row :key="$sku->id">
                        <flux:table.cell class="sku-primary-cell">
                            <strong>{{ $sku->sku }}</strong>
                            <span>{{ $sku->name }}</span>
                            <small>{{ $this->skuTypeLabel($sku->sku_type) }}</small>
                        </flux:table.cell>
                        <flux:table.cell class="sku-stock-cell">
                            @if ($sku->stockItem)
                                <strong>{{ $sku->stockItem->code }}</strong>
                                <span>{{ $sku->stockItem->name }}</span>
                                <small>{{ $sku->stockItem->barcode ?? __('skus.no_barcode') }}</small>
                            @elseif ($sku->sku_type === 'virtual_bundle')
                                <strong>{{ __('skus.virtual_bundle') }}</strong>
                                <span title="{{ $this->bundleComposition($sku, 999) }}">{{ $this->bundleComposition($sku) }}</span>
                            @else
                                <flux:badge color="amber">{{ __('skus.missing_stock_item') }}</flux:badge>
                                <span>{{ __('skus.missing_stock_item_hint') }}</span>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell class="sku-muted-cell">
                            @if ($sku->shop)
                                <strong>{{ $sku->shop->code }}</strong>
                                <span>{{ $sku->shop->name }}</span>
                            @else
                                <span class="muted-dash">{{ __('skus.no_shop') }}</span>
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
                            <flux:badge color="{{ $sku->status === 'active' ? 'green' : 'zinc' }}">{{ $this->statusLabel($sku->status) }}</flux:badge>
                        </flux:table.cell>
                        <flux:table.cell>
                            <span class="muted-dash">{{ __('skus.read_only') }}</span>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="7">
                            <div class="empty-state">{{ __('skus.empty_state') }}</div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </section>
</div>
