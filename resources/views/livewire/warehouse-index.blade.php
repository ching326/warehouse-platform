<div class="warehouse-index-page">
    @if (session('status'))
        <div class="active-filter-row">
            <flux:badge color="green">{{ session('status') }}</flux:badge>
        </div>
    @endif

    <section class="table-shell flux-panel">
        <div class="movement-toolbar">
            <flux:select wire:model.live="statusFilter" :label="__('setup.field_status')">
                <flux:select.option value="">{{ __('setup.all_statuses') }}</flux:select.option>
                @foreach ($statuses as $value => $label)
                    <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:input
                wire:model.live.debounce.300ms="search"
                :label="__('setup.search_label')"
                :placeholder="__('setup.search_warehouses_placeholder')"
            />

            <flux:button href="{{ route('setup.warehouses.create') }}" variant="primary">
                {{ __('setup.btn_create_warehouse') }}
            </flux:button>
        </div>

        <flux:table :paginate="$warehouses" class="movement-table">
            <flux:table.columns>
                <flux:table.column>{{ __('setup.warehouse_col_code') }}</flux:table.column>
                <flux:table.column>{{ __('setup.warehouse_col_name') }}</flux:table.column>
                <flux:table.column>{{ __('setup.warehouse_col_location') }}</flux:table.column>
                <flux:table.column>{{ __('setup.warehouse_col_timezone') }}</flux:table.column>
                <flux:table.column>{{ __('setup.warehouse_col_status') }}</flux:table.column>
                <flux:table.column>{{ __('setup.warehouse_col_actions') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($warehouses as $warehouse)
                    <flux:table.row :key="$warehouse->id">
                        <flux:table.cell>
                            <strong>{{ $warehouse->code }}</strong>
                        </flux:table.cell>
                        <flux:table.cell>{{ $warehouse->name }}</flux:table.cell>
                        <flux:table.cell>
                            <strong>{{ $warehouse->city ?: '-' }}</strong>
                            <span class="subtle">{{ $warehouse->country_code }}</span>
                        </flux:table.cell>
                        <flux:table.cell>{{ $warehouse->timezone }}</flux:table.cell>
                        <flux:table.cell>
                            <flux:badge color="{{ $this->statusColor($warehouse->status) }}">
                                {{ $this->statusLabel($warehouse->status) }}
                            </flux:badge>
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:button
                                href="{{ route('setup.warehouses.edit', $warehouse) }}"
                                variant="primary"
                            >
                                {{ __('setup.btn_edit') }}
                            </flux:button>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="6">
                            <div class="empty-state">{{ __('setup.warehouse_empty_state') }}</div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </section>
</div>
