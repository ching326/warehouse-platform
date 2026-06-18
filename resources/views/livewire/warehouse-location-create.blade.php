<div class="warehouse-location-create-page">
    <form wire:submit="save" class="sku-form">
        <section class="table-shell flux-panel form-panel">
            <div class="form-panel-header">
                <div>
                    <strong>{{ __('locations.create_page_title') }}</strong>
                    <span>{{ __('locations.create_page_subtitle') }}</span>
                </div>
                <flux:button href="{{ route('setup.locations.index') }}" variant="subtle">{{ __('locations.btn_back') }}</flux:button>
            </div>

            <div class="form-grid">
                <flux:select wire:model="warehouseId" :label="__('locations.field_warehouse')">
                    <flux:select.option value="">{{ __('stock_adjustments.select_warehouse') }}</flux:select.option>
                    @foreach ($warehouses as $warehouse)
                        <flux:select.option value="{{ $warehouse->id }}">{{ $warehouse->code }} - {{ $warehouse->name }}</flux:select.option>
                    @endforeach
                </flux:select>

                <div>
                    <flux:input wire:model="code" :label="__('locations.field_code')" />
                    <span class="subtle">{{ __('locations.field_code_hint') }}</span>
                </div>
            </div>

            <div class="form-grid">
                <flux:input wire:model="name" :label="__('locations.field_name')" />

                <flux:select wire:model="type" :label="__('locations.field_type')">
                    @foreach ($types as $value => $label)
                        <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <label>
                <span>{{ __('locations.field_note') }}</span>
                <textarea wire:model="note" rows="4"></textarea>
            </label>

            @foreach (['warehouse_id', 'code', 'name', 'type', 'note'] as $field)
                @error($field) <p class="form-error">{{ $message }}</p> @enderror
            @endforeach
        </section>

        <div class="form-actions">
            <flux:button href="{{ route('setup.locations.index') }}" variant="subtle">{{ __('locations.btn_cancel') }}</flux:button>
            <flux:button type="submit" variant="primary">{{ __('locations.btn_submit') }}</flux:button>
        </div>
    </form>
</div>
