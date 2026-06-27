<div class="fba-warehouse-edit-page">
    <form wire:submit="save" class="sku-form">
        <section class="table-shell flux-panel form-panel">
            <div class="form-panel-header">
                <div>
                    <strong>{{ __('setup.fba_warehouse_edit_page_title') }}</strong>
                    <span>{{ $fbaWarehouse->code }}</span>
                </div>
                <flux:button href="{{ route('setup.fba-warehouses.index') }}" variant="outline">{{ __('setup.btn_back_fba_warehouses') }}</flux:button>
            </div>

            <div class="form-grid three">
                <div>
                    <flux:select wire:model="countryCode" required :label="__('setup.fba_warehouse_country')">
                        @foreach ($countries as $value => $label)
                            <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    @error('country_code') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <flux:input wire:model="code" required :label="__('setup.fba_warehouse_code')" />
                    <span class="subtle">{{ __('setup.fba_warehouse_code_hint') }}</span>
                    @error('code') <p class="form-error">{{ $message }}</p> @enderror
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

            <div>
                <flux:input wire:model="name" required :label="__('setup.fba_warehouse_name')" />
                @error('name') <p class="form-error">{{ $message }}</p> @enderror
            </div>
        </section>

        <section class="table-shell flux-panel form-panel">
            <div class="form-panel-header">
                <div>
                    <strong>{{ __('setup.fba_warehouse_address') }}</strong>
                    <span>{{ __('setup.fba_warehouse_manual_entry_hint') }}</span>
                </div>
            </div>

            <div class="form-grid three">
                <div>
                    <flux:input wire:model="postalCode" :label="__('setup.fba_warehouse_postal_code')" />
                    @error('postal_code') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <flux:input wire:model="state" :label="__('setup.fba_warehouse_state')" />
                    @error('state') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <flux:input wire:model="city" :label="__('setup.fba_warehouse_city')" />
                    @error('city') <p class="form-error">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="form-grid">
                <div>
                    <flux:input wire:model="addressLine1" :label="__('setup.fba_warehouse_address_line1')" />
                    @error('address_line1') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <flux:input wire:model="addressLine2" :label="__('setup.fba_warehouse_address_line2')" />
                    @error('address_line2') <p class="form-error">{{ $message }}</p> @enderror
                </div>
            </div>

            <div>
                <flux:input wire:model="phone" :label="__('setup.fba_warehouse_phone')" />
                @error('phone') <p class="form-error">{{ $message }}</p> @enderror
            </div>

            <label>
                <span>{{ __('setup.fba_warehouse_note') }}</span>
                <textarea wire:model="note" rows="4"></textarea>
            </label>
            @error('note') <p class="form-error">{{ $message }}</p> @enderror
        </section>

        <div class="form-actions">
            <flux:button href="{{ route('setup.fba-warehouses.index') }}" variant="outline">{{ __('setup.btn_cancel') }}</flux:button>
            <flux:button type="submit" variant="primary">{{ __('setup.btn_save') }}</flux:button>
        </div>
    </form>
</div>
