<div class="tenant-create-page">
    <form wire:submit="save" class="sku-form">
        <section class="table-shell flux-panel form-panel">
            <div class="form-panel-header">
                <div>
                    <strong>{{ __('setup.tenant_create_page_title') }}</strong>
                    <span>{{ __('setup.tenant_create_page_subtitle') }}</span>
                </div>
                <flux:button href="{{ route('setup.tenants.index') }}" variant="subtle">{{ __('setup.btn_back_tenants') }}</flux:button>
            </div>

            <div class="form-grid three">
                <div>
                    <flux:input wire:model="code" :label="__('setup.field_code')" />
                    <span class="subtle">{{ __('setup.field_code_hint') }}</span>
                    @error('code') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <flux:input wire:model="name" :label="__('setup.field_name')" />
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

            <div class="form-grid">
                <div>
                    <flux:input wire:model="contactName" :label="__('setup.field_contact_name')" />
                    @error('contact_name') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <flux:input wire:model="contactEmail" type="email" :label="__('setup.field_contact_email')" />
                    @error('contact_email') <p class="form-error">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="form-grid">
                <div>
                    <flux:input wire:model="contactPhone" :label="__('setup.field_contact_phone')" />
                    @error('contact_phone') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <flux:input wire:model="billingTerms" :label="__('setup.field_billing_terms')" />
                    @error('billing_terms') <p class="form-error">{{ $message }}</p> @enderror
                </div>
            </div>

            <label>
                <span>{{ __('setup.field_notes') }}</span>
                <textarea wire:model="notes" rows="4"></textarea>
                @error('notes') <p class="form-error">{{ $message }}</p> @enderror
            </label>
        </section>

        <div class="form-actions">
            <flux:button href="{{ route('setup.tenants.index') }}" variant="subtle">{{ __('setup.btn_cancel') }}</flux:button>
            <flux:button type="submit" variant="primary">{{ __('setup.btn_create_tenant') }}</flux:button>
        </div>
    </form>
</div>
