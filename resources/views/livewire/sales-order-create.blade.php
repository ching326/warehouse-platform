<div class="sales-order-create-page">
    <form wire:submit="save" class="sku-form">
        <section class="table-shell flux-panel form-panel">
            <div class="form-panel-header">
                <div>
                    <strong>{{ __('sales_orders.field_order_status') }}</strong>
                    <span>{{ __('sales_orders.create_page_subtitle') }}</span>
                </div>
                <flux:button href="{{ route('sales.orders.index') }}" variant="outline" wire:navigate>
                    {{ __('sales_orders.btn_back_orders') }}
                </flux:button>
            </div>

            <div class="form-grid">
                <flux:select wire:model.live="shopId" required :label="__('sales_orders.field_shop')">
                    <flux:select.option value="">{{ __('sales_orders.field_shop') }}</flux:select.option>
                    @foreach ($shops as $shop)
                        <flux:select.option value="{{ $shop->id }}">
                            {{ $shop->tenant->code }} / {{ $shop->code }} - {{ $shop->name }}
                        </flux:select.option>
                    @endforeach
                </flux:select>

                <div>
                    <flux:input wire:model="platformOrderId" :label="__('sales_orders.field_platform_order_id')" />
                    <span class="subtle">{{ __('sales_orders.field_platform_order_id_hint') }}</span>
                </div>

                <flux:select wire:model="shippingMethodId" :label="__('sales_orders.field_shipping_method')">
                    <flux:select.option value="">{{ __('sales_orders.shipping_method_unset') }}</flux:select.option>
                    @foreach ($shippingMethods as $method)
                        <flux:select.option value="{{ $method->id }}">
                            {{ $method->name }} / {{ $method->carrier->name }}
                        </flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            @error('shopId') <p class="form-error">{{ $message }}</p> @enderror
            @error('platform_order_id') <p class="form-error">{{ $message }}</p> @enderror
            @error('shipping_method_id') <p class="form-error">{{ $message }}</p> @enderror
        </section>

        <section class="table-shell flux-panel form-panel">
            <div class="form-panel-header">
                <div>
                    <strong>{{ __('sales_orders.field_recipient') }}</strong>
                    <span>{{ __('sales_orders.related_orders_label') }}</span>
                </div>
            </div>

            <div class="form-grid three">
                <flux:input wire:model="recipientName" :label="__('sales_orders.field_recipient_name')" />
                <flux:input wire:model="recipientPhone" :label="__('sales_orders.field_recipient_phone')" />
                <flux:input wire:model="recipientCountryCode" maxlength="2" :label="__('sales_orders.field_country_code')" />
                <flux:input wire:model="recipientPostalCode" :label="__('sales_orders.field_postal_code')" />
                <flux:input wire:model="recipientState" :label="__('sales_orders.field_state')" />
                <flux:input wire:model="recipientCity" :label="__('sales_orders.field_city')" />
                <flux:input wire:model="recipientAddressLine1" :label="__('sales_orders.field_address_line1')" />
                <flux:input wire:model="recipientAddressLine2" :label="__('sales_orders.field_address_line2')" />
            </div>

            @foreach (['recipient_name', 'recipient_phone', 'recipient_country_code', 'recipient_postal_code', 'recipient_state', 'recipient_city', 'recipient_address_line1', 'recipient_address_line2'] as $field)
                @error($field) <p class="form-error">{{ $message }}</p> @enderror
            @endforeach
        </section>

        <section class="table-shell flux-panel form-panel">
            <div class="form-panel-header">
                <div>
                    <strong>{{ __('sales_orders.col_sku') }}</strong>
                    <span>{{ __('sales_orders.create_page_subtitle') }}</span>
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
                        wire:key="sales-order-create-sku-picker-{{ $index }}-{{ md5($shopId.'|'.($line['sku_id'] ?? '')) }}"
                        :label="__('sales_orders.field_sku')"
                        model="lines.{{ $index }}.sku_id"
                        search-model="skuSearches.{{ $index }}"
                        :options="$skuOptions"
                        :selected-label="$selectedSku['label'] ?? ($skuSearches[$index] ?? '')"
                        :placeholder="$shopId === '' ? __('sales_orders.select_shop_first') : __('inventory.search_placeholder')"
                        empty-label="No results"
                        required
                        :disabled="$shopId === ''"
                    />

                    <flux:input wire:model="lines.{{ $index }}.quantity" type="number" min="1" step="1" required :label="__('sales_orders.field_quantity')" />
                    <flux:input wire:model="lines.{{ $index }}.note" :label="__('sales_orders.field_note')" />
                    <button type="button" class="remove-line-btn {{ count($lines) <= 1 ? 'invisible' : '' }}" wire:click="removeLine({{ $index }})">
                        <span aria-hidden="true">x</span>
                    </button>
                </div>

                @error("lines.{$index}.sku_id") <p class="form-error">{{ $message }}</p> @enderror
                @error("lines.{$index}.quantity") <p class="form-error">{{ $message }}</p> @enderror
                @error("lines.{$index}.note") <p class="form-error">{{ $message }}</p> @enderror
            @endforeach

            <div>
                <flux:button type="button" variant="outline" wire:click="addLine">{{ __('sales_orders.btn_add_line') }}</flux:button>
            </div>
            @error('lines') <p class="form-error">{{ $message }}</p> @enderror
        </section>

        <section class="table-shell flux-panel form-panel">
            <label>
                <span>{{ __('sales_orders.field_note') }}</span>
                <textarea wire:model="note" rows="4"></textarea>
            </label>
            @error('note') <p class="form-error">{{ $message }}</p> @enderror
        </section>

        <div class="form-actions">
            <flux:button href="{{ route('sales.orders.index') }}" variant="outline" wire:navigate>
                {{ __('sales_orders.btn_cancel_edit') }}
            </flux:button>
            <flux:button type="submit" variant="primary">{{ __('sales_orders.btn_create_order') }}</flux:button>
        </div>
    </form>
</div>
