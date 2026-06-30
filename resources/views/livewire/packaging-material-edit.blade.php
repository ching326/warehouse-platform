<div class="packaging-edit-page">
    <form wire:submit="save" class="sku-form">
        <section class="table-shell flux-panel form-panel">
            <div class="form-panel-header">
                <div>
                    <strong>{{ __('setup.packaging_section_basic') }}</strong>
                    <span>{{ __('setup.packaging_section_basic_hint') }}</span>
                </div>
                <flux:button href="{{ route('setup.packagings.index') }}" variant="outline">{{ __('setup.btn_back_packagings') }}</flux:button>
            </div>

            <div class="form-grid four">
                <div>
                    <flux:input wire:model="code" required :label="__('setup.field_code')" />
                    <span class="subtle">{{ __('setup.field_code_hint') }}</span>
                </div>
                <div>
                    <flux:input wire:model="name" required :label="__('setup.field_name')" />
                </div>
                <div>
                    <flux:select wire:model="type" required :label="__('setup.field_type')">
                        <flux:select.option value="">{{ __('setup.select_type') }}</flux:select.option>
                        @foreach ($types as $value => $label)
                            <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>
                <div>
                    <flux:select wire:model="status" :label="__('setup.field_status')">
                        @foreach ($statuses as $value => $label)
                            <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>
            </div>
        </section>

        <section class="table-shell flux-panel form-panel">
            <div class="form-panel-header">
                <div>
                    <strong>{{ __('setup.packaging_section_dimensions') }}</strong>
                </div>
            </div>

            <div class="form-grid four form-grid-spaced">
                <flux:select wire:model="dimensionUnit" :label="__('setup.field_dimension_unit')">
                    <flux:select.option value="cm">cm</flux:select.option>
                    <flux:select.option value="mm">mm</flux:select.option>
                    <flux:select.option value="in">in</flux:select.option>
                </flux:select>
            </div>
            <div class="form-grid four form-grid-spaced">
                <flux:input wire:model="lengthValue" type="number" step="0.01" min="0" :label="__('setup.field_length')" />
                <flux:input wire:model="widthValue"  type="number" step="0.01" min="0" :label="__('setup.field_width')" />
                <flux:input wire:model="heightValue" type="number" step="0.01" min="0" :label="__('setup.field_height')" />
            </div>
            <div class="form-grid four form-grid-spaced">
                <flux:select wire:model="weightUnit" :label="__('setup.field_weight_unit')">
                    <flux:select.option value="g">g</flux:select.option>
                    <flux:select.option value="kg">kg</flux:select.option>
                </flux:select>
            </div>
            <div class="form-grid four form-grid-spaced">
                <flux:input wire:model="weightValue" type="number" step="0.001" min="0" :label="__('setup.field_weight')" />
            </div>
        </section>

        <section class="table-shell flux-panel form-panel">
            <div class="form-panel-header">
                <div>
                    <strong>{{ __('setup.packaging_section_cost') }}</strong>
                </div>
            </div>

            <div class="form-grid four">
                <flux:select wire:model="currency" :label="__('setup.field_currency')">
                    <flux:select.option value="JPY">JPY</flux:select.option>
                    <flux:select.option value="CNY">CNY</flux:select.option>
                    <flux:select.option value="USD">USD</flux:select.option>
                    <flux:select.option value="HKD">HKD</flux:select.option>
                </flux:select>
                <flux:input wire:model="cost" type="number" step="0.01" min="0" :label="__('setup.field_cost')" />
            </div>

            <label style="margin-top: 12px; display: block;">
                <span>{{ __('setup.field_notes') }}</span>
                <textarea wire:model="note" rows="3"></textarea>
            </label>
            @error('note') <p class="form-error">{{ $message }}</p> @enderror
        </section>

        <div class="form-actions">
            <flux:button href="{{ route('setup.packagings.index') }}" variant="outline">{{ __('setup.btn_cancel') }}</flux:button>
            <flux:button type="submit" variant="primary">{{ __('setup.btn_save') }}</flux:button>
        </div>
    </form>
</div>
