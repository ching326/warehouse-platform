@php
    $orderCount = collect($parsedRows)->pluck('platform_order_id')->filter()->unique()->count();
    $lineCount = count($parsedRows);
@endphp

<div class="sales-order-import-page">
    @if (session('status'))
        <div class="active-filter-row">
            <flux:badge color="green">{{ session('status') }}</flux:badge>
        </div>
    @endif

    @if (session('error'))
        <div class="active-filter-row">
            <flux:badge color="red">{{ session('error') }}</flux:badge>
        </div>
    @endif

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

            <label>
                <span>{{ __('sales_orders.import_file_label') }}</span>
                <input type="file" wire:model="file" accept="{{ $importFormat === 'amazon_report' ? '.txt' : '.csv,.txt,.xlsx' }}">
            </label>
        </div>

        @error('shopId') <p class="form-error">{{ $message }}</p> @enderror
        @error('file') <p class="form-error">{{ $message }}</p> @enderror

        <div class="form-actions">
            <flux:button type="button" variant="primary" wire:click="parse">
                {{ __('sales_orders.import_parse_btn') }}
            </flux:button>
        </div>
    </section>

    @if ($parsed)
        <section class="table-shell flux-panel form-panel">
            <div class="form-panel-header">
                <div>
                    <strong>{{ __('sales_orders.import_summary', ['orders' => $orderCount, 'lines' => $lineCount]) }}</strong>
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
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($parsedRows as $row)
                        <flux:table.row :key="$row['row'].'-'.$row['sku'].'-'.$row['platform_order_id']">
                            <flux:table.cell>{{ $row['row'] }}</flux:table.cell>
                            <flux:table.cell>
                                <flux:badge color="{{ $row['errors'] === [] ? 'green' : 'red' }}">
                                    {{ $row['errors'] === [] ? __('sales_orders.import_row_ok') : __('sales_orders.import_col_errors') }}
                                </flux:badge>
                            </flux:table.cell>
                            <flux:table.cell>{{ $row['platform_order_id'] ?: '-' }}</flux:table.cell>
                            <flux:table.cell>{{ $row['sku'] ?: '-' }}</flux:table.cell>
                            <flux:table.cell align="end">{{ $row['quantity'] ?: '-' }}</flux:table.cell>
                            <flux:table.cell>
                                {{ $row['errors'] === [] ? '-' : implode(' ', $row['errors']) }}
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </section>
    @endif
</div>
