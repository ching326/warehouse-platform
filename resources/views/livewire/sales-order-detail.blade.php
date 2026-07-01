@php
    $editable = in_array($order->order_status, ['pending', 'on_hold'], true)
        && in_array($order->fulfillment_status, ['unfulfilled', 'ready'], true);
@endphp

<div class="sales-order-detail-page">
    <x-flash-toast />
    @if ($showHoldChoicePrompt)
        <div class="tracking-import-backdrop" wire:key="sales-order-detail-hold-choice-modal">
            <section class="tracking-import-modal flux-panel">
                <header class="tracking-import-header">
                    <div>
                        <h2>{{ __('outbound.hold_grouped_choice_title') }}</h2>
                        <p>{{ __('outbound.hold_grouped_choice_body') }}</p>
                    </div>
                    <flux:button
                        type="button"
                        variant="ghost"
                        size="sm"
                        icon="x-mark"
                        inset
                        :aria-label="__('sales_orders.btn_cancel_edit')"
                        wire:click="cancelHoldChoice"
                    >
                    </flux:button>
                </header>

                <footer class="tracking-import-footer ready-combine-footer">
                    <flux:button type="button" variant="primary" class="ready-combine-action" wire:click="holdWholeShipment">
                        {{ __('outbound.hold_whole_shipment') }}
                    </flux:button>
                    <flux:button type="button" variant="primary" class="ready-combine-action" wire:click="splitAndRebuildHeldShipment">
                        {{ __('outbound.split_and_rebuild_shipment') }}
                    </flux:button>
                </footer>
            </section>
        </div>
    @endif
    @if ($showReshipModal)
        <div class="tracking-import-backdrop" wire:key="sales-order-detail-reship-modal">
            <section class="tracking-import-modal flux-panel reship-modal">
                <header class="tracking-import-header">
                    <div>
                        <h2>{{ __('outbound.reship_modal_title') }}</h2>
                    </div>
                    <flux:button
                        type="button"
                        variant="ghost"
                        size="sm"
                        icon="x-mark"
                        inset
                        :aria-label="__('sales_orders.btn_cancel_edit')"
                        wire:click="closeReshipModal"
                    >
                    </flux:button>
                </header>

                <div class="form-panel-subsection">
                    <strong>{{ __('outbound.reship_source_shipment') }}</strong>
                </div>
                <div class="reship-source-value">
                    {{ $reshipSourceOutboundLabel }}
                    <input type="hidden" wire:model="reshipSourceOutboundId">
                </div>

                <div class="form-grid two reship-top-grid">
                    <flux:select wire:model="reshipReason" :label="__('outbound.reship_reason_label')">
                        <flux:select.option value="">{{ __('outbound.reship_reason_label') }}</flux:select.option>
                        @foreach ($reshipReasonOptions as $value => $label)
                            <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:select wire:model="reshipWarehouseId" :label="__('outbound.reship_warehouse')">
                        <flux:select.option value="">{{ __('outbound.reship_warehouse') }}</flux:select.option>
                        @foreach ($reshipWarehouseOptions as $warehouse)
                            <flux:select.option value="{{ $warehouse->id }}">
                                {{ $warehouse->code }} / {{ $warehouse->name }}
                            </flux:select.option>
                        @endforeach
                    </flux:select>
                </div>

                @error('reshipSourceOutboundId') <p class="form-error">{{ $message }}</p> @enderror
                @error('reshipLines') <p class="form-error">{{ $message }}</p> @enderror

                <div class="reship-recipient-block" x-data="{ showRecipient: false }">
                    <div class="form-panel-subsection">
                        <strong>{{ __('outbound.section_recipient') }}</strong>
                    </div>
                    <div class="reship-recipient-summary">
                        <div>
                            <span>{{ $reshipRecipientName ?: '-' }}</span>
                        </div>
                        <flux:button type="button" variant="ghost" size="sm" icon="pencil-square" @click="showRecipient = ! showRecipient">
                            <span x-text="showRecipient ? @js(__('fulfillment.btn_hide')) : @js(__('fulfillment.btn_edit'))"></span>
                        </flux:button>
                    </div>
                    <div class="form-grid three form-grid-spaced" x-show="showRecipient" x-cloak>
                        <flux:input wire:model="reshipRecipientName" :label="__('outbound.field_recipient_name')" />
                        <flux:input wire:model="reshipRecipientPhone" :label="__('outbound.field_recipient_phone')" />
                        <flux:input wire:model="reshipRecipientCountryCode" :label="__('outbound.field_country_code')" />
                        <flux:input wire:model="reshipRecipientPostalCode" :label="__('outbound.field_postal_code')" />
                        <flux:input wire:model="reshipRecipientState" :label="__('outbound.field_state')" />
                        <flux:input wire:model="reshipRecipientCity" :label="__('outbound.field_city')" />
                        <flux:input wire:model="reshipRecipientAddressLine1" :label="__('outbound.field_address_line1')" />
                        <flux:input wire:model="reshipRecipientAddressLine2" :label="__('outbound.field_address_line2')" />
                    </div>
                </div>

                <div class="form-panel-subsection">
                    <strong>{{ __('sales_orders.section_lines') }}</strong>
                </div>
                <div class="reship-lines-grid">
                    <div class="reship-lines-head">{{ __('sales_orders.col_sku') }}</div>
                    <div class="reship-lines-head">{{ __('sales_orders.field_product_name') }}</div>
                    <div class="reship-lines-head reship-lines-number">{{ __('sales_orders.col_qty') }}</div>
                    <div class="reship-lines-head reship-lines-number">{{ __('outbound.reship_qty') }}</div>
                    @foreach ($order->lines as $index => $line)
                        <div class="reship-lines-cell">
                            <strong>{{ $line->sku->sku }}</strong>
                            <span class="subtle">{{ $line->sku->stockItem?->code ?? '-' }}</span>
                        </div>
                        <div class="reship-lines-cell reship-lines-name">{{ $line->sku->stockItem?->displayName() ?: ($line->sku->stockItem?->name ?? '-') }}</div>
                        <div class="reship-lines-cell reship-lines-number">{{ number_format($line->quantity) }}</div>
                        <div class="reship-lines-cell reship-lines-number">
                            <input type="hidden" wire:model="reshipLines.{{ $index }}.sales_order_line_id">
                            <input class="table-control reship-qty-input reship-copied-field" type="number" min="0" max="{{ $line->quantity }}" step="1" wire:model="reshipLines.{{ $index }}.qty">
                            @error("reshipLines.{$index}.qty") <p class="form-error">{{ $message }}</p> @enderror
                        </div>
                    @endforeach
                </div>

                @foreach ($reshipExtraLines as $index => $extra)
                    @php
                        $extraSkuOptions = collect($reshipSkuOptions[$index] ?? [])->map(function ($sku) {
                            $name = $sku->stockItem?->displayName() ?: ($sku->stockItem?->name ?? '');
                            $code = $sku->stockItem?->code ?? '';

                            return [
                                'value' => $sku->id,
                                'label' => $sku->sku,
                                'meta' => trim($code.($name !== '' ? ' / '.$name : '')),
                                'name' => $name,
                            ];
                        });
                        $selectedExtraSku = $extraSkuOptions->firstWhere('value', (int) ($extra['sku_id'] ?? 0));
                    @endphp
                    <div class="line-row reship-extra-row">
                        <x-searchable-select
                            wire:key="reship-extra-sku-{{ $index }}"
                            :label="__('sales_orders.field_sku')"
                            model="reshipExtraLines.{{ $index }}.sku_id"
                            search-model="reshipSkuSearches.{{ $index }}"
                            :options="$extraSkuOptions"
                            :selected-label="$selectedExtraSku['label'] ?? ($reshipSkuSearches[$index] ?? '')"
                            :placeholder="__('inventory.search_placeholder')"
                            empty-label="No results"
                        />
                        <div class="reship-extra-name-field">
                            <span class="reship-extra-name-label">{{ __('sales_orders.field_product_name') }}</span>
                            <span class="reship-extra-name-value">{{ $selectedExtraSku ? ($selectedExtraSku['name'] ?: '-') : '-' }}</span>
                        </div>
                        <flux:input wire:model="reshipExtraLines.{{ $index }}.qty" type="number" min="1" step="1" :label="__('outbound.reship_qty')" />
                        <button type="button" class="remove-line-btn" wire:click="removeReshipSku({{ $index }})">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z"/></svg>
                        </button>
                    </div>
                    @error("reshipExtraLines.{$index}.sku_id") <p class="form-error">{{ $message }}</p> @enderror
                    @error("reshipExtraLines.{$index}.qty") <p class="form-error">{{ $message }}</p> @enderror
                @endforeach

                <div class="form-actions form-actions-left reship-additional-actions">
                    <flux:button type="button" variant="outline" wire:click="addReshipSku" icon="plus">
                        {{ __('outbound.reship_add_sku') }}
                    </flux:button>
                </div>

                <label class="reship-note-field">
                    <span>{{ __('outbound.reship_note') }}</span>
                    <textarea class="reship-copied-field" wire:model="reshipNote" rows="3"></textarea>
                </label>

                <footer class="tracking-import-footer ready-combine-footer" style="justify-content:flex-end;">
                    <flux:button type="button" variant="primary" class="ready-combine-action" wire:click="confirmReship">
                        {{ __('outbound.reship_confirm') }}
                    </flux:button>
                </footer>
            </section>
        </div>
    @endif
<section class="table-shell flux-panel form-panel">
        <div class="form-panel-header">
            <div>
                <div class="sales-order-detail-title-row">
                    <strong>{{ $order->platform_order_id ?: '#'.$order->id }}</strong>
                    <div class="active-filter-row">
                        <flux:badge color="{{ $this->orderStatusColor($order->order_status) }}">
                            {{ $this->orderStatusLabel($order->order_status) }}
                        </flux:badge>
                        <flux:badge color="{{ $this->fulfillmentStatusColor($order->fulfillment_status) }}">
                            {{ $this->fulfillmentStatusLabel($order->fulfillment_status) }}
                        </flux:badge>
                        @if ($order->reshipStatus())
                            <x-status-badge :status="$order->reshipStatus()" :label="$order->reshipStatusLabel()" />
                        @endif
                        @if ($order->isPacking())
                            <span class="so-packing-text">{{ __('sales_orders.label_packing') }}</span>
                        @endif
                    </div>
                </div>
                <span>{{ $order->shop->name }} / {{ $order->shop->platform }} / {{ $order->created_at->format('Y-m-d H:i') }}</span>
            </div>
            <div class="sales-order-detail-header-actions" data-testid="sales-order-detail-header-actions">
                <flux:button href="{{ route('sales.orders.index') }}" variant="outline" wire:navigate>
                    {{ __('sales_orders.btn_back_orders') }}
                </flux:button>
            </div>
        </div>

        <div class="form-grid three">
            <div>
                <span class="subtle">{{ __('common.tenant') }}</span>
                <strong>{{ $order->shop->tenant->code }}</strong>
            </div>
            <div>
                <span class="subtle">{{ __('sales_orders.field_source') }}</span>
                <strong>{{ __('sales_orders.source_'.$order->source) }} / {{ $order->createdBy?->name ?: '-' }}</strong>
            </div>
            <div>
                <span class="subtle">{{ __('sales_orders.field_platform_order_id') }}</span>
                <strong>{{ $order->platform_order_id ?: '-' }}</strong>
            </div>
            <div>
                <span class="subtle">{{ __('sales_orders.field_shipping_method') }}</span>
                <select
                    class="table-control"
                    aria-label="{{ __('sales_orders.field_shipping_method') }}"
                    x-on:change="$wire.updateShippingMethod($event.target.value)"
                >
                    <option value="">{{ __('sales_orders.shipping_method_unset') }}</option>
                    @foreach ($shippingMethods as $method)
                        <option value="{{ $method->id }}" @selected((string) ($order->shipping_method_id ?? '') === (string) $method->id)>
                            {{ $method->displayName() }}
                            @if ($method->status !== 'active')
                                ({{ __('shipping.status_inactive') }})
                            @endif
                        </option>
                    @endforeach
                </select>
            </div>
        </div>
    </section>

    <section class="table-shell flux-panel form-panel">
        <div class="form-panel-header">
            <div>
                <strong>{{ __('sales_orders.section_actions') }}</strong>
                <span>{{ __('sales_orders.section_actions_hint') }}</span>
            </div>
        </div>

        <div class="sales-order-detail-actions" data-testid="sales-order-detail-actions">
            <div class="sales-order-detail-actions-main" data-testid="sales-order-detail-actions-main">
                @if ($order->order_status === 'pending' && $order->fulfillment_status === 'unfulfilled')
                    <flux:button class="action-button-md" type="button" size="sm" variant="primary" wire:click="markReady" data-action-variant="primary">
                        {{ __('sales_orders.btn_mark_ready') }}
                    </flux:button>
                @endif

                @if ($order->order_status === 'pending' && $order->fulfillment_status === 'ready')
                    <flux:button class="action-button-md" type="button" size="sm" variant="primary" wire:click="unmarkReady" data-action-variant="primary">
                        {{ __('sales_orders.btn_unmark_ready') }}
                    </flux:button>
                @endif

                @if ($order->order_status === 'pending' && in_array($order->fulfillment_status, ['unfulfilled', 'ready'], true))
                    <flux:button class="action-button-md" type="button" size="sm" variant="primary" wire:click="hold" data-action-variant="primary">
                        {{ __('sales_orders.btn_hold') }}
                    </flux:button>
                    <flux:button class="action-button-md" type="button" size="sm" variant="primary" wire:click="markBackorder" data-action-variant="primary">
                        {{ __('sales_orders.btn_backorder') }}
                    </flux:button>
                @endif

                @if ($order->order_status === 'on_hold' && in_array($order->fulfillment_status, ['unfulfilled', 'ready'], true))
                    <flux:button class="action-button-md" type="button" size="sm" variant="primary" wire:click="releaseHold" data-action-variant="primary">
                        {{ __('sales_orders.btn_release_hold') }}
                    </flux:button>
                @endif

                @if ($order->order_status === 'backorder' && in_array($order->fulfillment_status, ['unfulfilled', 'ready'], true))
                    <flux:button class="action-button-md" type="button" size="sm" variant="primary" wire:click="releaseBackorder" data-action-variant="primary">
                        {{ __('sales_orders.btn_release_backorder') }}
                    </flux:button>
                @endif

                @if ($editable && ! $editingLines)
                    <flux:button class="action-button-md" type="button" size="sm" variant="primary" wire:click="editLines" data-action-variant="primary">
                        {{ __('sales_orders.btn_edit_lines') }}
                    </flux:button>
                @endif

                <flux:button class="action-button-md" href="{{ route('sales.orders.issues.create', $order) }}" size="sm" variant="primary" wire:navigate>
                    {{ __('issues.btn_create_from_order') }}
                </flux:button>

                @if ($canReship)
                    <flux:button class="action-button-md" type="button" size="sm" variant="primary" wire:click="reship" data-action-variant="primary">
                        {{ __('outbound.reship') }}
                    </flux:button>
                @endif
            </div>

            @if (
                in_array($order->order_status, ['pending', 'on_hold', 'backorder'], true)
                && in_array($order->fulfillment_status, ['unfulfilled', 'ready'], true)
            )
                <div class="sales-order-detail-actions-danger" data-testid="sales-order-detail-actions-danger">
                    <flux:button class="action-button-md" type="button" size="sm" variant="danger" wire:click="cancelOrder" wire:confirm="{{ __('sales_orders.btn_cancel_order') }}?" data-action-variant="danger">
                        {{ __('sales_orders.btn_cancel_order') }}
                    </flux:button>
                    <flux:button class="action-button-md" type="button" size="sm" variant="danger" wire:click="deleteOrder" wire:confirm="{{ __('sales_orders.btn_delete_order') }}?" data-action-variant="danger">
                        {{ __('sales_orders.btn_delete_order') }}
                    </flux:button>
                </div>
            @endif
        </div>
    </section>

    @if ($relatedOrders->isNotEmpty())
        <section class="table-shell flux-panel form-panel">
            <div class="form-panel-header">
                <div>
                    <strong>{{ __('sales_orders.related_orders_label') }}</strong>
                    <span>{{ $relatedOrders->count() }} related</span>
                </div>
            </div>
            <div class="active-filter-row">
                @foreach ($relatedOrders as $relatedOrder)
                    <flux:button href="{{ route('sales.orders.show', $relatedOrder) }}" size="xs" variant="outline" wire:navigate>
                        {{ $relatedOrder->platform_order_id ?: '#'.$relatedOrder->id }}
                    </flux:button>
                @endforeach
            </div>
        </section>
    @endif

    @if ($order->issues->isNotEmpty())
        <section class="table-shell flux-panel form-panel">
            <div class="form-panel-header">
                <div>
                    <strong>{{ __('issues.linked_issues_title') }}</strong>
                    <span>{{ __('issues.linked_issues_hint') }}</span>
                </div>
            </div>
            <flux:table class="data-table">
                <flux:table.columns>
                    <flux:table.column>{{ __('issues.col_issue_no') }}</flux:table.column>
                    <flux:table.column>{{ __('issues.col_type') }}</flux:table.column>
                    <flux:table.column>{{ __('issues.col_status') }}</flux:table.column>
                    <flux:table.column>{{ __('issues.col_updated') }}</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($order->issues as $case)
                        <flux:table.row :key="$case->id">
                            <flux:table.cell>
                                <x-record-ref-link
                                    :href="route('issues.show', $case)"
                                    :value="$case->issue_no"
                                />
                            </flux:table.cell>
                            <flux:table.cell>{{ $case->typeLabel() }}</flux:table.cell>
                            <flux:table.cell>
                                <x-status-badge :status="$case->status" :label="$case->statusLabel()" />
                            </flux:table.cell>
                            <flux:table.cell>{{ $case->updated_at->format('Y-m-d H:i') }}</flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </section>
    @endif

    @if ($order->outboundOrders->isNotEmpty())
        <section class="table-shell flux-panel form-panel">
            <div class="form-panel-header">
                <div>
                    <strong>{{ __('outbound.shipments_heading') }}</strong>
                    <span>{{ $order->outboundOrders->count() }} shipments</span>
                </div>
            </div>
            <flux:table class="data-table">
                <flux:table.columns>
                    <flux:table.column>{{ __('outbound.col_ref') }}</flux:table.column>
                    <flux:table.column>{{ __('outbound.col_status') }}</flux:table.column>
                    <flux:table.column>{{ __('outbound.field_reason') }}</flux:table.column>
                    <flux:table.column>{{ __('issues.col_issue_no') }}</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($order->outboundOrders as $outbound)
                        <flux:table.row :key="'shipment-'.$outbound->id">
                            <flux:table.cell>
                                <x-record-ref-link
                                    :href="route('outbound.show', $outbound)"
                                    :value="$outbound->ref"
                                />
                            </flux:table.cell>
                            <flux:table.cell><x-status-badge :status="$outbound->status" :label="__('outbound.status_'.$outbound->status)" /></flux:table.cell>
                            <flux:table.cell>
                                {{ $outbound->reason === \App\Models\OutboundOrder::REASON_RE_SHIP ? __('outbound.shipment_reship') : __('outbound.shipment_original') }}
                            </flux:table.cell>
                            <flux:table.cell>
                                @if ($outbound->issue)
                                    <x-record-ref-link
                                        :href="route('issues.show', $outbound->issue)"
                                        :value="$outbound->issue->issue_no"
                                    />
                                @else
                                    -
                                @endif
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </section>
    @endif

    <section class="table-shell flux-panel form-panel">
        <div class="form-panel-header sales-order-recipient-header">
            <div>
                <strong>{{ __('sales_orders.field_recipient') }}</strong>
                @if ($relatedOrders->isEmpty())
                    <span>{{ __('sales_orders.related_orders_none') }}</span>
                @endif
            </div>

            @if (! $editingRecipient)
                <flux:button class="action-button-md" type="button" size="sm" variant="primary" wire:click="editRecipient" data-testid="edit-recipient-button">
                    {{ __('sales_orders.btn_edit_recipient') }}
                </flux:button>
            @endif
        </div>

        @if ($editingRecipient)
            <div class="form-grid three">
                <flux:input wire:model="editRecipientName" :label="__('sales_orders.field_recipient_name')" />
                <flux:input wire:model="editRecipientPhone" :label="__('sales_orders.field_recipient_phone')" />
                <flux:input wire:model="editRecipientCountryCode" maxlength="2" :label="__('sales_orders.field_country_code')" />
                <flux:input wire:model="editRecipientPostalCode" :label="__('sales_orders.field_postal_code')" />
                <flux:input wire:model="editRecipientState" :label="__('sales_orders.field_state')" />
                <flux:input wire:model="editRecipientCity" :label="__('sales_orders.field_city')" />
                <flux:input wire:model="editRecipientAddressLine1" :label="__('sales_orders.field_address_line1')" />
                <flux:input wire:model="editRecipientAddressLine2" :label="__('sales_orders.field_address_line2')" />
            </div>

            @foreach (['recipient_name', 'recipient_phone', 'recipient_country_code', 'recipient_postal_code', 'recipient_state', 'recipient_city', 'recipient_address_line1', 'recipient_address_line2'] as $field)
                @error($field) <p class="form-error">{{ $message }}</p> @enderror
            @endforeach

            <div class="form-actions">
                <flux:button type="button" variant="outline" wire:click="cancelEditRecipient">
                    {{ __('sales_orders.btn_cancel_edit') }}
                </flux:button>
                <flux:button type="button" variant="primary" wire:click="saveRecipient">
                    {{ __('sales_orders.btn_save_recipient') }}
                </flux:button>
            </div>
        @else
            <div class="form-grid three">
                <div><span class="subtle">{{ __('sales_orders.field_recipient_name') }}</span><strong>{{ $order->recipient_name ?: '-' }}</strong></div>
                <div><span class="subtle">{{ __('sales_orders.field_recipient_phone') }}</span><strong>{{ $order->recipient_phone ?: '-' }}</strong></div>
                <div><span class="subtle">{{ __('sales_orders.field_country_code') }}</span><strong>{{ $order->recipient_country_code ?: '-' }}</strong></div>
                <div><span class="subtle">{{ __('sales_orders.field_postal_code') }}</span><strong>{{ $order->recipient_postal_code ?: '-' }}</strong></div>
                <div><span class="subtle">{{ __('sales_orders.field_state') }}</span><strong>{{ $order->recipient_state ?: '-' }}</strong></div>
                <div><span class="subtle">{{ __('sales_orders.field_city') }}</span><strong>{{ $order->recipient_city ?: '-' }}</strong></div>
                <div><span class="subtle">{{ __('sales_orders.field_address_line1') }}</span><strong>{{ $order->recipient_address_line1 ?: '-' }}</strong></div>
                <div><span class="subtle">{{ __('sales_orders.field_address_line2') }}</span><strong>{{ $order->recipient_address_line2 ?: '-' }}</strong></div>
            </div>
        @endif
    </section>

    <section class="table-shell flux-panel form-panel">
        <div class="form-panel-header">
            <div>
                <strong>{{ __('sales_orders.section_lines') }}</strong>
                <span>{{ __('sales_orders.section_lines_hint') }}</span>
            </div>
        </div>

        @if ($editingLines)
            @foreach ($draftLines as $index => $line)
                @php
                    $skuOptions = collect($skuOptionsByLine[$index] ?? [])->map(fn ($sku) => [
                        'value' => $sku->id,
                        'label' => $sku->sku,
                        'meta' => trim(($sku->stockItem?->code ? $sku->stockItem->code.' / ' : '').($sku->displayName() ?: '')),
                    ]);
                    $selectedSku = $skuOptions->firstWhere('value', (int) ($line['sku_id'] ?? 0));
                @endphp
                <div class="line-row">
                    <x-searchable-select
                        wire:key="sales-order-detail-sku-picker-{{ $index }}-{{ md5(($line['sku_id'] ?? '').'|'.$order->id) }}"
                        :label="__('sales_orders.field_sku')"
                        model="draftLines.{{ $index }}.sku_id"
                        search-model="draftLineSkuSearches.{{ $index }}"
                        :options="$skuOptions"
                        :selected-label="$selectedSku['label'] ?? ($draftLineSkuSearches[$index] ?? '')"
                        :placeholder="__('inventory.search_placeholder')"
                        empty-label="No results"
                        required
                    />
                    <flux:input wire:model="draftLines.{{ $index }}.quantity" type="number" min="1" step="1" required :label="__('sales_orders.field_quantity')" />
                    <flux:input wire:model="draftLines.{{ $index }}.note" :label="__('sales_orders.field_note')" />
                    <button type="button" class="remove-line-btn {{ count($draftLines) <= 1 ? 'invisible' : '' }}" wire:click="removeDraftLine({{ $index }})">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z"/></svg>
                    </button>
                </div>

                @error("lines.{$index}.sku_id") <p class="form-error">{{ $message }}</p> @enderror
            @endforeach

            @error('lines') <p class="form-error">{{ $message }}</p> @enderror

            <div class="form-actions">
                <flux:button type="button" variant="outline" wire:click="addDraftLine">{{ __('sales_orders.btn_add_line') }}</flux:button>
                <flux:button type="button" variant="outline" wire:click="cancelEditLines">{{ __('sales_orders.btn_cancel_edit') }}</flux:button>
                <flux:button type="button" variant="primary" wire:click="saveLines">{{ __('sales_orders.btn_save_lines') }}</flux:button>
            </div>
        @else
            <flux:table class="data-table">
                <flux:table.columns>
                    <flux:table.column>{{ __('sales_orders.col_sku') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('sales_orders.col_qty') }}</flux:table.column>
                    <flux:table.column>{{ __('sales_orders.col_line_status') }}</flux:table.column>
                    <flux:table.column>{{ __('sales_orders.field_note') }}</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @forelse ($order->lines as $line)
                        <flux:table.row :key="$line->id">
                            <flux:table.cell>
                                <strong>{{ $line->sku->sku }}</strong>
                                <span class="subtle">{{ $line->sku->displayName() }} / {{ $line->sku->stockItem?->code ?? __('common.sku_types.virtual_bundle') }}</span>
                            </flux:table.cell>
                            <flux:table.cell align="end">{{ number_format($line->quantity) }}</flux:table.cell>
                            <flux:table.cell>
                                <flux:badge color="{{ $line->line_status === 'cancelled' ? 'red' : 'blue' }}">
                                    {{ $this->lineStatusLabel($line->line_status) }}
                                </flux:badge>
                            </flux:table.cell>
                            <flux:table.cell>{{ $line->note ?: '-' }}</flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="4">{{ __('sales_orders.no_lines') }}</flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        @endif
    </section>

    <section class="table-shell flux-panel form-panel">
        <div class="form-grid">
            <div>
                <span class="subtle">{{ __('sales_orders.field_note') }}</span>
                <textarea
                    class="table-control"
                    rows="3"
                    aria-label="{{ __('sales_orders.field_note') }}"
                    x-on:change="$wire.updateNote($event.target.value)"
                >{{ $order->note }}</textarea>
            </div>
        </div>
    </section>

    @if ($activities->isNotEmpty())
        <section class="table-shell flux-panel form-panel">
            <div class="form-panel-header">
                <div>
                    <strong>Activity</strong>
                    <span>{{ $activities->count() }} entries</span>
                </div>
            </div>
            @foreach ($activities as $activity)
                <p class="subtle">{{ $activity->created_at->format('Y-m-d H:i') }} - {{ $activity->description }} - {{ $activity->causer?->name ?? '-' }}</p>
            @endforeach
        </section>
    @endif
</div>
