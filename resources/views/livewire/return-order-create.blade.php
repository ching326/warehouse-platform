<div class="return-order-create-page">
<form wire:submit="save" class="form-stack">
<section class="table-shell flux-panel form-panel"><div class="form-panel-header"><div><strong>{{ __('return_orders.section_header') }}</strong><span>{{ __('return_orders.create_page_subtitle') }}</span></div></div><div class="form-grid three">
@if($showTenantSelect)<flux:select wire:model.live="tenantId" required :label="__('return_orders.field_tenant')"><flux:select.option value="">{{ __('common.select_tenant') }}</flux:select.option>@foreach($tenants as $tenant)<flux:select.option value="{{ $tenant->id }}">{{ $tenant->code }} - {{ $tenant->name }}</flux:select.option>@endforeach</flux:select>@endif
<flux:select wire:model="warehouseId" :label="__('return_orders.field_warehouse')"><flux:select.option value="">{{ __('return_orders.select_warehouse') }}</flux:select.option>@foreach($warehouses as $warehouse)<flux:select.option value="{{ $warehouse->id }}">{{ $warehouse->code }} - {{ $warehouse->name }}</flux:select.option>@endforeach</flux:select>
<flux:select wire:model="return_type" required :label="__('return_orders.field_return_type')">@foreach($types as $value=>$label)<flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>@endforeach</flux:select>
<flux:select wire:model="return_reason" :label="__('return_orders.field_reason')"><flux:select.option value="">-</flux:select.option>@foreach($reasons as $value=>$label)<flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>@endforeach</flux:select>
<flux:select wire:model="issueId" :label="__('return_orders.field_issue')"><flux:select.option value="">-</flux:select.option>@foreach($issues as $issue)<flux:select.option value="{{ $issue->id }}">{{ $issue->issue_no }}</flux:select.option>@endforeach</flux:select>
<flux:select wire:model="salesOrderId" :label="__('return_orders.field_sales_order')"><flux:select.option value="">-</flux:select.option>@foreach($salesOrders as $order)<flux:select.option value="{{ $order->id }}">{{ $order->platform_order_id ?: '#'.$order->id }}</flux:select.option>@endforeach</flux:select>
<label><span>{{ __('return_orders.field_tracking') }}</span><input type="text" wire:model="tracking_no"></label><label><span>{{ __('return_orders.field_original_order_no') }}</span><input type="text" wire:model="original_order_no"></label><label><span>{{ __('return_orders.field_external_return_id') }}</span><input type="text" wire:model="external_return_id"></label><label><span>{{ __('return_orders.field_customer_name') }}</span><input type="text" wire:model="customer_name"></label><label><span>{{ __('return_orders.field_sender_name') }}</span><input type="text" wire:model="sender_name"></label><label><span>{{ __('return_orders.field_shipping_method') }}</span><input type="text" wire:model="shipping_method"></label><flux:select wire:model="payment_type" :label="__('return_orders.field_payment_type')">@foreach($paymentTypes as $value=>$label)<flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>@endforeach</flux:select><label><span>{{ __('return_orders.field_expected_arrival') }}</span><input type="date" wire:model="expected_arrival_date"></label><label><span>{{ __('return_orders.field_package_count') }}</span><input type="number" min="1" wire:model="package_count"></label><label class="span-3"><span>{{ __('return_orders.field_note') }}</span><textarea wire:model="note" rows="3"></textarea></label>
</div></section>
<section class="table-shell flux-panel form-panel">
    <div class="form-panel-header">
        <div><strong>{{ __('return_orders.section_lines') }}</strong></div>
        <flux:button type="button" wire:click="addLine">{{ __('return_orders.btn_add_line') }}</flux:button>
    </div>
    @foreach($lines as $index=>$line)
        @php
            $skuOptions = collect($skuOptionsByLine[$index] ?? [])->map(fn ($sku) => [
                'value' => $sku->id,
                'label' => $sku->sku,
                'meta' => trim(($sku->stockItem?->code ? $sku->stockItem->code.' / ' : '').($sku->stockItem?->name ?? $sku->name ?? '')),
            ]);
            $selectedSku = $skuOptions->firstWhere('value', (int) ($line['sku_id'] ?? 0));
        @endphp
        <div class="form-grid three">
            <x-searchable-select
                wire:key="return-create-sku-picker-{{ $index }}-{{ md5($tenantId.'|'.($line['sku_id'] ?? '')) }}"
                :label="__('return_orders.field_sku')"
                model="lines.{{ $index }}.sku_id"
                search-model="skuSearches.{{ $index }}"
                :options="$skuOptions"
                :selected-label="$selectedSku['label'] ?? ($skuSearches[$index] ?? '')"
                :placeholder="$tenantId === '' ? __('common.select_tenant') : __('inventory.search_placeholder')"
                empty-label="No results"
                required
                :disabled="$tenantId === ''"
            />
            <label><span>{{ __('return_orders.field_expected_qty') }}</span><input type="number" min="1" wire:model="lines.{{ $index }}.expected_qty"></label>
            <label><span>{{ __('return_orders.field_line_note') }}</span><input type="text" wire:model="lines.{{ $index }}.note"></label>
            <flux:button type="button" variant="danger" wire:click="removeLine({{ $index }})">{{ __('common.remove') }}</flux:button>
        </div>
    @endforeach
</section>
<div class="form-actions"><flux:button href="{{ route('return-orders.index') }}" variant="outline" wire:navigate>{{ __('common.cancel') }}</flux:button><flux:button type="submit" variant="primary">{{ __('return_orders.btn_save') }}</flux:button></div>
</form></div>
