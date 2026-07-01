<div class="fee-rate-form-page">
    <x-flash-toast />

    <section class="table-shell flux-panel form-panel">
        <div class="form-panel-header">
            <div>
                <strong>{{ __('billing.fee_rate_create_page_title') }}</strong>
                <span>{{ __('billing.fee_rate_form_hint') }}</span>
            </div>
            <flux:button href="{{ route('setup.fee-rates.index') }}" variant="outline">
                {{ __('billing.btn_back_fee_rates') }}
            </flux:button>
        </div>

        <form wire:submit="save" class="form-stack">
            <div class="form-grid three">
                <flux:select wire:model="tenantId" required :label="__('common.tenant')">
                    <flux:select.option value="">{{ __('common.select_tenant') }}</flux:select.option>
                    @foreach ($tenants as $tenant)
                        <flux:select.option value="{{ $tenant->id }}">{{ $tenant->code }} / {{ $tenant->name }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select wire:model.live="feeType" required :label="__('billing.field_fee_type')">
                    @foreach ($feeTypes as $value => $label)
                        <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select wire:model="unit" required :label="__('billing.field_unit')">
                    @foreach ($units as $value => $label)
                        <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <div class="form-grid three">
                @if ($usesMarkup)
                    <flux:input wire:model="markupPct" type="number" step="0.0001" min="0" required :label="__('billing.field_markup_pct')" />
                @else
                    <flux:input wire:model="rate" type="number" step="0.0001" min="0" required :label="__('billing.field_rate')" />
                @endif

                <flux:input wire:model="currency" maxlength="3" required :label="__('billing.field_currency')" />
                <div></div>
            </div>

            <div class="form-grid two">
                <flux:input wire:model="effectiveFrom" type="date" required :label="__('billing.field_effective_from')" />
                <flux:input wire:model="effectiveTo" type="date" :label="__('billing.field_effective_to')" />
            </div>

            <div class="form-actions">
                <flux:button href="{{ route('setup.fee-rates.index') }}" variant="outline">{{ __('setup.btn_cancel') }}</flux:button>
                <flux:button type="submit" variant="primary">{{ __('billing.btn_create_fee_rate') }}</flux:button>
            </div>
        </form>
    </section>
</div>
