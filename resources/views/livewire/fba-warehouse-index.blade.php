<div class="fba-warehouse-index-page">
    <x-flash-toast />

    <section class="table-shell flux-panel">
        <div class="movement-toolbar fba-warehouse-toolbar">
            <flux:select wire:model.live="countryCode" :label="__('setup.fba_warehouse_country')">
                <flux:select.option value="">{{ __('setup.all_countries') }}</flux:select.option>
                @foreach ($countries as $value => $label)
                    <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="statusFilter" :label="__('setup.field_status')">
                <flux:select.option value="">{{ __('setup.all_statuses') }}</flux:select.option>
                @foreach ($statuses as $value => $label)
                    <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:input
                wire:model.live.debounce.300ms="search"
                :label="__('setup.search_label')"
                :placeholder="__('setup.search_fba_warehouses_placeholder')"
            />

            <div class="warehouse-create-action">
                <flux:button href="{{ route('setup.fba-warehouses.create') }}" variant="primary">
                    {{ __('setup.btn_create_fba_warehouse') }}
                </flux:button>
            </div>
        </div>

        <flux:table :paginate="$fbaWarehouses" class="data-table">
            <flux:table.columns>
                <flux:table.column>{{ __('setup.fba_warehouse_code') }}</flux:table.column>
                <flux:table.column>{{ __('setup.fba_warehouse_name') }}</flux:table.column>
                <flux:table.column>{{ __('setup.fba_warehouse_country') }}</flux:table.column>
                <flux:table.column>{{ __('setup.fba_warehouse_postal_code') }}</flux:table.column>
                <flux:table.column>{{ __('setup.fba_warehouse_address') }}</flux:table.column>
                <flux:table.column>{{ __('setup.fba_warehouse_phone') }}</flux:table.column>
                <flux:table.column>{{ __('setup.field_status') }}</flux:table.column>
                <flux:table.column>{{ __('setup.fba_warehouse_note') }}</flux:table.column>
                <flux:table.column>{{ __('setup.warehouse_col_actions') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($fbaWarehouses as $fbaWarehouse)
                    <flux:table.row :key="$fbaWarehouse->id">
                        <flux:table.cell>
                            <strong>{{ $fbaWarehouse->code }}</strong>
                        </flux:table.cell>
                        <flux:table.cell>{{ $fbaWarehouse->name }}</flux:table.cell>
                        <flux:table.cell>{{ $fbaWarehouse->country_code }}</flux:table.cell>
                        <flux:table.cell>{{ $fbaWarehouse->postal_code ?: '-' }}</flux:table.cell>
                        <flux:table.cell>
                            <div class="fba-address-cell" title="{{ trim(collect([$fbaWarehouse->state, $fbaWarehouse->city, $fbaWarehouse->address_line1, $fbaWarehouse->address_line2])->filter()->join(' ')) }}">
                                @if ($fbaWarehouse->state || $fbaWarehouse->city)
                                    <div>{{ trim(($fbaWarehouse->state ?? '').' '.($fbaWarehouse->city ?? '')) }}</div>
                                @endif
                                @if ($fbaWarehouse->address_line1)
                                    <div>{{ $fbaWarehouse->address_line1 }}</div>
                                @endif
                                @if ($fbaWarehouse->address_line2)
                                    <div>{{ $fbaWarehouse->address_line2 }}</div>
                                @endif
                                @unless ($fbaWarehouse->state || $fbaWarehouse->city || $fbaWarehouse->address_line1 || $fbaWarehouse->address_line2)
                                    <span class="subtle">-</span>
                                @endunless
                            </div>
                        </flux:table.cell>
                        <flux:table.cell>{{ $fbaWarehouse->phone ?: '-' }}</flux:table.cell>
                        <flux:table.cell>
                            <flux:badge color="{{ $this->statusColor($fbaWarehouse->status) }}">
                                {{ $this->statusLabel($fbaWarehouse->status) }}
                            </flux:badge>
                        </flux:table.cell>
                        <flux:table.cell>
                            <div class="fba-note-cell" title="{{ $fbaWarehouse->note }}">{{ $fbaWarehouse->note ?: '-' }}</div>
                        </flux:table.cell>
                        <flux:table.cell>
                            <div class="row-actions">
                                <flux:button href="{{ route('setup.fba-warehouses.edit', $fbaWarehouse) }}" variant="primary">
                                    {{ __('setup.btn_edit') }}
                                </flux:button>
                                <flux:button wire:click="toggleStatus({{ $fbaWarehouse->id }})" variant="outline">
                                    {{ $fbaWarehouse->status === 'active' ? __('setup.btn_deactivate') : __('setup.btn_activate') }}
                                </flux:button>
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="9">
                            <div class="empty-state">{{ __('setup.fba_warehouse_empty') }}</div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </section>

    <style>
        .fba-warehouse-toolbar {
            grid-template-columns: 132px 132px minmax(260px, 1fr) auto;
        }

        .fba-address-cell,
        .fba-note-cell {
            display: -webkit-box;
            max-width: 260px;
            overflow: hidden;
            -webkit-box-orient: vertical;
            -webkit-line-clamp: 2;
        }

        .row-actions {
            display: flex;
            gap: 8px;
            align-items: center;
            justify-content: flex-start;
        }

        @media (max-width: 880px) {
            .fba-warehouse-toolbar {
                grid-template-columns: 1fr;
            }

            .warehouse-create-action {
                justify-self: start;
            }
        }
    </style>
</div>
