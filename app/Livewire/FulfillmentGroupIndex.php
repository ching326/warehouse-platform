<?php

namespace App\Livewire;

use App\Models\FulfillmentGroup;
use App\Models\Tenant;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class FulfillmentGroupIndex extends Component
{
    use WithPagination;

    #[Url(as: 'tenant_id', except: '')]
    public string $tenantId = '';

    #[Url(as: 'status', except: '')]
    public string $statusFilter = '';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    private bool $allowedTenantIdsResolved = false;

    private array $allowedTenantIdsCache = [];

    public function mount(): void
    {
        $this->authorizeTenantAccess();
    }

    public function updatedTenantId(): void
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

    public function statusLabel(string $status): string
    {
        return $this->statuses()[$status] ?? $status;
    }

    public function statusColor(string $status): string
    {
        return match ($status) {
            FulfillmentGroup::STATUS_SHIPPED => 'green',
            FulfillmentGroup::STATUS_CANCELLED => 'red',
            default => 'blue',
        };
    }

    public function render()
    {
        $groups = FulfillmentGroup::query()
            ->with(['tenant:id,code,name', 'warehouse:id,code,name'])
            ->withCount('orders')
            ->whereIn('tenant_id', $this->allowedTenantIds())
            ->when($this->tenantId !== '', fn ($query) => $query->where('tenant_id', (int) $this->tenantId))
            ->when($this->statusFilter !== '', fn ($query) => $query->where('status', $this->statusFilter))
            ->when($this->search !== '', function ($query) {
                $like = '%'.$this->search.'%';

                $query->where(fn ($query) => $query
                    ->where('reference_no', 'like', $like)
                    ->orWhere('tracking_no', 'like', $like));
            })
            ->orderByDesc('created_at')
            ->paginate(30);

        return view('livewire.fulfillment-group-index', [
            'groups' => $groups,
            'tenants' => Tenant::query()
                ->whereIn('id', $this->allowedTenantIds())
                ->orderBy('name')
                ->get(['id', 'code', 'name']),
            'statuses' => $this->statuses(),
            'showTenantFilter' => $this->isInternalUser(),
        ])->layout('inventory', [
            'title' => __('fulfillment_groups.page_title'),
            'subtitle' => __('fulfillment_groups.page_subtitle'),
            'pageWide' => true,
        ]);
    }

    private function statuses(): array
    {
        return [
            FulfillmentGroup::STATUS_RESERVED => __('fulfillment_groups.status_reserved'),
            FulfillmentGroup::STATUS_SHIPPED => __('fulfillment_groups.status_shipped'),
            FulfillmentGroup::STATUS_CANCELLED => __('fulfillment_groups.status_cancelled'),
        ];
    }
    private function isInternalUser(): bool
    {
        $user = Auth::user();

        return $user?->user_type === 'internal';
    }

    private function allowedTenantIds(): array
    {
        if ($this->allowedTenantIdsResolved) {
            return $this->allowedTenantIdsCache;
        }

        $this->allowedTenantIdsResolved = true;

        if ($this->isInternalUser()) {
            return $this->allowedTenantIdsCache = Tenant::query()->pluck('id')->all();
        }

        $user = Auth::user();

        if (! $user) {
            return $this->allowedTenantIdsCache = [];
        }

        return $this->allowedTenantIdsCache = $user->activeTenantIds();
    }

    private function authorizeTenantAccess(): void
    {
        if (! $this->isInternalUser() && $this->allowedTenantIds() === []) {
            abort(403);
        }
    }
}
