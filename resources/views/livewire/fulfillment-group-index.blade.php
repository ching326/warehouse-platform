<div class="fulfillment-group-index-page">
    @if (session('status'))
        <div class="active-filter-row">
            <flux:badge color="green">{{ session('status') }}</flux:badge>
        </div>
    @endif

    <section class="table-shell flux-panel">
        <div class="movement-toolbar">
            @if ($showTenantFilter)
                <flux:select wire:model.live="tenantId" :label="__('fulfillment_groups.field_tenant')">
                    <flux:select.option value="">{{ __('common.all_tenants') }}</flux:select.option>
                    @foreach ($tenants as $tenant)
                        <flux:select.option value="{{ $tenant->id }}">{{ $tenant->code }} - {{ $tenant->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endif

            <flux:select wire:model.live="statusFilter" :label="__('fulfillment_groups.col_status')">
                <flux:select.option value="">{{ __('fulfillment_groups.all_statuses') }}</flux:select.option>
                @foreach ($statuses as $status => $label)
                    <flux:select.option value="{{ $status }}">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:input
                wire:model.live.debounce.300ms="search"
                :label="__('common.search')"
                :placeholder="__('fulfillment_groups.search_placeholder')"
            />

            <flux:button href="{{ route('fulfillment-groups.create') }}" variant="primary" wire:navigate>
                {{ __('fulfillment_groups.btn_create') }}
            </flux:button>
        </div>

        <flux:table :paginate="$groups" class="data-table">
            <flux:table.columns>
                <flux:table.column>{{ __('fulfillment_groups.col_reference_no') }}</flux:table.column>
                <flux:table.column>{{ __('fulfillment_groups.col_tenant_warehouse') }}</flux:table.column>
                <flux:table.column>{{ __('fulfillment_groups.col_recipient') }}</flux:table.column>
                <flux:table.column align="end">{{ __('fulfillment_groups.col_orders') }}</flux:table.column>
                <flux:table.column>{{ __('fulfillment_groups.col_status') }}</flux:table.column>
                <flux:table.column>{{ __('fulfillment_groups.col_shipped_at') }}</flux:table.column>
                <flux:table.column>{{ __('fulfillment_groups.col_actions') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($groups as $group)
                    <flux:table.row :key="$group->id">
                        <flux:table.cell>
                            <strong>{{ $group->reference_no }}</strong>
                            <span class="subtle">#{{ $group->id }}</span>
                        </flux:table.cell>
                        <flux:table.cell>
                            <strong>{{ $group->tenant->code }}</strong>
                            <span class="subtle">{{ $group->warehouse->code }}</span>
                        </flux:table.cell>
                        <flux:table.cell>
                            <strong>{{ $group->recipient_name ?: '-' }}</strong>
                            <span class="subtle">{{ $group->recipient_city ?: $group->recipient_postal_code ?: '-' }}</span>
                        </flux:table.cell>
                        <flux:table.cell align="end">{{ number_format($group->orders_count) }}</flux:table.cell>
                        <flux:table.cell>
                            <flux:badge color="{{ $this->statusColor($group->status) }}">
                                {{ $this->statusLabel($group->status) }}
                            </flux:badge>
                        </flux:table.cell>
                        <flux:table.cell>{{ $group->shipped_at ? $group->shipped_at->format('Y-m-d H:i') : '-' }}</flux:table.cell>
                        <flux:table.cell>
                            <flux:button href="{{ route('fulfillment-groups.show', $group) }}" size="xs" variant="outline" wire:navigate>
                                {{ __('fulfillment_groups.btn_view') }}
                            </flux:button>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="7">
                            <div class="empty-state">{{ __('fulfillment_groups.empty_state') }}</div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </section>
</div>
