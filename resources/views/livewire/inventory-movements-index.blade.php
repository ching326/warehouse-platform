<div class="movements-page">
    <section class="summary-grid movements-summary" aria-label="Inventory movement summary">
        <flux:card size="sm" class="summary-card">
            <span>Filtered Movements</span>
            <strong>{{ number_format($summary['movements']) }}</strong>
        </flux:card>
        <flux:card size="sm" class="summary-card">
            <span>Positive Qty</span>
            <strong>{{ number_format($summary['positive']) }}</strong>
        </flux:card>
        <flux:card size="sm" class="summary-card">
            <span>Negative Qty</span>
            <strong>{{ number_format($summary['negative']) }}</strong>
        </flux:card>
        <flux:card size="sm" class="summary-card">
            <span>Latest Movement</span>
            <strong class="summary-date">
                {{ $summary['latest'] ? \Illuminate\Support\Carbon::parse($summary['latest'])->format('M j') : '-' }}
            </strong>
        </flux:card>
    </section>

    <section class="table-shell flux-panel">
        <div class="movement-toolbar">
            <flux:input
                wire:model.live.debounce.300ms="search"
                label="Search movements"
                placeholder="Stock code, item name, barcode, ref, or note"
            />

            <flux:select wire:model.live="tenantId" label="Tenant">
                <flux:select.option value="">All tenants</flux:select.option>
                @foreach ($tenants as $tenant)
                    <flux:select.option value="{{ $tenant->id }}">{{ $tenant->code }} - {{ $tenant->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="warehouseId" label="Warehouse">
                <flux:select.option value="">All warehouses</flux:select.option>
                @foreach ($warehouses as $warehouse)
                    <flux:select.option value="{{ $warehouse->id }}">{{ $warehouse->code }} - {{ $warehouse->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="stockItemId" label="Stock item">
                <flux:select.option value="">All stock items</flux:select.option>
                @foreach ($stockItems as $stockItem)
                    <flux:select.option value="{{ $stockItem->id }}">{{ $stockItem->code }} - {{ $stockItem->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="movementType" label="Movement type">
                <flux:select.option value="">All movements</flux:select.option>
                @foreach ($movementTypes as $type)
                    <flux:select.option value="{{ $type }}">{{ $this->movementTypeLabel($type) }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="userId" label="User">
                <flux:select.option value="">All users</flux:select.option>
                @foreach ($users as $user)
                    <flux:select.option value="{{ $user->id }}">{{ $user->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:input wire:model.live="dateFrom" type="date" label="Date from" />

            <flux:input wire:model.live="dateTo" type="date" label="Date to" />

            <flux:button wire:click="clearFilters" variant="outline">
                Clear
            </flux:button>
        </div>

        @if ($stockItemId !== '')
            <div class="active-filter-row">
                <flux:badge color="teal">
                    Stock item filter:
                    {{ $selectedStockItem ? $selectedStockItem->code.' - '.$selectedStockItem->name : $stockItemId }}
                </flux:badge>
                <flux:button size="xs" variant="subtle" wire:click="$set('stockItemId', '')">Remove</flux:button>
            </div>
        @endif

        <flux:table :paginate="$movements" class="movement-table">
            <flux:table.columns>
                <flux:table.column>Created</flux:table.column>
                <flux:table.column>Stock Item</flux:table.column>
                <flux:table.column>Movement</flux:table.column>
                <flux:table.column align="end">Balance</flux:table.column>
                <flux:table.column>Reference</flux:table.column>
                <flux:table.column>Actor / Note</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($movements as $movement)
                    <flux:table.row :key="$movement->id">
                        <flux:table.cell class="movement-created-cell">
                            <strong>{{ optional($movement->created_at)->format('M j') }}</strong>
                            <span>{{ optional($movement->created_at)->format('H:i') }}</span>
                        </flux:table.cell>
                        <flux:table.cell class="movement-stock-cell">
                            <strong>{{ $movement->stockItem->code }}</strong>
                            <span>{{ $movement->stockItem->name }}</span>
                            <small>
                                {{ $movement->tenant->code }} · {{ $movement->tenant->name }} · {{ $movement->warehouse->code }}
                            </small>
                        </flux:table.cell>
                        <flux:table.cell class="movement-change-cell">
                            <flux:badge color="{{ $this->movementColor($movement->quantity_delta) }}">
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
                                <span class="subtle">No note</span>
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="6">
                            <div class="empty-state">No inventory movements match the current filters.</div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </section>
</div>
