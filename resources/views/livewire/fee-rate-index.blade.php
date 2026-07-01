<div class="fee-rate-index-page">
    <x-flash-toast />

    <x-page-panel-header
        :title="__('billing.fee_rates_page_title')"
        :subtitle="__('billing.fee_rates_page_subtitle')"
    />

    <section class="table-shell flux-panel">
        <div class="movement-toolbar fee-rate-toolbar">
            <flux:select wire:model.live="tenantId" :label="__('common.tenant')">
                <flux:select.option value="">{{ __('common.all_tenants') }}</flux:select.option>
                @foreach ($tenants as $tenant)
                    <flux:select.option value="{{ $tenant->id }}">{{ $tenant->code }} / {{ $tenant->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="feeType" :label="__('billing.field_fee_type')">
                <flux:select.option value="">{{ __('billing.all_fee_types') }}</flux:select.option>
                @foreach ($feeTypes as $value => $label)
                    <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>

            <div class="fee-rate-create-action">
                <flux:button href="{{ route('setup.fee-rates.create') }}" variant="primary">
                    {{ __('billing.btn_create_fee_rate') }}
                </flux:button>
            </div>
        </div>

        <flux:table :paginate="$rates" class="data-table">
            <flux:table.columns>
                <flux:table.column>{{ __('common.tenant') }}</flux:table.column>
                <flux:table.column>{{ __('billing.field_fee_type') }}</flux:table.column>
                <flux:table.column>{{ __('billing.field_unit') }}</flux:table.column>
                <flux:table.column>{{ __('billing.field_rate') }}</flux:table.column>
                <flux:table.column>{{ __('billing.field_markup_pct') }}</flux:table.column>
                <flux:table.column>{{ __('billing.field_currency') }}</flux:table.column>
                <flux:table.column>{{ __('billing.field_effective_window') }}</flux:table.column>
                <flux:table.column>{{ __('common.actions') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($rates as $rate)
                    <flux:table.row :key="$rate->id">
                        <flux:table.cell>
                            <strong>{{ $rate->tenant->code }}</strong>
                            <span class="subtle">{{ $rate->tenant->name }}</span>
                        </flux:table.cell>
                        <flux:table.cell>{{ $this->feeTypeLabel($rate->fee_type) }}</flux:table.cell>
                        <flux:table.cell>{{ $this->unitLabel($rate->unit) }}</flux:table.cell>
                        <flux:table.cell>{{ number_format((float) $rate->rate, 4) }}</flux:table.cell>
                        <flux:table.cell>
                            {{ $rate->markup_pct === null ? '-' : number_format((float) $rate->markup_pct, 4).'%' }}
                        </flux:table.cell>
                        <flux:table.cell>{{ $rate->currency }}</flux:table.cell>
                        <flux:table.cell>
                            {{ $rate->effective_from?->format('Y-m-d') }}
                            <span class="subtle">{{ $rate->effective_to?->format('Y-m-d') ?? __('billing.open_ended') }}</span>
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:button class="action-button-md" href="{{ route('setup.fee-rates.edit', $rate) }}" variant="primary">
                                {{ __('setup.btn_edit') }}
                            </flux:button>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="8">
                            <div class="empty-state">{{ __('billing.fee_rates_empty') }}</div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </section>

    <style>
        .fee-rate-toolbar {
            grid-template-columns: minmax(220px, 280px) minmax(220px, 280px) minmax(16px, 1fr) auto;
        }

        .fee-rate-create-action {
            grid-column: 4;
            justify-self: end;
            align-self: end;
        }

        @media (max-width: 900px) {
            .fee-rate-toolbar {
                grid-template-columns: 1fr;
            }

            .fee-rate-create-action {
                grid-column: auto;
                justify-self: start;
            }
        }
    </style>
</div>
