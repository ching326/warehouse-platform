<div class="exception-case-index-page">
    @if (session('status'))
        <div class="active-filter-row">
            <flux:badge color="green">{{ session('status') }}</flux:badge>
        </div>
    @endif

    <section class="table-shell flux-panel">
        <div class="movement-toolbar">
            @if ($showTenantFilter)
                <flux:select wire:model.live="tenantId" :label="__('exception_cases.field_tenant')">
                    <flux:select.option value="">{{ __('common.all_tenants') }}</flux:select.option>
                    @foreach ($tenants as $tenant)
                        <flux:select.option value="{{ $tenant->id }}">{{ $tenant->code }} - {{ $tenant->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endif

            <flux:select wire:model.live="typeFilter" :label="__('exception_cases.field_case_type')">
                <flux:select.option value="">{{ __('exception_cases.all_types') }}</flux:select.option>
                @foreach ($types as $value => $label)
                    <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="statusFilter" :label="__('exception_cases.field_status')">
                <flux:select.option value="">{{ __('exception_cases.all_statuses') }}</flux:select.option>
                @foreach ($statuses as $value => $label)
                    <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:input
                wire:model.live.debounce.300ms="search"
                :label="__('common.search')"
                :placeholder="__('exception_cases.search_placeholder')"
            />

            <flux:input wire:model.live.debounce.300ms="salesOrderId" type="number" min="1" :label="__('exception_cases.field_sales_order')" />
            <flux:input wire:model.live.debounce.300ms="outboundOrderId" type="number" min="1" :label="__('exception_cases.field_outbound_order')" />

            <flux:button href="{{ route('exception-cases.create') }}" variant="primary" wire:navigate>
                {{ __('exception_cases.btn_create') }}
            </flux:button>
        </div>

        <flux:table :paginate="$cases" class="data-table">
            <flux:table.columns>
                <flux:table.column>{{ __('exception_cases.col_case_no') }}</flux:table.column>
                <flux:table.column>{{ __('exception_cases.col_type') }}</flux:table.column>
                <flux:table.column>{{ __('exception_cases.col_status') }}</flux:table.column>
                <flux:table.column>{{ __('exception_cases.col_related_order') }}</flux:table.column>
                <flux:table.column>{{ __('exception_cases.col_lines') }}</flux:table.column>
                <flux:table.column>{{ __('exception_cases.col_reported_at') }}</flux:table.column>
                <flux:table.column>{{ __('exception_cases.col_updated') }}</flux:table.column>
                <flux:table.column>{{ __('exception_cases.col_actions') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($cases as $case)
                    <flux:table.row :key="$case->id">
                        <flux:table.cell>
                            <strong>{{ $case->case_no }}</strong>
                            <span class="subtle">{{ $case->tenant->code }} / #{{ $case->id }}</span>
                        </flux:table.cell>
                        <flux:table.cell>{{ $case->typeLabel() }}</flux:table.cell>
                        <flux:table.cell>
                            <flux:badge color="{{ $case->statusColor() }}">{{ $case->statusLabel() }}</flux:badge>
                        </flux:table.cell>
                        <flux:table.cell>
                            @if ($case->salesOrder)
                                <strong>{{ $case->salesOrder->platform_order_id ?: '#'.$case->salesOrder->id }}</strong>
                                <span class="subtle">{{ __('exception_cases.related_sales_order') }}</span>
                            @elseif ($case->outboundOrder)
                                <strong>{{ $case->outboundOrder->ref ?: '#'.$case->outboundOrder->id }}</strong>
                                <span class="subtle">{{ __('exception_cases.related_outbound_order') }}</span>
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
                                <span class="subtle">+{{ $case->lines->count() - 2 }} {{ __('exception_cases.more_lines') }}</span>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>{{ $case->reported_at?->format('Y-m-d H:i') ?? '-' }}</flux:table.cell>
                        <flux:table.cell>{{ $case->updated_at->format('Y-m-d H:i') }}</flux:table.cell>
                        <flux:table.cell>
                            <flux:button href="{{ route('exception-cases.show', $case) }}" size="xs" variant="outline" wire:navigate>
                                {{ __('exception_cases.btn_view') }}
                            </flux:button>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="8">
                            <div class="empty-state">{{ __('exception_cases.empty_state') }}</div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </section>
</div>
