<?php

namespace App\Livewire;

use App\Models\FbaWarehouse;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class FbaWarehouseIndex extends Component
{
    use WithPagination;

    #[Url(as: 'country', except: '')]
    public string $countryCode = '';

    #[Url(as: 'status', except: '')]
    public string $statusFilter = '';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public function mount(): void
    {
        if (! $this->isInternalUser()) {
            abort(403);
        }
    }

    public function updatedCountryCode(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function toggleStatus(int $fbaWarehouseId): void
    {
        $fbaWarehouse = FbaWarehouse::findOrFail($fbaWarehouseId);
        $fbaWarehouse->status = $fbaWarehouse->status === FbaWarehouse::STATUS_ACTIVE
            ? FbaWarehouse::STATUS_INACTIVE
            : FbaWarehouse::STATUS_ACTIVE;
        $fbaWarehouse->save();

        session()->flash('status', __('setup.status_updated'));
    }

    public function render()
    {
        $fbaWarehouses = FbaWarehouse::query()
            ->when($this->countryCode !== '', fn ($query) => $query->where('country_code', $this->countryCode))
            ->when($this->statusFilter !== '', fn ($query) => $query->where('status', $this->statusFilter))
            ->when($this->search !== '', function ($query): void {
                $like = '%'.$this->search.'%';

                $query->where(fn ($query) => $query
                    ->where('code', 'like', $like)
                    ->orWhere('name', 'like', $like)
                    ->orWhere('postal_code', 'like', $like)
                    ->orWhere('state', 'like', $like)
                    ->orWhere('city', 'like', $like)
                    ->orWhere('address_line1', 'like', $like)
                    ->orWhere('address_line2', 'like', $like)
                    ->orWhere('phone', 'like', $like)
                    ->orWhere('note', 'like', $like));
            })
            ->orderBy('country_code')
            ->orderBy('code')
            ->paginate(30);

        return view('livewire.fba-warehouse-index', [
            'fbaWarehouses' => $fbaWarehouses,
            'countries' => $this->countryOptions(),
            'statuses' => $this->statusOptions(),
        ])->layout('inventory', [
            'title' => __('setup.fba_warehouses_page_title'),
            'subtitle' => __('setup.fba_warehouses_page_subtitle'),
            'pageWide' => true,
        ]);
    }

    public function statusLabel(string $status): string
    {
        return $this->statusOptions()[$status] ?? str($status)->replace('_', ' ')->title()->toString();
    }

    public function statusColor(string $status): string
    {
        return $status === FbaWarehouse::STATUS_ACTIVE ? 'green' : 'zinc';
    }

    private function countryOptions(): array
    {
        return [
            'JP' => 'JP',
        ];
    }

    private function statusOptions(): array
    {
        return [
            FbaWarehouse::STATUS_ACTIVE => __('setup.status_active'),
            FbaWarehouse::STATUS_INACTIVE => __('setup.status_inactive'),
        ];
    }

    private function isInternalUser(): bool
    {
        return Auth::user()?->user_type === 'internal';
    }
}
