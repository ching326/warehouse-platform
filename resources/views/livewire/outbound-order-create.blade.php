<div class="outbound-create-page">
    <x-flash-toast />

    @if ($showDraftSaveConfirmation)
        <div class="app-toast app-toast-warning app-toast-confirm" role="alert">
            <div class="app-toast-body">
                <strong class="app-toast-title">{{ __('common.toast.warning') }}</strong>
                <span class="app-toast-text">{{ __('outbound.draft_not_submitted_warning') }}</span>
                <span class="app-toast-text">{{ __('outbound.draft_confirm_question') }}</span>
                <div class="app-toast-actions">
                    <flux:button type="button" size="sm" variant="outline" wire:click="cancelSaveDraft">
                        {{ __('common.cancel') }}
                    </flux:button>
                    <flux:button type="button" size="sm" variant="primary" wire:click="confirmSaveDraft">
                        {{ __('outbound.confirm_save_draft') }}
                    </flux:button>
                </div>
            </div>
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

                <flux:select wire:model.live="shopId" :label="__('skus.field_shop')">
                    <flux:select.option value="">{{ __('skus.no_shop') }}</flux:select.option>
                    @foreach ($shops as $shop)
                        <flux:select.option value="{{ $shop->id }}">{{ $shop->code }} - {{ $shop->name }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select wire:model.live="warehouseId" required :label="__('outbound.field_warehouse')">
                    <flux:select.option value="">{{ __('stock_adjustments.select_warehouse') }}</flux:select.option>
                    @foreach ($warehouses as $warehouse)
                        <flux:select.option value="{{ $warehouse->id }}">{{ $warehouse->code }} - {{ $warehouse->name }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select wire:model.live="reason" required :label="__('outbound.field_reason')">
                    <flux:select.option value="">{{ __('outbound.select_reason') }}</flux:select.option>
                    @foreach ($this->manualReasons() as $reasonOption)
                        <flux:select.option value="{{ $reasonOption }}">{{ __('outbound.reason_'.$reasonOption) }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select wire:model.live="status" required :label="__('outbound.field_status')">
                    @foreach ($statusOptions as $statusValue => $statusLabel)
                        <flux:select.option value="{{ $statusValue }}">{{ $statusLabel }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select wire:model="shippingMethodId" :label="__('outbound.field_shipping_method')">
                    <flux:select.option value="">{{ __('sales_orders.shipping_method_unset') }}</flux:select.option>
                    @foreach ($shippingMethods as $method)
                        <flux:select.option value="{{ $method->id }}">
                            {{ $method->name }} / {{ $method->carrier->name }}
                        </flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <div class="form-grid form-grid-spaced">
                <label class="form-grid-wide">
                    <span>{{ __('outbound.field_note') }}</span>
                    <textarea wire:model="note" rows="3"></textarea>
                </label>
            </div>

            @foreach (['tenant_id', 'warehouse_id', 'shop_id', 'ref', 'shipping_method_id', 'note'] as $field)
                @error($field) <p class="form-error">{{ $message }}</p> @enderror
            @endforeach
        </section>

        <section class="table-shell flux-panel form-panel">
            <div class="form-panel-header">
                <div>
                    <strong>{{ __('outbound.section_recipient') }}</strong>
                    <span>{{ __('outbound.section_recipient_hint') }}</span>
                </div>
                <flux:button type="button" variant="outline" wire:click="toggleRecipientCollapsed">
                    {{ $recipientCollapsed ? __('outbound.btn_show_recipient') : __('outbound.btn_hide_recipient') }}
                </flux:button>
            </div>

            @unless ($recipientCollapsed)
                @if ($reason === \App\Models\OutboundOrder::REASON_FBA)
                    <div class="form-grid form-grid-spaced">
                        <flux:select wire:model.live="fbaWarehouseId" :label="__('outbound.field_fba_warehouse')">
                            <flux:select.option value="">{{ __('outbound.select_fba_warehouse') }}</flux:select.option>
                            @foreach ($fbaWarehouses as $warehouse)
                                <flux:select.option value="{{ $warehouse->id }}">
                                    {{ $warehouse->code }} - {{ $warehouse->name }} / {{ $warehouse->state }} {{ $warehouse->city }}
                                </flux:select.option>
                            @endforeach
                        </flux:select>
                        <span class="subtle form-grid-wide">{{ __('outbound.fba_warehouse_hint') }}</span>
                    </div>
                @endif

                <div class="form-grid three">
                    <flux:input wire:model="recipientName" :label="__('outbound.field_recipient_name')" />
                    <flux:input wire:model="recipientPhone" :label="__('outbound.field_recipient_phone')" />
                    <flux:input wire:model="recipientCountryCode" maxlength="2" :label="__('outbound.field_country_code')" />
                    <flux:input wire:model.blur="recipientPostalCode" :label="__('outbound.field_postal_code')" />
                    <flux:input wire:model="recipientState" :label="__('outbound.field_state')" />
                    <flux:input wire:model="recipientCity" :label="__('outbound.field_city')" />
                    <flux:input wire:model="recipientAddressLine1" :label="__('outbound.field_address_line1')" />
                    <flux:input wire:model="recipientAddressLine2" :label="__('outbound.field_address_line2')" />
                </div>

                @foreach (['recipient_name', 'recipient_phone', 'recipient_country_code', 'recipient_postal_code', 'recipient_state', 'recipient_city', 'recipient_address_line1', 'recipient_address_line2'] as $field)
                    @error($field) <p class="form-error">{{ $message }}</p> @enderror
                @endforeach
            @endunless
        </section>

        <section class="table-shell flux-panel form-panel">
            <div class="form-panel-header">
                <div>
                    <strong>{{ __('outbound.section_lines') }}</strong>
                    <span>{{ __('outbound.section_lines_hint') }}</span>
                </div>
            </div>

            @foreach ($lines as $index => $line)
                @php
                    $skuOptions = collect($skuOptionsByLine[$index] ?? [])->map(fn ($sku) => [
                        'value' => $sku->id,
                        'label' => $sku->sku,
                        'meta' => trim(($sku->stockItem?->code ? $sku->stockItem->code.' / ' : '').($sku->displayName() ?: '')),
                    ]);
                    $selectedSku = $skuOptions->firstWhere('value', (int) ($line['sku_id'] ?? 0));
                @endphp
                <div class="line-row">
                    <div>
                        <x-searchable-select
                            wire:key="outbound-sku-picker-{{ $index }}-{{ md5($tenantId.'|'.$shopId.'|'.($line['sku_id'] ?? '')) }}"
                            :label="__('outbound.field_sku')"
                            model="lines.{{ $index }}.sku_id"
                            search-model="skuSearches.{{ $index }}"
                            :options="$skuOptions"
                            :selected-label="$selectedSku['label'] ?? ($skuSearches[$index] ?? '')"
                            :placeholder="$tenantId === '' ? __('outbound.select_tenant') : __('inventory.search_placeholder')"
                            empty-label="No results"
                            required
                            :disabled="$tenantId === ''"
                        />
                        @error("lines.{$index}.sku_id") <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                    <flux:input wire:model="lines.{{ $index }}.qty" type="number" min="1" step="1" required :label="__('outbound.field_qty')" />
                    <flux:input wire:model="lines.{{ $index }}.note" :label="__('outbound.field_line_note')" />
                    <button type="button" class="remove-line-btn {{ count($lines) <= 1 ? 'invisible' : '' }}" wire:click="removeLine({{ $index }})">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z"/></svg>
                    </button>
                </div>
            @endforeach

            <div>
                <flux:button type="button" variant="outline" wire:click="addLine">{{ __('outbound.btn_add_line') }}</flux:button>
            </div>
            @error('lines') <p class="form-error">{{ $message }}</p> @enderror
        </section>

        <div class="form-actions">
            <flux:button type="submit" variant="primary">{{ __('outbound.btn_submit') }}</flux:button>
        </div>
    </form>
</div>
