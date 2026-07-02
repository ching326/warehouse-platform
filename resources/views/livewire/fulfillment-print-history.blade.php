<div class="fulfillment-print-history-page">
    <x-flash-toast />

    <x-page-panel-header
        :title="__('fulfillment.print_history_title')"
        :subtitle="__('fulfillment.print_history_subtitle')"
    />

    <section class="table-shell flux-panel">
        <div class="form-grid three">
            @if ($showTenantFilter)
                <flux:select wire:model.live="tenantId" :label="__('fulfillment.field_tenant')">
                    <flux:select.option value="">{{ __('common.all_tenants') }}</flux:select.option>
                    @foreach ($tenants as $tenant)
                        <flux:select.option value="{{ $tenant->id }}">
                            {{ $tenant->code }} - {{ $tenant->name }}
                        </flux:select.option>
                    @endforeach
                </flux:select>
            @endif

            <flux:select wire:model.live="type" :label="__('outbound.col_export_type')">
                <flux:select.option value="">{{ __('fulfillment.print_history_all_types') }}</flux:select.option>
                @foreach ($typeOptions as $value => $label)
                    <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:input type="date" wire:model.live="dateFrom" :label="__('sales_orders.field_date_from')" />
            <flux:input type="date" wire:model.live="dateTo" :label="__('sales_orders.field_date_to')" />
            <flux:input wire:model.live.debounce.300ms="search" :label="__('common.search')" :placeholder="__('fulfillment.print_history_search_placeholder')" />
        </div>

        <flux:table :paginate="$batches" class="data-table">
            <flux:table.columns>
                <flux:table.column>{{ __('outbound.col_exported_at') }}</flux:table.column>
                <flux:table.column>{{ __('outbound.col_export_type') }}</flux:table.column>
                <flux:table.column>{{ __('fulfillment.field_tenant') }}</flux:table.column>
                <flux:table.column align="end">{{ __('fulfillment.print_history_orders') }}</flux:table.column>
                <flux:table.column>{{ __('outbound.col_export_file') }}</flux:table.column>
                <flux:table.column>{{ __('outbound.col_exported_by') }}</flux:table.column>
                <flux:table.column>{{ __('fulfillment.col_actions') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($batches as $batch)
                    <flux:table.row :key="$batch->id">
                        <flux:table.cell>
                            <strong>{{ $batch->exported_at?->format('Y-m-d') ?: '-' }}</strong>
                            <span class="subtle">{{ $batch->exported_at?->format('H:i') ?: '-' }}</span>
                        </flux:table.cell>
                        <flux:table.cell>{{ $this->typeLabel((string) $batch->carrier) }}</flux:table.cell>
                        <flux:table.cell>
                            <strong>{{ $batch->tenant?->code ?: '-' }}</strong>
                            <span class="subtle">{{ $batch->tenant?->name ?: '-' }}</span>
                        </flux:table.cell>
                        <flux:table.cell align="end">{{ number_format((int) $batch->order_count) }}</flux:table.cell>
                        <flux:table.cell>
                            <span title="{{ $batch->file_name }}">{{ $batch->file_name }}</span>
                        </flux:table.cell>
                        <flux:table.cell>{{ $batch->exportedBy?->name ?: '-' }}</flux:table.cell>
                        <flux:table.cell>
                            <flux:button
                                href="{{ route('courier-export-batches.download', $batch) }}"
                                size="sm"
                                variant="outline"
                            >
                                {{ __('fulfillment.print_history_download') }}
                            </flux:button>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="7">
                            <div class="empty-state">{{ __('fulfillment.print_history_empty') }}</div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </section>
</div>
