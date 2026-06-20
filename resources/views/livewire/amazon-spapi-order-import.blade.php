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
                <strong>{{ __('amazon_spapi_import.page_title') }}</strong>
                <span>{{ __('amazon_spapi_import.page_subtitle') }}</span>
            </div>
            <flux:button href="{{ route('sales.orders.import') }}" variant="outline" wire:navigate>
                {{ __('sales_orders.import_page_title') }}
            </flux:button>
        </div>

        <div class="form-grid">
            <flux:select wire:model.live="shopId" :label="__('amazon_spapi_import.shop')">
                <flux:select.option value="">{{ __('amazon_spapi_import.shop') }}</flux:select.option>
                @foreach ($shops as $shop)
                    <flux:select.option value="{{ $shop->id }}">
                        {{ $shop->tenant->code }} / {{ $shop->code }} - {{ $shop->name }}
                    </flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="windowType" :label="__('amazon_spapi_import.window_type')">
                <flux:select.option value="last_updated">{{ __('amazon_spapi_import.window_last_updated') }}</flux:select.option>
                <flux:select.option value="created">{{ __('amazon_spapi_import.window_created') }}</flux:select.option>
            </flux:select>
        </div>

        <div class="form-grid">
            <flux:input wire:model.live="windowFrom" type="datetime-local" :label="__('amazon_spapi_import.from')" />
            <flux:input wire:model.live="windowTo" type="datetime-local" :label="__('amazon_spapi_import.to')" />
        </div>

        @error('shopId') <p class="form-error">{{ $message }}</p> @enderror
        @error('windowFrom') <p class="form-error">{{ $message }}</p> @enderror
        @error('windowTo') <p class="form-error">{{ $message }}</p> @enderror
        @error('api') <p class="form-error">{{ $message }}</p> @enderror

        <div class="form-actions">
            <flux:button type="button" variant="outline" wire:click="useDefaultWindow">
                {{ __('amazon_spapi_import.btn_default_window') }}
            </flux:button>
            <flux:button type="button" variant="outline" wire:click="resetForm">
                {{ __('amazon_spapi_import.btn_reset') }}
            </flux:button>
            <flux:button type="button" variant="primary" wire:click="fetchPreview">
                {{ __('amazon_spapi_import.btn_fetch_preview') }}
            </flux:button>
        </div>
    </section>

    @if ($parsed)
        <section class="summary-grid movement-summary">
            <div class="summary-card">
                <span>{{ __('amazon_spapi_import.summary_api_orders') }}</span>
                <strong>{{ $summary['api_orders'] }}</strong>
            </div>
            <div class="summary-card">
                <span>{{ __('amazon_spapi_import.summary_new_orders') }}</span>
                <strong>{{ $summary['new_orders'] }}</strong>
            </div>
            <div class="summary-card">
                <span>{{ __('amazon_spapi_import.summary_duplicates') }}</span>
                <strong>{{ $summary['duplicates'] }}</strong>
            </div>
            <div class="summary-card">
                <span>{{ __('amazon_spapi_import.summary_missing_sku') }}</span>
                <strong>{{ $summary['missing_sku'] }}</strong>
            </div>
            <div class="summary-card">
                <span>{{ __('amazon_spapi_import.summary_cancel_requested') }}</span>
                <strong>{{ $summary['cancel_requested'] }}</strong>
            </div>
            <div class="summary-card">
                <span>{{ __('amazon_spapi_import.summary_skipped') }}</span>
                <strong>{{ $summary['skipped'] }}</strong>
            </div>
        </section>

        @if ($warning)
            <div class="active-filter-row">
                <flux:badge color="amber">{{ $warning }}</flux:badge>
            </div>
        @endif

        <section class="table-shell flux-panel form-panel">
            <div class="form-panel-header">
                <div>
                    <strong>{{ __('amazon_spapi_import.preview_title') }}</strong>
                    <span>{{ $hasErrors ? __('sales_orders.import_has_errors') : __('sales_orders.import_row_ok') }}</span>
                </div>
                <flux:button type="button" variant="primary" wire:click="confirmImport" :disabled="$hasErrors || $summary['missing_sku'] > 0">
                    {{ __('amazon_spapi_import.btn_confirm_import') }}
                </flux:button>
            </div>

            <flux:table class="data-table">
                <flux:table.columns>
                    <flux:table.column>{{ __('amazon_spapi_import.col_row') }}</flux:table.column>
                    <flux:table.column>{{ __('amazon_spapi_import.col_status') }}</flux:table.column>
                    <flux:table.column>{{ __('amazon_spapi_import.col_order') }}</flux:table.column>
                    <flux:table.column>{{ __('amazon_spapi_import.col_sku') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('amazon_spapi_import.col_qty') }}</flux:table.column>
                    <flux:table.column>{{ __('amazon_spapi_import.col_recipient') }}</flux:table.column>
                    <flux:table.column>{{ __('amazon_spapi_import.col_address') }}</flux:table.column>
                    <flux:table.column>{{ __('amazon_spapi_import.col_shipping') }}</flux:table.column>
                    <flux:table.column>{{ __('amazon_spapi_import.col_notes') }}</flux:table.column>
                    <flux:table.column>{{ __('amazon_spapi_import.col_action') }}</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($parsedRows as $row)
                        <flux:table.row :key="$row['row'].'-'.$row['platform_order_id'].'-'.$row['sku']">
                            <flux:table.cell>{{ $row['row'] }}</flux:table.cell>
                            <flux:table.cell>
                                @php
                                    $status = $row['preview_status'] ?? 'ready';
                                    $badgeColor = match ($status) {
                                        'ready' => 'green',
                                        'duplicate', 'existing_cancel_requested' => 'amber',
                                        'missing_sku' => 'red',
                                        default => 'zinc',
                                    };
                                @endphp
                                <flux:badge color="{{ $badgeColor }}">
                                    {{ __('amazon_spapi_import.status_'.$status) }}
                                </flux:badge>
                            </flux:table.cell>
                            <flux:table.cell>{{ $row['platform_order_id'] ?: '-' }}</flux:table.cell>
                            <flux:table.cell>{{ $row['sku'] ?: '-' }}</flux:table.cell>
                            <flux:table.cell align="end">{{ $row['quantity'] ?: '-' }}</flux:table.cell>
                            <flux:table.cell>{{ $row['recipient_name'] ?: '-' }}</flux:table.cell>
                            <flux:table.cell>
                                {{ trim(($row['recipient_postal_code'] ?? '').' '.($row['recipient_state'] ?? '').' '.($row['recipient_city'] ?? '').' '.($row['recipient_address_line1'] ?? '')) ?: '-' }}
                            </flux:table.cell>
                            <flux:table.cell>{{ $row['shipping_method'] ?: '-' }}</flux:table.cell>
                            <flux:table.cell>
                                @if (($row['errors'] ?? []) !== [])
                                    {{ implode(' ', $row['errors']) }}
                                @else
                                    {{ $row['order_note'] ?: '-' }}
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>
                                @if (($row['preview_status'] ?? '') === 'missing_sku' && filled($row['sku'] ?? ''))
                                    <flux:button
                                        as="a"
                                        variant="primary"
                                        size="sm"
                                        href="{{ route('skus.create', [
                                            'tenant_id' => $row['tenant_id'] ?? '',
                                            'shop_id' => $row['shop_id'] ?? '',
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
