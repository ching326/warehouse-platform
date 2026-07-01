<div class="shipping-method-index-page">
    <x-flash-toast />

    <x-page-panel-header
        :title="__('shipping.index_page_title')"
        :subtitle="__('shipping.index_page_subtitle')"
    />

<section class="table-shell flux-panel">
        <div class="form-panel-header">
            <div>
                <strong>{{ __('shipping.section_carriers') }}</strong>
                <span>{{ __('shipping.carrier_section_hint') }}</span>
            </div>
        </div>

        <form wire:submit="saveCarrier" class="shipping-carrier-form">
            <div>
                <flux:select wire:model="carrierCode" :label="__('shipping.field_carrier_code')">
                    <flux:select.option value="">{{ __('shipping.select_carrier_code') }}</flux:select.option>
                    @foreach ($carrierCodeOptions as $value => $label)
                        <flux:select.option value="{{ $value }}">{{ $label }} ({{ $value }})</flux:select.option>
                    @endforeach
                </flux:select>
                @error('carrier_code') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <flux:input wire:model="carrierName" :label="__('shipping.field_carrier_name')" />
                @error('carrier_name') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <flux:select wire:model="carrierCountryCode" :label="__('shipping.field_country_code')">
                    <flux:select.option value="JP">JP</flux:select.option>
                </flux:select>
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

            <div class="active-filter-row shipping-carrier-actions">
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
                <flux:table.column class="shipping-sort-column">{{ __('shipping.field_sort_order') }}</flux:table.column>
                <flux:table.column>{{ __('shipping.field_carrier') }}</flux:table.column>
                <flux:table.column>{{ __('shipping.field_country_code') }}</flux:table.column>
                <flux:table.column>{{ __('shipping.method_count') }}</flux:table.column>
                <flux:table.column>{{ __('shipping.field_status') }}</flux:table.column>
                <flux:table.column>{{ __('shipping.col_actions') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($carrierRows as $carrier)
                    <flux:table.row :key="'carrier-'.$carrier->id">
                        <flux:table.cell class="shipping-sort-cell">
                            <flux:input
                                wire:model="carrierSortOrders.{{ $carrier->id }}"
                                type="number"
                                min="0"
                                step="1"
                                aria-label="{{ __('shipping.field_sort_order') }} {{ $carrier->name }}"
                            />
                        </flux:table.cell>
                        <flux:table.cell>
                            <strong>{{ $carrier->name }}</strong>
                            <span class="subtle">{{ $carrier->code }}</span>
                        </flux:table.cell>
                        <flux:table.cell>{{ $carrier->country_code ?: '-' }}</flux:table.cell>
                        <flux:table.cell>{{ $carrier->shipping_methods_count }}</flux:table.cell>
                        <flux:table.cell>
                            <x-status-badge :status="$carrier->status" :label="$this->statusLabel($carrier->status)" />
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

        <div class="form-actions compact">
            <flux:button type="button" variant="primary" wire:click="saveCarrierOrder">
                {{ __('shipping.btn_save_order') }}
            </flux:button>
        </div>
    </section>

    <section class="table-shell flux-panel">
        <div class="form-panel-header">
            <div>
                <strong>{{ __('shipping.section_shipping_methods') }}</strong>
                <span>{{ __('shipping.shipping_methods_section_hint') }}</span>
            </div>
        </div>

        <div class="movement-toolbar shipping-method-toolbar">
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

            <div class="shipping-method-create-action">
                <flux:button href="{{ route('setup.shipping-methods.create') }}" variant="primary">
                    {{ __('shipping.btn_create_method') }}
                </flux:button>
            </div>
        </div>

        <flux:table :paginate="$methods" class="data-table">
            <flux:table.columns>
                <flux:table.column class="shipping-sort-column">{{ __('shipping.field_sort_order') }}</flux:table.column>
                <flux:table.column class="shipping-priority-column">{{ __('shipping.field_selection_priority') }}</flux:table.column>
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
                        <flux:table.cell class="shipping-sort-cell">
                            <flux:input
                                wire:model="methodSortOrders.{{ $method->id }}"
                                type="number"
                                min="0"
                                step="1"
                                aria-label="{{ __('shipping.field_sort_order') }} {{ $method->displayName() }}"
                            />
                        </flux:table.cell>
                        <flux:table.cell class="shipping-priority-cell">
                            <flux:input
                                wire:model="methodSelectionPriorities.{{ $method->id }}"
                                type="number"
                                min="0"
                                step="1"
                                aria-label="{{ __('shipping.field_selection_priority') }} {{ $method->displayName() }}"
                            />
                        </flux:table.cell>
                        <flux:table.cell>
                            <strong>{{ $method->carrier->name }}</strong>
                            <span class="subtle">{{ $method->carrier->code }}</span>
                        </flux:table.cell>
                        <flux:table.cell><strong>{{ $method->code }}</strong></flux:table.cell>
                        <flux:table.cell>
                            <strong>{{ $method->displayName() }}</strong>
                            @if ($method->displayName() !== $method->name)
                                <span class="subtle">{{ $method->name }}</span>
                            @endif
                            <span class="subtle">
                                {{ $method->supports_courier_csv ? __('shipping.supports_courier_csv_yes') : __('shipping.supports_courier_csv_no') }}
                            </span>
                        </flux:table.cell>
                        <flux:table.cell>{{ $method->service_type ?: '-' }}</flux:table.cell>
                        <flux:table.cell>
                            @php($rate = $method->rates->first())
                            {{ $rate ? $rate->currency.' '.number_format((float) $rate->price) : '-' }}
                        </flux:table.cell>
                        <flux:table.cell>
                            <x-status-badge :status="$method->status" :label="$this->statusLabel($method->status)" />
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
                        <flux:table.cell colspan="9">
                            <div class="empty-state">{{ __('shipping.empty_state') }}</div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>

        <div class="form-actions compact">
            <flux:button type="button" variant="primary" wire:click="saveMethodOrder">
                {{ __('shipping.btn_save_order') }}
            </flux:button>
        </div>
    </section>

    <style>
        .shipping-carrier-form {
            display: grid;
            grid-template-columns: 220px 220px 118px 132px minmax(16px, 1fr) auto;
            gap: 12px;
            align-items: end;
        }

        .shipping-carrier-actions {
            grid-column: 6;
            justify-content: flex-end;
            margin-bottom: 0;
            white-space: nowrap;
        }

        .shipping-method-toolbar {
            grid-template-columns: 168px 132px minmax(260px, 1fr) auto;
        }

        .shipping-method-create-action {
            justify-self: end;
            align-self: end;
        }

        .shipping-sort-column,
        .shipping-sort-cell {
            width: 88px;
            max-width: 88px;
        }

        .shipping-sort-cell input {
            max-width: 72px;
        }

        .shipping-priority-column,
        .shipping-priority-cell {
            width: 132px;
            max-width: 132px;
        }

        .shipping-priority-cell input {
            max-width: 108px;
        }

        @media (max-width: 980px) {
            .shipping-carrier-form,
            .shipping-method-toolbar {
                grid-template-columns: 1fr 1fr;
            }

            .shipping-carrier-actions,
            .shipping-method-create-action {
                grid-column: 1 / -1;
                justify-self: start;
            }
        }
    </style>
</div>
