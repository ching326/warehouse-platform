<div class="exception-case-show-page">
    @if (session('status'))
        <div class="active-filter-row"><flux:badge color="green">{{ session('status') }}</flux:badge></div>
    @endif
    @if (session('error'))
        <div class="active-filter-row"><flux:badge color="red">{{ session('error') }}</flux:badge></div>
    @endif

    <section class="table-shell flux-panel form-panel">
        <div class="form-panel-header">
            <div>
                <strong>{{ $case->case_no }}</strong>
                <span>{{ $case->tenant->code }} / {{ $case->created_at->format('Y-m-d H:i') }}</span>
            </div>
            <div class="active-filter-row">
                <flux:badge>{{ $case->typeLabel() }}</flux:badge>
                <flux:badge color="{{ $case->statusColor() }}">{{ $case->statusLabel() }}</flux:badge>
            </div>
        </div>

        <div class="form-grid three">
            <div>
                <span class="subtle">{{ __('exception_cases.field_sales_order') }}</span>
                @if ($case->salesOrder)
                    <a href="{{ route('sales.orders.show', $case->salesOrder) }}" wire:navigate><strong>{{ $case->salesOrder->platform_order_id ?: '#'.$case->salesOrder->id }}</strong></a>
                @else
                    <strong>-</strong>
                @endif
            </div>
            <div><span class="subtle">{{ __('exception_cases.field_outbound_order') }}</span><strong>{{ $case->outboundOrder?->ref ?: '-' }}</strong></div>
            <div><span class="subtle">{{ __('exception_cases.field_fulfillment_group') }}</span><strong>{{ $case->fulfillmentGroup?->reference_no ?: '-' }}</strong></div>
            <div><span class="subtle">{{ __('exception_cases.field_reported_at') }}</span><strong>{{ $case->reported_at?->format('Y-m-d H:i') ?? '-' }}</strong></div>
            <div><span class="subtle">{{ __('exception_cases.field_reported_by') }}</span><strong>{{ $case->reported_by ?: '-' }}</strong></div>
            <div><span class="subtle">{{ __('exception_cases.field_created_by') }}</span><strong>{{ $case->createdBy?->name ?: '-' }}</strong></div>
        </div>
    </section>

    <section class="table-shell flux-panel form-panel">
        <div class="form-panel-header">
            <div>
                <strong>{{ __('exception_cases.section_workflow') }}</strong>
                <span>{{ $case->isClosed() ? __('exception_cases.read_only_hint') : __('exception_cases.workflow_hint') }}</span>
            </div>
        </div>

        <div class="form-grid">
            <flux:select wire:model="status" :label="__('exception_cases.field_status')" :disabled="$case->isClosed()">
                @foreach ($statuses as $value => $label)
                    <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>
            <label>
                <span>{{ __('exception_cases.field_note') }}</span>
                <textarea wire:model="note" rows="3" @disabled($case->isClosed())></textarea>
            </label>
        </div>

        @if (! $case->isClosed())
            <div class="form-actions">
                <flux:button type="button" variant="primary" wire:click="saveCase">{{ __('exception_cases.btn_save_case') }}</flux:button>
            </div>
        @endif
    </section>

    <section class="table-shell flux-panel form-panel">
        <div class="form-panel-header">
            <div>
                <strong>{{ __('exception_cases.section_lines') }}</strong>
                <span>{{ __('exception_cases.no_inventory_hint') }}</span>
            </div>
        </div>

        <flux:table class="data-table">
            <flux:table.columns>
                <flux:table.column>{{ __('exception_cases.col_sku_stock') }}</flux:table.column>
                <flux:table.column align="end">{{ __('exception_cases.field_qty') }}</flux:table.column>
                <flux:table.column>{{ __('exception_cases.field_condition') }}</flux:table.column>
                <flux:table.column>{{ __('exception_cases.field_action') }}</flux:table.column>
                <flux:table.column>{{ __('exception_cases.field_line_note') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($case->lines as $line)
                    <flux:table.row :key="$line->id">
                        <flux:table.cell>
                            <strong>{{ $line->sku?->sku ?? '-' }}</strong>
                            <span class="subtle">{{ $line->stockItem?->code ?? __('common.sku_types.virtual_bundle') }} / {{ $line->stockItem?->name ?? $line->sku?->name ?? '-' }}</span>
                        </flux:table.cell>
                        <flux:table.cell align="end">{{ number_format($line->qty) }}</flux:table.cell>
                        <flux:table.cell>
                            <select wire:model="lineDrafts.{{ $line->id }}.condition" @disabled($case->isClosed())>
                                @foreach ($conditions as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </flux:table.cell>
                        <flux:table.cell>
                            <select wire:model="lineDrafts.{{ $line->id }}.action" @disabled($case->isClosed())>
                                @foreach ($actions as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </flux:table.cell>
                        <flux:table.cell><input type="text" wire:model="lineDrafts.{{ $line->id }}.note" @disabled($case->isClosed())></flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>

        @if (! $case->isClosed())
            <div class="form-actions">
                <flux:button type="button" variant="primary" wire:click="saveLines">{{ __('exception_cases.btn_save_lines') }}</flux:button>
            </div>
        @endif
    </section>

    <div class="form-actions">
        <flux:button href="{{ route('exception-cases.index') }}" variant="outline" wire:navigate>{{ __('exception_cases.btn_back') }}</flux:button>
    </div>
</div>
