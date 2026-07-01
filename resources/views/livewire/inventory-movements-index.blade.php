<div class="movements-page">
    <section class="summary-grid movements-summary" aria-label="Inventory movement summary">
        <x-page-panel-header
            class="inventory-summary-heading"
            :title="__('movements.page_title')"
            :subtitle="__('movements.page_subtitle')"
        />

        <flux:card size="sm" class="summary-card">
            <span>{{ __('movements.summary_movements') }}</span>
            <strong>{{ number_format($summary['movements']) }}</strong>
        </flux:card>
        <flux:card size="sm" class="summary-card">
            <span>{{ __('movements.summary_net_available') }}</span>
            <strong class="{{ $this->quantityDeltaClass($summary['netAvailable']) }}">
                {{ $this->signedQuantity($summary['netAvailable']) }}
            </strong>
        </flux:card>
        <flux:card size="sm" class="summary-card">
            <span>{{ __('movements.summary_positive') }}</span>
            <strong>{{ number_format($summary['positive']) }}</strong>
        </flux:card>
        <flux:card size="sm" class="summary-card">
            <span>{{ __('movements.summary_negative') }}</span>
            <strong>{{ number_format($summary['negative']) }}</strong>
        </flux:card>
    </section>

    <section class="latest-movement-row" aria-label="Latest inventory movement">
        <span>{{ __('movements.latest_movement_label') }}</span>
        <strong>{{ $summary['latest'] ? \Illuminate\Support\Carbon::parse($summary['latest'])->format('Y-m-d') : '-' }}</strong>
    </section>

    <section class="table-shell flux-panel">
        <div class="movement-toolbar">
            <flux:input
                wire:model.live.debounce.300ms="search"
                :label="__('movements.search_label')"
                :placeholder="__('movements.search_placeholder')"
            />

            <flux:select wire:model.live="tenantId" :label="__('common.tenant')">
                <flux:select.option value="">{{ __('common.all_tenants') }}</flux:select.option>
                @foreach ($tenants as $tenant)
                    <flux:select.option value="{{ $tenant->id }}">{{ $tenant->code }} - {{ $tenant->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="warehouseId" :label="__('common.warehouse')">
                <flux:select.option value="">{{ __('common.all_warehouses') }}</flux:select.option>
                @foreach ($warehouses as $warehouse)
                    <flux:select.option value="{{ $warehouse->id }}">{{ $warehouse->code }} - {{ $warehouse->name }}</flux:select.option>
                @endforeach
            </flux:select>

            @php
                $stockItemOptions = collect($stockItems)->map(fn ($stockItem) => [
                    'value' => $stockItem->id,
                    'label' => $stockItem->code,
                    'meta' => $stockItem->displayName(),
                ]);
                $selectedStockItemOption = $stockItemOptions->firstWhere('value', (int) $stockItemId);
            @endphp
            <x-searchable-select
                wire:key="movement-stock-item-picker-{{ $tenantId }}-{{ $stockItemId }}"
                :label="__('movements.filter_stock_item')"
                model="stockItemId"
                search-model="stockItemSearch"
                :options="$stockItemOptions"
                :selected-label="$selectedStockItemOption['label'] ?? ($selectedStockItem?->code ?? $stockItemSearch)"
                :placeholder="__('common.all_stock_items')"
                empty-label="No results"
            />

            <flux:select wire:model.live="movementType" :label="__('movements.filter_movement_type')">
                <flux:select.option value="">{{ __('common.all_movements') }}</flux:select.option>
                @foreach ($movementTypes as $type)
                    <flux:select.option value="{{ $type }}">{{ $this->movementTypeLabel($type) }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="userId" :label="__('movements.filter_user')">
                <flux:select.option value="">{{ __('common.all_users') }}</flux:select.option>
                @foreach ($users as $user)
                    <flux:select.option value="{{ $user->id }}">{{ $user->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:input wire:model.live="dateFrom" type="date" :label="__('movements.filter_date_from')" />

            <flux:input wire:model.live="dateTo" type="date" :label="__('movements.filter_date_to')" />

            <flux:button wire:click="clearFilters" variant="primary">
                {{ __('common.clear') }}
            </flux:button>
        </div>

        @if ($stockItemId !== '')
            <div class="active-filter-row">
                <flux:badge color="teal">
                    {{ __('movements.stock_item_filter_badge') }}
                    {{ $selectedStockItem ? $selectedStockItem->code.' - '.$selectedStockItem->name : $stockItemId }}
                </flux:badge>
                <flux:button size="xs" variant="outline" wire:click="$set('stockItemId', '')">{{ __('common.remove') }}</flux:button>
            </div>
        @endif

        <flux:table class="movement-table">
            <flux:table.columns>
                <flux:table.column>{{ __('movements.col_created') }}</flux:table.column>
                <flux:table.column>{{ __('movements.col_stock_item') }}</flux:table.column>
                <flux:table.column>{{ __('movements.col_movement') }}</flux:table.column>
                <flux:table.column align="end">{{ __('movements.col_balance') }}</flux:table.column>
                <flux:table.column>{{ __('movements.col_reference') }}</flux:table.column>
                <flux:table.column>{{ __('movements.col_actor_note') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($movements as $movement)
                    <flux:table.row :key="$movement->id">
                        <flux:table.cell class="movement-created-cell">
                            <strong>{{ optional($movement->created_at)->format('Y-m-d') }}</strong>
                            <span>{{ optional($movement->created_at)->format('H:i') }}</span>
                        </flux:table.cell>
                        <flux:table.cell class="movement-stock-cell">
                            <strong>{{ $movement->stockItem->code }}</strong>
                            <span>{{ $movement->stockItem->name }}</span>
                            <small>
                                {{ $movement->tenant->code }} / {{ $movement->tenant->name }} / {{ $movement->warehouse->code }}
                            </small>
                        </flux:table.cell>
                        <flux:table.cell class="movement-change-cell">
                            <flux:badge color="blue">
                                {{ $this->movementTypeLabel($movement->movement_type) }}
                            </flux:badge>
                            <div class="bucket-list">
                                @foreach ($this->movementImpactBuckets($movement) as $bucket)
                                    <span>
                                        {{ $bucket['label'] }}
                                        <strong class="{{ $this->quantityDeltaClass($bucket['value']) }}">
                                            {{ $this->signedQuantity($bucket['value']) }}
                                        </strong>
                                    </span>
                                @endforeach
                            </div>
                        </flux:table.cell>
                        <flux:table.cell align="end">
                            <div class="bucket-list bucket-list-end">
                                @foreach ($this->movementAfterBuckets($movement) as $bucket)
                                    <span>
                                        {{ $bucket['label'] }}
                                        <strong>{{ number_format($bucket['value']) }}</strong>
                                    </span>
                                @endforeach
                            </div>
                        </flux:table.cell>
                        <flux:table.cell>
                            @if ($movement->ref_type || $movement->ref_id)
                                <span class="reference-cell">
                                    @if ($movement->ref_type)
                                        <strong>{{ $movement->ref_type }}</strong>
                                    @endif
                                    @if ($movement->ref_id)
                                        <span title="{{ $movement->ref_id }}">{{ $movement->ref_id }}</span>
                                    @endif
                                </span>
                            @else
                                <span class="muted-dash">-</span>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell class="movement-actor-cell">
                            @if ($movement->user)
                                <strong>{{ $movement->user->name }}</strong>
                            @else
                                <span class="muted-dash">-</span>
                            @endif
                            @if ($movement->note)
                                <span title="{{ $movement->note }}">{{ $movement->note }}</span>
                            @else
                                <span class="subtle">{{ __('common.no_note') }}</span>
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="6">
                            <div class="empty-state">{{ __('movements.empty_state') }}</div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>

        <div class="table-pagination-row">
            <x-rows-per-page-select :options="$perPageOptions" />
            <flux:pagination :paginator="$movements" />
        </div>
    </section>
</div>
