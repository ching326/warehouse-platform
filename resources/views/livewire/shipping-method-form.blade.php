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
    </div>

    <div>
        <flux:input wire:model="name" :label="__('shipping.field_name')" />
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
        <flux:input wire:model="selectionPriority" type="number" min="0" max="65535" step="1" :label="__('shipping.field_selection_priority')" />
        <p class="subtle">{{ __('shipping.selection_priority_hint') }}</p>
        @error('selection_priority') <p class="form-error">{{ $message }}</p> @enderror
    </div>

    <div>
        <flux:select wire:model="status" :label="__('shipping.field_status')">
            @foreach ($statuses as $value => $label)
                <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
            @endforeach
        </flux:select>
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
        <flux:select wire:model="mappingPlatform" :label="__('shipping.field_mapping_platform')">
            <flux:select.option value="">{{ __('shipping.field_mapping_platform') }}</flux:select.option>
            @foreach ($this->mappingPlatforms() as $value => $label)
                <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model="mappingMarketplace" :label="__('shipping.field_mapping_marketplace')">
            <flux:select.option value="">{{ __('shipping.mapping_marketplace_default') }}</flux:select.option>
            @foreach ($this->mappingMarketplaces() as $value => $label)
                <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:input wire:model="mappingCarrierCode" :label="__('shipping.field_mapping_carrier_code')" />
        <flux:input wire:model="mappingCarrierName" :label="__('shipping.field_mapping_carrier_name')" />
        <flux:input wire:model="mappingServiceCode" :label="__('shipping.field_mapping_service_code')" />
        <flux:input wire:model="mappingServiceName" :label="__('shipping.field_mapping_service_name')" />
    </div>
    @foreach (['mapping_platform', 'mapping_marketplace', 'mapping_carrier_code', 'mapping_carrier_name', 'mapping_service_code', 'mapping_service_name'] as $field)
        @error($field) <p class="form-error">{{ $message }}</p> @enderror
    @endforeach

    @isset($marketplaceMappings)
        <div class="form-actions compact">
            <flux:button type="button" variant="primary" wire:click="saveMarketplaceMapping">
                {{ __('shipping.btn_save_mapping') }}
            </flux:button>
        </div>

        <div class="table-shell nested-table-shell">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('shipping.field_mapping_platform') }}</flux:table.column>
                    <flux:table.column>{{ __('shipping.field_mapping_marketplace') }}</flux:table.column>
                    <flux:table.column>{{ __('shipping.field_mapping_carrier_code') }}</flux:table.column>
                    <flux:table.column>{{ __('shipping.field_mapping_carrier_name') }}</flux:table.column>
                    <flux:table.column>{{ __('shipping.field_mapping_service_code') }}</flux:table.column>
                    <flux:table.column>{{ __('shipping.field_mapping_service_name') }}</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @forelse ($marketplaceMappings as $mapping)
                        <flux:table.row :key="$mapping->id">
                            <flux:table.cell>{{ $this->mappingPlatforms()[$mapping->platform] ?? ucfirst($mapping->platform) }}</flux:table.cell>
                            <flux:table.cell>{{ $mapping->marketplace !== '' ? $mapping->marketplace : '-' }}</flux:table.cell>
                            <flux:table.cell>{{ $mapping->carrier_code ?: '-' }}</flux:table.cell>
                            <flux:table.cell>{{ $mapping->carrier_name ?: '-' }}</flux:table.cell>
                            <flux:table.cell>{{ $mapping->service_code ?: '-' }}</flux:table.cell>
                            <flux:table.cell>{{ $mapping->service_name ?: '-' }}</flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="6">{{ __('shipping.no_marketplace_mappings') }}</flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </div>
    @endisset
</div>

<label>
    <span>{{ __('shipping.field_note') }}</span>
    <textarea wire:model="note" rows="4"></textarea>
    @error('note') <p class="form-error">{{ $message }}</p> @enderror
</label>
