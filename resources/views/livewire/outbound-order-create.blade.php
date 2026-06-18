<div class="outbound-create-page">
    @if (session('status'))
        <div class="active-filter-row">
            <flux:badge color="green">{{ session('status') }}</flux:badge>
        </div>
    @endif

    <form wire:submit="save" class="sku-form">
        <section class="table-shell flux-panel form-panel">
            <div class="form-panel-header">
                <div>
                    <strong>{{ __('outbound.section_order') }}</strong>
                    <span>{{ __('outbound.section_order_hint') }}</span>
                </div>
                <flux:button href="{{ route('outbound.index') }}" variant="outline" wire:navigate>{{ __('outbound.btn_back') }}</flux:button>
            </div>

            <div class="form-grid four">
                @if ($showTenantSelect)
                    <flux:select wire:model.live="tenantId" required :label="__('outbound.field_tenant')">
                        <flux:select.option value="">{{ __('outbound.select_tenant') }}</flux:select.option>
                        @foreach ($tenants as $tenant)
                            <flux:select.option value="{{ $tenant->id }}">{{ $tenant->code }} - {{ $tenant->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @else
                    <label>
                        <span>{{ __('outbound.field_tenant') }}</span>
                        <input type="text" value="{{ $currentTenant ? $currentTenant->code.' - '.$currentTenant->name : __('outbound.no_active_tenant') }}" readonly>
                    </label>
                @endif

                <flux:select wire:model.live="warehouseId" required :label="__('outbound.field_warehouse')">
                    <flux:select.option value="">{{ __('stock_adjustments.select_warehouse') }}</flux:select.option>
                    @foreach ($warehouses as $warehouse)
                        <flux:select.option value="{{ $warehouse->id }}">{{ $warehouse->code }} - {{ $warehouse->name }}</flux:select.option>
                    @endforeach
                </flux:select>

                <div>
                    <flux:input wire:model="ref" :label="__('outbound.field_ref')" />
                    <span class="subtle">{{ __('outbound.field_ref_hint') }}</span>
                </div>

                <flux:input wire:model="expectedShipAt" type="date" :label="__('outbound.field_expected_ship_at')" />
            </div>

            <div class="form-grid form-grid-spaced">
                <flux:input wire:model="shippingMethod" :label="__('outbound.field_shipping_method')" />
                <label>
                    <span>{{ __('outbound.field_note') }}</span>
                    <textarea wire:model="note" rows="3"></textarea>
                </label>
            </div>

            @foreach (['tenantId', 'tenant_id', 'warehouse_id', 'ref', 'expected_ship_at', 'shipping_method', 'note'] as $field)
                @error($field) <p class="form-error">{{ $message }}</p> @enderror
            @endforeach
        </section>

        <section class="table-shell flux-panel form-panel">
            <div class="form-panel-header">
                <div>
                    <strong>{{ __('outbound.section_recipient') }}</strong>
                    <span>{{ __('outbound.section_recipient_hint') }}</span>
                </div>
            </div>

            <div class="form-grid three">
                <flux:input wire:model="recipientName" :label="__('outbound.field_recipient_name')" />
                <flux:input wire:model="recipientPhone" :label="__('outbound.field_recipient_phone')" />
                <flux:input wire:model="recipientCountryCode" maxlength="2" :label="__('outbound.field_country_code')" />
                <flux:input wire:model="recipientPostalCode" :label="__('outbound.field_postal_code')" />
                <flux:input wire:model="recipientState" :label="__('outbound.field_state')" />
                <flux:input wire:model="recipientCity" :label="__('outbound.field_city')" />
                <flux:input wire:model="recipientAddressLine1" :label="__('outbound.field_address_line1')" />
                <flux:input wire:model="recipientAddressLine2" :label="__('outbound.field_address_line2')" />
            </div>

            @foreach (['recipient_name', 'recipient_phone', 'recipient_country_code', 'recipient_postal_code', 'recipient_state', 'recipient_city', 'recipient_address_line1', 'recipient_address_line2'] as $field)
                @error($field) <p class="form-error">{{ $message }}</p> @enderror
            @endforeach
        </section>

        <section class="table-shell flux-panel form-panel">
            <div class="form-panel-header">
                <div>
                    <strong>{{ __('outbound.section_lines') }}</strong>
                    <span>{{ __('outbound.section_lines_hint') }}</span>
                </div>
            </div>

            <flux:input
                wire:model.live.debounce.300ms="skuSearch"
                :label="__('outbound.search_skus_label')"
                :placeholder="__('inventory.search_placeholder')"
            />

            @foreach ($lines as $index => $line)
                <div class="line-row">
                    <flux:select wire:model="lines.{{ $index }}.sku_id" required :label="__('outbound.field_sku')">
                        <flux:select.option value="">{{ __('outbound.select_sku') }}</flux:select.option>
                        @foreach ($skus as $sku)
                            <flux:select.option value="{{ $sku->id }}">
                                {{ $sku->sku }} - {{ $sku->name }}
                                @if ($sku->stockItem)
                                    / {{ $sku->stockItem->code }}
                                @else
                                    / {{ __('common.sku_types.virtual_bundle') }}
                                @endif
                            </flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:input wire:model="lines.{{ $index }}.qty" type="number" min="1" step="1" required :label="__('outbound.field_qty')" />
                    <flux:input wire:model="lines.{{ $index }}.note" :label="__('outbound.field_line_note')" />
                    <button type="button" class="remove-line-btn {{ count($lines) <= 1 ? 'invisible' : '' }}" wire:click="removeLine({{ $index }})">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z"/></svg>
                    </button>
                </div>

                @error("lines.{$index}.sku_id") <p class="form-error">{{ $message }}</p> @enderror
                @error("lines.{$index}.qty") <p class="form-error">{{ $message }}</p> @enderror
            @endforeach

            <div>
                <flux:button type="button" variant="outline" wire:click="addLine">{{ __('outbound.btn_add_line') }}</flux:button>
            </div>
            @error('lines') <p class="form-error">{{ $message }}</p> @enderror
        </section>

        <div class="form-actions">
            <flux:button href="{{ route('outbound.index') }}" variant="outline" wire:navigate>{{ __('outbound.btn_cancel') }}</flux:button>
            <flux:button type="submit" variant="primary">{{ __('outbound.btn_submit') }}</flux:button>
        </div>
    </form>
</div>
