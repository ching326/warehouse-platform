<div class="sku-create-page">
    <form wire:submit="save" class="sku-form">
        <section class="table-shell flux-panel form-panel">
            <div class="form-panel-header">
                <div>
                    <strong>Tenant / Shop</strong>
                    <span>Choose where this SKU belongs.</span>
                </div>
                <flux:button href="{{ route('skus.index') }}" variant="subtle">Back to SKUs</flux:button>
            </div>

            <div class="form-grid">
                @if ($showTenantSelect)
                    <flux:select wire:model.live="tenantId" label="Tenant">
                        <flux:select.option value="">Select tenant</flux:select.option>
                        @foreach ($tenants as $tenant)
                            <flux:select.option value="{{ $tenant->id }}">{{ $tenant->code }} - {{ $tenant->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @else
                    <label>
                        <span>Tenant</span>
                        <input type="text" value="{{ $currentTenant ? $currentTenant->code.' - '.$currentTenant->name : 'No active tenant' }}" readonly>
                    </label>
                @endif

                <flux:select wire:model="shopId" label="Shop">
                    <flux:select.option value="">No shop</flux:select.option>
                    @foreach ($shops as $shop)
                        <flux:select.option value="{{ $shop->id }}">{{ $shop->code }} - {{ $shop->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            @error('tenantId') <p class="form-error">{{ $message }}</p> @enderror
            @error('tenant_id') <p class="form-error">{{ $message }}</p> @enderror
            @error('shop_id') <p class="form-error">{{ $message }}</p> @enderror
        </section>

        <section class="table-shell flux-panel form-panel">
            <div class="form-panel-header">
                <div>
                    <strong>SKU</strong>
                    <span>Marketplace-facing SKU identity.</span>
                </div>
            </div>

            <div class="form-grid three">
                <flux:input wire:model="sku" label="SKU" />
                <flux:input wire:model="name" label="SKU name" />
                <flux:select wire:model="skuType" label="SKU type">
                    <flux:select.option value="single">Single</flux:select.option>
                    <flux:select.option value="virtual_bundle">Virtual bundle</flux:select.option>
                    <flux:select.option value="physical_bundle">Physical bundle</flux:select.option>
                </flux:select>
                <flux:input wire:model="platformSku" label="Platform SKU" />
                <flux:input wire:model="platformProductId" label="Platform product ID" />
                <flux:input wire:model="platformVariantId" label="Platform variant ID" />
                <flux:input wire:model="platformVariantName" label="Platform variant name" />
                <flux:input wire:model="platformLabelCode" label="Platform label code" />
                <flux:select wire:model="defaultPackagingMaterialId" label="Default packaging">
                    <flux:select.option value="">None</flux:select.option>
                    @foreach ($packagingMaterials as $material)
                        <flux:select.option value="{{ $material->id }}">{{ $material->code }} - {{ $material->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model="status" label="Status">
                    <flux:select.option value="active">Active</flux:select.option>
                    <flux:select.option value="inactive">Inactive</flux:select.option>
                    <flux:select.option value="draft">Draft</flux:select.option>
                    <flux:select.option value="archived">Archived</flux:select.option>
                </flux:select>
                <label class="form-grid-wide">
                    <span>Note</span>
                    <textarea wire:model="note" rows="3"></textarea>
                </label>
            </div>

            @foreach (['sku', 'name', 'sku_type', 'default_packaging_material_id', 'status'] as $field)
                @error($field) <p class="form-error">{{ $message }}</p> @enderror
            @endforeach
        </section>

        <section class="table-shell flux-panel form-panel">
            <div class="form-panel-header">
                <div>
                    <strong>Stock Item Mode</strong>
                    <span>Linked SKUs share the same inventory pool.</span>
                </div>
            </div>

            <div class="segmented-row">
                <label>
                    <input type="radio" wire:model.live="stockItemMode" value="create">
                    <span>Create new stock item</span>
                </label>
                <label>
                    <input type="radio" wire:model.live="stockItemMode" value="link">
                    <span>Link existing stock item</span>
                </label>
            </div>

            @if ($stockItemMode === 'link')
                <div class="form-grid">
                    <flux:input wire:model.live.debounce.300ms="stockItemSearch" label="Search stock items" placeholder="Code, name, barcode..." />
                    <flux:select wire:model="existingStockItemId" label="Stock item">
                        <flux:select.option value="">No stock item</flux:select.option>
                        @foreach ($stockItems as $item)
                            <flux:select.option value="{{ $item->id }}">{{ $item->code }} - {{ $item->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>
                @error('existing_stock_item_id') <p class="form-error">{{ $message }}</p> @enderror
            @else
                <div class="form-grid three">
                    <flux:input wire:model="stockItem.name" label="Stock item name" />
                    <flux:input wire:model="stockItem.short_name" label="Short name" />
                    <flux:input wire:model="stockItem.brand" label="Brand" />
                    <flux:input wire:model="stockItem.model_number" label="Model number" />
                    <flux:input wire:model="stockItem.variation_code" label="Variation code" />
                    <flux:input wire:model="stockItem.color" label="Color" />
                    <flux:input wire:model="stockItem.size" label="Size" />
                    <flux:input wire:model="stockItem.barcode" label="Barcode" />
                    <flux:select wire:model="stockItem.barcode_type" label="Barcode type">
                        <flux:select.option value="unknown">Unknown</flux:select.option>
                        <flux:select.option value="jan">JAN</flux:select.option>
                        <flux:select.option value="ean">EAN</flux:select.option>
                        <flux:select.option value="upc">UPC</flux:select.option>
                    </flux:select>
                    <flux:select wire:model="stockItem.product_type" label="Product type">
                        <flux:select.option value="normal">Normal</flux:select.option>
                        <flux:select.option value="dangerous_goods">Dangerous goods</flux:select.option>
                        <flux:select.option value="expiry_tracked">Expiry tracked</flux:select.option>
                    </flux:select>
                    <flux:select wire:model="stockItem.status" label="Stock item status">
                        <flux:select.option value="active">Active</flux:select.option>
                        <flux:select.option value="inactive">Inactive</flux:select.option>
                        <flux:select.option value="draft">Draft</flux:select.option>
                        <flux:select.option value="archived">Archived</flux:select.option>
                    </flux:select>
                    <div class="checkbox-stack">
                        <label><input type="checkbox" wire:model="stockItem.is_dangerous_goods"> Dangerous goods</label>
                        <label><input type="checkbox" wire:model="stockItem.requires_expiry_tracking"> Expiry tracking</label>
                        <label><input type="checkbox" wire:model="stockItem.requires_lot_tracking"> Lot tracking</label>
                    </div>
                </div>

                <div class="form-grid three form-grid-spaced">
                    <flux:input wire:model="stockItem.weight_value" type="number" step="0.001" min="0" label="Weight" />
                    <flux:select wire:model="stockItem.weight_unit" label="Weight unit">
                        <flux:select.option value="g">g</flux:select.option>
                        <flux:select.option value="kg">kg</flux:select.option>
                    </flux:select>
                    <flux:select wire:model="stockItem.dimension_unit" label="Dimension unit">
                        <flux:select.option value="cm">cm</flux:select.option>
                        <flux:select.option value="mm">mm</flux:select.option>
                        <flux:select.option value="in">in</flux:select.option>
                    </flux:select>
                    <flux:input wire:model="stockItem.length_value" type="number" step="0.01" min="0" label="Length" />
                    <flux:input wire:model="stockItem.width_value" type="number" step="0.01" min="0" label="Width" />
                    <flux:input wire:model="stockItem.height_value" type="number" step="0.01" min="0" label="Height" />
                </div>

                <div class="form-grid three form-grid-spaced">
                    <label>
                        <span>Description</span>
                        <textarea wire:model="stockItem.description" rows="3"></textarea>
                    </label>
                    <label>
                        <span>Stock item note</span>
                        <textarea wire:model="stockItem.note" rows="3"></textarea>
                    </label>
                    <label>
                        <span>Handling note</span>
                        <textarea wire:model="stockItem.handling_note" rows="3"></textarea>
                    </label>
                </div>

                @foreach (['stock_item.name', 'stock_item.weight_value', 'stock_item.length_value', 'stock_item.width_value', 'stock_item.height_value'] as $field)
                    @error($field) <p class="form-error">{{ $message }}</p> @enderror
                @endforeach
            @endif
        </section>

        <div class="form-actions">
            <flux:button href="{{ route('skus.index') }}" variant="subtle">Cancel</flux:button>
            <flux:button type="submit" variant="primary">Create SKU</flux:button>
        </div>
    </form>
</div>
