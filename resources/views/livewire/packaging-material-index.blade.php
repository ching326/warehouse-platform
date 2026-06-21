<div class="packaging-index-page">
    <x-flash-toast />

<section class="table-shell flux-panel">
        <div class="movement-toolbar packaging-toolbar">
            <flux:select wire:model.live="typeFilter" :label="__('setup.field_type')">
                <flux:select.option value="">{{ __('setup.all_types') }}</flux:select.option>
                @foreach ($types as $value => $label)
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
                :placeholder="__('setup.search_packagings_placeholder')"
            />

            <div class="packaging-create-action">
                <flux:button href="{{ route('setup.packagings.create') }}" variant="primary">
                    {{ __('setup.btn_create_packaging') }}
                </flux:button>
            </div>
        </div>

        <flux:table :paginate="$items" class="data-table">
            <flux:table.columns>
                <flux:table.column>{{ __('setup.packaging_col_code') }}</flux:table.column>
                <flux:table.column>{{ __('setup.packaging_col_name') }}</flux:table.column>
                <flux:table.column>{{ __('setup.packaging_col_type') }}</flux:table.column>
                <flux:table.column>{{ __('setup.packaging_col_dimensions') }}</flux:table.column>
                <flux:table.column>{{ __('setup.packaging_col_weight') }}</flux:table.column>
                <flux:table.column>{{ __('setup.packaging_col_cost') }}</flux:table.column>
                <flux:table.column>{{ __('setup.packaging_col_status') }}</flux:table.column>
                <flux:table.column>{{ __('setup.tenant_col_actions') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($items as $item)
                    <flux:table.row :key="$item->id">
                        <flux:table.cell>
                            <strong>{{ $item->code }}</strong>
                        </flux:table.cell>
                        <flux:table.cell>{{ $item->name }}</flux:table.cell>
                        <flux:table.cell>{{ $this->typeLabel($item->type) }}</flux:table.cell>
                        <flux:table.cell>
                            @if ($item->length_value || $item->width_value || $item->height_value)
                                <span class="subtle">
                                    {{ number_format($item->length_value, 0) }}
                                    x {{ number_format($item->width_value, 0) }}
                                    x {{ number_format($item->height_value, 0) }}
                                    {{ $item->dimension_unit }}
                                </span>
                            @else
                                <span class="subtle">-</span>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>
                            @if ($item->weight_value)
                                <span class="subtle">{{ number_format($item->weight_value, 0) }} {{ $item->weight_unit }}</span>
                            @else
                                <span class="subtle">-</span>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>
                            @if ($item->cost)
                                <span class="subtle">{{ number_format($item->cost, 0) }} {{ $item->currency }}</span>
                            @else
                                <span class="subtle">-</span>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:badge color="{{ $this->statusColor($item->status) }}">
                                {{ $this->statusLabel($item->status) }}
                            </flux:badge>
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:button
                                href="{{ route('setup.packagings.edit', $item) }}"
                                variant="primary"
                            >
                                {{ __('setup.btn_edit') }}
                            </flux:button>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="8">
                            <div class="empty-state">{{ __('setup.packaging_empty_state') }}</div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </section>

    <style>
        .packaging-toolbar {
            grid-template-columns: 132px 132px minmax(260px, 1fr) auto;
        }

        .packaging-create-action {
            justify-self: end;
            align-self: end;
        }

        @media (max-width: 860px) {
            .packaging-toolbar {
                grid-template-columns: 1fr;
            }

            .packaging-create-action {
                justify-self: start;
            }
        }
    </style>
</div>
