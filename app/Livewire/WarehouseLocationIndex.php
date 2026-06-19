<?php

namespace App\Livewire;

use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class WarehouseLocationIndex extends Component
{
    use WithPagination;

    #[Url(as: 'warehouse_id', except: '')]
    public string $warehouseId = '';

    #[Url(as: 'type', except: '')]
    public string $typeFilter = '';

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

    public function updatedWarehouseId(): void
    {
        $this->resetPage();
    }

    public function updatedTypeFilter(): void
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

    public function toggleStatus(int $locationId): void
    {
        $location = WarehouseLocation::findOrFail($locationId);
        $location->status = $location->status === 'active' ? 'inactive' : 'active';
        $location->save();

        session()->flash('status', __('locations.status_updated'));
    }

    public function render()
    {
        $locations = WarehouseLocation::query()
            ->with('warehouse:id,code,name')
            ->when($this->warehouseId !== '', fn ($query) => $query->where('warehouse_id', (int) $this->warehouseId))
            ->when($this->typeFilter !== '', fn ($query) => $query->where('type', $this->typeFilter))
            ->when($this->statusFilter !== '', fn ($query) => $query->where('status', $this->statusFilter))
            ->when($this->search !== '', function ($query) {
                $like = '%'.$this->search.'%';

                $query->where(fn ($query) => $query
                    ->where('code', 'like', $like)
                    ->orWhere('name', 'like', $like));
            })
            ->orderBy('warehouse_id')
            ->orderBy('code')
            ->paginate(50);

        return view('livewire.warehouse-location-index', [
            'locations' => $locations,
            'warehouses' => Warehouse::query()->orderBy('name')->get(['id', 'code', 'name']),
            'types' => $this->locationTypes(),
            'statuses' => [
                'active' => __('locations.status_active'),
                'inactive' => __('locations.status_inactive'),
            ],
        ])->layout('inventory', [
            'title' => __('locations.page_title'),
            'subtitle' => __('locations.page_subtitle'),
            'pageWide' => true,
        ]);
    }

    public function typeLabel(string $type): string
    {
        return $this->locationTypes()[$type] ?? str($type)->replace('_', ' ')->title()->toString();
    }

    public function statusLabel(string $status): string
    {
        return match ($status) {
            'active' => __('locations.status_active'),
            'inactive' => __('locations.status_inactive'),
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

    private function locationTypes(): array
    {
        return [
            'storage' => __('locations.type_storage'),
            'receiving' => __('locations.type_receiving'),
            'qc' => __('locations.type_qc'),
            'packing' => __('locations.type_packing'),
            'shipping' => __('locations.type_shipping'),
            'hold' => __('locations.type_hold'),
            'damaged' => __('locations.type_damaged'),
            'other' => __('locations.type_other'),
        ];
    }
}
