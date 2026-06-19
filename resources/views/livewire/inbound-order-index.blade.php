<div class="inbound-index-page">
    @if (session('status'))
        <div class="active-filter-row">
            <flux:badge color="green">{{ session('status') }}</flux:badge>
        </div>
    @endif

    <section class="table-shell flux-panel">
        <div class="movement-toolbar">
            @if ($showTenantFilter)
                <flux:select wire:model.live="tenantId" :label="__('inbound.field_tenant')">
                    <flux:select.option value="">{{ __('common.all_tenants') }}</flux:select.option>
                    @foreach ($tenants as $tenant)
                        <flux:select.option value="{{ $tenant->id }}">{{ $tenant->code }} - {{ $tenant->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endif

            <flux:select wire:model.live="warehouseId" :label="__('inbound.field_warehouse')">
                <flux:select.option value="">{{ __('common.all_warehouses') }}</flux:select.option>
                @foreach ($warehouses as $warehouse)
                    <flux:select.option value="{{ $warehouse->id }}">{{ $warehouse->code }} - {{ $warehouse->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="status" :label="__('inbound.col_status')">
                <flux:select.option value="">{{ __('inbound.all_statuses') }}</flux:select.option>
                @foreach ($statuses as $statusOption)
                    <flux:select.option value="{{ $statusOption }}">{{ $this->statusLabel($statusOption) }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:button href="{{ route('inbound.create') }}" variant="primary">
                {{ __('inbound.btn_create') }}
            </flux:button>
        </div>

        <flux:table :paginate="$orders" class="data-table">
            <flux:table.columns>
                <flux:table.column>{{ __('inbound.col_ref') }}</flux:table.column>
                <flux:table.column>{{ __('inbound.col_tenant_warehouse') }}</flux:table.column>
                <flux:table.column>{{ __('inbound.col_expected_at') }}</flux:table.column>
                <flux:table.column align="end">{{ __('inbound.col_lines') }}</flux:table.column>
                <flux:table.column>{{ __('inbound.col_status') }}</flux:table.column>
                <flux:table.column>{{ __('inbound.col_actions') }}</flux:table.column>
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
                        <flux:table.cell>{{ $order->expected_at ? $order->expected_at->format('Y-m-d') : '-' }}</flux:table.cell>
                        <flux:table.cell align="end">{{ number_format($order->lines_count) }}</flux:table.cell>
                        <flux:table.cell>
                            <flux:badge color="{{ $this->statusColor($order->status) }}">
                                {{ $this->statusLabel($order->status) }}
                            </flux:badge>
                        </flux:table.cell>
                        <flux:table.cell>
                            @if ($order->status === 'pending')
                                <flux:button
                                    type="button"
                                    size="xs"
                                    variant="primary"
                                    wire:click="markArrived({{ $order->id }})"
                                    wire:confirm="{{ __('inbound.confirm_arrive') }}"
                                >
                                    {{ __('inbound.btn_mark_arrived') }}
                                </flux:button>
                                <flux:button
                                    type="button"
                                    size="xs"
                                    variant="danger"
                                    wire:click="cancel({{ $order->id }})"
                                    wire:confirm="{{ __('inbound.confirm_cancel') }}"
                                >
                                    {{ __('inbound.btn_cancel_order') }}
                                </flux:button>
                            @elseif (in_array($order->status, ['arrived', 'partially_received'], true))
                                <flux:button href="{{ route('inbound.receive', $order) }}" variant="primary">
                                    {{ __('inbound.btn_receive') }}
                                </flux:button>
                            @else
                                <span class="muted-dash">-</span>
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="6">
                            <div class="empty-state">{{ __('inbound.empty_state') }}</div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </section>
</div>
