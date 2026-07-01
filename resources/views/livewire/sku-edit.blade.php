<div class="sku-create-page">
    <form wire:submit="save" class="sku-form">
        <section class="table-shell flux-panel form-panel">
            <div class="form-panel-header">
                <div>
                    <strong>{{ __('skus.section_sku') }}</strong>
                    <span>{{ __('skus.section_sku_hint') }}</span>
                </div>
                <flux:button href="{{ route('skus.index') }}" variant="outline">{{ __('skus.btn_back') }}</flux:button>
            </div>

            <div class="form-grid three">
                <flux:input wire:model="skuCode" required :label="__('skus.field_sku')" />
                <label>
                    <span>{{ __('skus.field_sku_type') }}</span>
                    <input type="text" value="{{ __('common.sku_types.'.$sku->sku_type) }}" readonly>
                </label>
                <flux:select wire:model="shopId" :label="__('skus.field_shop')">
                    <flux:select.option value="">{{ __('skus.no_shop') }}</flux:select.option>
                    @foreach ($shops as $shop)
                        <flux:select.option value="{{ $shop->id }}">{{ $shop->code }} - {{ $shop->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="platformSku" :label="__('skus.field_platform_sku')" />
                <flux:input wire:model="platformProductId" :label="__('skus.field_platform_product_id')" />
                <flux:input wire:model="platformVariantId" :label="__('skus.field_platform_variant_id')" />
                <flux:input wire:model="platformVariantName" :label="__('skus.field_platform_variant_name')" />
                <div>
                    <flux:input wire:model="platformLabelCode" :label="__('skus.field_platform_label_code')" />
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

            @foreach (['skuCode', 'shop_id', 'default_packaging_material_id', 'default_shipping_method_id', 'status'] as $field)
                @error($field) <p class="form-error">{{ $message }}</p> @enderror
            @endforeach
        </section>

        @if ($sku->stockItem)
        <section class="table-shell flux-panel form-panel">
            <div class="form-panel-header">
                <div>
                    <strong>{{ __('skus.field_stock_item_name') }}</strong>
                    <span>{{ $sku->stockItem->code }}</span>
                </div>
            </div>

            <div class="form-grid three">
                @include('livewire.partials.localized-name-field', [
                    'label'        => __('skus.field_stock_item_name'),
                    'baseModel'    => 'stockItem.name',
                    'baseLocale'   => $stockItemNameBaseLocale,
                    'openInitially' => $stockItemHasTranslations,
                    'localeModels' => [
                        'en'    => 'stockItem.name_en',
                        'ja'    => 'stockItem.name_ja',
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
        </section>
        @endif

        <div class="form-actions">
            <flux:button href="{{ route('skus.index') }}" variant="outline">{{ __('skus.btn_cancel') }}</flux:button>
            <flux:button type="submit" variant="primary">{{ __('setup.btn_save') }}</flux:button>
        </div>
    </form>
</div>
