<div class="billing-run-page">
    <x-flash-toast />

    <x-page-panel-header
        :title="__('billing.billing_page_title')"
        :subtitle="__('billing.billing_page_subtitle')"
    />

    <section class="table-shell flux-panel form-panel">
        <div class="billing-run-toolbar">
            <flux:select wire:model.live="tenantId" :label="__('common.tenant')">
                <flux:select.option value="">{{ __('common.select_tenant') }}</flux:select.option>
                @foreach ($tenants as $tenant)
                    <flux:select.option value="{{ $tenant->id }}">{{ $tenant->code }} / {{ $tenant->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:input wire:model.live="period" type="month" :label="__('billing.field_period')" />

            <div class="billing-run-actions">
                <flux:button type="button" variant="primary" wire:click="generate" :disabled="$tenantId === '' || $period === ''">
                    {{ $invoice && $invoice->status === \App\Models\Invoice::STATUS_DRAFT ? __('billing.btn_regenerate') : __('billing.btn_generate') }}
                </flux:button>

                @if ($invoice)
                    <flux:button type="button" variant="outline" wire:click="exportCsv">
                        {{ __('billing.btn_export_csv') }}
                    </flux:button>
                @endif

                @if ($invoice && $invoice->status === \App\Models\Invoice::STATUS_DRAFT)
                    <flux:button type="button" variant="primary" wire:click="finalize">
                        {{ __('billing.btn_finalize') }}
                    </flux:button>
                @endif
            </div>
        </div>
    </section>

    @if ($invoice)
        <section class="table-shell flux-panel">
            <div class="form-panel-header">
                <div>
                    <strong>{{ __('billing.invoice_review') }}</strong>
                    <span>{{ $invoice->tenant->code }} / {{ $invoice->period }}</span>
                </div>
                <div class="active-filter-row">
                    <x-status-badge :status="$invoice->status" :label="__('billing.invoice_statuses.'.$invoice->status)" />
                    <strong>{{ $invoice->currency }} {{ number_format((float) $invoice->total, 2) }}</strong>
                </div>
            </div>

            @if ($warnings !== [])
                <div class="billing-warning-list">
                    @foreach ($warnings as $warning)
                        <flux:badge color="amber">
                            {{ $warning['message'] ?? $warning['code'] }} ({{ $warning['count'] ?? 0 }})
                        </flux:badge>
                    @endforeach
                </div>
            @endif

            <flux:table class="data-table">
                <flux:table.columns>
                    <flux:table.column>{{ __('billing.field_fee_type') }}</flux:table.column>
                    <flux:table.column>{{ __('billing.field_unit') }}</flux:table.column>
                    <flux:table.column>{{ __('billing.field_quantity') }}</flux:table.column>
                    <flux:table.column>{{ __('billing.field_rate') }}</flux:table.column>
                    <flux:table.column>{{ __('billing.field_cost_base') }}</flux:table.column>
                    <flux:table.column>{{ __('billing.field_effective_window') }}</flux:table.column>
                    <flux:table.column>{{ __('billing.field_amount') }}</flux:table.column>
                    <flux:table.column>{{ __('billing.field_sources') }}</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @forelse ($invoice->lines as $line)
                        <flux:table.row :key="$line->id">
                            <flux:table.cell>{{ $this->feeTypeLabel($line->fee_type) }}</flux:table.cell>
                            <flux:table.cell>{{ $this->unitLabel($line->unit) }}</flux:table.cell>
                            <flux:table.cell>{{ number_format((float) $line->quantity, 4) }}</flux:table.cell>
                            <flux:table.cell>
                                {{ $line->rate !== null ? number_format((float) $line->rate, 4) : '-' }}
                                @if ($line->markup_pct !== null)
                                    <span class="subtle">{{ number_format((float) $line->markup_pct, 4) }}%</span>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>{{ $line->cost_base !== null ? number_format((float) $line->cost_base, 2) : '-' }}</flux:table.cell>
                            <flux:table.cell>
                                {{ $line->rate_from?->format('Y-m-d') ?? '-' }}
                                <span class="subtle">{{ $line->rate_to?->format('Y-m-d') ?? '-' }}</span>
                            </flux:table.cell>
                            <flux:table.cell>{{ number_format((float) $line->amount, 2) }}</flux:table.cell>
                            <flux:table.cell>
                                <flux:button type="button" size="sm" variant="outline" wire:click="toggleLine({{ $line->id }})">
                                    {{ $line->sources->count() }}
                                </flux:button>
                            </flux:table.cell>
                        </flux:table.row>

                        @if (isset($expandedLines[$line->id]))
                            <flux:table.row :key="'sources-'.$line->id">
                                <flux:table.cell colspan="8">
                                    <div class="billing-source-list">
                                        @foreach ($line->sources as $source)
                                            <div>
                                                <strong>{{ $source->source_type }} #{{ $source->source_id }}</strong>
                                                <span class="subtle">
                                                    {{ $source->source_date?->format('Y-m-d') ?? '-' }}
                                                    / {{ $source->quantity ?? $source->amount_basis ?? '-' }}
                                                </span>
                                            </div>
                                        @endforeach
                                    </div>
                                </flux:table.cell>
                            </flux:table.row>
                        @endif
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="8">
                                <div class="empty-state">{{ __('billing.invoice_lines_empty') }}</div>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </section>
    @endif

    <style>
        .billing-run-toolbar {
            display: grid;
            grid-template-columns: minmax(220px, 300px) 180px minmax(16px, 1fr);
            gap: 12px;
            align-items: end;
        }

        .billing-run-actions,
        .billing-warning-list {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            justify-content: flex-end;
        }

        .billing-source-list {
            display: grid;
            gap: 6px;
            padding: 8px 0;
        }
    </style>
</div>
