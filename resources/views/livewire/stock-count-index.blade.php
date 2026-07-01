<div class="stock-count-index-page">
    <x-page-panel-header :title="__('stock_counts.page_title')" :subtitle="__('stock_counts.page_subtitle')" />

    <section class="table-shell flux-panel form-panel">
        <div class="form-panel-header">
            <span></span>
            <div class="active-filter-row">
                <flux:button href="{{ route('stock-counts.create') }}" variant="primary" wire:navigate>{{ __('stock_counts.btn_new') }}</flux:button>
                <flux:button href="{{ route('stock-counts.import') }}" variant="primary" wire:navigate>{{ __('stock_counts.btn_import') }}</flux:button>
            </div>
        </div>

        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('stock_counts.col_count_no') }}</flux:table.column>
                <flux:table.column>{{ __('stock_counts.col_tenant_warehouse') }}</flux:table.column>
                <flux:table.column>{{ __('stock_counts.col_source') }}</flux:table.column>
                <flux:table.column>{{ __('stock_counts.col_lines') }}</flux:table.column>
                <flux:table.column>{{ __('stock_counts.col_created_at') }}</flux:table.column>
                <flux:table.column>{{ __('stock_counts.col_posted_at') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($runs as $run)
                    <flux:table.row :key="$run->id">
                        <flux:table.cell><a href="{{ route('stock-counts.show', $run) }}" class="record-link" wire:navigate>#{{ $run->id }}</a></flux:table.cell>
                        <flux:table.cell>
                            <strong>{{ $run->tenant->code }}</strong>
                            <span class="subtle">{{ $run->warehouse->code }}</span>
                        </flux:table.cell>
                        <flux:table.cell>{{ __('stock_counts.sources.'.$run->source) }}</flux:table.cell>
                        <flux:table.cell>
                            <strong>{{ __('stock_counts.col_total_lines') }}: {{ number_format($run->total_lines) }}</strong>
                            <span class="subtle">
                                {{ __('stock_counts.col_adjusted_lines') }} {{ number_format($run->adjusted_lines) }}
                                /
                                {{ __('stock_counts.col_no_change_lines') }} {{ number_format($run->no_change_lines) }}
                                /
                                {{ __('stock_counts.col_failed_lines') }} {{ number_format($run->failed_lines) }}
                            </span>
                        </flux:table.cell>
                        <flux:table.cell>
                            <strong>{{ $run->created_at?->format('Y-m-d') }}</strong>
                            <span class="subtle">{{ $run->created_at?->format('H:i') }}</span>
                            <span class="subtle">{{ $run->createdBy?->name ?? '-' }}</span>
                        </flux:table.cell>
                        <flux:table.cell>
                            <strong>{{ $run->posted_at?->format('Y-m-d') ?? '-' }}</strong>
                            @if ($run->posted_at)
                                <span class="subtle">{{ $run->posted_at->format('H:i') }}</span>
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="6"><div class="empty-state">{{ __('stock_counts.empty_state') }}</div></flux:table.cell>
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
