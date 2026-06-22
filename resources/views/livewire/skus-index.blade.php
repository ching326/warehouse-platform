<div class="skus-page">
    @php($managedStockItem = $managedStockItem ?? null)

    <section class="table-shell flux-panel">
        <div class="sku-page-actions">
            <div>
                <strong>{{ __('skus.page_title') }}</strong>
                <span>{{ __('skus.page_subtitle') }}</span>
            </div>
            <flux:button href="{{ route('skus.create') }}" variant="primary">{{ __('skus.btn_create') }}</flux:button>
        </div>
        <x-flash-toast />

        <div class="active-filter-row">
            <div class="view-switcher" role="group" aria-label="{{ __('skus.view_switcher_label') }}">
                @foreach ($views as $viewKey => $viewLabel)
                    <button
                        type="button"
                        @class(['view-switcher-btn', 'is-active' => $view === $viewKey])
                        aria-pressed="{{ $view === $viewKey ? 'true' : 'false' }}"
                        wire:click="switchView('{{ $viewKey }}')"
                    >
                        {{ $viewLabel }}
                    </button>
                @endforeach
            </div>
            @if ($canSaveDefaultView)
                <label class="default-view-toggle">
                    <input type="checkbox" wire:model.live="currentViewIsDefault">
                    <span>{{ __('skus.default_view_checkbox') }}</span>
                </label>
            @endif
        </div>

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
                @foreach ($productTypes as $type)
                    <flux:select.option value="{{ $type->slug }}">{{ $type->name }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>

        @if ($view === 'detailed')
            <flux:table :paginate="$skus" class="sku-table">
                <flux:table.columns>
                    <flux:table.column>{{ __('skus.col_sku') }}</flux:table.column>
                    <flux:table.column>{{ __('skus.col_image') }}</flux:table.column>
                    <flux:table.column>{{ __('skus.col_stock_item') }}</flux:table.column>
                    <flux:table.column>{{ __('skus.col_shop') }}</flux:table.column>
                    <flux:table.column>{{ __('skus.col_platform_ids') }}</flux:table.column>
                    <flux:table.column>{{ __('skus.col_packaging') }}</flux:table.column>
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
                            <flux:table.cell>
                                @include('livewire.partials.stock-item-thumbnail', ['stockItem' => $sku->stockItem, 'interactive' => true])
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
                                @else
                                    <span class="muted-dash">-</span>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>
                                @if ($sku->stockItem)
                                    <flux:button type="button" size="xs" variant="primary" wire:click="openImagePanel({{ $sku->stockItem->id }})">
                                        {{ __('skus.manage_images') }}
                                    </flux:button>
                                @else
                                    <span class="muted-dash">{{ __('skus.read_only') }}</span>
                                @endif
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="{{ $currentColumnCount }}">
                                <div class="empty-state">{{ __('skus.empty_state') }}</div>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        @elseif ($view === 'logistics')
            <flux:table :paginate="$skus" class="sku-table sku-logistics-table">
                <flux:table.columns>
                    <flux:table.column>{{ __('skus.col_image') }}</flux:table.column>
                    <flux:table.column>{{ __('skus.col_stock_item') }}</flux:table.column>
                    <flux:table.column>{{ __('skus.col_sku') }}</flux:table.column>
                    <flux:table.column>{{ __('skus.col_name') }}</flux:table.column>
                    <flux:table.column>{{ __('skus.col_short_name') }}</flux:table.column>
                    <flux:table.column class="sku-number-column">{{ __('skus.col_weight_g') }}</flux:table.column>
                    <flux:table.column class="sku-number-column">{{ __('skus.col_length_cm') }}</flux:table.column>
                    <flux:table.column class="sku-number-column">{{ __('skus.col_width_cm') }}</flux:table.column>
                    <flux:table.column class="sku-number-column">{{ __('skus.col_height_cm') }}</flux:table.column>
                    <flux:table.column>{{ __('skus.col_packaging') }}</flux:table.column>
                    <flux:table.column>{{ __('skus.col_shipping_method') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @forelse ($skus as $sku)
                        <flux:table.row :key="$sku->id">
                            <flux:table.cell>
                                @include('livewire.partials.stock-item-thumbnail', ['stockItem' => $sku->stockItem, 'interactive' => true])
                            </flux:table.cell>
                            <flux:table.cell class="sku-stock-cell">
                                @if ($sku->stockItem)
                                    <strong>{{ $sku->stockItem->code }}</strong>
                                @elseif ($sku->sku_type === 'virtual_bundle')
                                    <strong>{{ __('skus.virtual_bundle') }}</strong>
                                    <span title="{{ $this->bundleComposition($sku, 999) }}">{{ $this->bundleComposition($sku) }}</span>
                                @else
                                    <flux:badge color="amber">{{ __('skus.missing_stock_item') }}</flux:badge>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell class="sku-primary-cell">
                                <strong>{{ $sku->sku }}</strong>
                            </flux:table.cell>
                            <flux:table.cell class="sku-primary-cell">
                                <span title="{{ $sku->name }}">{{ $sku->name }}</span>
                            </flux:table.cell>
                            @foreach (['short_name', 'weight_value', 'length_value', 'width_value', 'height_value'] as $field)
                                <flux:table.cell class="{{ $field === 'short_name' ? 'sku-short-name-cell' : 'sku-number-cell' }}">
                                    @if ($sku->stockItem)
                                        <input
                                            type="{{ $field === 'short_name' ? 'text' : 'number' }}"
                                            step="{{ $field === 'weight_value' ? '1' : '0.1' }}"
                                            min="0"
                                            wire:model="logisticsDrafts.{{ $sku->id }}.{{ $field }}"
                                            wire:blur="saveLogisticsField({{ $sku->id }}, '{{ $field }}')"
                                        >
                                    @else
                                        <span class="muted-dash">-</span>
                                    @endif
                                </flux:table.cell>
                            @endforeach
                            <flux:table.cell class="sku-select-cell">
                                <select wire:model="logisticsDrafts.{{ $sku->id }}.default_packaging_material_id" wire:change="saveLogisticsField({{ $sku->id }}, 'default_packaging_material_id')">
                                    <option value=""></option>
                                    @foreach ($packagingMaterials as $material)
                                        <option value="{{ $material->id }}">{{ $material->code }}</option>
                                    @endforeach
                                </select>
                            </flux:table.cell>
                            <flux:table.cell class="sku-select-cell">
                                <select wire:model="logisticsDrafts.{{ $sku->id }}.default_shipping_method_id" wire:change="saveLogisticsField({{ $sku->id }}, 'default_shipping_method_id')">
                                    <option value=""></option>
                                    @php($currentShippingMethodId = (string) ($sku->default_shipping_method_id ?? ''))
                                    @foreach ($shippingMethods as $method)
                                        @php($isInactiveShippingMethod = $method->status !== 'active')
                                        @php($isCurrentShippingMethod = (string) $method->id === $currentShippingMethodId)
                                        @continue($isInactiveShippingMethod && ! $isCurrentShippingMethod)

                                        <option value="{{ $method->id }}" @disabled($isInactiveShippingMethod && ! $isCurrentShippingMethod)>
                                            {{ $method->name }}
                                            @if ($method->status !== 'active')
                                                ({{ __('skus.inactive_shipping_method') }})
                                            @endif
                                        </option>
                                    @endforeach
                                </select>
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="{{ $currentColumnCount }}">
                                <div class="empty-state">{{ __('skus.empty_state') }}</div>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        @else
            <flux:table :paginate="$skus" class="sku-table sku-flat-table sku-{{ $view }}-table">
                <flux:table.columns>
                    @foreach ($flatColumns as $label)
                        <flux:table.column>{{ $label }}</flux:table.column>
                    @endforeach
                </flux:table.columns>

                <flux:table.rows>
                    @forelse ($skus as $sku)
                        <flux:table.row :key="$sku->id">
                            @foreach ($flatColumns as $key => $label)
                                <flux:table.cell @class([
                                    'sku-flat-cell',
                                    'sku-name-cell' => $key === 'name',
                                    'sku-image-cell' => $key === 'image',
                                    'sku-narrow-cell' => in_array($key, ['variation_code', 'type'], true),
                                    'sku-product-type-cell' => $key === 'product_type',
                                ])>
                                    @if ($view === 'catalog' && $key === 'image')
                                        @include('livewire.partials.stock-item-thumbnail', ['stockItem' => $sku->stockItem, 'interactive' => true])
                                    @elseif ($view === 'catalog' && $key === 'product_type')
                                        @if ($sku->stockItem)
                                            <select
                                                class="table-control compact-select-control"
                                                wire:model="catalogDrafts.{{ $sku->id }}.product_type"
                                                wire:change="saveCatalogField({{ $sku->id }}, 'product_type')"
                                                aria-label="{{ __('skus.col_product_type') }} {{ $sku->sku }}"
                                            >
                                                @foreach ($productTypes as $type)
                                                    <option value="{{ $type->slug }}">{{ $type->name }}</option>
                                                @endforeach
                                            </select>
                                        @else
                                            <span class="muted-dash">-</span>
                                        @endif
                                    @else
                                        @php($cellValue = $this->flatCellValue($sku, $key))
                                        <span title="{{ $cellValue }}">{{ $cellValue }}</span>
                                    @endif
                                </flux:table.cell>
                            @endforeach
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="{{ $currentColumnCount }}">
                                <div class="empty-state">{{ __('skus.empty_state') }}</div>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        @endif

        @if ($managedStockItem)
            <div class="image-panel-backdrop">
                <section class="image-panel flux-panel" aria-label="{{ __('skus.manage_images') }}">
                    <div class="image-panel-header">
                        <div>
                            <strong>{{ __('skus.manage_images') }}</strong>
                            <span>{{ $managedStockItem->code }} - {{ $managedStockItem->name }}</span>
                        </div>
                        <flux:button type="button" size="sm" variant="subtle" wire:click="closeImagePanel">{{ __('skus.btn_cancel') }}</flux:button>
                    </div>

                    <form class="image-upload-form" wire:submit="uploadStockImage">
                        <label>
                            <span>{{ __('skus.image_file') }}</span>
                            <input type="file" accept="image/*" capture="environment" wire:model="stockImage">
                        </label>
                        @error('stockImage')
                            <span class="field-error">{{ $message }}</span>
                        @enderror

                        <flux:select wire:model="stockImageType" :label="__('skus.image_type')">
                            @foreach ($this->imageTypeOptions() as $type => $label)
                                <flux:select.option value="{{ $type }}">{{ $label }}</flux:select.option>
                            @endforeach
                        </flux:select>

                        <label class="default-view-toggle image-primary-toggle">
                            <input type="checkbox" wire:model="stockImageIsPrimary">
                            <span>{{ __('skus.set_as_primary') }}</span>
                        </label>

                        <flux:button type="submit" variant="primary">{{ __('skus.upload_image') }}</flux:button>
                    </form>

                    <div class="image-list">
                        @forelse ($managedStockItem->mediaAssets as $asset)
                            <article class="image-list-item" wire:key="media-asset-{{ $asset->id }}">
                                <img src="{{ $this->mediaUrl($asset) }}" alt="{{ $asset->file_name }}">
                                <div>
                                    <strong>{{ $asset->file_name }}</strong>
                                    <span>{{ $this->imageTypeOptions()[$asset->type] ?? $asset->type }} @if ($asset->is_primary) / {{ __('skus.primary_image') }} @endif</span>
                                    @if ($asset->width && $asset->height)
                                        <small>{{ $asset->width }} x {{ $asset->height }}</small>
                                    @endif
                                </div>
                                <select wire:change="updateImageType({{ $asset->id }}, $event.target.value)" aria-label="{{ __('skus.image_type') }}">
                                    @foreach ($this->imageTypeOptions() as $type => $label)
                                        <option value="{{ $type }}" @selected($asset->type === $type)>{{ $label }}</option>
                                    @endforeach
                                </select>
                                <flux:button type="button" size="xs" variant="subtle" wire:click="setPrimaryImage({{ $asset->id }})" :disabled="$asset->is_primary">
                                    {{ __('skus.set_primary') }}
                                </flux:button>
                                <flux:button type="button" size="xs" variant="danger" wire:click="deleteImage({{ $asset->id }})">
                                    {{ __('skus.delete_image') }}
                                </flux:button>
                            </article>
                        @empty
                            <div class="empty-state">{{ __('skus.no_images') }}</div>
                        @endforelse
                    </div>
                </section>
            </div>
        @endif
    </section>
</div>
