<div class="form-grid three">
    <div>
        <flux:select wire:model="carrierId" :label="__('shipping.field_carrier')">
            <flux:select.option value="">{{ __('shipping.field_carrier') }}</flux:select.option>
            @foreach ($carriers as $carrier)
                <flux:select.option value="{{ $carrier->id }}">{{ $carrier->name }} / {{ $carrier->code }}</flux:select.option>
            @endforeach
        </flux:select>
        @error('carrier_id') <p class="form-error">{{ $message }}</p> @enderror
    </div>

    <div>
        <flux:input wire:model="code" :label="__('shipping.field_code')" />
        @error('code') <p class="form-error">{{ $message }}</p> @enderror
    </div>

    <div>
        <flux:input wire:model="name" :label="__('shipping.field_name')" />
        @error('name') <p class="form-error">{{ $message }}</p> @enderror
    </div>

    <div>
        <flux:input wire:model="serviceType" :label="__('shipping.field_service_type')" />
        @error('service_type') <p class="form-error">{{ $message }}</p> @enderror
    </div>

    <div>
        <flux:input wire:model="sortOrder" type="number" min="0" step="1" :label="__('shipping.field_sort_order')" />
        @error('sort_order') <p class="form-error">{{ $message }}</p> @enderror
    </div>

    <div>
        <flux:select wire:model="status" :label="__('shipping.field_status')">
            @foreach ($statuses as $value => $label)
                <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
            @endforeach
        </flux:select>
        @error('status') <p class="form-error">{{ $message }}</p> @enderror
    </div>
</div>

<div class="checkbox-stack">
    <label><input type="checkbox" wire:model="isTrackable"> {{ __('shipping.field_is_trackable') }}</label>
    <label><input type="checkbox" wire:model="requiresSize"> {{ __('shipping.field_requires_size') }}</label>
    <label><input type="checkbox" wire:model="requiresZone"> {{ __('shipping.field_requires_zone') }}</label>
    <label><input type="checkbox" wire:model="supportsCourierCsv"> {{ __('shipping.field_supports_courier_csv') }}</label>
</div>

<div class="form-subsection">
    <div class="form-panel-header">
        <div>
            <strong>{{ __('shipping.section_rates') }}</strong>
            <span>{{ __('shipping.flat_fee_hint') }}</span>
        </div>
    </div>
    <div class="form-grid">
        <div>
            <flux:input wire:model="flatFee" type="number" min="0" step="0.01" :label="__('shipping.field_flat_fee')" />
            @error('flat_fee') <p class="form-error">{{ $message }}</p> @enderror
        </div>
        <div>
            <flux:input wire:model="currency" maxlength="3" :label="__('shipping.field_currency')" />
            @error('currency') <p class="form-error">{{ $message }}</p> @enderror
        </div>
    </div>
</div>

<div class="form-subsection">
    <div class="form-panel-header">
        <div>
            <strong>{{ __('shipping.section_marketplace_mappings') }}</strong>
            <span>{{ __('shipping.mapping_hint') }}</span>
        </div>
    </div>
    <div class="form-grid three">
        <flux:input wire:model="mappingPlatform" :label="__('shipping.field_mapping_platform')" />
        <flux:input wire:model="mappingMarketplace" :label="__('shipping.field_mapping_marketplace')" />
        <flux:input wire:model="mappingCarrierCode" :label="__('shipping.field_mapping_carrier_code')" />
        <flux:input wire:model="mappingCarrierName" :label="__('shipping.field_mapping_carrier_name')" />
        <flux:input wire:model="mappingServiceCode" :label="__('shipping.field_mapping_service_code')" />
        <flux:input wire:model="mappingServiceName" :label="__('shipping.field_mapping_service_name')" />
    </div>
    @foreach (['mapping_platform', 'mapping_marketplace', 'mapping_carrier_code', 'mapping_carrier_name', 'mapping_service_code', 'mapping_service_name'] as $field)
        @error($field) <p class="form-error">{{ $message }}</p> @enderror
    @endforeach
</div>

<label>
    <span>{{ __('shipping.field_note') }}</span>
    <textarea wire:model="note" rows="4"></textarea>
    @error('note') <p class="form-error">{{ $message }}</p> @enderror
</label>
