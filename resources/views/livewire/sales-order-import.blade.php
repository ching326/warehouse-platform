@php
    $parsedRowsCollection = collect($parsedRows);
    $orderGroups = $parsedRowsCollection
        ->filter(fn ($row) => filled($row['platform_order_id'] ?? ''))
        ->groupBy('platform_order_id');
    $orderCount = $orderGroups->count();
    $duplicateOrderCount = $orderGroups
        ->filter(fn ($rows) => $rows->contains(fn ($row) => $row['is_duplicate'] ?? false))
        ->count();
    $validOrderCount = $orderGroups
        ->filter(fn ($rows) => ! $rows->contains(fn ($row) => $row['is_duplicate'] ?? false)
            && $rows->every(fn ($row) => ($row['errors'] ?? []) === []))
        ->count();
    $lineCount = count($parsedRows);
@endphp

<div
    class="sales-order-import-page"
    x-data="{ uploadingImportFile: false }"
    x-on:livewire-upload-start="uploadingImportFile = true"
    x-on:livewire-upload-finish="uploadingImportFile = false"
    x-on:livewire-upload-error="uploadingImportFile = false"
    x-on:livewire-upload-cancel="uploadingImportFile = false"
>
    <x-flash-toast />
<section class="table-shell flux-panel form-panel">
        <div class="form-panel-header">
            <div>
                <strong>{{ __('sales_orders.import_page_title') }}</strong>
                <span>{{ __('sales_orders.import_page_subtitle') }}</span>
            </div>
            <flux:button href="{{ route('sales.orders.index') }}" variant="outline" wire:navigate>
                {{ __('sales_orders.btn_back_orders') }}
            </flux:button>
        </div>

        <div class="form-grid">
            <flux:select wire:model.live="importFormat" required :label="__('sales_orders.import_format')">
                @foreach ($importFormatOptions as $value => $label)
                    <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="shopId" required :label="__('sales_orders.field_shop')">
                <flux:select.option value="">{{ __('sales_orders.field_shop') }}</flux:select.option>
                @foreach ($shops as $shop)
                    <flux:select.option value="{{ $shop->id }}">
                        {{ $shop->tenant->code }} / {{ $shop->code }} - {{ $shop->name }}
                    </flux:select.option>
                @endforeach
            </flux:select>

            <label
                class="tracking-import-dropzone form-grid-wide"
                x-data="{ dragging: false, fileName: @js($file?->getClientOriginalName() ?? '') }"
                x-bind:class="{ 'is-dragging': dragging }"
                x-on:dragover.prevent="dragging = true"
                x-on:dragleave.prevent="dragging = false"
                x-on:drop.prevent="
                    dragging = false;
                    const input = $refs.salesOrderImportFile;
                    input.files = $event.dataTransfer.files;
                    fileName = input.files.length ? input.files[0].name : '';
                    input.dispatchEvent(new Event('change', { bubbles: true }));
                "
            >
                <input
                    x-ref="salesOrderImportFile"
                    class="tracking-import-file-input"
                    type="file"
                    wire:model="file"
                    accept="{{ $importFormat === 'amazon_report' ? '.txt' : '.csv,.txt,.xlsx' }}"
                    x-on:change="fileName = $event.target.files.length ? $event.target.files[0].name : ''"
                >
                <strong>{{ __('sales_orders.tracking_import_drop_title') }}</strong>
                <span>{{ __('sales_orders.import_file_label') }}</span>
                <span class="tracking-import-file-name" x-show="fileName" x-text="fileName"></span>
                <span class="subtle" wire:loading wire:target="file">
                    {{ __('sales_orders.import_uploading_file') }}
                </span>
            </label>
        </div>

        @error('shopId') <p class="form-error">{{ $message }}</p> @enderror
        @error('file') <p class="form-error">{{ $message }}</p> @enderror

        <div class="form-actions">
            <span></span>
            <flux:button
                type="button"
                variant="primary"
                wire:click="parse"
                wire:loading.attr="disabled"
                wire:target="file,parse"
                x-bind:disabled="uploadingImportFile"
            >
                {{ __('sales_orders.import_parse_btn') }}
            </flux:button>
        </div>
    </section>

    @if ($parsed)
        <section class="table-shell flux-panel form-panel">
            <div class="form-panel-header">
                <div>
                    <strong>{{ __('sales_orders.import_summary', [
                        'total' => $orderCount,
                        'duplicates' => $duplicateOrderCount,
                        'valid' => $validOrderCount,
                        'lines' => $lineCount,
                    ]) }}</strong>
                    <span>{{ $hasErrors ? __('sales_orders.import_has_errors') : __('sales_orders.import_row_ok') }}</span>
                </div>
                <flux:button type="button" variant="primary" wire:click="import" :disabled="$hasErrors">
                    {{ __('sales_orders.import_confirm_btn') }}
                </flux:button>
            </div>

            <flux:table class="data-table">
                <flux:table.columns>
                    <flux:table.column>{{ __('sales_orders.import_col_row') }}</flux:table.column>
                    <flux:table.column>{{ __('sales_orders.import_col_status') }}</flux:table.column>
                    <flux:table.column>{{ __('sales_orders.import_col_order') }}</flux:table.column>
                    <flux:table.column>{{ __('sales_orders.import_col_sku') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('sales_orders.import_col_qty') }}</flux:table.column>
                    <flux:table.column>{{ __('sales_orders.import_col_errors') }}</flux:table.column>
                    <flux:table.column>{{ __('sales_orders.import_col_action') }}</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($parsedRows as $row)
                        <flux:table.row :key="$row['row'].'-'.$row['sku'].'-'.$row['platform_order_id']">
                            <flux:table.cell>{{ $row['row'] }}</flux:table.cell>
                            <flux:table.cell>
                                <flux:badge color="{{ ($row['is_duplicate'] ?? false) ? 'amber' : (($row['sku_not_found'] ?? false) ? 'red' : ($row['errors'] === [] ? 'green' : 'red')) }}">
                                    @if ($row['is_duplicate'] ?? false)
                                        {{ __('sales_orders.import_row_duplicate') }}
                                    @elseif ($row['sku_not_found'] ?? false)
                                        {{ __('sales_orders.import_row_sku_not_found') }}
                                    @else
                                        {{ $row['errors'] === [] ? __('sales_orders.import_row_ok') : __('sales_orders.import_col_errors') }}
                                    @endif
                                </flux:badge>
                            </flux:table.cell>
                            <flux:table.cell>{{ $row['platform_order_id'] ?: '-' }}</flux:table.cell>
                            <flux:table.cell>{{ $row['sku'] ?: '-' }}</flux:table.cell>
                            <flux:table.cell align="end">{{ $row['quantity'] ?: '-' }}</flux:table.cell>
                            <flux:table.cell>
                                @if ($row['is_duplicate'] ?? false)
                                    {{ __('sales_orders.import_duplicate_skip') }}
                                @else
                                    {{ $row['errors'] === [] ? '-' : implode(' ', $row['errors']) }}
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>
                                @if (($row['sku_not_found'] ?? false) && filled($row['sku'] ?? '') && filled($row['shop_id'] ?? null))
                                    <flux:button
                                        as="a"
                                        variant="primary"
                                        size="sm"
                                        href="{{ route('skus.create', [
                                            'tenant_id' => $row['tenant_id'] ?? '',
                                            'shop_id' => $row['shop_id'],
                                            'sku' => $row['sku'],
                                            'name' => $row['platform_product_name'] ?? $row['sku'],
                                            'platform_sku' => $row['sku'],
                                        ]) }}"
                                        target="_blank"
                                        rel="noopener"
                                    >
                                        {{ __('sales_orders.import_add_sku') }}
                                    </flux:button>
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
</div>
