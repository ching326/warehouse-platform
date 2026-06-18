<div class="inbound-create-page">
    <form wire:submit="save" class="sku-form">
        <section class="table-shell flux-panel form-panel">
            <div class="form-panel-header">
                <div>
                    <strong>{{ __('inbound.section_header') }}</strong>
                    <span>{{ __('inbound.section_header_hint') }}</span>
                </div>
                <flux:button href="{{ route('inbound.index') }}" variant="subtle">{{ __('inbound.btn_back') }}</flux:button>
            </div>

            <div class="form-grid">
                @if ($showTenantSelect)
                    <flux:select wire:model.live="tenantId" :label="__('inbound.field_tenant')">
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

                <flux:select wire:model.live="warehouseId" :label="__('inbound.field_warehouse')">
                    <flux:select.option value="">{{ __('stock_adjustments.select_warehouse') }}</flux:select.option>
                    @foreach ($warehouses as $warehouse)
                        <flux:select.option value="{{ $warehouse->id }}">{{ $warehouse->code }} - {{ $warehouse->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <div class="form-grid three form-grid-spaced">
                <div>
                    <flux:input wire:model="ref" :label="__('inbound.field_ref')" />
                    <span class="subtle">{{ __('inbound.field_ref_hint') }}</span>
                </div>
                <flux:input wire:model="expectedAt" type="date" :label="__('inbound.field_expected_at')" />
                <label>
                    <span>{{ __('inbound.field_note') }}</span>
                    <textarea wire:model="note" rows="3"></textarea>
                </label>
            </div>

            @foreach (['tenantId', 'tenant_id', 'warehouse_id', 'ref', 'expected_at', 'note'] as $field)
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

            <flux:input
                wire:model.live.debounce.300ms="skuSearch"
                :label="__('inbound.search_skus_label')"
                :placeholder="__('inventory.search_placeholder')"
            />

            @foreach ($lines as $index => $line)
                <div class="form-grid three form-grid-spaced">
                    <flux:select wire:model="lines.{{ $index }}.sku_id" :label="__('inbound.field_sku')">
                        <flux:select.option value="">{{ __('inbound.select_sku') }}</flux:select.option>
                        @foreach ($skus as $sku)
                            <flux:select.option value="{{ $sku->id }}">
                                {{ $sku->sku }} - {{ $sku->stockItem->code }} {{ $sku->stockItem->name }}
                            </flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:input wire:model="lines.{{ $index }}.expected_qty" type="number" min="1" step="1" :label="__('inbound.field_expected_qty')" />
                    <div>
                        <flux:input wire:model="lines.{{ $index }}.note" :label="__('inbound.field_line_note')" />
                        @if (count($lines) > 1)
                            <flux:button type="button" size="xs" variant="subtle" wire:click="removeLine({{ $index }})">
                                {{ __('inbound.btn_remove_line') }}
                            </flux:button>
                        @endif
                    </div>
                </div>

                @error("lines.{$index}.sku_id") <p class="form-error">{{ $message }}</p> @enderror
                @error("lines.{$index}.expected_qty") <p class="form-error">{{ $message }}</p> @enderror
            @endforeach

            <div class="form-actions form-actions-left">
                <flux:button type="button" variant="outline" wire:click="addLine">{{ __('inbound.btn_add_line') }}</flux:button>
            </div>
            @error('lines') <p class="form-error">{{ $message }}</p> @enderror
        </section>

        <div class="form-actions">
            <flux:button href="{{ route('inbound.index') }}" variant="subtle">{{ __('inbound.btn_cancel') }}</flux:button>
            <flux:button type="submit" variant="primary">{{ __('inbound.btn_submit') }}</flux:button>
        </div>
    </form>
</div>
