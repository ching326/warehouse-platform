<?php

namespace App\Livewire;

use App\Models\Warehouse;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class WarehouseIndex extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'status', except: '')]
    public string $statusFilter = '';

    public function mount(): void
    {
        if (! $this->isInternalUser()) {
            abort(403);
        }
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function toggleStatus(int $warehouseId): void
    {
        $warehouse = Warehouse::findOrFail($warehouseId);
        $warehouse->status = $warehouse->status === 'active' ? 'inactive' : 'active';
        $warehouse->save();

        session()->flash('status', __('setup.status_updated'));
    }

    public function render()
    {
        $warehouses = Warehouse::query()
            ->when($this->statusFilter !== '', fn ($query) => $query->where('status', $this->statusFilter))
            ->when($this->search !== '', function ($query) {
                $like = '%'.$this->search.'%';

                $query->where(fn ($query) => $query
                    ->where('code', 'like', $like)
                    ->orWhere('name', 'like', $like)
                    ->orWhere('city', 'like', $like));
            })
            ->orderBy('name')
            ->paginate(30);

        return view('livewire.warehouse-index', [
            'warehouses' => $warehouses,
            'statuses' => [
                'active' => __('setup.status_active'),
                'inactive' => __('setup.status_inactive'),
            ],
        ])->layout('inventory', [
            'title' => __('setup.warehouses_page_title'),
            'subtitle' => __('setup.warehouses_page_subtitle'),
            'pageWide' => true,
        ]);
    }

    public function statusLabel(string $status): string
    {
        return match ($status) {
            'active' => __('setup.status_active'),
            'inactive' => __('setup.status_inactive'),
            default => str($status)->replace('_', ' ')->title()->toString(),
        };
    }

    public function statusColor(string $status): string
    {
        return $status === 'active' ? 'green' : 'zinc';
    }

    // TODO: remove unauthenticated fallback when auth is implemented
    private function isInternalUser(): bool
    {
        $user = Auth::user();

        return ! $user || $user->user_type === 'internal';
    }
}
