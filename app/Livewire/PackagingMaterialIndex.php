<?php

namespace App\Livewire;

use App\Models\PackagingMaterial;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class PackagingMaterialIndex extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'status', except: '')]
    public string $statusFilter = '';

    #[Url(as: 'type', except: '')]
    public string $typeFilter = '';

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

    public function updatedTypeFilter(): void
    {
        $this->resetPage();
    }

    public function toggleStatus(int $id): void
    {
        $item = PackagingMaterial::findOrFail($id);
        $item->status = $item->status === 'active' ? 'inactive' : 'active';
        $item->save();
        session()->flash('status', __('setup.status_updated'));
    }

    public function render()
    {
        $items = PackagingMaterial::query()
            ->when($this->statusFilter !== '', fn ($q) => $q->where('status', $this->statusFilter))
            ->when($this->typeFilter !== '', fn ($q) => $q->where('type', $this->typeFilter))
            ->when($this->search !== '', function ($q) {
                $like = '%'.$this->search.'%';
                $q->where(fn ($q) => $q
                    ->where('code', 'like', $like)
                    ->orWhere('name', 'like', $like));
            })
            ->orderBy('code')
            ->paginate(30);

        return view('livewire.packaging-material-index', [
            'items' => $items,
            'types' => $this->typeOptions(),
            'statuses' => [
                'active' => __('setup.status_active'),
                'inactive' => __('setup.status_inactive'),
            ],
        ])->layout('inventory', [
            'title' => __('setup.packagings_page_title'),
            'subtitle' => __('setup.packagings_page_subtitle'),
            'pageWide' => true,
        ]);
    }

    public function statusColor(string $status): string
    {
        return $status === 'active' ? 'green' : 'zinc';
    }

    public function statusLabel(string $status): string
    {
        return match ($status) {
            'active' => __('setup.status_active'),
            'inactive' => __('setup.status_inactive'),
            default => $status,
        };
    }

    public function typeLabel(string $type): string
    {
        return __('setup.packaging_types.'.$type, [], 'en') !== 'setup.packaging_types.'.$type
            ? __('setup.packaging_types.'.$type)
            : str($type)->replace('_', ' ')->title()->toString();
    }

    // TODO: remove unauthenticated fallback when auth is implemented
    private function isInternalUser(): bool
    {
        $user = Auth::user();

        return ! $user || $user->user_type === 'internal';
    }

    private function typeOptions(): array
    {
        return [
            'box' => __('setup.packaging_types.box'),
            'bag' => __('setup.packaging_types.bag'),
            'envelope' => __('setup.packaging_types.envelope'),
            'tube' => __('setup.packaging_types.tube'),
            'other' => __('setup.packaging_types.other'),
        ];
    }
}
