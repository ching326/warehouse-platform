<div class="stock-count-index-page">
    <x-page-panel-header :title="__('stock_counts.page_title')" :subtitle="__('stock_counts.page_subtitle')">
        <x-slot:actions>
            <flux:button href="{{ route('stock-counts.create') }}" variant="primary" wire:navigate>{{ __('stock_counts.btn_new') }}</flux:button>
            <flux:button href="{{ route('stock-counts.import') }}" variant="primary" wire:navigate>{{ __('stock_counts.btn_import') }}</flux:button>
        </x-slot:actions>
    </x-page-panel-header>

    <section class="table-shell flux-panel">
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('stock_counts.col_count_no') }}</flux:table.column>
                <flux:table.column>{{ __('common.tenant') }}</flux:table.column>
                <flux:table.column>{{ __('common.warehouse') }}</flux:table.column>
                <flux:table.column>{{ __('stock_counts.col_source') }}</flux:table.column>
                <flux:table.column align="end">{{ __('stock_counts.col_total_lines') }}</flux:table.column>
                <flux:table.column align="end">{{ __('stock_counts.col_adjusted_lines') }}</flux:table.column>
                <flux:table.column align="end">{{ __('stock_counts.col_no_change_lines') }}</flux:table.column>
                <flux:table.column align="end">{{ __('stock_counts.col_failed_lines') }}</flux:table.column>
                <flux:table.column>{{ __('stock_counts.col_created_by') }}</flux:table.column>
                <flux:table.column>{{ __('stock_counts.col_created_at') }}</flux:table.column>
                <flux:table.column>{{ __('stock_counts.col_posted_at') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($runs as $run)
                    <flux:table.row :key="$run->id">
                        <flux:table.cell><a href="{{ route('stock-counts.show', $run) }}" wire:navigate>#{{ $run->id }}</a></flux:table.cell>
                        <flux:table.cell>{{ $run->tenant->code }}</flux:table.cell>
                        <flux:table.cell>{{ $run->warehouse->code }}</flux:table.cell>
                        <flux:table.cell>{{ __('stock_counts.sources.'.$run->source) }}</flux:table.cell>
                        <flux:table.cell align="end">{{ number_format($run->total_lines) }}</flux:table.cell>
                        <flux:table.cell align="end">{{ number_format($run->adjusted_lines) }}</flux:table.cell>
                        <flux:table.cell align="end">{{ number_format($run->no_change_lines) }}</flux:table.cell>
                        <flux:table.cell align="end">{{ number_format($run->failed_lines) }}</flux:table.cell>
                        <flux:table.cell>{{ $run->createdBy?->name ?? '-' }}</flux:table.cell>
                        <flux:table.cell>{{ $run->created_at?->format('Y-m-d H:i') }}</flux:table.cell>
                        <flux:table.cell>{{ $run->posted_at?->format('Y-m-d H:i') ?? '-' }}</flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="11"><div class="empty-state">{{ __('stock_counts.empty_state') }}</div></flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>

        <div class="table-pagination-row">
            <span></span>
            <flux:pagination :paginator="$runs" />
        </div>
    </section>
</div>
