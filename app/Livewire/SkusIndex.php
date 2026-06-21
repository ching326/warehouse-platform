<?php

namespace App\Livewire;

use App\Livewire\Concerns\HasEnumLabels;
use App\Models\PackagingMaterial;
use App\Models\ProductType;
use App\Models\ShippingMethod;
use App\Models\Shop;
use App\Models\Sku;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
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

    #[Url(as: 'view')]
    public string $view = 'detailed';

    public int $perPage = 15;

    public array $logisticsDrafts = [];

    private const VIEW_DETAILED = 'detailed';
    private const VIEW_CATALOG = 'catalog';
    private const VIEW_MARKETPLACE = 'marketplace';
    private const VIEW_LOGISTICS = 'logistics';

    public function mount(): void
    {
        $queryView = request()->query('view');
        $savedView = Auth::user()?->preference('skus_view');

        $this->view = match (true) {
            is_string($queryView) && $this->isAllowedView($queryView) => $queryView,
            is_string($savedView) && $this->isAllowedView($savedView) => $savedView,
            default => self::VIEW_DETAILED,
        };
    }

    public function updatedView(): void
    {
        if (! $this->isAllowedView($this->view)) {
            $this->view = self::VIEW_DETAILED;
        }

        $this->resetPage();
    }

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

    public function switchView(string $view): void
    {
        $this->view = $this->isAllowedView($view) ? $view : self::VIEW_DETAILED;
        $this->resetPage();
    }

    public function saveDefaultView(): void
    {
        $user = Auth::user();

        if (! $user || ! $this->isAllowedView($this->view)) {
            return;
        }

        $user->setPreference('skus_view', $this->view);
        session()->flash('status', __('skus.default_view_saved'));
    }

    public function saveLogisticsField(int $skuId, string $field): void
    {
        $sku = $this->scopedSkuQuery()
            ->with('stockItem')
            ->find($skuId);

        if (! $sku) {
            abort(404);
        }

        if (! array_key_exists($field, $this->logisticsDrafts[$skuId] ?? [])) {
            return;
        }

        $value = $this->logisticsDrafts[$skuId][$field];

        if (in_array($field, ['short_name', 'weight_value', 'length_value', 'width_value', 'height_value'], true)) {
            $this->saveStockItemField($sku, $field, $value);

            return;
        }

        if ($field === 'default_packaging_material_id') {
            validator([$field => $value === '' ? null : $value], [
                $field => ['nullable', Rule::exists('packaging_materials', 'id')],
            ])->validate();

            $sku->update([$field => $this->nullableId((string) $value)]);
            session()->flash('status', __('skus.inline_saved'));

            return;
        }

        if ($field === 'default_shipping_method_id') {
            $this->saveDefaultShippingMethod($sku, (string) $value);
        }
    }

    public function render()
    {
        if (! $this->isAllowedView($this->view)) {
            $this->view = self::VIEW_DETAILED;
        }

        $skus = $this->skus();
        $this->prepareLogisticsDrafts($skus->getCollection());

        return view('livewire.skus-index', [
            'skus' => $skus,
            'tenants' => $this->tenantOptions(),
            'shops' => $this->shopOptions(),
            'statuses' => $this->statusOptions(),
            'skuTypes' => $this->skuTypeOptions(),
            'productTypes' => $this->productTypeOptions(),
            'showTenantFilter' => $this->isInternalUser(),
            'views' => $this->viewOptions(),
            'flatColumns' => $this->flatColumns(),
            'currentColumnCount' => $this->currentColumnCount(),
            'packagingMaterials' => $this->packagingMaterialOptions(),
            'shippingMethods' => $this->shippingMethodOptions($skus->getCollection()),
            'canSaveDefaultView' => Auth::check(),
        ])->layout('inventory', [
            'title' => __('skus.page_title'),
            'subtitle' => __('skus.page_subtitle'),
            'hidePageHeader' => true,
            'pageWide' => true,
        ]);
    }

    public function skus()
    {
        return $this->baseQuery()
            ->with([
                'tenant:id,code,name',
                'shop:id,tenant_id,code,name,platform,marketplace',
                'stockItem:id,tenant_id,code,name,short_name,barcode,product_type,weight_value,weight_unit,length_value,width_value,height_value,dimension_unit',
                'bundleComponents' => fn ($query) => $query->with('componentStockItem:id,tenant_id,code,name')->orderBy('id'),
                'defaultPackagingMaterial:id,code,name,type',
                'defaultShippingMethod:id,carrier_id,code,name,status',
                'defaultShippingMethod.carrier:id,code,name',
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

    public function flatCellValue(Sku $sku, string $key): string
    {
        $value = match ($key) {
            'sku' => $sku->sku,
            'name' => $sku->name,
            'stock_code' => $sku->stockItem?->code,
            'stock_short_name' => $sku->stockItem?->short_name,
            'shop_code' => $sku->shop?->code,
            'type' => $this->skuTypeLabel($sku->sku_type),
            'status' => $this->statusLabel($sku->status),
            'platform_sku' => $sku->platform_sku,
            'platform_product_id' => $sku->platform_product_id,
            'platform_label_code' => $sku->platform_label_code,
            'platform_variant_name' => $sku->platform_variant_name,
            default => null,
        };

        return filled($value) ? (string) $value : '-';
    }

    public function viewOptions(): array
    {
        return [
            self::VIEW_DETAILED => __('skus.view_detailed'),
            self::VIEW_CATALOG => __('skus.view_catalog'),
            self::VIEW_MARKETPLACE => __('skus.view_marketplace'),
            self::VIEW_LOGISTICS => __('skus.view_logistics'),
        ];
    }

    private function baseQuery(): Builder
    {
        return $this->scopedSkuQuery()
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

    private function scopedSkuQuery(): Builder
    {
        return Sku::query()
            ->when($this->visibleTenantIds() !== null, fn ($query) => $query->whereIn('tenant_id', $this->visibleTenantIds()));
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

    private function packagingMaterialOptions(): Collection
    {
        return PackagingMaterial::query()
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'type']);
    }

    private function shippingMethodOptions(Collection $skus): Collection
    {
        $currentIds = $skus
            ->pluck('default_shipping_method_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        return ShippingMethod::query()
            ->where(function ($query) use ($currentIds) {
                $query->where('shipping_methods.status', 'active')
                    ->when($currentIds !== [], fn ($query) => $query->orWhereIn('shipping_methods.id', $currentIds));
            })
            ->with('carrier:id,code,name')
            ->ordered()
            ->get(['shipping_methods.id', 'shipping_methods.carrier_id', 'shipping_methods.code', 'shipping_methods.name', 'shipping_methods.status']);
    }

    private function prepareLogisticsDrafts(Collection $skus): void
    {
        foreach ($skus as $sku) {
            $this->logisticsDrafts[$sku->id] ??= [
                'short_name' => (string) ($sku->stockItem?->short_name ?? ''),
                'weight_value' => $sku->stockItem?->weight_value !== null ? (string) $sku->stockItem->weight_value : '',
                'length_value' => $sku->stockItem?->length_value !== null ? (string) $sku->stockItem->length_value : '',
                'width_value' => $sku->stockItem?->width_value !== null ? (string) $sku->stockItem->width_value : '',
                'height_value' => $sku->stockItem?->height_value !== null ? (string) $sku->stockItem->height_value : '',
                'default_packaging_material_id' => $sku->default_packaging_material_id ? (string) $sku->default_packaging_material_id : '',
                'default_shipping_method_id' => $sku->default_shipping_method_id ? (string) $sku->default_shipping_method_id : '',
            ];
        }
    }

    private function saveStockItemField(Sku $sku, string $field, mixed $value): void
    {
        if (! $sku->stockItem) {
            return;
        }

        if ((int) $sku->stockItem->tenant_id !== (int) $sku->tenant_id || ! $this->tenantIsVisible((int) $sku->stockItem->tenant_id)) {
            abort(404);
        }

        $rules = [
            'short_name' => ['nullable', 'string', 'max:255'],
            'weight_value' => ['nullable', 'numeric', 'min:0'],
            'length_value' => ['nullable', 'numeric', 'min:0'],
            'width_value' => ['nullable', 'numeric', 'min:0'],
            'height_value' => ['nullable', 'numeric', 'min:0'],
        ];

        if (! isset($rules[$field])) {
            return;
        }

        validator([$field => $value === '' ? null : $value], [$field => $rules[$field]])->validate();

        $sku->stockItem->update([
            $field => $field === 'short_name' ? $this->nullableString((string) $value) : $this->nullableDecimal((string) $value),
        ]);

        $sku->stockItem->refresh();
        $this->refreshSharedStockItemDrafts($sku, $field, $sku->stockItem->{$field});

        session()->flash('status', __('skus.inline_saved'));
    }

    private function refreshSharedStockItemDrafts(Sku $sku, string $field, mixed $value): void
    {
        if (! $sku->stock_item_id) {
            return;
        }

        $draftValue = $this->draftValue($value);
        $visibleSkuIds = $this->scopedSkuQuery()
            ->where('stock_item_id', $sku->stock_item_id)
            ->pluck('id');

        foreach ($visibleSkuIds as $visibleSkuId) {
            if (array_key_exists($visibleSkuId, $this->logisticsDrafts)) {
                $this->logisticsDrafts[$visibleSkuId][$field] = $draftValue;

                continue;
            }

            $stringKey = (string) $visibleSkuId;

            if (array_key_exists($stringKey, $this->logisticsDrafts)) {
                $this->logisticsDrafts[$stringKey][$field] = $draftValue;
            }
        }
    }

    private function saveDefaultShippingMethod(Sku $sku, string $value): void
    {
        $methodId = $this->nullableId($value);

        if ($methodId === null) {
            $sku->update(['default_shipping_method_id' => null]);
            session()->flash('status', __('skus.inline_saved'));

            return;
        }

        if ($sku->default_shipping_method_id === $methodId) {
            return;
        }

        $exists = ShippingMethod::query()
            ->whereKey($methodId)
            ->where('status', 'active')
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages(['default_shipping_method_id' => __('skus.invalid_shipping_method')]);
        }

        $sku->update(['default_shipping_method_id' => $methodId]);
        session()->flash('status', __('skus.inline_saved'));
    }

    private function flatColumns(): array
    {
        return match ($this->view) {
            self::VIEW_CATALOG => [
                'sku' => __('skus.col_sku'),
                'name' => __('skus.col_name'),
                'stock_code' => __('skus.col_stock_item'),
                'stock_short_name' => __('skus.col_short_name'),
                'shop_code' => __('skus.col_shop'),
                'type' => __('skus.col_type'),
                'status' => __('skus.col_status'),
            ],
            self::VIEW_MARKETPLACE => [
                'sku' => __('skus.col_sku'),
                'platform_sku' => __('skus.col_seller_sku'),
                'platform_product_id' => __('skus.col_asin'),
                'platform_label_code' => __('skus.col_fnsku'),
                'platform_variant_name' => __('skus.col_variant'),
                'shop_code' => __('skus.col_shop'),
            ],
            default => [],
        };
    }

    private function currentColumnCount(): int
    {
        return match ($this->view) {
            self::VIEW_CATALOG => 7,
            self::VIEW_MARKETPLACE => 6,
            self::VIEW_LOGISTICS => 8,
            default => 6,
        };
    }

    private function isAllowedView(string $view): bool
    {
        return in_array($view, [self::VIEW_DETAILED, self::VIEW_CATALOG, self::VIEW_MARKETPLACE, self::VIEW_LOGISTICS], true);
    }

    private function isInternalUser(): bool
    {
        $user = Auth::user();

        return $user?->user_type === 'internal';
    }

    private function tenantIsVisible(int $tenantId): bool
    {
        $visibleTenantIds = $this->visibleTenantIds();

        return $visibleTenantIds === null || in_array($tenantId, array_map('intval', $visibleTenantIds), true);
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

        return $user->activeTenantIds();
    }

    private function nullableString(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function nullableDecimal(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function nullableId(string $value): ?int
    {
        return trim($value) === '' ? null : (int) $value;
    }

    private function draftValue(mixed $value): string
    {
        return $value === null ? '' : (string) $value;
    }
}
