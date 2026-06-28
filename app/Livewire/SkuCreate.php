<?php

namespace App\Livewire;

use App\Exceptions\AliasCollisionException;
use App\Models\PackagingMaterial;
use App\Models\ProductType;
use App\Models\ShippingMethod;
use App\Models\Shop;
use App\Models\Sku;
use App\Models\StockItem;
use App\Models\Tenant;
use App\Services\BarcodeAliasService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\Component;

class SkuCreate extends Component
{
    #[Url(as: 'tenant_id', except: '')]
    public string $tenantId = '';

    #[Url(as: 'shop_id', except: '')]
    public string $shopId = '';

    #[Url(as: 'sku', except: '')]
    public string $sku = '';

    #[Url(as: 'name', except: '')]
    public string $name = '';

    /** Per-locale SKU name overrides keyed by app locale; base name is the fallback. */
    public array $nameTranslations = ['en' => '', 'ja' => '', 'zh_TW' => '', 'zh_CN' => ''];

    #[Url(as: 'platform_sku', except: '')]
    public string $platformSku = '';

    public string $platformProductId = '';

    public string $platformVariantId = '';

    public string $platformVariantName = '';

    public string $platformLabelCode = '';

    public string $skuType = 'single';

    public string $defaultPackagingMaterialId = '';

    public string $defaultShippingMethodId = '';

    public string $status = 'active';

    public string $note = '';

    public string $stockItemMode = 'create';

    public string $existingStockItemId = '';

    public string $stockItemSearch = '';

    public array $stockItem = [
        'name' => '',
        'name_en' => '',
        'name_ja' => '',
        'name_zh_tw' => '',
        'name_zh_cn' => '',
        'tenant_item_code' => '',
        'short_name' => '',
        'brand' => '',
        'model_number' => '',
        'variation_code' => '',
        'color' => '',
        'size' => '',
        'barcode' => '',
        'barcode_type' => 'unknown',
        'product_type' => 'normal',
        'is_dangerous_goods' => false,
        'requires_expiry_tracking' => false,
        'requires_lot_tracking' => false,
        'description' => '',
        'note' => '',
        'handling_note' => '',
        'weight_value' => '',
        'weight_unit' => 'g',
        'length_value' => '',
        'width_value' => '',
        'height_value' => '',
        'dimension_unit' => 'cm',
        'status' => 'active',
    ];

    public function mount(): void
    {
        if (! $this->isInternalUser()) {
            $this->tenantId = (string) ($this->activeTenantIds()[0] ?? '');
        }

        $this->normalizeQueryPrefill();
    }

    public function updatedTenantId(): void
    {
        $this->shopId = '';
        $this->existingStockItemId = '';
        $this->stockItemSearch = '';
    }

    public function updatedSkuType(): void
    {
        if ($this->skuType === 'virtual_bundle') {
            $this->stockItemMode = 'create';
            $this->existingStockItemId = '';
        }
    }

    public function updatedStockItemSearch(): void
    {
        $this->existingStockItemId = '';
    }

    public function save()
    {
        $tenantId = $this->validatedTenantId();
        $this->validateInput($tenantId);

        try {
            DB::transaction(function () use ($tenantId) {
                $barcodeAliases = app(BarcodeAliasService::class);
                $stockItemId = null;
                $stockItem = null;

                if ($this->skuType !== 'virtual_bundle') {
                    if ($this->stockItemMode === 'create') {
                        $stockItem = StockItem::create($this->stockItemPayload($tenantId));
                        $stockItemId = $stockItem->id;
                    } elseif ($this->existingStockItemId !== '') {
                        $stockItem = StockItem::query()
                            ->where('tenant_id', $tenantId)
                            ->findOrFail($this->existingStockItemId);
                        $stockItemId = $stockItem->id;
                    }

                    if ($stockItem && trim((string) ($this->stockItem['barcode'] ?? '')) !== '') {
                        $barcodeAliases->setPrimaryProductBarcode(
                            $stockItem,
                            $this->stockItem['barcode'] ?? '',
                            $this->stockItem['barcode_type'] ?: 'unknown',
                        );
                    }
                }

                $sku = Sku::create([
                    'tenant_id' => $tenantId,
                    'shop_id' => $this->nullableId($this->shopId),
                    'stock_item_id' => $stockItemId,
                    'sku' => trim($this->sku),
                    'name' => trim($this->name),
                    'name_en' => $this->nullableString($this->nameTranslations['en'] ?? ''),
                    'name_ja' => $this->nullableString($this->nameTranslations['ja'] ?? ''),
                    'name_zh_tw' => $this->nullableString($this->nameTranslations['zh_TW'] ?? ''),
                    'name_zh_cn' => $this->nullableString($this->nameTranslations['zh_CN'] ?? ''),
                    'platform_sku' => $this->nullableString($this->platformSku),
                    'platform_product_id' => $this->nullableString($this->platformProductId),
                    'platform_variant_id' => $this->nullableString($this->platformVariantId),
                    'platform_variant_name' => $this->nullableString($this->platformVariantName),
                    'platform_label_code' => null,
                    'sku_type' => $this->skuType,
                    'default_packaging_material_id' => $this->nullableId($this->defaultPackagingMaterialId),
                    'default_shipping_method_id' => $this->nullableId($this->defaultShippingMethodId),
                    'status' => $this->status,
                    'note' => $this->nullableString($this->note),
                ]);

                $barcodeAliases->setSkuPlatformLabel($sku, $this->platformLabelCode);
            });
        } catch (AliasCollisionException) {
            throw ValidationException::withMessages(['platformLabelCode' => __('skus.fnsku_alias_conflict')]);
        }

        session()->flash('status', __('skus.sku_created'));

        return redirect()->route('skus.index');
    }

    public function render()
    {
        $currentTenant = $this->currentTenant();

        return view('livewire.sku-create', [
            'tenants' => $this->tenantOptions(),
            'shops' => $this->shopOptions(),
            'packagingMaterials' => $this->packagingMaterialOptions(),
            'shippingMethods' => $this->shippingMethodOptions(),
            'stockItems' => $this->stockItemOptions(),
            'productTypes' => ProductType::orderBy('sort_order')->orderBy('name')->get(['slug', 'name']),
            'showTenantSelect' => $this->isInternalUser(),
            'currentTenant' => $currentTenant,
            'skuNameBaseLocale' => $currentTenant?->sku_name_locale ?: 'en',
            'stockItemNameBaseLocale' => $currentTenant?->stock_item_name_locale ?: 'en',
        ])->layout('inventory', [
            'title' => __('skus.create_page_title'),
            'subtitle' => __('skus.create_page_subtitle'),
        ]);
    }

    private function validateInput(int $tenantId): void
    {
        $shopId = $this->nullableId($this->shopId);

        validator($this->formData(), [
            'tenant_id' => ['required', 'integer'],
            'shop_id' => ['nullable', Rule::exists('shops', 'id')->where('tenant_id', $tenantId)],
            'sku' => [
                'required',
                'string',
                'max:255',
                Rule::unique('skus', 'sku')
                    ->where('tenant_id', $tenantId)
                    ->when($shopId === null, fn ($rule) => $rule->whereNull('shop_id'), fn ($rule) => $rule->where('shop_id', $shopId)),
            ],
            'name' => ['required', 'string', 'max:255'],
            'name_translations.*' => ['nullable', 'string', 'max:255'],
            'sku_type' => ['required', Rule::in(['single', 'virtual_bundle', 'physical_bundle'])],
            'default_packaging_material_id' => ['nullable', Rule::exists('packaging_materials', 'id')],
            'default_shipping_method_id' => ['nullable', Rule::exists('shipping_methods', 'id')->where('status', 'active')],
            'status' => ['required', Rule::in(['active', 'inactive', 'draft', 'archived'])],
            'stock_item_mode' => ['required', Rule::in(['create', 'link'])],
            'existing_stock_item_id' => [
                Rule::requiredIf(fn () => $this->stockItemMode === 'link' && in_array($this->skuType, ['single', 'physical_bundle'], true)),
                'nullable',
                Rule::exists('stock_items', 'id')->where('tenant_id', $tenantId),
            ],
            'stock_item.name' => ['nullable', 'string', 'max:255'],
            'stock_item.name_en' => ['nullable', 'string', 'max:255'],
            'stock_item.name_ja' => ['nullable', 'string', 'max:255'],
            'stock_item.name_zh_tw' => ['nullable', 'string', 'max:255'],
            'stock_item.name_zh_cn' => ['nullable', 'string', 'max:255'],
            'stock_item.tenant_item_code' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('stock_items', 'tenant_item_code')->where('tenant_id', $tenantId),
            ],
            'stock_item.weight_value' => ['nullable', 'numeric', 'min:0'],
            'stock_item.length_value' => ['nullable', 'numeric', 'min:0'],
            'stock_item.width_value' => ['nullable', 'numeric', 'min:0'],
            'stock_item.height_value' => ['nullable', 'numeric', 'min:0'],
        ])->validate();
    }

    private function formData(): array
    {
        return [
            'tenant_id' => $this->tenantId,
            'shop_id' => $this->shopId === '' ? null : $this->shopId,
            'sku' => trim($this->sku),
            'name' => trim($this->name),
            'name_translations' => $this->nameTranslations,
            'sku_type' => $this->skuType,
            'default_packaging_material_id' => $this->defaultPackagingMaterialId === '' ? null : $this->defaultPackagingMaterialId,
            'default_shipping_method_id' => $this->defaultShippingMethodId === '' ? null : $this->defaultShippingMethodId,
            'status' => $this->status,
            'stock_item_mode' => $this->stockItemMode,
            'existing_stock_item_id' => $this->existingStockItemId === '' ? null : $this->existingStockItemId,
            'stock_item' => [
                ...$this->stockItem,
                'tenant_item_code' => $this->nullableString($this->stockItem['tenant_item_code'] ?? ''),
            ],
        ];
    }

    private function stockItemPayload(int $tenantId): array
    {
        return [
            'tenant_id' => $tenantId,
            'code' => $this->nextStockItemCode($tenantId),
            'name' => trim($this->stockItem['name']) ?: trim($this->name),
            'name_en' => $this->nullableString($this->stockItem['name_en'] ?? ''),
            'name_ja' => $this->nullableString($this->stockItem['name_ja'] ?? ''),
            'name_zh_tw' => $this->nullableString($this->stockItem['name_zh_tw'] ?? ''),
            'name_zh_cn' => $this->nullableString($this->stockItem['name_zh_cn'] ?? ''),
            'tenant_item_code' => $this->nullableString($this->stockItem['tenant_item_code'] ?? ''),
            'short_name' => $this->nullableString($this->stockItem['short_name']),
            'brand' => $this->nullableString($this->stockItem['brand']),
            'model_number' => $this->nullableString($this->stockItem['model_number']),
            'variation_code' => $this->nullableString($this->stockItem['variation_code']),
            'color' => $this->nullableString($this->stockItem['color']),
            'size' => $this->nullableString($this->stockItem['size']),
            'product_type' => $this->stockItem['product_type'] ?: 'normal',
            'is_dangerous_goods' => (bool) $this->stockItem['is_dangerous_goods'],
            'requires_expiry_tracking' => (bool) $this->stockItem['requires_expiry_tracking'],
            'requires_lot_tracking' => (bool) $this->stockItem['requires_lot_tracking'],
            'description' => $this->nullableString($this->stockItem['description']),
            'note' => $this->nullableString($this->stockItem['note']),
            'handling_note' => $this->nullableString($this->stockItem['handling_note']),
            'weight_value' => $this->nullableDecimal($this->stockItem['weight_value']),
            'weight_unit' => $this->stockItem['weight_unit'] ?: 'g',
            'length_value' => $this->nullableDecimal($this->stockItem['length_value']),
            'width_value' => $this->nullableDecimal($this->stockItem['width_value']),
            'height_value' => $this->nullableDecimal($this->stockItem['height_value']),
            'dimension_unit' => $this->stockItem['dimension_unit'] ?: 'cm',
            'status' => $this->stockItem['status'] ?: 'active',
        ];
    }

    private function nextStockItemCode(int $tenantId): string
    {
        $tenantCode = Tenant::query()->whereKey($tenantId)->value('code');
        $prefix = $tenantCode ?: 'TENANT';

        $lastCode = StockItem::query()
            ->where('tenant_id', $tenantId)
            ->where('code', 'like', $prefix.'-%')
            ->lockForUpdate()
            ->orderByDesc('code')
            ->value('code');

        $next = $lastCode && preg_match('/^'.preg_quote($prefix, '/').'-(\d+)$/', $lastCode, $matches)
            ? ((int) $matches[1]) + 1
            : 1;

        return $prefix.'-'.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }

    private function validatedTenantId(): int
    {
        $tenantId = (int) $this->tenantId;

        if ($tenantId <= 0 || ! in_array($tenantId, $this->allowedTenantIds(), true)) {
            throw ValidationException::withMessages(['tenantId' => __('skus.invalid_tenant')]);
        }

        return $tenantId;
    }

    private function allowedTenantIds(): array
    {
        if ($this->isInternalUser()) {
            return Tenant::query()->pluck('id')->all();
        }

        return $this->activeTenantIds();
    }

    private function activeTenantIds(): array
    {
        $user = Auth::user();

        if (! $user) {
            return [];
        }

        return $user->activeTenantIds();
    }

    private function tenantOptions(): Collection
    {
        return Tenant::query()
            ->whereIn('id', $this->allowedTenantIds())
            ->orderBy('name')
            ->get(['id', 'code', 'name']);
    }

    private function shopOptions(): Collection
    {
        return Shop::query()
            ->where('tenant_id', $this->tenantId)
            ->orderBy('name')
            ->get(['id', 'code', 'name']);
    }

    private function packagingMaterialOptions(): Collection
    {
        return PackagingMaterial::query()
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'type']);
    }

    private function shippingMethodOptions(): Collection
    {
        return ShippingMethod::query()
            ->where('shipping_methods.status', 'active')
            ->with('carrier:id,code,name')
            ->ordered()
            ->get(['shipping_methods.id', 'shipping_methods.carrier_id', 'shipping_methods.code', 'shipping_methods.name']);
    }

    private function stockItemOptions(): Collection
    {
        $searchTerm = trim($this->stockItemSearch);
        $search = '%'.$searchTerm.'%';

        return StockItem::query()
            ->where('tenant_id', $this->tenantId)
            ->when($searchTerm !== '', function ($query) use ($search) {
                $query->where(function ($query) use ($search) {
                    $query
                        ->where('code', 'like', $search)
                        ->orWhere('tenant_item_code', 'like', $search)
                        ->orWhere('name', 'like', $search)
                        ->orWhere('name_en', 'like', $search)
                        ->orWhere('name_ja', 'like', $search)
                        ->orWhere('name_zh_tw', 'like', $search)
                        ->orWhere('name_zh_cn', 'like', $search)
                        ->orWhere('short_name', 'like', $search)
                        ->orWhere('barcode', 'like', $search)
                        ->orWhereHas('skus', function ($query) use ($search) {
                            $query
                                ->where('sku', 'like', $search)
                                ->orWhere('name', 'like', $search)
                                ->orWhere('platform_sku', 'like', $search)
                                ->orWhere('platform_label_code', 'like', $search);
                        });
                });
            })
            ->orderBy('code')
            ->limit(30)
            ->get(['id', 'code', 'tenant_item_code', ...StockItem::DISPLAY_NAME_COLUMNS, 'barcode']);
    }

    private function currentTenant(): ?Tenant
    {
        if ($this->tenantId === '') {
            return null;
        }

        return Tenant::query()->find($this->tenantId, ['id', 'code', 'name', 'sku_name_locale', 'stock_item_name_locale']);
    }

    private function normalizeQueryPrefill(): void
    {
        $allowedTenantIds = $this->allowedTenantIds();

        if ($this->shopId !== '') {
            $shop = Shop::query()
                ->whereIn('tenant_id', $allowedTenantIds)
                ->find((int) $this->shopId, ['id', 'tenant_id']);

            if (! $shop) {
                $this->shopId = '';
            } elseif ($this->tenantId === '' || (int) $this->tenantId !== $shop->tenant_id) {
                $this->tenantId = (string) $shop->tenant_id;
            }
        }

        if ($this->tenantId !== '' && ! in_array((int) $this->tenantId, $allowedTenantIds, true)) {
            $this->tenantId = (string) ($allowedTenantIds[0] ?? '');
            $this->shopId = '';
        }
    }

    private function isInternalUser(): bool
    {
        $user = Auth::user();

        return $user?->user_type === 'internal';
    }

    private function nullableString(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function nullableId(string $value): ?int
    {
        return $value === '' ? null : (int) $value;
    }

    private function nullableDecimal(string $value): ?string
    {
        return trim($value) === '' ? null : trim($value);
    }
}
