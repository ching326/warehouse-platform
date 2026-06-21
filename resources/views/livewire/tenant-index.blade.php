<div class="tenant-index-page">
    <x-flash-toast />

<section class="table-shell flux-panel">
        <div class="movement-toolbar tenant-toolbar">
            <flux:select wire:model.live="statusFilter" :label="__('setup.field_status')">
                <flux:select.option value="">{{ __('setup.all_statuses') }}</flux:select.option>
                @foreach ($statuses as $value => $label)
                    <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:input
                wire:model.live.debounce.300ms="search"
                :label="__('setup.search_label')"
                :placeholder="__('setup.search_tenants_placeholder')"
            />

            <div class="tenant-create-action">
                <flux:button href="{{ route('setup.tenants.create') }}" variant="primary">
                    {{ __('setup.btn_create_tenant') }}
                </flux:button>
            </div>
        </div>

        <flux:table :paginate="$tenants" class="data-table">
            <flux:table.columns>
                <flux:table.column>{{ __('setup.tenant_col_code') }}</flux:table.column>
                <flux:table.column>{{ __('setup.tenant_col_name') }}</flux:table.column>
                <flux:table.column>{{ __('setup.tenant_col_contact') }}</flux:table.column>
                <flux:table.column>{{ __('setup.tenant_col_billing') }}</flux:table.column>
                <flux:table.column>{{ __('setup.tenant_col_status') }}</flux:table.column>
                <flux:table.column>{{ __('setup.tenant_col_actions') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($tenants as $tenant)
                    <flux:table.row :key="$tenant->id">
                        <flux:table.cell>
                            <strong>{{ $tenant->code }}</strong>
                        </flux:table.cell>
                        <flux:table.cell>{{ $tenant->name }}</flux:table.cell>
                        <flux:table.cell>
                            <strong>{{ $tenant->contact_name ?: '-' }}</strong>
                            <span class="subtle">{{ $tenant->contact_email ?: '-' }}</span>
                        </flux:table.cell>
                        <flux:table.cell>{{ $tenant->billing_terms ?: '-' }}</flux:table.cell>
                        <flux:table.cell>
                            <flux:badge color="{{ $this->statusColor($tenant->status) }}">
                                {{ $this->statusLabel($tenant->status) }}
                            </flux:badge>
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:button
                                href="{{ route('setup.tenants.edit', $tenant) }}"
                                variant="primary"
                            >
                                {{ __('setup.btn_edit') }}
                            </flux:button>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="6">
                            <div class="empty-state">{{ __('setup.tenant_empty_state') }}</div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </section>

    <style>
        .tenant-toolbar {
            grid-template-columns: 132px minmax(260px, 1fr) auto;
        }

        .tenant-create-action {
            justify-self: end;
            align-self: end;
        }

        @media (max-width: 760px) {
            .tenant-toolbar {
                grid-template-columns: 1fr;
            }

            .tenant-create-action {
                justify-self: start;
            }
        }
    </style>
</div>
