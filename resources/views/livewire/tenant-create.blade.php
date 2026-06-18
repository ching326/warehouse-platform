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
                </div>
                <flux:input wire:model="name" :label="__('setup.field_name')" />
                <flux:select wire:model="status" :label="__('setup.field_status')">
                    @foreach ($statuses as $value => $label)
                        <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <div class="form-grid">
                <flux:input wire:model="contactName" :label="__('setup.field_contact_name')" />
                <flux:input wire:model="contactEmail" type="email" :label="__('setup.field_contact_email')" />
            </div>

            <div class="form-grid">
                <flux:input wire:model="contactPhone" :label="__('setup.field_contact_phone')" />
                <flux:input wire:model="billingTerms" :label="__('setup.field_billing_terms')" />
            </div>

            <label>
                <span>{{ __('setup.field_notes') }}</span>
                <textarea wire:model="notes" rows="4"></textarea>
            </label>

            @foreach (['code', 'name', 'contact_name', 'contact_email', 'contact_phone', 'billing_terms', 'status', 'notes'] as $field)
                @error($field) <p class="form-error">{{ $message }}</p> @enderror
            @endforeach
        </section>

        <div class="form-actions">
            <flux:button href="{{ route('setup.tenants.index') }}" variant="subtle">{{ __('setup.btn_cancel') }}</flux:button>
            <flux:button type="submit" variant="primary">{{ __('setup.btn_create_tenant') }}</flux:button>
        </div>
    </form>
</div>
