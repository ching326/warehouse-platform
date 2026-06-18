<div class="warehouse-location-edit-page">
    <form wire:submit="save" class="sku-form">
        <section class="table-shell flux-panel form-panel">
            <div class="form-panel-header">
                <div>
                    <strong>{{ __('locations.edit_page_title') }}</strong>
                    <span>{{ $location->warehouse->code }} / {{ $location->code }}</span>
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

            <div class="form-grid three">
                <flux:input wire:model="name" :label="__('locations.field_name')" />

                <flux:select wire:model="type" :label="__('locations.field_type')">
                    @foreach ($types as $value => $label)
                        <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select wire:model="status" :label="__('locations.field_status')">
                    @foreach ($statuses as $value => $label)
                        <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <label>
                <span>{{ __('locations.field_note') }}</span>
                <textarea wire:model="note" rows="4"></textarea>
            </label>

            @foreach (['warehouse_id', 'code', 'name', 'type', 'status', 'note'] as $field)
                @error($field) <p class="form-error">{{ $message }}</p> @enderror
            @endforeach
        </section>

        <div class="form-actions">
            <flux:button href="{{ route('setup.locations.index') }}" variant="outline">{{ __('locations.btn_cancel') }}</flux:button>
            <flux:button type="submit" variant="primary">{{ __('setup.btn_save') }}</flux:button>
        </div>
    </form>
</div>
