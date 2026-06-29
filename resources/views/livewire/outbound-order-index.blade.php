<div class="outbound-index-page">
    <x-flash-toast />
    <x-page-panel-header
        :title="__('outbound.page_title')"
        :subtitle="__('outbound.page_subtitle')"
    />

    <section class="table-shell flux-panel">
        <div class="movement-toolbar outbound-index-toolbar">
            <flux:select wire:model.live="tenantId" :label="__('outbound.field_tenant')">
                <flux:select.option value="">{{ __('common.all_tenants') }}</flux:select.option>
                @foreach ($tenants as $tenant)
                    <flux:select.option value="{{ $tenant->id }}">{{ $tenant->code }} - {{ $tenant->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="shopId" :label="__('skus.field_shop')">
                <flux:select.option value="">{{ __('common.all_shops') }}</flux:select.option>
                @foreach ($shops as $shop)
                    <flux:select.option value="{{ $shop->id }}">{{ $shop->code }} - {{ $shop->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="warehouseId" :label="__('outbound.field_warehouse')">
                <flux:select.option value="">{{ __('common.all_warehouses') }}</flux:select.option>
                @foreach ($warehouses as $warehouse)
                    <flux:select.option value="{{ $warehouse->id }}">{{ $warehouse->code }} - {{ $warehouse->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="reasonFilter" :label="__('outbound.field_reason')">
                <flux:select.option value="">{{ __('outbound.all_reasons') }}</flux:select.option>
                @foreach ($reasons as $reason => $label)
                    <flux:select.option value="{{ $reason }}">{{ $label }}</flux:select.option>
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
                        @php
                            $shops = $order->salesOrders
                                ->map(fn ($salesOrder) => $salesOrder->shop)
                                ->merge($order->parentLines->map(fn ($line) => $line->sku?->shop))
                                ->filter()
                                ->unique('id')
                                ->values();
                            $shopLabel = match (true) {
                                $shops->isEmpty() => '-',
                                $shops->count() === 1 => $shops->first()->code.' - '.$shops->first()->name,
                                default => $shops->first()->code.' +'.($shops->count() - 1),
                            };
                        @endphp
                        <flux:table.cell>
                            <x-record-ref-link
                                :href="route('outbound.show', $order)"
                                :value="$order->ref"
                            />
                        </flux:table.cell>
                        <flux:table.cell>
                            <span class="outbound-inline-pair">
                                <strong>{{ $order->tenant->code }}</strong>
                                <span class="outbound-inline-muted">{{ $shopLabel }}</span>
                            </span>
                        </flux:table.cell>
                        <flux:table.cell>
                            <strong>{{ $order->reasonLabel() ?? '-' }}</strong>
                        </flux:table.cell>
                        <flux:table.cell>{{ $order->shipped_at ? $order->shipped_at->format('Y-m-d H:i') : '-' }}</flux:table.cell>
                        <flux:table.cell>
                            @foreach ($order->parentLines as $line)
                                <span class="subtle">{{ number_format($line->qty) }} x {{ $line->sku->sku }}</span>
                            @endforeach
                        </flux:table.cell>
                        <flux:table.cell>
                            <div class="record-status-stack">
                                <x-status-badge :status="$order->status" :label="$this->statusLabel($order->status)" />
                                @if ($order->hold_status === \App\Models\OutboundOrder::HOLD_STATUS_ON_HOLD)
                                    <x-status-badge :status="$order->hold_status" :label="__('outbound.on_hold')" />
                                @endif
                            </div>
                        </flux:table.cell>
                        <flux:table.cell>
                            @if ($order->hold_status === \App\Models\OutboundOrder::HOLD_STATUS_ON_HOLD)
                                <div class="outbound-row-actions">
                                    <flux:button class="action-button-md" type="button" size="sm" variant="primary" wire:click="releaseHold({{ $order->id }})">
                                        {{ __('outbound.release_hold') }}
                                    </flux:button>
                                </div>
                            @elseif ($order->status === \App\Models\OutboundOrder::STATUS_RESERVED)
                                <div class="outbound-row-actions">
                                    <flux:button class="action-button-md" href="{{ route('outbound.ship', $order) }}" size="sm" variant="primary" wire:navigate>
                                        {{ __('outbound.btn_direct_pack') }}
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

        .outbound-index-toolbar {
            grid-template-columns: repeat(5, minmax(130px, 1fr)) max-content;
        }

        .outbound-index-toolbar > :last-child {
            justify-self: end;
        }

        .outbound-inline-pair {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            max-width: 100%;
            min-width: 0;
            white-space: nowrap;
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
            .outbound-index-toolbar {
                grid-template-columns: 1fr;
            }

            .outbound-index-toolbar > :last-child {
                justify-self: stretch;
            }

            .outbound-row-actions {
                flex-wrap: wrap;
            }
        }
    </style>
</div>
