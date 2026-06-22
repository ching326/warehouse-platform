<div class="fulfillment-group-index-page">
    <x-flash-toast />

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

            <flux:select wire:model.live="warehouseId" :label="__('fulfillment_groups.field_warehouse')">
                <flux:select.option value="">{{ __('fulfillment_groups.all_warehouses') }}</flux:select.option>
                @foreach ($warehouses as $warehouse)
                    <flux:select.option value="{{ $warehouse->id }}">{{ $warehouse->code }} - {{ $warehouse->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="statusFilter" :label="__('fulfillment_groups.col_status')">
                <flux:select.option value="">{{ __('fulfillment_groups.all_statuses') }}</flux:select.option>
                @foreach ($statuses as $status => $label)
                    <flux:select.option value="{{ $status }}">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>

            <label class="fg-toggle">
                <input type="checkbox" wire:model.live="printWaiting" />
                {{ __('fulfillment_groups.filter_print_waiting') }}
            </label>

            <flux:input
                wire:model.live.debounce.300ms="search"
                :label="__('common.search')"
                :placeholder="__('fulfillment_groups.search_placeholder')"
            />

            <flux:button href="{{ route('fulfillment-groups.create') }}" variant="primary" wire:navigate>
                {{ __('fulfillment_groups.btn_create') }}
            </flux:button>
        </div>

        @if (count($selectedIds) > 0)
            <div class="fg-batchbar">
                <span class="fg-batchbar-count">
                    {{ __('fulfillment_groups.selected_count', ['count' => count($selectedIds)]) }}
                </span>
                <span class="fg-batchbar-spacer"></span>
                <flux:button size="sm" variant="outline" disabled :title="__('fulfillment_groups.batch_pending_hint')">
                    {{ __('fulfillment_groups.batch_export_yamato') }}
                </flux:button>
                <flux:button size="sm" variant="outline" disabled :title="__('fulfillment_groups.batch_pending_hint')">
                    {{ __('fulfillment_groups.batch_export_sagawa') }}
                </flux:button>
                <flux:button size="sm" variant="outline" disabled :title="__('fulfillment_groups.batch_pending_hint')">
                    {{ __('fulfillment_groups.batch_import_tracking') }}
                </flux:button>
                <flux:button size="sm" variant="primary" disabled :title="__('fulfillment_groups.batch_pending_hint')">
                    {{ __('fulfillment_groups.btn_mark_shipped') }}
                </flux:button>
            </div>
        @endif

        <flux:table :paginate="$groups" class="data-table">
            <flux:table.columns>
                <flux:table.column class="fg-col-select"></flux:table.column>
                <flux:table.column>{{ __('fulfillment_groups.col_reference_no') }}</flux:table.column>
                <flux:table.column>{{ __('fulfillment_groups.col_shop') }}</flux:table.column>
                <flux:table.column>{{ __('fulfillment_groups.col_recipient') }}</flux:table.column>
                <flux:table.column align="end">{{ __('fulfillment_groups.col_orders_items') }}</flux:table.column>
                <flux:table.column>{{ __('fulfillment_groups.col_shipping') }}</flux:table.column>
                <flux:table.column>{{ __('fulfillment_groups.col_tracking') }}</flux:table.column>
                <flux:table.column>{{ __('fulfillment_groups.col_note') }}</flux:table.column>
                <flux:table.column>{{ __('fulfillment_groups.col_added') }}</flux:table.column>
                <flux:table.column>{{ __('fulfillment_groups.col_status') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($groups as $group)
                    @php
                        $members = $group->groupOrders;
                        $orderIds = $members
                            ->map(fn ($go) => $go->salesOrder?->platform_order_id)
                            ->filter()
                            ->values();
                        $shops = $members
                            ->map(fn ($go) => $go->salesOrder?->shop?->name)
                            ->filter()
                            ->unique()
                            ->values();
                        $itemQty = $members->sum(fn ($go) => $go->salesOrder
                            ? (int) $go->salesOrder->lines->sum('quantity')
                            : 0);
                        $arranged = $members->pluck('arranged_at')->filter()->min();
                        $printed = $members
                            ->map(fn ($go) => $go->salesOrder?->courier_csv_exported_at)
                            ->filter()
                            ->min();
                    @endphp
                    <flux:table.row :key="$group->id">
                        <flux:table.cell class="fg-col-select">
                            <input type="checkbox" wire:model.live="selectedIds" value="{{ $group->id }}" />
                        </flux:table.cell>

                        <flux:table.cell>
                            <a class="fg-ref-link" href="{{ route('fulfillment-groups.show', $group) }}" wire:navigate>
                                {{ $group->reference_no }}
                            </a>
                            <div class="fg-subtle">
                                @if ($orderIds->isEmpty())
                                    -
                                @elseif ($orderIds->count() === 1)
                                    {{ $orderIds->first() }}
                                @else
                                    {{ $orderIds->first() }} +{{ $orderIds->count() - 1 }}
                                @endif
                            </div>
                        </flux:table.cell>

                        <flux:table.cell>
                            <strong>{{ $group->tenant->code }}</strong>
                            <div class="fg-subtle">
                                @if ($shops->count() === 1)
                                    {{ $shops->first() }}
                                @elseif ($shops->count() > 1)
                                    {{ __('fulfillment_groups.shops_count', ['count' => $shops->count()]) }}
                                @else
                                    -
                                @endif
                            </div>
                        </flux:table.cell>

                        <flux:table.cell>
                            <strong>{{ $group->recipient_name ?: '-' }}</strong>
                            <div class="fg-subtle">{{ $group->recipient_city ?: $group->recipient_postal_code ?: '-' }}</div>
                        </flux:table.cell>

                        <flux:table.cell align="end">
                            <strong>{{ number_format($group->orders_count) }}</strong> / {{ number_format($itemQty) }}
                        </flux:table.cell>

                        <flux:table.cell>
                            <select
                                class="fg-inline-input"
                                wire:change="updateShippingMethod({{ $group->id }}, $event.target.value)"
                            >
                                <option value="">-</option>
                                @foreach ($shippingMethods as $methodId => $methodName)
                                    <option value="{{ $methodId }}" @selected((string) $group->shipping_method_id === (string) $methodId)>{{ $methodName }}</option>
                                @endforeach
                            </select>
                        </flux:table.cell>

                        <flux:table.cell>
                            <input
                                type="text"
                                class="fg-inline-input"
                                value="{{ $trackingDrafts[$group->id] ?? '' }}"
                                placeholder="{{ __('fulfillment_groups.tracking_placeholder') }}"
                                wire:change="updateTracking({{ $group->id }}, $event.target.value)"
                            />
                        </flux:table.cell>

                        <flux:table.cell>
                            <input
                                type="text"
                                class="fg-inline-input"
                                value="{{ $noteDrafts[$group->id] ?? '' }}"
                                placeholder="{{ __('fulfillment_groups.note_placeholder') }}"
                                wire:change="updateNote({{ $group->id }}, $event.target.value)"
                            />
                        </flux:table.cell>

                        <flux:table.cell>
                            <div>{{ $arranged ? $arranged->format('Y-m-d H:i') : '-' }}</div>
                            <div class="fg-subtle">
                                {{ $printed ? __('fulfillment_groups.printed_at', ['time' => $printed->format('m-d H:i')]) : __('fulfillment_groups.not_printed') }}
                            </div>
                        </flux:table.cell>

                        <flux:table.cell>
                            <flux:badge color="{{ $this->statusColor($group->status) }}">
                                {{ $this->statusLabel($group->status) }}
                            </flux:badge>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="10">
                            <div class="empty-state">{{ __('fulfillment_groups.empty_state') }}</div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </section>

    <style>
        .fg-toggle {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border: 1px solid var(--line);
            border-radius: 6px;
            background: #fff;
            color: var(--ink);
            padding: 9px 12px;
            font-size: 12px;
            font-weight: 700;
            white-space: nowrap;
        }

        .fg-toggle input {
            width: 15px;
            height: 15px;
            margin: 0;
            accent-color: var(--accent);
        }

        .fg-batchbar {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 12px;
            margin-bottom: 10px;
            border: 1px solid var(--line);
            border-radius: 8px;
            background: #f8fafc;
        }

        .fg-batchbar-count {
            font-size: 12px;
            font-weight: 700;
            color: var(--ink);
        }

        .fg-batchbar-spacer {
            flex: 1 1 auto;
        }

        .fg-ref-link {
            color: var(--accent);
            font-weight: 700;
            font-size: 13px;
            text-decoration: none;
        }

        .fg-ref-link:hover {
            text-decoration: underline;
        }

        .fg-subtle {
            color: var(--muted);
            font-size: 11px;
        }

        .fg-inline-input {
            width: 100%;
            min-width: 120px;
            height: 30px;
            padding: 4px 8px;
            border: 1px solid var(--line);
            border-radius: 6px;
            font-size: 12px;
            background: #fff;
            color: var(--ink);
        }

        .fg-inline-input:focus {
            outline: none;
            border-color: var(--accent);
        }

        .fg-col-select {
            width: 34px;
        }
    </style>
</div>
