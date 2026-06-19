<div class="shop-index-page">
    @if (session('status'))
        <div class="active-filter-row">
            <flux:badge color="green">{{ session('status') }}</flux:badge>
        </div>
    @endif

    <section class="table-shell flux-panel">
        <div class="movement-toolbar">
            <flux:select wire:model.live="tenantId" :label="__('shop.field_tenant')">
                <flux:select.option value="">{{ __('shop.all_tenants') }}</flux:select.option>
                @foreach ($tenants as $tenant)
                    <flux:select.option value="{{ $tenant->id }}">{{ $tenant->code }} - {{ $tenant->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="platformFilter" :label="__('shop.field_platform')">
                <flux:select.option value="">{{ __('shop.all_platforms') }}</flux:select.option>
                @foreach ($platforms as $value => $label)
                    <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="statusFilter" :label="__('shop.field_status')">
                <flux:select.option value="">{{ __('shop.all_statuses') }}</flux:select.option>
                @foreach ($statuses as $value => $label)
                    <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:input
                wire:model.live.debounce.300ms="search"
                :label="__('setup.search_label')"
                :placeholder="__('shop.search_placeholder')"
            />

            <flux:button href="{{ route('setup.shops.create') }}" variant="primary">
                {{ __('shop.btn_create_shop') }}
            </flux:button>
        </div>

        <flux:table :paginate="$shops" class="data-table">
            <flux:table.columns>
                <flux:table.column>{{ __('shop.col_tenant') }}</flux:table.column>
                <flux:table.column>{{ __('shop.col_code') }}</flux:table.column>
                <flux:table.column>{{ __('shop.col_name') }}</flux:table.column>
                <flux:table.column>{{ __('shop.col_platform') }}</flux:table.column>
                <flux:table.column>{{ __('shop.col_marketplace') }}</flux:table.column>
                <flux:table.column>{{ __('shop.col_contact') }}</flux:table.column>
                <flux:table.column>{{ __('shop.col_status') }}</flux:table.column>
                <flux:table.column>{{ __('shop.col_actions') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($shops as $shop)
                    <flux:table.row :key="$shop->id">
                        <flux:table.cell>
                            <strong>{{ $shop->tenant->code }}</strong>
                            <span class="subtle">{{ $shop->tenant->name }}</span>
                        </flux:table.cell>
                        <flux:table.cell>
                            <strong>{{ $shop->code }}</strong>
                        </flux:table.cell>
                        <flux:table.cell>{{ $shop->name }}</flux:table.cell>
                        <flux:table.cell>{{ $this->platformLabel($shop->platform) }}</flux:table.cell>
                        <flux:table.cell>{{ $shop->marketplace !== '' ? $shop->marketplace : '-' }}</flux:table.cell>
                        <flux:table.cell>
                            <strong>{{ $shop->contact_name ?: '-' }}</strong>
                            <span class="subtle">{{ $shop->contact_email ?: '-' }}</span>
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:badge color="{{ $this->statusColor($shop->status) }}">
                                {{ $this->statusLabel($shop->status) }}
                            </flux:badge>
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:button
                                href="{{ route('setup.shops.edit', $shop) }}"
                                variant="primary"
                            >
                                {{ __('setup.btn_edit') }}
                            </flux:button>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="8">
                            <div class="empty-state">{{ __('shop.empty_state') }}</div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </section>
</div>
