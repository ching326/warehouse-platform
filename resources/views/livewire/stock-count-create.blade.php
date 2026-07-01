<div class="stock-count-create-page">
    <x-page-panel-header :title="__('stock_counts.create_title')" :subtitle="__('stock_counts.create_subtitle')" />

    <form wire:submit="save" class="sku-form">
        <section class="table-shell flux-panel form-panel form-panel-with-overflow">
            <div class="form-panel-header">
                <strong>{{ __('stock_counts.section_target') }}</strong>
                <div class="active-filter-row">
                    <flux:button href="{{ route('stock-counts.index') }}" variant="outline" wire:navigate>{{ __('stock_counts.btn_back_to_index') }}</flux:button>
                    <flux:button href="{{ route('stock-counts.import') }}" variant="primary" wire:navigate>{{ __('stock_counts.btn_import') }}</flux:button>
                </div>
            </div>

            <div class="form-grid">
                @if ($showTenantSelect)
                    <flux:select wire:model.live="tenantId" required :label="__('stock_adjustments.field_tenant')">
                        <flux:select.option value="">{{ __('skus.select_tenant') }}</flux:select.option>
                        @foreach ($tenants as $tenant)
                            <flux:select.option value="{{ $tenant->id }}">{{ $tenant->code }} - {{ $tenant->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @else
                    <label>
                        <span>{{ __('stock_adjustments.field_tenant') }}</span>
                        <input type="text" value="{{ $currentTenant ? $currentTenant->code.' - '.$currentTenant->name : __('skus.no_active_tenant') }}" readonly>
                    </label>
                @endif

                <flux:select wire:model.live="warehouseId" required :label="__('stock_adjustments.field_warehouse')">
                    <flux:select.option value="">{{ __('stock_adjustments.select_warehouse') }}</flux:select.option>
                    @foreach ($warehouses as $warehouse)
                        <flux:select.option value="{{ $warehouse->id }}">{{ $warehouse->code }} - {{ $warehouse->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            @php
                $stockItemDisabled = $tenantId === '' || $warehouseId === '';
                $stockItemOptions = $stockItems->map(fn ($item) => [
                    'value' => $item->id,
                    'label' => $item->code.' - '.$item->displayName(),
                    'meta' => $item->skus->pluck('sku')->take(3)->implode(', '),
                ]);
                $selectedStockItemLabel = $selectedStockItem
                    ? $selectedStockItem->code.' - '.$selectedStockItem->displayName()
                    : '';
            @endphp

            <div class="form-grid">
                @if ($stockItemDisabled)
                    <flux:select required :label="__('stock_counts.field_stock_item')" disabled>
                        <flux:select.option value="">{{ __('stock_adjustments.select_stock_item') }}</flux:select.option>
                    </flux:select>
                @else
                    <x-searchable-select
                        wire:key="stock-count-stock-item-{{ $tenantId }}-{{ $warehouseId }}-{{ $stockItemId }}"
                        :label="__('stock_counts.field_stock_item')"
                        model="stockItemId"
                        search-model="stockItemSearch"
                        :options="$stockItemOptions"
                        :selected-label="$selectedStockItemLabel"
                        :placeholder="__('stock_adjustments.select_stock_item')"
                        :empty-label="__('skus.no_stock_item')"
                        required
                    />
                @endif

                <flux:input type="number" min="0" step="1" wire:model.live="countedQty" required :label="__('stock_counts.field_counted_qty')" />
            </div>

            @unless ($stockItemDisabled)
                @error('stockItemId') <p class="form-error">{{ $message }}</p> @enderror
            @endunless
        </section>

        <section class="table-shell flux-panel form-panel">
            <div class="form-panel-header">
                <strong>{{ __('stock_counts.section_balance_preview') }}</strong>
            </div>

            @if ($currentBalance)
                <div class="balance-preview-grid">
                    <div><span>{{ __('stock_counts.col_current_on_hand') }}</span><strong>{{ number_format($currentBalance->on_hand_qty) }}</strong></div>
                    <div><span>{{ __('stock_adjustments.col_reserved') }}</span><strong>{{ number_format($currentBalance->reserved_qty) }}</strong></div>
                    <div><span>{{ __('stock_adjustments.col_hold') }}</span><strong>{{ number_format($currentBalance->hold_qty) }}</strong></div>
                    <div><span>{{ __('stock_adjustments.col_damaged') }}</span><strong>{{ number_format($currentBalance->damaged_qty) }}</strong></div>
                    <div><span>{{ __('stock_adjustments.col_available') }}</span><strong>{{ number_format($currentBalance->available_qty) }}</strong></div>
                    <div><span>{{ __('stock_counts.field_counted_qty') }}</span><strong>{{ $countedQty !== '' ? number_format((int) $countedQty) : '-' }}</strong></div>
                    <div><span>{{ __('stock_counts.col_delta') }}</span><strong>{{ $deltaQty !== null ? number_format($deltaQty) : '-' }}</strong></div>
                </div>
            @elseif ($stockItemId !== '')
                <span class="subtle">{{ __('stock_adjustments.balance_none_yet') }}</span>
            @endif
        </section>

        <section class="table-shell flux-panel form-panel">
            <flux:textarea wire:model="note" :label="__('stock_adjustments.field_note')" rows="4" />
        </section>

        <div class="form-actions">
            <span></span>
            <flux:button type="submit" variant="primary">{{ __('stock_counts.btn_save') }}</flux:button>
        </div>
    </form>
</div>
