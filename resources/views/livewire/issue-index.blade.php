<div class="issue-index-page">
    <x-flash-toast />

<section class="table-shell flux-panel">
        <div class="issue-filter-stack">
            <div class="issue-filter-row issue-filter-row-primary">
                @if ($showTenantFilter)
                    <flux:select wire:model.live="tenantId" :label="__('issues.field_tenant')">
                        <flux:select.option value="">{{ __('common.all_tenants') }}</flux:select.option>
                        @foreach ($tenants as $tenant)
                            <flux:select.option value="{{ $tenant->id }}">{{ $tenant->code }} - {{ $tenant->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @endif

                <flux:select wire:model.live="typeFilter" :label="__('issues.field_issue_type')">
                    <flux:select.option value="">{{ __('issues.all_types') }}</flux:select.option>
                    @foreach ($types as $value => $label)
                        <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select wire:model.live="statusFilter" :label="__('issues.field_status')">
                    <flux:select.option value="">{{ __('issues.all_statuses') }}</flux:select.option>
                    @foreach ($statuses as $value => $label)
                        <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:button href="{{ route('issues.create') }}" variant="primary" wire:navigate>
                    {{ __('issues.btn_create') }}
                </flux:button>
            </div>

            <div class="issue-filter-row issue-filter-row-search">
                <flux:input
                    class="issue-global-search"
                    wire:model.live.debounce.300ms="search"
                    :label="__('common.search')"
                    :placeholder="__('issues.search_placeholder')"
                />
            </div>
        </div>

        <flux:table :paginate="$cases" class="data-table">
            <flux:table.columns>
                <flux:table.column>{{ __('issues.col_issue_no') }}</flux:table.column>
                <flux:table.column>{{ __('issues.col_type') }}</flux:table.column>
                <flux:table.column>{{ __('issues.col_status') }}</flux:table.column>
                <flux:table.column>{{ __('issues.col_related_order') }}</flux:table.column>
                <flux:table.column>{{ __('issues.col_lines') }}</flux:table.column>
                <flux:table.column>{{ __('issues.col_reported_at') }}</flux:table.column>
                <flux:table.column>{{ __('issues.col_updated') }}</flux:table.column>
                <flux:table.column>{{ __('issues.col_actions') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($cases as $case)
                    <flux:table.row :key="$case->id">
                        <flux:table.cell>
                            <strong>{{ $case->issue_no }}</strong>
                            <span class="subtle">{{ $case->tenant->code }} / #{{ $case->id }}</span>
                        </flux:table.cell>
                        <flux:table.cell>{{ $case->typeLabel() }}</flux:table.cell>
                        <flux:table.cell>
                            <flux:badge color="{{ $case->statusColor() }}">{{ $case->statusLabel() }}</flux:badge>
                        </flux:table.cell>
                        <flux:table.cell>
                            @if ($case->salesOrder)
                                <strong>{{ $case->salesOrder->platform_order_id ?: '#'.$case->salesOrder->id }}</strong>
                                <span class="subtle">{{ __('issues.related_sales_order') }}</span>
                            @elseif ($case->outboundOrder)
                                <strong>{{ $case->outboundOrder->ref ?: '#'.$case->outboundOrder->id }}</strong>
                                <span class="subtle">{{ __('issues.related_outbound_order') }}</span>
                            @else
                                <span class="muted-dash">-</span>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>
                            @foreach ($case->lines->take(2) as $line)
                                <div class="so-item-line">
                                    <strong>{{ $line->sku?->sku ?? $line->stockItem?->code ?? '-' }}</strong>
                                    <span class="subtle">{{ number_format($line->qty) }} x {{ $line->stockItem?->name ?? $line->sku?->name ?? '-' }}</span>
                                </div>
                            @endforeach
                            @if ($case->lines->count() > 2)
                                <span class="subtle">+{{ $case->lines->count() - 2 }} {{ __('issues.more_lines') }}</span>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>{{ $case->reported_at?->format('Y-m-d H:i') ?? '-' }}</flux:table.cell>
                        <flux:table.cell>{{ $case->updated_at->format('Y-m-d H:i') }}</flux:table.cell>
                        <flux:table.cell>
                            <flux:button href="{{ route('issues.show', $case) }}" size="xs" variant="outline" wire:navigate>
                                {{ __('issues.btn_view') }}
                            </flux:button>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="8">
                            <div class="empty-state">{{ __('issues.empty_state') }}</div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </section>

    <style>
        .issue-filter-stack {
            display: grid;
            gap: 12px;
            margin-bottom: 14px;
        }

        .issue-filter-row {
            display: grid;
            gap: 12px;
            align-items: end;
        }

        .issue-filter-row-primary {
            grid-template-columns: repeat(3, minmax(150px, 190px)) max-content;
        }

        .issue-filter-row-search {
            grid-template-columns: minmax(280px, 520px);
        }

        .issue-global-search {
            width: 100%;
        }

        @media (max-width: 900px) {
            .issue-filter-row-primary,
            .issue-filter-row-search {
                grid-template-columns: 1fr;
            }
        }
    </style>
</div>
