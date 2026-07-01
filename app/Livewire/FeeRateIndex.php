<?php

namespace App\Livewire;

use App\Models\FeeRate;
use App\Models\Tenant;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class FeeRateIndex extends Component
{
    use WithPagination;

    #[Url(as: 'tenant_id', except: '')]
    public string $tenantId = '';

    #[Url(as: 'fee_type', except: '')]
    public string $feeType = '';

    public function mount(): void
    {
        $this->authorizeInternalUser();
    }

    public function updatedTenantId(): void
    {
        $this->resetPage();
    }

    public function updatedFeeType(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $tenantIds = $this->allowedTenantIds();

        $rates = FeeRate::query()
            ->with('tenant:id,code,name')
            ->whereIn('tenant_id', $tenantIds)
            ->when($this->tenantId !== '', fn ($query) => $query->where('tenant_id', (int) $this->tenantId))
            ->when($this->feeType !== '', fn ($query) => $query->where('fee_type', $this->feeType))
            ->orderBy('tenant_id')
            ->orderBy('fee_type')
            ->orderByDesc('effective_from')
            ->paginate(30);

        return view('livewire.fee-rate-index', [
            'rates' => $rates,
            'tenants' => Tenant::query()
                ->whereIn('id', $tenantIds)
                ->orderBy('code')
                ->get(['id', 'code', 'name']),
            'feeTypes' => $this->feeTypeOptions(),
        ])->layout('inventory', [
            'title' => __('billing.fee_rates_page_title'),
            'subtitle' => __('billing.fee_rates_page_subtitle'),
            'pageWide' => true,
        ]);
    }

    public function feeTypeLabel(string $feeType): string
    {
        return $this->feeTypeOptions()[$feeType] ?? str($feeType)->replace('_', ' ')->title()->toString();
    }

    public function unitLabel(string $unit): string
    {
        return $this->unitOptions()[$unit] ?? str($unit)->replace('_', ' ')->title()->toString();
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

    private function feeTypeOptions(): array
    {
        return collect(FeeRate::FEE_TYPES)
            ->mapWithKeys(fn (string $feeType): array => [$feeType => __('billing.fee_types.'.$feeType)])
            ->all();
    }

    private function unitOptions(): array
    {
        return collect(FeeRate::UNITS)
            ->mapWithKeys(fn (string $unit): array => [$unit => __('billing.units.'.$unit)])
            ->all();
    }
}
