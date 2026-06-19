<?php

namespace App\Livewire;

use App\Models\Carrier;
use App\Models\ShippingMethod;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class ShippingMethodIndex extends Component
{
    use WithPagination;

    #[Url(as: 'carrier', except: '')]
    public string $carrierId = '';

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

    public function updatedCarrierId(): void
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

    public function toggleStatus(int $methodId): void
    {
        $method = ShippingMethod::findOrFail($methodId);
        $method->update(['status' => $method->status === 'active' ? 'inactive' : 'active']);

        session()->flash('status', __('shipping.status_updated'));
    }

    public function render()
    {
        $methods = ShippingMethod::query()
            ->with(['carrier', 'rates' => fn ($query) => $query->whereNull('tenant_id')->where('rate_type', 'flat')])
            ->when($this->carrierId !== '', fn ($query) => $query->where('carrier_id', (int) $this->carrierId))
            ->when($this->statusFilter !== '', fn ($query) => $query->where('status', $this->statusFilter))
            ->when($this->search !== '', function ($query) {
                $like = '%'.$this->search.'%';

                $query->where(fn ($query) => $query
                    ->where('code', 'like', $like)
                    ->orWhere('name', 'like', $like));
            })
            ->orderBy('name')
            ->paginate(30);

        return view('livewire.shipping-method-index', [
            'methods' => $methods,
            'carriers' => Carrier::orderBy('name')->get(['id', 'code', 'name']),
            'statuses' => $this->statuses(),
        ])->layout('inventory', [
            'title' => __('shipping.index_page_title'),
            'subtitle' => __('shipping.index_page_subtitle'),
            'pageWide' => true,
        ]);
    }

    public function statusLabel(string $status): string
    {
        return $this->statuses()[$status] ?? str($status)->replace('_', ' ')->title()->toString();
    }

    public function statusColor(string $status): string
    {
        return $status === 'active' ? 'green' : 'zinc';
    }

    private function statuses(): array
    {
        return [
            'active' => __('shipping.status_active'),
            'inactive' => __('shipping.status_inactive'),
        ];
    }

    private function isInternalUser(): bool
    {
        $user = Auth::user();

        return ! $user || $user->user_type === 'internal';
    }
}
