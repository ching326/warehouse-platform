<div class="outbound-index-page">
    @if (session('status'))
        <div class="active-filter-row">
            <flux:badge color="green">{{ session('status') }}</flux:badge>
        </div>
    @endif

    @if (session('error'))
        <div class="active-filter-row">
            <flux:badge color="red">{{ session('error') }}</flux:badge>
        </div>
    @endif

    <section class="table-shell flux-panel">
        <div class="movement-toolbar">
            <flux:select wire:model.live="tenantId" :label="__('outbound.field_tenant')">
                <flux:select.option value="">{{ __('common.all_tenants') }}</flux:select.option>
                @foreach ($tenants as $tenant)
                    <flux:select.option value="{{ $tenant->id }}">{{ $tenant->code }} - {{ $tenant->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="warehouseId" :label="__('outbound.field_warehouse')">
                <flux:select.option value="">{{ __('common.all_warehouses') }}</flux:select.option>
                @foreach ($warehouses as $warehouse)
                    <flux:select.option value="{{ $warehouse->id }}">{{ $warehouse->code }} - {{ $warehouse->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="statusFilter" :label="__('outbound.col_status')">
                <flux:select.option value="">{{ __('outbound.all_statuses') }}</flux:select.option>
                @foreach ($statuses as $status => $label)
                    <flux:select.option value="{{ $status }}">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:button href="{{ route('outbound.create') }}" variant="primary" wire:navigate>
                {{ __('outbound.btn_create') }}
            </flux:button>
        </div>

        <flux:table :paginate="$orders" class="movement-table">
            <flux:table.columns>
                <flux:table.column>{{ __('outbound.col_ref') }}</flux:table.column>
                <flux:table.column>{{ __('outbound.col_tenant_warehouse') }}</flux:table.column>
                <flux:table.column>{{ __('outbound.col_expected_ship_at') }}</flux:table.column>
                <flux:table.column>{{ __('outbound.col_lines') }}</flux:table.column>
                <flux:table.column>{{ __('outbound.col_status') }}</flux:table.column>
                <flux:table.column>{{ __('outbound.col_actions') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($orders as $order)
                    <flux:table.row :key="$order->id">
                        <flux:table.cell>
                            <strong>{{ $order->ref ?: '-' }}</strong>
                            <span class="subtle">#{{ $order->id }}</span>
                        </flux:table.cell>
                        <flux:table.cell>
                            <strong>{{ $order->tenant->code }}</strong>
                            <span class="subtle">{{ $order->warehouse->code }}</span>
                        </flux:table.cell>
                        <flux:table.cell>{{ $order->expected_ship_at ? $order->expected_ship_at->format('Y-m-d') : '-' }}</flux:table.cell>
                        <flux:table.cell>
                            @foreach ($order->parentLines as $line)
                                <span class="subtle">{{ $line->sku->sku }} x{{ number_format($line->qty) }}</span>
                            @endforeach
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:badge color="{{ $this->statusColor($order->status) }}">
                                {{ $this->statusLabel($order->status) }}
                            </flux:badge>
                        </flux:table.cell>
                        <flux:table.cell>
                            @if ($order->status === 'pending')
                                <flux:button href="{{ route('outbound.ship', $order) }}" size="xs" variant="outline" wire:navigate>
                                    {{ __('outbound.btn_ship') }}
                                </flux:button>
                                <flux:button
                                    type="button"
                                    size="xs"
                                    variant="danger"
                                    wire:click="cancel({{ $order->id }})"
                                    wire:confirm="{{ __('outbound.confirm_cancel') }}"
                                >
                                    {{ __('outbound.btn_cancel_order') }}
                                </flux:button>
                            @else
                                <span class="muted-dash">-</span>
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="6">
                            <div class="empty-state">{{ __('outbound.empty_state') }}</div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </section>
</div>
