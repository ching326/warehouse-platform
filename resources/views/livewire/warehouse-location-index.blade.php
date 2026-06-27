<div class="warehouse-location-index-page">
    <x-flash-toast />

<section class="table-shell flux-panel">
        <div class="movement-toolbar">
            <flux:select wire:model.live="warehouseId" :label="__('locations.field_warehouse')">
                <flux:select.option value="">{{ __('locations.all_warehouses') }}</flux:select.option>
                @foreach ($warehouses as $warehouse)
                    <flux:select.option value="{{ $warehouse->id }}">{{ $warehouse->code }} - {{ $warehouse->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="zoneTypeFilter" :label="__('locations.field_zone_type')">
                <flux:select.option value="">{{ __('locations.all_zone_types') }}</flux:select.option>
                @foreach ($zoneTypes as $value => $label)
                    <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="storageUnitTypeFilter" :label="__('locations.field_storage_unit_type')">
                <flux:select.option value="">{{ __('locations.all_storage_unit_types') }}</flux:select.option>
                @foreach ($storageUnitTypes as $value => $label)
                    <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="statusFilter" :label="__('locations.field_status')">
                <flux:select.option value="">{{ __('locations.all_statuses') }}</flux:select.option>
                @foreach ($statuses as $value => $label)
                    <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:input
                wire:model.live.debounce.300ms="search"
                :label="__('locations.search_label')"
                :placeholder="__('locations.search_placeholder')"
            />

            <flux:button href="{{ route('setup.locations.create') }}" variant="primary">
                {{ __('locations.btn_create') }}
            </flux:button>
        </div>

        <flux:table :paginate="$locations" class="data-table">
            <flux:table.columns>
                <flux:table.column>{{ __('locations.col_warehouse') }}</flux:table.column>
                <flux:table.column>{{ __('locations.col_code') }}</flux:table.column>
                <flux:table.column>{{ __('locations.col_name') }}</flux:table.column>
                <flux:table.column>{{ __('locations.col_zone_type') }}</flux:table.column>
                <flux:table.column>{{ __('locations.col_storage_unit_type') }}</flux:table.column>
                <flux:table.column>{{ __('locations.col_status') }}</flux:table.column>
                <flux:table.column>{{ __('locations.col_note') }}</flux:table.column>
                <flux:table.column>{{ __('locations.col_actions') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($locations as $location)
                    <flux:table.row :key="$location->id">
                        <flux:table.cell>
                            <strong>{{ $location->warehouse->code }}</strong>
                            <span class="subtle">{{ $location->warehouse->name }}</span>
                        </flux:table.cell>
                        <flux:table.cell>
                            <strong>{{ $location->code }}</strong>
                        </flux:table.cell>
                        <flux:table.cell>{{ $location->name ?: '-' }}</flux:table.cell>
                        <flux:table.cell>{{ $this->zoneTypeLabel($location->zone_type) }}</flux:table.cell>
                        <flux:table.cell>{{ $this->storageUnitTypeLabel($location->storage_unit_type) }}</flux:table.cell>
                        <flux:table.cell>
                            <flux:badge color="{{ $this->statusColor($location->status) }}">
                                {{ $this->statusLabel($location->status) }}
                            </flux:badge>
                        </flux:table.cell>
                        <flux:table.cell>
                            <span class="subtle">{{ $location->note ?: __('common.no_note') }}</span>
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:button
                                href="{{ route('setup.locations.edit', $location) }}"
                                variant="primary"
                            >
                                {{ __('setup.btn_edit') }}
                            </flux:button>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="8">
                            <div class="empty-state">{{ __('locations.empty_state') }}</div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </section>
</div>
