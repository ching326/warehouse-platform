<div class="shipping-method-index-page">
    @if (session('status'))
        <div class="active-filter-row">
            <flux:badge color="green">{{ session('status') }}</flux:badge>
        </div>
    @endif

    <section class="table-shell flux-panel">
        <div class="form-panel-header">
            <div>
                <strong>{{ __('shipping.section_carriers') }}</strong>
                <span>{{ __('shipping.carrier_section_hint') }}</span>
            </div>
        </div>

        <form wire:submit="saveCarrier" class="form-grid four">
            <div>
                <flux:input wire:model="carrierCode" :label="__('shipping.field_carrier_code')" />
                @error('carrier_code') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <flux:input wire:model="carrierName" :label="__('shipping.field_carrier_name')" />
                @error('carrier_name') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <flux:input wire:model="carrierCountryCode" maxlength="2" :label="__('shipping.field_country_code')" />
                @error('carrier_country_code') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <flux:select wire:model="carrierStatus" :label="__('shipping.field_status')">
                    @foreach ($statuses as $value => $label)
                        <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>
                @error('carrier_status') <p class="form-error">{{ $message }}</p> @enderror
            </div>

            <div class="active-filter-row">
                <flux:button type="submit" variant="primary">
                    {{ $editingCarrierId ? __('shipping.btn_update_carrier') : __('shipping.btn_create_carrier') }}
                </flux:button>
                @if ($editingCarrierId)
                    <flux:button type="button" variant="outline" wire:click="resetCarrierForm">
                        {{ __('shipping.btn_cancel_carrier_edit') }}
                    </flux:button>
                @endif
            </div>
        </form>

        <flux:table class="data-table">
            <flux:table.columns>
                <flux:table.column>{{ __('shipping.field_carrier') }}</flux:table.column>
                <flux:table.column>{{ __('shipping.field_country_code') }}</flux:table.column>
                <flux:table.column>{{ __('shipping.method_count') }}</flux:table.column>
                <flux:table.column>{{ __('shipping.field_status') }}</flux:table.column>
                <flux:table.column>{{ __('shipping.col_actions') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($carrierRows as $carrier)
                    <flux:table.row :key="'carrier-'.$carrier->id">
                        <flux:table.cell>
                            <strong>{{ $carrier->name }}</strong>
                            <span class="subtle">{{ $carrier->code }}</span>
                        </flux:table.cell>
                        <flux:table.cell>{{ $carrier->country_code ?: '-' }}</flux:table.cell>
                        <flux:table.cell>{{ $carrier->shipping_methods_count }}</flux:table.cell>
                        <flux:table.cell>
                            <flux:badge color="{{ $this->statusColor($carrier->status) }}">
                                {{ $this->statusLabel($carrier->status) }}
                            </flux:badge>
                        </flux:table.cell>
                        <flux:table.cell>
                            <div class="active-filter-row">
                                <flux:button type="button" variant="primary" wire:click="editCarrier({{ $carrier->id }})">
                                    {{ __('setup.btn_edit') }}
                                </flux:button>
                                <flux:button type="button" variant="outline" wire:click="toggleCarrierStatus({{ $carrier->id }})">
                                    {{ $carrier->status === 'active' ? __('shipping.btn_deactivate') : __('shipping.btn_activate') }}
                                </flux:button>
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </section>

    <section class="table-shell flux-panel">
        <div class="form-panel-header">
            <div>
                <strong>{{ __('shipping.section_shipping_methods') }}</strong>
                <span>{{ __('shipping.shipping_methods_section_hint') }}</span>
            </div>
        </div>

        <div class="movement-toolbar">
            <flux:select wire:model.live="carrierId" :label="__('shipping.field_carrier')">
                <flux:select.option value="">{{ __('shipping.all_carriers') }}</flux:select.option>
                @foreach ($carriers as $carrier)
                    <flux:select.option value="{{ $carrier->id }}">{{ $carrier->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="statusFilter" :label="__('shipping.field_status')">
                <flux:select.option value="">{{ __('shipping.all_statuses') }}</flux:select.option>
                @foreach ($statuses as $value => $label)
                    <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:input wire:model.live.debounce.300ms="search" :label="__('setup.search_label')" :placeholder="__('shipping.search_placeholder')" />

            <flux:button href="{{ route('setup.shipping-methods.create') }}" variant="primary">
                {{ __('shipping.btn_create_method') }}
            </flux:button>
        </div>

        <flux:table :paginate="$methods" class="data-table">
            <flux:table.columns>
                <flux:table.column>{{ __('shipping.field_carrier') }}</flux:table.column>
                <flux:table.column>{{ __('shipping.field_code') }}</flux:table.column>
                <flux:table.column>{{ __('shipping.field_name') }}</flux:table.column>
                <flux:table.column>{{ __('shipping.field_service_type') }}</flux:table.column>
                <flux:table.column>{{ __('shipping.section_rates') }}</flux:table.column>
                <flux:table.column>{{ __('shipping.field_status') }}</flux:table.column>
                <flux:table.column>{{ __('shipping.col_actions') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($methods as $method)
                    <flux:table.row :key="$method->id">
                        <flux:table.cell>
                            <strong>{{ $method->carrier->name }}</strong>
                            <span class="subtle">{{ $method->carrier->code }}</span>
                        </flux:table.cell>
                        <flux:table.cell><strong>{{ $method->code }}</strong></flux:table.cell>
                        <flux:table.cell>
                            <strong>{{ $method->name }}</strong>
                            <span class="subtle">
                                {{ $method->supports_courier_csv ? __('shipping.supports_courier_csv_yes') : __('shipping.supports_courier_csv_no') }}
                            </span>
                        </flux:table.cell>
                        <flux:table.cell>{{ $method->service_type ?: '-' }}</flux:table.cell>
                        <flux:table.cell>
                            @php($rate = $method->rates->first())
                            {{ $rate ? $rate->currency.' '.number_format((float) $rate->price, 2) : '-' }}
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:badge color="{{ $this->statusColor($method->status) }}">
                                {{ $this->statusLabel($method->status) }}
                            </flux:badge>
                        </flux:table.cell>
                        <flux:table.cell>
                            <div class="active-filter-row">
                                <flux:button href="{{ route('setup.shipping-methods.edit', $method) }}" variant="primary">
                                    {{ __('setup.btn_edit') }}
                                </flux:button>
                                <flux:button type="button" variant="outline" wire:click="toggleStatus({{ $method->id }})">
                                    {{ $method->status === 'active' ? __('shipping.btn_deactivate') : __('shipping.btn_activate') }}
                                </flux:button>
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="7">
                            <div class="empty-state">{{ __('shipping.empty_state') }}</div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </section>
</div>
