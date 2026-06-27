<div class="warehouse-edit-page">
    <form wire:submit="save" class="sku-form">
        <section class="table-shell flux-panel form-panel">
            <div class="form-panel-header">
                <div>
                    <strong>{{ __('setup.warehouse_edit_page_title') }}</strong>
                    <span>{{ $warehouse->code }}</span>
                </div>
                <flux:button href="{{ route('setup.warehouses.index') }}" variant="outline">{{ __('setup.btn_back_warehouses') }}</flux:button>
            </div>

            <div class="form-grid three">
                <div>
                    <flux:input wire:model="code" required :label="__('setup.field_code')" />
                    <span class="subtle">{{ __('setup.field_code_hint') }}</span>
                    @error('code') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <flux:input wire:model="name" required :label="__('setup.field_name')" />
                    @error('name') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <flux:select wire:model="status" :label="__('setup.field_status')">
                        @foreach ($statuses as $value => $label)
                            <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    @error('status') <p class="form-error">{{ $message }}</p> @enderror
                </div>
            </div>
        </section>

        <section class="table-shell flux-panel form-panel">
            <div class="form-panel-header">
                <div>
                    <strong>{{ __('setup.warehouse_col_location') }}</strong>
                    <span>{{ __('setup.field_country_code_hint') }}</span>
                </div>
            </div>

            <div class="form-grid three">
                <div>
                    <flux:input wire:model="countryCode" required :label="__('setup.field_country_code')" />
                    <span class="subtle">{{ __('setup.field_country_code_hint') }}</span>
                    @error('country_code') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <flux:select wire:model="timezone" required :label="__('setup.field_timezone')">
                        @foreach ($timezones as $tz)
                            <flux:select.option value="{{ $tz }}">{{ $tz }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    @error('timezone') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <flux:input wire:model="city" :label="__('setup.field_city')" />
                    @error('city') <p class="form-error">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="form-grid">
                <div>
                    <flux:input wire:model="state" :label="__('setup.field_state')" />
                    @error('state') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <flux:input wire:model="postalCode" :label="__('setup.field_postal_code')" />
                    @error('postal_code') <p class="form-error">{{ $message }}</p> @enderror
                </div>
            </div>
        </section>

        <section class="table-shell flux-panel form-panel">
            <div class="form-grid">
                <div>
                    <flux:input wire:model="addressLine1" :label="__('setup.field_address_line1')" />
                    @error('address_line1') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <flux:input wire:model="addressLine2" :label="__('setup.field_address_line2')" />
                    @error('address_line2') <p class="form-error">{{ $message }}</p> @enderror
                </div>
            </div>

            <div>
                <flux:input wire:model="phone" :label="__('setup.field_phone')" />
                @error('phone') <p class="form-error">{{ $message }}</p> @enderror
            </div>
        </section>

        <div class="form-actions">
            <flux:button
                type="button"
                variant="danger"
                wire:click="delete"
                wire:confirm="{{ __('setup.warehouse_delete_confirm') }}"
            >
                {{ __('setup.btn_delete') }}
            </flux:button>
            <flux:button type="submit" variant="primary">{{ __('setup.btn_save') }}</flux:button>
        </div>
    </form>
</div>
