<div class="inbound-create-page">
    <form wire:submit="save" class="sku-form">
        <section class="table-shell flux-panel form-panel">
            <div class="form-panel-header">
                <div>
                    <strong>{{ __('inbound.section_header') }}</strong>
                    <span>{{ __('inbound.section_header_hint') }}</span>
                </div>
                <flux:button href="{{ route('inbound.index') }}" variant="outline">{{ __('inbound.btn_back') }}</flux:button>
            </div>

            <div class="form-grid four">
                @if ($showTenantSelect)
                    <flux:select wire:model.live="tenantId" required :label="__('inbound.field_tenant')">
                        <flux:select.option value="">{{ __('inbound.select_tenant') }}</flux:select.option>
                        @foreach ($tenants as $tenant)
                            <flux:select.option value="{{ $tenant->id }}">{{ $tenant->code }} - {{ $tenant->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @else
                    <label>
                        <span>{{ __('inbound.field_tenant') }}</span>
                        <input type="text" value="{{ $currentTenant ? $currentTenant->code.' - '.$currentTenant->name : __('inbound.no_active_tenant') }}" readonly>
                    </label>
                @endif

                <flux:select wire:model.live="shopId" :label="__('skus.field_shop')">
                    <flux:select.option value="">{{ __('skus.no_shop') }}</flux:select.option>
                    @foreach ($shops as $shop)
                        <flux:select.option value="{{ $shop->id }}">{{ $shop->code }} - {{ $shop->name }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select wire:model.live="warehouseId" required :label="__('inbound.field_warehouse')">
                    <flux:select.option value="">{{ __('stock_adjustments.select_warehouse') }}</flux:select.option>
                    @foreach ($warehouses as $warehouse)
                        <flux:select.option value="{{ $warehouse->id }}">{{ $warehouse->code }} - {{ $warehouse->name }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:input wire:model="expectedAt" type="date" :label="__('inbound.field_expected_at')" />

                <flux:input wire:model="expectedCartonCount" type="number" min="0" step="1" :label="__('inbound.field_expected_carton_count')" />
            </div>

            <flux:input wire:model="cartonMark" :label="__('inbound.field_carton_mark')" />

            <label style="margin-top: 12px; display: block;">
                <span>{{ __('inbound.field_note') }}</span>
                <textarea wire:model="note" rows="3"></textarea>
            </label>

            @foreach (['tenantId', 'tenant_id', 'warehouse_id', 'shop_id', 'expected_at', 'expected_carton_count', 'carton_mark', 'note'] as $field)
                @error($field) <p class="form-error">{{ $message }}</p> @enderror
            @endforeach
        </section>

        <section class="table-shell flux-panel form-panel">
            <div class="form-panel-header">
                <div>
                    <strong>{{ __('inbound.section_lines') }}</strong>
                    <span>{{ __('inbound.section_lines_hint') }}</span>
                </div>
            </div>

            @foreach ($lines as $index => $line)
                @php
                    $skuOptions = collect($skuOptionsByLine[$index] ?? [])->map(fn ($sku) => [
                        'value' => $sku->id,
                        'label' => $sku->sku,
                        'meta' => trim(($sku->stockItem?->code ? $sku->stockItem->code.' / ' : '').($sku->stockItem?->name ?? $sku->name ?? '')),
                    ]);
                    $selectedSku = $skuOptions->firstWhere('value', (int) ($line['sku_id'] ?? 0));
                @endphp
                <div class="line-row">
                    <x-searchable-select
                        wire:key="inbound-sku-picker-{{ $index }}-{{ md5($tenantId.'|'.$shopId.'|'.($skuSearches[$index] ?? '').'|'.($line['sku_id'] ?? '')) }}"
                        :label="__('inbound.field_sku')"
                        model="lines.{{ $index }}.sku_id"
                        search-model="skuSearches.{{ $index }}"
                        :options="$skuOptions"
                        :selected-label="$selectedSku['label'] ?? ($skuSearches[$index] ?? '')"
                        :placeholder="__('inventory.search_placeholder')"
                        empty-label="No results"
                        required
                    />
                    <flux:input wire:model="lines.{{ $index }}.expected_qty" type="number" min="1" step="1" required :label="__('inbound.field_expected_qty')" />
                    <flux:input wire:model="lines.{{ $index }}.note" :label="__('inbound.field_line_note')" />
                    <button type="button" class="remove-line-btn {{ count($lines) <= 1 ? 'invisible' : '' }}" wire:click="removeLine({{ $index }})">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z"/></svg>
                    </button>
                </div>

                @error("lines.{$index}.sku_id") <p class="form-error">{{ $message }}</p> @enderror
                @error("lines.{$index}.expected_qty") <p class="form-error">{{ $message }}</p> @enderror
            @endforeach

            <div>
                <flux:button type="button" variant="outline" wire:click="addLine">{{ __('inbound.btn_add_line') }}</flux:button>
            </div>
            @error('lines') <p class="form-error">{{ $message }}</p> @enderror
        </section>

        <div class="form-actions">
            <flux:button href="{{ route('inbound.index') }}" variant="outline">{{ __('inbound.btn_cancel') }}</flux:button>
            <flux:button type="submit" variant="primary">{{ __('inbound.btn_submit') }}</flux:button>
        </div>
    </form>
</div>
