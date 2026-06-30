<div class="warehouse-location-create-page">
    <form wire:submit="save" class="sku-form">
        <section class="table-shell flux-panel form-panel">
            <div class="form-panel-header">
                <div>
                    <strong>{{ __('locations.create_page_title') }}</strong>
                    <span>{{ __('locations.create_page_subtitle') }}</span>
                </div>
                <flux:button href="{{ route('setup.locations.index') }}" variant="outline">{{ __('locations.btn_back') }}</flux:button>
            </div>

            <div class="form-grid">
                <flux:select wire:model="warehouseId" required :label="__('locations.field_warehouse')">
                    <flux:select.option value="">{{ __('stock_adjustments.select_warehouse') }}</flux:select.option>
                    @foreach ($warehouses as $warehouse)
                        <flux:select.option value="{{ $warehouse->id }}">{{ $warehouse->code }} - {{ $warehouse->name }}</flux:select.option>
                    @endforeach
                </flux:select>

                <div>
                    <flux:input wire:model="code" required :label="__('locations.field_code')" />
                    <span class="subtle">{{ __('locations.field_code_hint') }}</span>
                </div>
            </div>

            <div class="form-grid">
                <flux:input wire:model="name" :label="__('locations.field_name')" />

                <flux:select wire:model="zoneType" :label="__('locations.field_zone_type')">
                    @foreach ($zoneTypes as $value => $label)
                        <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select wire:model="storageUnitType" :label="__('locations.field_storage_unit_type')">
                    <flux:select.option value="">{{ __('locations.no_storage_unit_type') }}</flux:select.option>
                    @foreach ($storageUnitTypes as $value => $label)
                        <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <label>
                <span>{{ __('locations.field_note') }}</span>
                <textarea wire:model="note" rows="4"></textarea>
            </label>

            @foreach (['warehouse_id', 'zone_type', 'storage_unit_type', 'note'] as $field)
                @error($field) <p class="form-error">{{ $message }}</p> @enderror
            @endforeach
        </section>

        <div class="form-actions">
            <flux:button href="{{ route('setup.locations.index') }}" variant="outline">{{ __('locations.btn_cancel') }}</flux:button>
            <flux:button type="submit" variant="primary">{{ __('locations.btn_submit') }}</flux:button>
        </div>
    </form>
</div>
