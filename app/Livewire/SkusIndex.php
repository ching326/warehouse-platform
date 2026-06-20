<?php

namespace App\Livewire;

use App\Livewire\Concerns\HasEnumLabels;
use App\Models\ProductType;
use App\Models\Shop;
use App\Models\Sku;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithPagination;

class SkusIndex extends Component
{
    use HasEnumLabels;
    use WithPagination;

    public string $search = '';

    public string $tenantId = '';

    public string $shopId = '';

    public string $status = '';

    public string $skuType = '';

    public string $productType = '';

    public int $perPage = 15;

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatedTenantId(): void
    {
        $this->shopId = '';
        $this->resetPage();
    }

    public function updatedShopId(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedSkuType(): void
    {
        $this->resetPage();
    }

    public function updatedProductType(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        return view('livewire.skus-index', [
            'skus' => $this->skus(),
            'tenants' => $this->tenantOptions(),
            'shops' => $this->shopOptions(),
            'statuses' => $this->statusOptions(),
            'skuTypes' => $this->skuTypeOptions(),
            'productTypes' => $this->productTypeOptions(),
            'showTenantFilter' => $this->isInternalUser(),
        ])->layout('inventory', [
            'title' => __('skus.page_title'),
            'subtitle' => __('skus.page_subtitle'),
            'pageWide' => true,
        ]);
    }

    public function skus()
    {
        return $this->baseQuery()
            ->with([
                'tenant:id,code,name',
                'shop:id,tenant_id,code,name,platform,marketplace',
                'stockItem:id,tenant_id,code,name,barcode,product_type',
                'bundleComponents' => fn ($query) => $query->with('componentStockItem:id,tenant_id,code,name')->orderBy('id'),
                'defaultPackagingMaterial:id,code,name,type',
            ])
            ->latest('id')
            ->paginate($this->perPage);
    }

    public function bundleComposition(Sku $sku, int $limit = 2): string
    {
        $components = $sku->bundleComponents->take($limit)->map(function ($component) {
            $code = $component->componentStockItem?->code ?? __('skus.unknown_stock_item');

            return __('skus.bundle_component', ['code' => $code, 'qty' => number_format($component->quantity)]);
        });

        if ($components->isEmpty()) {
            return __('skus.no_components_configured');
        }

        $composition = $components->implode(' + ');
        $hiddenCount = max(0, $sku->bundleComponents->count() - $limit);

        return $hiddenCount > 0
            ? __('skus.bundle_more', ['composition' => $composition, 'count' => $hiddenCount])
            : $composition;
    }

    public function skuTypeLabel(string $type): string
    {
        return $this->enumLabel('sku_types', $type);
    }

    public function productTypeLabel(string $type): string
    {
        static $map = null;
        $locale = app()->getLocale();
        $map ??= ProductType::all()->mapWithKeys(
            fn ($t) => [$t->slug => $t->translations[$locale] ?? $t->name]
        )->all();

        return $map[$type] ?? $type;
    }

    public function statusLabel(string $status): string
    {
        return $this->enumLabel('statuses', $status);
    }

    private function baseQuery(): Builder
    {
        return Sku::query()
            ->when($this->visibleTenantIds() !== null, fn ($query) => $query->whereIn('tenant_id', $this->visibleTenantIds()))
            ->when($this->tenantId !== '', fn ($query) => $query->where('tenant_id', $this->tenantId))
            ->when($this->shopId !== '', fn ($query) => $query->where('shop_id', $this->shopId))
            ->when($this->status !== '', fn ($query) => $query->where('status', $this->status))
            ->when($this->skuType !== '', fn ($query) => $query->where('sku_type', $this->skuType))
            ->when($this->productType !== '', fn ($query) => $query->whereHas('stockItem', fn ($query) => $query->where('product_type', $this->productType)))
            ->when($this->search !== '', function ($query) {
                $search = '%'.$this->search.'%';

                $query->where(function ($query) use ($search) {
                    $query
                        ->where('sku', 'like', $search)
                        ->orWhere('name', 'like', $search)
                        ->orWhere('platform_sku', 'like', $search)
                        ->orWhere('platform_product_id', 'like', $search)
                        ->orWhere('platform_variant_id', 'like', $search)
                        ->orWhere('platform_label_code', 'like', $search)
                        ->orWhereHas('stockItem', function ($query) use ($search) {
                            $query
                                ->where('code', 'like', $search)
                                ->orWhere('name', 'like', $search)
                                ->orWhere('barcode', 'like', $search);
                        });
                });
            });
    }

    private function tenantOptions(): Collection
    {
        return Tenant::query()
            ->when($this->visibleTenantIds() !== null, fn ($query) => $query->whereIn('id', $this->visibleTenantIds()))
            ->orderBy('name')
            ->get(['id', 'code', 'name']);
    }

    private function shopOptions(): Collection
    {
        return Shop::query()
            ->when($this->visibleTenantIds() !== null, fn ($query) => $query->whereIn('tenant_id', $this->visibleTenantIds()))
            ->when($this->tenantId !== '', fn ($query) => $query->where('tenant_id', $this->tenantId))
            ->orderBy('name')
            ->get(['id', 'tenant_id', 'code', 'name']);
    }

    private function statusOptions(): Collection
    {
        return Sku::query()
            ->when($this->visibleTenantIds() !== null, fn ($query) => $query->whereIn('tenant_id', $this->visibleTenantIds()))
            ->select('status')
            ->distinct()
            ->orderBy('status')
            ->pluck('status');
    }

    private function skuTypeOptions(): Collection
    {
        return Sku::query()
            ->when($this->visibleTenantIds() !== null, fn ($query) => $query->whereIn('tenant_id', $this->visibleTenantIds()))
            ->select('sku_type')
            ->distinct()
            ->orderBy('sku_type')
            ->pluck('sku_type');
    }

    private function productTypeOptions(): Collection
    {
        return ProductType::orderBy('sort_order')->orderBy('name')->get(['slug', 'name']);
    }

    private function isInternalUser(): bool
    {
        $user = Auth::user();

        return $user?->user_type === 'internal';
    }

    private function visibleTenantIds(): ?array
    {
        if ($this->isInternalUser()) {
            return null;
        }

        $user = Auth::user();

        if (! $user) {
            return [];
        }

        return $user
            ->tenantUsers()
            ->where('status', 'active')
            ->pluck('tenant_id')
            ->all();
    }
}
