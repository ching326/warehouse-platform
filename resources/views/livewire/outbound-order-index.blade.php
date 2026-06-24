<div class="outbound-index-page">
    <x-flash-toast />
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

        <flux:table :paginate="$orders" class="data-table">
            <flux:table.columns>
                <flux:table.column>{{ __('outbound.col_ref') }}</flux:table.column>
                <flux:table.column>{{ __('outbound.col_tenant_warehouse') }}</flux:table.column>
                <flux:table.column>{{ __('outbound.col_reason') }}</flux:table.column>
                <flux:table.column>{{ __('outbound.col_shipped_at') }}</flux:table.column>
                <flux:table.column>{{ __('outbound.col_lines') }}</flux:table.column>
                <flux:table.column>{{ __('outbound.col_status') }}</flux:table.column>
                <flux:table.column>{{ __('outbound.col_actions') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($orders as $order)
                    <flux:table.row :key="$order->id">
                        @php($shop = $order->fulfillmentGroup?->orders->first()?->shop)
                        <flux:table.cell>
                            <a class="outbound-order-number-link" href="{{ route('outbound.show', $order) }}" wire:navigate>
                                {{ $order->ref ?: '-' }}
                            </a>
                        </flux:table.cell>
                        <flux:table.cell>
                            <span class="outbound-inline-pair">
                                <strong>{{ $order->tenant->code }}</strong>
                                <span class="outbound-inline-muted">{{ $shop ? $shop->code.' - '.$shop->name : '-' }}</span>
                            </span>
                        </flux:table.cell>
                        <flux:table.cell>
                            <span class="outbound-inline-pair">
                                <strong>{{ $order->reasonLabel() ?? '-' }}</strong>
                                @if ($order->shipModeLabel())
                                    <span class="outbound-inline-muted">{{ $order->shipModeLabel() }}</span>
                                @endif
                            </span>
                        </flux:table.cell>
                        <flux:table.cell>{{ $order->shipped_at ? $order->shipped_at->format('Y-m-d H:i') : '-' }}</flux:table.cell>
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
                                <div class="outbound-row-actions">
                                    <flux:button href="{{ route('outbound.ship', $order) }}" variant="primary" wire:navigate>
                                        {{ __('outbound.btn_ship') }}
                                    </flux:button>
                                </div>
                            @else
                                <span class="muted-dash">-</span>
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="7">
                            <div class="empty-state">{{ __('outbound.empty_state') }}</div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </section>

    <style>
        .outbound-row-actions {
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .outbound-row-actions > * {
            width: 92px;
        }

        .outbound-row-actions button,
        .outbound-row-actions a {
            height: 26px !important;
            min-height: 26px !important;
            padding-top: 2px !important;
            padding-bottom: 2px !important;
            font-size: 12px;
            line-height: 1;
            justify-content: center;
        }

        .outbound-inline-pair {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            max-width: 100%;
            min-width: 0;
            white-space: nowrap;
        }

        .outbound-order-number-link {
            display: inline-flex;
            align-items: center;
            max-width: 100%;
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            color: #2563eb;
            font-size: 13px;
            font-weight: 700;
            line-height: 1.2;
            text-decoration: none;
        }

        .outbound-order-number-link:hover {
            color: #1d4ed8;
            text-decoration: none;
        }

        .outbound-inline-muted {
            display: inline-flex;
            align-items: center;
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            color: var(--muted);
            font-size: 12px;
            font-weight: 600;
            line-height: 1.2;
        }

        @media (max-width: 760px) {
            .outbound-row-actions {
                flex-wrap: wrap;
            }
        }
    </style>
</div>
