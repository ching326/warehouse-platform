<div class="inbound-index-page">
    <x-page-panel-header
        :title="__('inbound.page_title')"
        :subtitle="__('inbound.page_subtitle')"
        :show-nav="false"
    />

    <section class="table-shell flux-panel">
        <x-flash-toast />

        <div class="movement-toolbar">
            @if ($showTenantFilter)
                <flux:select wire:model.live="tenantId" :label="__('inbound.field_tenant')">
                    <flux:select.option value="">{{ __('common.all_tenants') }}</flux:select.option>
                    @foreach ($tenants as $tenant)
                        <flux:select.option value="{{ $tenant->id }}">{{ $tenant->code }} - {{ $tenant->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endif

            <flux:select wire:model.live="shopId" :label="__('skus.field_shop')">
                <flux:select.option value="">{{ __('common.all_shops') }}</flux:select.option>
                @foreach ($shops as $shop)
                    <flux:select.option value="{{ $shop->id }}">{{ $shop->code }} - {{ $shop->name }}</flux:select.option>
                @endforeach
            </flux:select>

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

            <x-rows-per-page-select :options="$perPageOptions" />

            <flux:button href="{{ route('inbound.create') }}" variant="primary">
                {{ __('inbound.btn_create') }}
            </flux:button>
        </div>

        <flux:table :paginate="$orders" class="data-table">
            <flux:table.columns>
                <flux:table.column>{{ __('inbound.col_ref') }}</flux:table.column>
                <flux:table.column>{{ __('inbound.col_tenant_warehouse') }}</flux:table.column>
                <flux:table.column>{{ __('inbound.col_expected_at') }}</flux:table.column>
                <flux:table.column>{{ __('inbound.col_cartons') }}</flux:table.column>
                <flux:table.column align="center" class="inbound-lines-cell">{{ __('inbound.col_lines') }}</flux:table.column>
                <flux:table.column>{{ __('inbound.col_status') }}</flux:table.column>
                <flux:table.column>{{ __('inbound.col_actions') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($orders as $order)
                    <flux:table.row :key="$order->id">
                        @php
                            $shops = $order->lines
                                ->map(fn ($line) => $line->sku?->shop)
                                ->filter()
                                ->unique('id')
                                ->values();
                            $shopLabel = match (true) {
                                $shops->isEmpty() => '-',
                                $shops->count() === 1 => $shops->first()->code.' - '.$shops->first()->name,
                                default => $shops->first()->code.' +'.($shops->count() - 1),
                            };
                            $cartonsLabel = $order->expected_carton_count !== null || $order->received_carton_count !== null
                                ? number_format((int) ($order->received_carton_count ?? 0)).' / '.($order->expected_carton_count !== null ? number_format((int) $order->expected_carton_count) : '-')
                                : '-';
                        @endphp
                        <flux:table.cell>
                            <x-record-ref-link
                                :href="route('inbound.show', $order)"
                                :value="$order->ref"
                            />
                        </flux:table.cell>
                        <flux:table.cell>
                            <span class="inbound-inline-pair">
                                <strong>{{ $order->tenant->code }}</strong>
                                <span class="inbound-inline-muted">{{ $shopLabel }}</span>
                            </span>
                        </flux:table.cell>
                        <flux:table.cell>{{ $order->expected_at ? $order->expected_at->format('Y-m-d') : '-' }}</flux:table.cell>
                        <flux:table.cell>{{ $cartonsLabel }}</flux:table.cell>
                        <flux:table.cell align="center" class="inbound-lines-cell">{{ number_format($order->lines_count) }}</flux:table.cell>
                        <flux:table.cell>
                            <x-status-badge :status="$order->status" :label="$this->statusLabel($order->status)" />
                        </flux:table.cell>
                        <flux:table.cell>
                            @if ($order->status === 'pending')
                                <div class="standard-row-actions inbound-row-actions">
                                    <flux:button
                                        class="action-button-md inbound-row-action-button"
                                        type="button"
                                        size="sm"
                                        variant="primary"
                                        wire:click="markArrived({{ $order->id }})"
                                        wire:confirm="{{ __('inbound.confirm_arrive') }}"
                                    >
                                        {{ __('inbound.btn_mark_arrived') }}
                                    </flux:button>
                                </div>
                            @elseif (in_array($order->status, ['arrived', 'partially_received'], true))
                                <div class="standard-row-actions inbound-row-actions">
                                    <flux:button class="action-button-md inbound-row-action-button" href="{{ route('inbound.receive', $order) }}" size="sm" variant="primary">
                                        {{ __('inbound.btn_receive') }}
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
                            <div class="empty-state">{{ __('inbound.empty_state') }}</div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </section>

    <style>
        .inbound-lines-cell {
            width: 96px;
            text-align: center;
        }

        .inbound-lines-cell > div {
            justify-content: center;
        }

        .inbound-inline-pair {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            max-width: 100%;
            min-width: 0;
            white-space: nowrap;
        }

        .inbound-inline-muted {
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
            .inbound-row-actions {
                flex-wrap: wrap;
            }
        }
    </style>
</div>
