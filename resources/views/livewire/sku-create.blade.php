<div class="sku-create-page">
    <form wire:submit="save" class="sku-form">
        <section class="table-shell flux-panel form-panel">
            <div class="form-panel-header">
                <div>
                    <strong>{{ __('skus.section_tenant_shop') }}</strong>
                    <span>{{ __('skus.section_tenant_shop_hint') }}</span>
                </div>
                <flux:button href="{{ route('skus.index') }}" variant="outline">{{ __('skus.btn_back') }}</flux:button>
            </div>

            <div class="form-grid">
                @if ($showTenantSelect)
                    <flux:select wire:model.live="tenantId" required :label="__('skus.field_tenant')">
                        <flux:select.option value="">{{ __('skus.select_tenant') }}</flux:select.option>
                        @foreach ($tenants as $tenant)
                            <flux:select.option value="{{ $tenant->id }}">{{ $tenant->code }} - {{ $tenant->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @else
                    <label>
                        <span>{{ __('skus.field_tenant') }}</span>
                        <input type="text" value="{{ $currentTenant ? $currentTenant->code.' - '.$currentTenant->name : __('skus.no_active_tenant') }}" readonly>
                    </label>
                @endif

                <flux:select wire:model="shopId" :label="__('skus.field_shop')">
                    <flux:select.option value="">{{ __('skus.no_shop') }}</flux:select.option>
                    @foreach ($shops as $shop)
                        <flux:select.option value="{{ $shop->id }}">{{ $shop->code }} - {{ $shop->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            @error('tenant_id') <p class="form-error">{{ $message }}</p> @enderror
            @error('shop_id') <p class="form-error">{{ $message }}</p> @enderror
        </section>

        <section class="table-shell flux-panel form-panel">
            <div class="form-panel-header">
                <div>
                    <strong>{{ __('skus.section_sku') }}</strong>
                    <span>{{ __('skus.section_sku_hint') }}</span>
                </div>
            </div>

            <div class="form-grid three">
                <flux:input wire:model="sku" required :label="__('skus.field_sku')" />
                <flux:select wire:model="skuType" :label="__('skus.field_sku_type')">
                    <flux:select.option value="single">{{ __('common.sku_types.single') }}</flux:select.option>
                    <flux:select.option value="virtual_bundle">{{ __('common.sku_types.virtual_bundle') }}</flux:select.option>
                    <flux:select.option value="physical_bundle">{{ __('common.sku_types.physical_bundle') }}</flux:select.option>
                </flux:select>
                <flux:input wire:model="platformSku" :label="__('skus.field_platform_sku')" />
                <div>
                    <flux:input wire:model="platformProductId" :label="__('skus.field_platform_product_id')" />
                    <small class="field-hint">{{ __('skus.field_platform_product_id_hint') }}</small>
                </div>
                <div>
                    <flux:input wire:model="platformVariantId" :label="__('skus.field_platform_variant_id')" />
                    <small class="field-hint">{{ __('skus.field_platform_variant_id_hint') }}</small>
                </div>
                <div>
                    <flux:input wire:model="platformVariantName" :label="__('skus.field_platform_variant_name')" />
                    <small class="field-hint">{{ __('skus.field_platform_variant_name_hint') }}</small>
                </div>
                <div>
                    <flux:input wire:model="platformLabelCode" :label="__('skus.field_platform_label_code')" />
                    <small class="field-hint">{{ __('skus.field_platform_label_code_hint') }}</small>
                    <small class="field-hint">{{ __('skus.fnsku_also_scannable_hint') }}</small>
                </div>
                <flux:select wire:model="defaultPackagingMaterialId" :label="__('skus.field_default_packaging')">
                    <flux:select.option value="">{{ __('skus.no_packaging') }}</flux:select.option>
                    @foreach ($packagingMaterials as $material)
                        <flux:select.option value="{{ $material->id }}">{{ $material->code }} - {{ $material->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model="defaultShippingMethodId" :label="__('skus.field_default_shipping_method')">
                    <flux:select.option value="">{{ __('skus.no_shipping_method') }}</flux:select.option>
                    @foreach ($shippingMethods as $method)
                        <flux:select.option value="{{ $method->id }}">{{ $method->displayName() }} / {{ $method->carrier?->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model="status" :label="__('skus.field_status')">
                    <flux:select.option value="active">{{ __('common.statuses.active') }}</flux:select.option>
                    <flux:select.option value="inactive">{{ __('common.statuses.inactive') }}</flux:select.option>
                    <flux:select.option value="draft">{{ __('common.statuses.draft') }}</flux:select.option>
                    <flux:select.option value="archived">{{ __('common.statuses.archived') }}</flux:select.option>
                </flux:select>
                <label class="form-grid-wide">
                    <span>{{ __('skus.field_note') }}</span>
                    <textarea wire:model="note" rows="3"></textarea>
                </label>
            </div>

            @foreach (['sku_type', 'default_packaging_material_id', 'default_shipping_method_id'] as $field)
                @error($field) <p class="form-error">{{ $message }}</p> @enderror
            @endforeach
        </section>

        @if ($skuType === 'virtual_bundle')
        <section class="table-shell flux-panel form-panel">
            <div class="form-panel-header">
                <div>
                    <strong>{{ __('skus.section_virtual_bundle_info') }}</strong>
                    <span>{{ __('skus.section_virtual_bundle_hint') }}</span>
                </div>
            </div>
        </section>
        @else
        <section class="table-shell flux-panel form-panel form-panel-with-overflow">
            <div class="form-panel-header">
                <div>
                    <strong>{{ __('skus.section_stock_item_mode') }}</strong>
                    <span>{{ __('skus.section_stock_item_mode_hint') }}</span>
                </div>
            </div>

            <div class="segmented-row">
                <label>
                    <input type="radio" wire:model.live="stockItemMode" value="create">
                    <span>{{ __('skus.stock_item_mode_create') }}</span>
                </label>
                <label>
                    <input type="radio" wire:model.live="stockItemMode" value="link">
                    <span>{{ __('skus.stock_item_mode_link') }}</span>
                </label>
            </div>

            @if ($stockItemMode === 'link')
                @php
                    $stockItemOptions = collect($stockItems)->map(fn ($item) => [
                        'value' => $item->id,
                        'label' => $item->tenant_item_code ?: $item->code,
                        'meta' => trim(($item->tenant_item_code ? $item->code.' / ' : '').$item->displayName()),
                    ]);
                    $selectedStockItem = $stockItemOptions->firstWhere('value', (int) $existingStockItemId);
                @endphp
                <div class="form-grid">
                    <x-searchable-select
                        wire:key="sku-create-stock-item-picker-{{ $tenantId }}-{{ $existingStockItemId }}"
                        :label="__('skus.col_stock_item')"
                        model="existingStockItemId"
                        search-model="stockItemSearch"
                        :options="$stockItemOptions"
                        :selected-label="$selectedStockItem['label'] ?? $stockItemSearch"
                        :placeholder="__('stock_adjustments.select_stock_item')"
                        empty-label="No results"
                    />
                </div>
                @error('existing_stock_item_id') <p class="form-error">{{ $message }}</p> @enderror
            @else
                <div class="form-grid three">
                    @include('livewire.partials.localized-name-field', [
                        'label' => __('skus.field_stock_item_name'),
                        'baseModel' => 'stockItem.name',
                        'baseLocale' => $stockItemNameBaseLocale,
                        'localeModels' => [
                            'en' => 'stockItem.name_en',
                            'ja' => 'stockItem.name_ja',
                            'zh_TW' => 'stockItem.name_zh_tw',
                            'zh_CN' => 'stockItem.name_zh_cn',
                        ],
                    ])
                    <flux:input wire:model="stockItem.tenant_item_code" :label="__('skus.field_tenant_item_code')" />
                    <flux:input wire:model="stockItem.short_name" :label="__('skus.field_short_name')" />
                    <flux:input wire:model="stockItem.brand" :label="__('skus.field_brand')" />
                    <flux:input wire:model="stockItem.model_number" :label="__('skus.field_model_number')" />
                    <flux:input wire:model="stockItem.variation_code" :label="__('skus.field_variation_code')" />
                    <flux:input wire:model="stockItem.color" :label="__('skus.field_color')" />
                    <flux:input wire:model="stockItem.size" :label="__('skus.field_size')" />
                    <flux:select wire:model="stockItem.product_type" :label="__('skus.field_product_type')">
                        @foreach ($productTypes as $type)
                            <flux:select.option value="{{ $type->slug }}">{{ $type->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:select wire:model="stockItem.status" :label="__('skus.field_stock_item_status')">
                        <flux:select.option value="active">{{ __('common.statuses.active') }}</flux:select.option>
                        <flux:select.option value="inactive">{{ __('common.statuses.inactive') }}</flux:select.option>
                        <flux:select.option value="draft">{{ __('common.statuses.draft') }}</flux:select.option>
                        <flux:select.option value="archived">{{ __('common.statuses.archived') }}</flux:select.option>
                    </flux:select>
                    <div class="checkbox-stack form-grid-wide">
                        <label><input type="checkbox" wire:model="stockItem.is_dangerous_goods"> {{ __('skus.field_is_dangerous_goods') }}</label>
                        <label><input type="checkbox" wire:model="stockItem.requires_expiry_tracking"> {{ __('skus.field_requires_expiry') }}</label>
                        <label><input type="checkbox" wire:model="stockItem.requires_lot_tracking"> {{ __('skus.field_requires_lot') }}</label>
                    </div>
                </div>

                <div class="form-grid three form-grid-spaced">
                    <flux:input wire:model="stockItem.weight_value" type="number" step="0.001" min="0" :label="__('skus.field_weight')" />
                    <flux:select wire:model="stockItem.weight_unit" :label="__('skus.field_weight_unit')">
                        <flux:select.option value="g">g</flux:select.option>
                        <flux:select.option value="kg">kg</flux:select.option>
                    </flux:select>
                    <flux:select wire:model="stockItem.dimension_unit" :label="__('skus.field_dimension_unit')">
                        <flux:select.option value="cm">cm</flux:select.option>
                        <flux:select.option value="mm">mm</flux:select.option>
                        <flux:select.option value="in">in</flux:select.option>
                    </flux:select>
                    <flux:input wire:model="stockItem.length_value" type="number" step="0.01" min="0" :label="__('skus.field_length')" />
                    <flux:input wire:model="stockItem.width_value" type="number" step="0.01" min="0" :label="__('skus.field_width')" />
                    <flux:input wire:model="stockItem.height_value" type="number" step="0.01" min="0" :label="__('skus.field_height')" />
                </div>

                <div class="form-grid three form-grid-spaced">
                    <label>
                        <span>{{ __('skus.field_description') }}</span>
                        <textarea wire:model="stockItem.description" rows="3"></textarea>
                    </label>
                    <label>
                        <span>{{ __('skus.field_stock_item_note') }}</span>
                        <textarea wire:model="stockItem.note" rows="3"></textarea>
                    </label>
                    <label>
                        <span>{{ __('skus.field_handling_note') }}</span>
                        <textarea wire:model="stockItem.handling_note" rows="3"></textarea>
                    </label>
                </div>

                @foreach (['stock_item.name', 'stock_item.tenant_item_code', 'stock_item.weight_value', 'stock_item.length_value', 'stock_item.width_value', 'stock_item.height_value'] as $field)
                    @error($field) <p class="form-error">{{ $message }}</p> @enderror
                @endforeach
            @endif
        </section>
        @endif

        <div class="form-actions">
            <flux:button href="{{ route('skus.index') }}" variant="outline">{{ __('skus.btn_cancel') }}</flux:button>
            <flux:button type="submit" variant="primary">{{ __('skus.btn_submit') }}</flux:button>
        </div>
    </form>

</div>
