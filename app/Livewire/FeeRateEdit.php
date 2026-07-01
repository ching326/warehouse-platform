<?php

namespace App\Livewire;

use App\Models\FeeRate;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Livewire\Component;

class FeeRateEdit extends Component
{
    public FeeRate $feeRate;

    public string $tenantId = '';

    public string $feeType = FeeRate::TYPE_STORAGE;

    public string $unit = FeeRate::UNIT_PER_M3_MONTH;

    public string $rate = '0.0000';

    public string $markupPct = '';

    public string $currency = 'JPY';

    public string $effectiveFrom = '';

    public string $effectiveTo = '';

    public function mount(FeeRate $feeRate): void
    {
        $this->authorizeInternalUser();

        if (! in_array($feeRate->tenant_id, $this->allowedTenantIds(), true)) {
            abort(404);
        }

        $this->feeRate = $feeRate->load('tenant:id,code,name');
        $this->tenantId = (string) $feeRate->tenant_id;
        $this->feeType = $feeRate->fee_type;
        $this->unit = $feeRate->unit;
        $this->rate = (string) $feeRate->rate;
        $this->markupPct = $feeRate->markup_pct === null ? '' : (string) $feeRate->markup_pct;
        $this->currency = $feeRate->currency;
        $this->effectiveFrom = CarbonImmutable::parse($feeRate->effective_from)->toDateString();
        $this->effectiveTo = $feeRate->effective_to === null
            ? ''
            : CarbonImmutable::parse($feeRate->effective_to)->toDateString();
    }

    public function updatedFeeType(): void
    {
        $this->unit = FeeRate::allowedUnitsFor($this->feeType)[0] ?? '';

        if ($this->usesMarkup()) {
            $this->rate = '0.0000';
        } else {
            $this->markupPct = '';
        }
    }

    public function save()
    {
        $data = $this->validatedData();

        $this->feeRate->update($data);

        session()->flash('status', __('billing.fee_rate_updated'));

        return redirect()->route('setup.fee-rates.index');
    }

    public function render()
    {
        return view('livewire.fee-rate-edit', [
            'tenants' => $this->tenantOptions(),
            'feeTypes' => $this->feeTypeOptions(),
            'units' => $this->unitOptions(),
            'usesMarkup' => $this->usesMarkup(),
        ])->layout('inventory', [
            'title' => __('billing.fee_rate_edit_page_title'),
            'subtitle' => $this->subtitle(),
        ]);
    }

    public function usesMarkup(): bool
    {
        return FeeRate::isPercentFeeType($this->feeType);
    }

    private function validatedData(): array
    {
        $this->currency = strtoupper(trim($this->currency));

        $validator = Validator::make([
            'tenantId' => $this->tenantId,
            'feeType' => $this->feeType,
            'unit' => $this->unit,
            'rate' => $this->rate,
            'markupPct' => $this->markupPct,
            'currency' => $this->currency,
            'effectiveFrom' => $this->effectiveFrom,
            'effectiveTo' => $this->effectiveTo,
        ], $this->rules());

        $validator->after(function ($validator): void {
            $this->validateRateSemantics($validator);
        });

        $validated = $validator->validate();

        return $this->payload($validated);
    }

    private function validateRateSemantics($validator): void
    {
        $allowedUnits = FeeRate::allowedUnitsFor($this->feeType);

        if ($allowedUnits === [] || ! in_array($this->unit, $allowedUnits, true)) {
            $validator->errors()->add('unit', __('billing.validation_invalid_unit'));
        }

        if (FeeRate::hasOverlap(
            (int) $this->tenantId,
            $this->feeType,
            $this->effectiveFrom,
            $this->effectiveTo === '' ? null : $this->effectiveTo,
            $this->feeRate->id,
        )) {
            $validator->errors()->add('effectiveFrom', __('billing.validation_overlap'));
        }
    }

    private function rules(): array
    {
        return [
            'tenantId' => ['required', Rule::in($this->allowedTenantIds())],
            'feeType' => ['required', Rule::in(FeeRate::FEE_TYPES)],
            'unit' => ['required', Rule::in(FeeRate::UNITS)],
            'rate' => [$this->usesMarkup() ? 'nullable' : 'required', 'numeric', 'min:0'],
            'markupPct' => [$this->usesMarkup() ? 'required' : 'nullable', 'numeric', 'min:0'],
            'currency' => ['required', 'string', 'size:3'],
            'effectiveFrom' => ['required', 'date'],
            'effectiveTo' => ['nullable', 'date', 'after_or_equal:effectiveFrom'],
        ];
    }

    private function payload(array $validated): array
    {
        $usesMarkup = FeeRate::isPercentFeeType($validated['feeType']);

        return [
            'tenant_id' => (int) $validated['tenantId'],
            'fee_type' => $validated['feeType'],
            'unit' => $validated['unit'],
            'rate' => $usesMarkup ? 0 : (float) $validated['rate'],
            'markup_pct' => $usesMarkup ? (float) $validated['markupPct'] : null,
            'currency' => $validated['currency'],
            'effective_from' => $validated['effectiveFrom'],
            'effective_to' => $validated['effectiveTo'] === '' ? null : $validated['effectiveTo'],
        ];
    }

    private function authorizeInternalUser(): void
    {
        if (Auth::user()?->user_type !== 'internal') {
            abort(403);
        }
    }

    private function allowedTenantIds(): array
    {
        return Tenant::query()->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private function tenantOptions()
    {
        return Tenant::query()
            ->whereIn('id', $this->allowedTenantIds())
            ->orderBy('code')
            ->get(['id', 'code', 'name']);
    }

    private function feeTypeOptions(): array
    {
        return collect(FeeRate::FEE_TYPES)
            ->mapWithKeys(fn (string $feeType): array => [$feeType => __('billing.fee_types.'.$feeType)])
            ->all();
    }

    private function unitOptions(): array
    {
        return collect(FeeRate::allowedUnitsFor($this->feeType))
            ->mapWithKeys(fn (string $unit): array => [$unit => __('billing.units.'.$unit)])
            ->all();
    }

    private function feeTypeLabel(string $feeType): string
    {
        return $this->feeTypeOptions()[$feeType] ?? str($feeType)->replace('_', ' ')->title()->toString();
    }

    private function subtitle(): string
    {
        $tenantCode = Tenant::query()
            ->whereKey($this->feeRate->tenant_id)
            ->value('code') ?? (string) $this->feeRate->tenant_id;

        return $tenantCode.' - '.$this->feeTypeLabel($this->feeRate->fee_type);
    }
}
