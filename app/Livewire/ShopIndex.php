<?php

namespace App\Livewire;

use App\Models\Shop;
use App\Models\Tenant;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class ShopIndex extends Component
{
    use WithPagination;

    #[Url(as: 'tenant', except: '')]
    public string $tenantId = '';

    #[Url(as: 'platform', except: '')]
    public string $platformFilter = '';

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

    public function updatedTenantId(): void
    {
        $this->resetPage();
    }

    public function updatedPlatformFilter(): void
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

    public function toggleStatus(int $shopId): void
    {
        $shop = Shop::findOrFail($shopId);
        $shop->status = $shop->status === 'active' ? 'inactive' : 'active';
        $shop->save();

        session()->flash('status', __('shop.status_updated'));
    }

    public function render()
    {
        $shops = Shop::query()
            ->with('tenant:id,code,name')
            ->when($this->tenantId !== '', fn ($query) => $query->where('tenant_id', $this->tenantId))
            ->when($this->platformFilter !== '', fn ($query) => $query->where('platform', $this->platformFilter))
            ->when($this->statusFilter !== '', fn ($query) => $query->where('status', $this->statusFilter))
            ->when($this->search !== '', function ($query) {
                $like = '%'.$this->search.'%';

                $query->where(fn ($query) => $query
                    ->where('code', 'like', $like)
                    ->orWhere('name', 'like', $like));
            })
            ->orderBy('name')
            ->paginate(30);

        return view('livewire.shop-index', [
            'shops' => $shops,
            'tenants' => Tenant::where('status', 'active')->orderBy('name')->get(['id', 'code', 'name']),
            'platforms' => $this->platforms(),
            'statuses' => [
                'active' => __('shop.status_active'),
                'inactive' => __('shop.status_inactive'),
            ],
        ])->layout('inventory', [
            'title' => __('shop.shops_page_title'),
            'subtitle' => __('shop.shops_page_subtitle'),
            'pageWide' => true,
        ]);
    }

    public function platformLabel(string $platform): string
    {
        return $this->platforms()[$platform] ?? str($platform)->replace('_', ' ')->title()->toString();
    }

    public function statusLabel(string $status): string
    {
        return match ($status) {
            'active' => __('shop.status_active'),
            'inactive' => __('shop.status_inactive'),
            default => str($status)->replace('_', ' ')->title()->toString(),
        };
    }

    public function statusColor(string $status): string
    {
        return $status === 'active' ? 'green' : 'zinc';
    }

    private function isInternalUser(): bool
    {
        $user = Auth::user();

        return $user?->user_type === 'internal';
    }

    private function platforms(): array
    {
        return [
            'amazon' => __('shop.platform_amazon'),
            'rakuten' => __('shop.platform_rakuten'),
            'shopify' => __('shop.platform_shopify'),
            'manual' => __('shop.platform_manual'),
        ];
    }
}
