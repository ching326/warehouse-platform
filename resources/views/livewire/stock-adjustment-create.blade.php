<div class="stock-adjustment-page">
    <x-page-panel-header
        :title="__('stock_adjustments.page_title')"
        :subtitle="__('stock_adjustments.page_subtitle')"
    >
        <x-slot:actions>
            <flux:button href="{{ route('stock-adjustments.import') }}" variant="primary" wire:navigate>
                {{ __('stock_adjustment_import.btn_import') }}
            </flux:button>
        </x-slot:actions>
    </x-page-panel-header>

    <form wire:submit="save" class="sku-form">
        <section class="table-shell flux-panel form-panel form-panel-with-overflow">
            <div class="form-panel-header">
                <div>
                    <strong>{{ __('stock_adjustments.section_target') }}</strong>
                    <span>{{ __('stock_adjustments.section_target_hint') }}</span>
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

                <div class="stock-adjustment-warehouse-field">
                    <flux:select wire:model.live="warehouseId" required :label="__('stock_adjustments.field_warehouse')">
                        <flux:select.option value="">{{ __('stock_adjustments.select_warehouse') }}</flux:select.option>
                        @foreach ($warehouses as $warehouse)
                            <flux:select.option value="{{ $warehouse->id }}">{{ $warehouse->code }} - {{ $warehouse->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    @if ($warehouseId !== '')
                        <label class="default-view-toggle">
                            <input type="checkbox" wire:model.live="currentWarehouseIsDefault">
                            <span>{{ __('stock_adjustments.default_warehouse_checkbox') }}</span>
                        </label>
                    @endif
                </div>
            </div>

            @error('tenant_id') <p class="form-error">{{ $message }}</p> @enderror
            @error('warehouse_id') <p class="form-error">{{ $message }}</p> @enderror

            <div class="form-grid">
                @php
                    $stockItemDisabled = $tenantId === '' || $warehouseId === '';
                    $stockItemOptions = $stockItems->map(fn ($item) => [
                        'value' => $item->id,
                        'label' => $item->code.' - '.$item->displayName(),
                        'meta' => collect([
                            $item->skus->pluck('sku')->take(3)->implode(', '),
                        ])->filter()->implode(' / '),
                    ]);
                    $selectedStockItemLabel = $selectedStockItem
                        ? $selectedStockItem->code.' - '.$selectedStockItem->displayName()
                        : '';
                @endphp

                @if ($stockItemDisabled)
                    <flux:select required :label="__('stock_adjustments.field_stock_item')" disabled>
                        <flux:select.option value="">{{ __('stock_adjustments.select_stock_item') }}</flux:select.option>
                    </flux:select>
                @else
                    <x-searchable-select
                        wire:key="stock-adjustment-stock-item-{{ $tenantId }}-{{ $warehouseId }}-{{ $stockItemId }}"
                        :label="__('stock_adjustments.field_stock_item')"
                        model="stockItemId"
                        search-model="stockItemSearch"
                        :options="$stockItemOptions"
                        :selected-label="$selectedStockItemLabel"
                        :placeholder="__('stock_adjustments.select_stock_item')"
                        :empty-label="__('skus.no_stock_item')"
                        required
                    />
                @endif
            </div>

            @unless ($stockItemDisabled)
                @error('stock_item_id') <p class="form-error">{{ $message }}</p> @enderror
            @endunless
        </section>

        <section class="table-shell flux-panel form-panel">
            <div class="form-panel-header">
                <div>
                    <strong>{{ __('stock_adjustments.section_adjustment') }}</strong>
                    <span>{{ __('stock_adjustments.section_adjustment_hint') }}</span>
                </div>
            </div>

            <div class="form-grid">
                <flux:select wire:model.live="action" required :label="__('stock_adjustments.field_action')">
                    <flux:select.option value="">{{ __('stock_adjustments.select_action') }}</flux:select.option>
                    @foreach ($actionOptions as $value => $label)
                        <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>

                <div>
                    <flux:input
                        type="number"
                        wire:model="quantity"
                        min="1"
                        step="1"
                        required
                        :label="__('stock_adjustments.field_quantity')"
                    />
                    <span class="subtle">{{ __('stock_adjustments.field_quantity_hint') }}</span>
                </div>

                <flux:select wire:model="reason" required :label="__('stock_adjustments.field_reason')" :disabled="$action === ''">
                    <flux:select.option value="">{{ __('stock_adjustments.select_reason') }}</flux:select.option>
                    @foreach ($reasonOptions as $value => $label)
                        <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <div class="form-grid">
                <div>
                    <flux:input
                        wire:model="refId"
                        :label="__('stock_adjustments.field_ref_id')"
                    />
                    <span class="subtle">{{ __('stock_adjustments.field_ref_id_hint') }}</span>
                    @error('ref_id') <p class="form-error">{{ $message }}</p> @enderror
                </div>
            </div>

            <label>
                <span>{{ __('stock_adjustments.field_note') }}</span>
                <textarea wire:model="note" rows="4"></textarea>
            </label>
            @error('note') <p class="form-error">{{ $message }}</p> @enderror
        </section>

        <section class="table-shell flux-panel form-panel">
            <div class="form-panel-header">
                <div>
                    <strong>{{ __('stock_adjustments.section_balance_preview') }}</strong>
                </div>
            </div>

            @if ($currentBalance)
                <div class="balance-preview-grid">
                    <div>
                        <span>{{ __('stock_adjustments.col_on_hand') }}</span>
                        <strong>{{ number_format($currentBalance->on_hand_qty) }}</strong>
                    </div>
                    <div>
                        <span>{{ __('stock_adjustments.col_reserved') }}</span>
                        <strong>{{ number_format($currentBalance->reserved_qty) }}</strong>
                    </div>
                    <div>
                        <span>{{ __('stock_adjustments.col_available') }}</span>
                        <strong>{{ number_format($currentBalance->available_qty) }}</strong>
                    </div>
                    <div>
                        <span>{{ __('stock_adjustments.col_hold') }}</span>
                        <strong>{{ number_format($currentBalance->hold_qty) }}</strong>
                    </div>
                    <div>
                        <span>{{ __('stock_adjustments.col_damaged') }}</span>
                        <strong>{{ number_format($currentBalance->damaged_qty) }}</strong>
                    </div>
                </div>
            @elseif ($stockItemId !== '')
                <span class="subtle">{{ __('stock_adjustments.balance_none_yet') }}</span>
            @endif
        </section>

        <div class="form-actions">
            <flux:button href="{{ route('inventory.index') }}" variant="outline">{{ __('stock_adjustments.btn_cancel') }}</flux:button>
            <flux:button type="submit" variant="primary">{{ __('stock_adjustments.btn_submit') }}</flux:button>
        </div>
    </form>

    <style>
        .stock-adjustment-warehouse-field {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
    </style>
</div>
