<div class="stock-count-show-page">
    <x-page-panel-header :title="__('stock_counts.show_title', ['id' => $run->id])" :subtitle="__('stock_counts.page_subtitle')" />

    <section class="table-shell flux-panel form-panel stock-count-summary-panel">
        <div class="stock-count-section-actions">
            <span></span>
            <flux:button href="{{ route('stock-counts.index') }}" variant="outline" wire:navigate>{{ __('stock_counts.btn_back_to_index') }}</flux:button>
        </div>

        <div class="stock-count-summary-grid">
            <div><span>{{ __('common.tenant') }}</span><strong>{{ $run->tenant->code }}</strong></div>
            <div><span>{{ __('common.warehouse') }}</span><strong>{{ $run->warehouse->code }}</strong></div>
            <div><span>{{ __('stock_counts.col_source') }}</span><strong>{{ __('stock_counts.sources.'.$run->source) }}</strong></div>
            <div><span>{{ __('stock_counts.col_created_by') }}</span><strong>{{ $run->createdBy?->name ?? '-' }}</strong></div>
            <div><span>{{ __('stock_counts.col_posted_at') }}</span><strong>{{ $run->posted_at?->format('Y-m-d H:i') ?? '-' }}</strong></div>
            <div><span>{{ __('stock_counts.col_total_lines') }}</span><strong>{{ number_format($run->total_lines) }}</strong></div>
            <div><span>{{ __('stock_counts.col_adjusted_lines') }}</span><strong>{{ number_format($run->adjusted_lines) }}</strong></div>
            <div><span>{{ __('stock_counts.col_no_change_lines') }}</span><strong>{{ number_format($run->no_change_lines) }}</strong></div>
            <div><span>{{ __('stock_counts.col_failed_lines') }}</span><strong>{{ number_format($run->failed_lines) }}</strong></div>
        </div>
    </section>

    <section class="table-shell flux-panel stock-count-lines-panel">
        <flux:table class="stock-count-lines-table">
            <flux:table.columns>
                <flux:table.column>{{ __('skus.col_stock_item') }}</flux:table.column>
                <flux:table.column align="end">{{ __('stock_counts.col_previous_on_hand') }}</flux:table.column>
                <flux:table.column align="end">{{ __('stock_counts.field_counted_qty') }}</flux:table.column>
                <flux:table.column align="end">{{ __('stock_counts.col_delta') }}</flux:table.column>
                <flux:table.column>{{ __('stock_counts.col_movement') }}</flux:table.column>
                <flux:table.column>{{ __('common.status') }}</flux:table.column>
                <flux:table.column>{{ __('stock_counts.field_line_note') }}</flux:table.column>
                <flux:table.column>{{ __('stock_counts.field_reference_no') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($run->lines as $line)
                    <flux:table.row :key="$line->id">
                        <flux:table.cell>
                            <strong>{{ $line->stockItem->code }}</strong>
                            @if ($line->stockItem->tenant_item_code)
                                <span class="subtle">{{ $line->stockItem->tenant_item_code }}</span>
                            @endif
                            <span class="subtle">{{ $line->stockItem->displayName() }}</span>
                        </flux:table.cell>
                        <flux:table.cell align="end">{{ number_format($line->previous_on_hand_qty) }}</flux:table.cell>
                        <flux:table.cell align="end">{{ number_format($line->counted_qty) }}</flux:table.cell>
                        <flux:table.cell align="end">{{ number_format($line->delta_qty) }}</flux:table.cell>
                        <flux:table.cell>
                            @if ($line->movement_id)
                                <a href="{{ route('inventory.movements.index', ['stock_item_id' => $line->stock_item_id]) }}" class="record-link" wire:navigate>#{{ $line->movement_id }}</a>
                            @else
                                -
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>
                            <x-status-badge :status="$line->status" :label="__('stock_counts.statuses.'.$line->status)" />
                        </flux:table.cell>
                        <flux:table.cell>{{ $line->line_note ?: '-' }}</flux:table.cell>
                        <flux:table.cell>{{ $line->reference_no ?: '-' }}</flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </section>
</div>
