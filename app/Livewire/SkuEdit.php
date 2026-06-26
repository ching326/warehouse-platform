<?php

namespace App\Livewire;

use App\Exceptions\AliasCollisionException;
use App\Models\BarcodeAlias;
use App\Models\PackagingMaterial;
use App\Models\ProductType;
use App\Models\ShippingMethod;
use App\Models\Shop;
use App\Models\Sku;
use App\Models\StockItem;
use App\Services\BarcodeAliasService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class SkuEdit extends Component
{
    public Sku $sku;

    public string $skuCode = '';

    public string $shopId = '';

    public string $name = '';

    public array $nameTranslations = ['en' => '', 'ja' => '', 'zh_TW' => '', 'zh_CN' => ''];

    public string $platformSku = '';

    public string $platformProductId = '';

    public string $platformVariantId = '';

    public string $platformVariantName = '';

    public string $platformLabelCode = '';

    public string $defaultPackagingMaterialId = '';

    public string $defaultShippingMethodId = '';

    public string $status = 'active';

    public string $note = '';

    public array $stockItem = [
        'name' => '',
        'name_en' => '',
        'name_ja' => '',
        'name_zh_tw' => '',
        'name_zh_cn' => '',
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

    public function mount(Sku $sku): void
    {
        $this->authorizeAccess($sku);

        $sku->load(['stockItem.barcodeAliases', 'tenant']);
        $this->sku = $sku;

        $this->skuCode = $sku->sku;
        $this->shopId = (string) ($sku->shop_id ?? '');
        $this->name = $sku->name;
        $this->nameTranslations = [
            'en' => $sku->name_en ?? '',
            'ja' => $sku->name_ja ?? '',
            'zh_TW' => $sku->name_zh_tw ?? '',
            'zh_CN' => $sku->name_zh_cn ?? '',
        ];
        $this->platformSku = $sku->platform_sku ?? '';
        $this->platformProductId = $sku->platform_product_id ?? '';
        $this->platformVariantId = $sku->platform_variant_id ?? '';
        $this->platformVariantName = $sku->platform_variant_name ?? '';
        $this->platformLabelCode = $sku->platform_label_code ?? '';
        $this->defaultPackagingMaterialId = (string) ($sku->default_packaging_material_id ?? '');
        $this->defaultShippingMethodId = (string) ($sku->default_shipping_method_id ?? '');
        $this->status = $sku->status;
        $this->note = $sku->note ?? '';

        if ($sku->stockItem) {
            $si = $sku->stockItem;
            $this->stockItem = [
                'name' => $si->name,
                'name_en' => $si->name_en ?? '',
                'name_ja' => $si->name_ja ?? '',
                'name_zh_tw' => $si->name_zh_tw ?? '',
                'name_zh_cn' => $si->name_zh_cn ?? '',
                'short_name' => $si->short_name ?? '',
                'brand' => $si->brand ?? '',
                'model_number' => $si->model_number ?? '',
                'variation_code' => $si->variation_code ?? '',
                'color' => $si->color ?? '',
                'size' => $si->size ?? '',
                'barcode' => $this->stockItemPrimaryBarcode($si) ?? $si->barcode ?? '',
                'barcode_type' => $this->stockItemPrimaryBarcodeType($si) ?? $si->barcode_type ?? 'unknown',
                'product_type' => $si->product_type ?? 'normal',
                'is_dangerous_goods' => (bool) $si->is_dangerous_goods,
                'requires_expiry_tracking' => (bool) $si->requires_expiry_tracking,
                'requires_lot_tracking' => (bool) $si->requires_lot_tracking,
                'description' => $si->description ?? '',
                'note' => $si->note ?? '',
                'handling_note' => $si->handling_note ?? '',
                'weight_value' => (string) ($si->weight_value ?? ''),
                'weight_unit' => $si->weight_unit ?? 'g',
                'length_value' => (string) ($si->length_value ?? ''),
                'width_value' => (string) ($si->width_value ?? ''),
                'height_value' => (string) ($si->height_value ?? ''),
                'dimension_unit' => $si->dimension_unit ?? 'cm',
                'status' => $si->status ?? 'active',
            ];
        }
    }

    public function save()
    {
        $this->validateInput();

        try {
            DB::transaction(function () {
                $barcodeAliases = app(BarcodeAliasService::class);

                $this->sku->update([
                    'sku' => trim($this->skuCode),
                    'shop_id' => $this->nullableId($this->shopId),
                    'name' => trim($this->name),
                    'name_en' => $this->nullableString($this->nameTranslations['en'] ?? ''),
                    'name_ja' => $this->nullableString($this->nameTranslations['ja'] ?? ''),
                    'name_zh_tw' => $this->nullableString($this->nameTranslations['zh_TW'] ?? ''),
                    'name_zh_cn' => $this->nullableString($this->nameTranslations['zh_CN'] ?? ''),
                    'platform_sku' => $this->nullableString($this->platformSku),
                    'platform_product_id' => $this->nullableString($this->platformProductId),
                    'platform_variant_id' => $this->nullableString($this->platformVariantId),
                    'platform_variant_name' => $this->nullableString($this->platformVariantName),
                    'default_packaging_material_id' => $this->nullableId($this->defaultPackagingMaterialId),
                    'default_shipping_method_id' => $this->nullableId($this->defaultShippingMethodId),
                    'status' => $this->status,
                    'note' => $this->nullableString($this->note),
                ]);

                $barcodeAliases->setSkuPlatformLabel($this->sku->refresh(), $this->platformLabelCode);

                if ($this->sku->stockItem) {
                    $this->sku->stockItem->update([
                        'name' => trim($this->stockItem['name']) ?: trim($this->name),
                        'name_en' => $this->nullableString($this->stockItem['name_en'] ?? ''),
                        'name_ja' => $this->nullableString($this->stockItem['name_ja'] ?? ''),
                        'name_zh_tw' => $this->nullableString($this->stockItem['name_zh_tw'] ?? ''),
                        'name_zh_cn' => $this->nullableString($this->stockItem['name_zh_cn'] ?? ''),
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
                    ]);

                    $barcodeAliases->setPrimaryProductBarcode(
                        $this->sku->stockItem,
                        $this->stockItem['barcode'] ?? '',
                        $this->stockItem['barcode_type'] ?: 'unknown',
                    );
                }
            });
        } catch (AliasCollisionException) {
            throw ValidationException::withMessages(['platformLabelCode' => __('skus.fnsku_alias_conflict')]);
        }

        session()->flash('status', __('skus.sku_updated'));

        return redirect()->route('skus.index');
    }

    public function render()
    {
        $tenant = $this->sku->tenant;

        return view('livewire.sku-edit', [
            'shops' => $this->shopOptions(),
            'packagingMaterials' => $this->packagingMaterialOptions(),
            'shippingMethods' => $this->shippingMethodOptions(),
            'productTypes' => ProductType::orderBy('sort_order')->orderBy('name')->get(['slug', 'name']),
            'skuNameBaseLocale' => $tenant?->sku_name_locale ?: 'en',
            'stockItemNameBaseLocale' => $tenant?->stock_item_name_locale ?: 'en',
            'skuNameHasTranslations' => array_filter($this->nameTranslations) !== [],
            'stockItemHasTranslations' => $this->sku->stockItem !== null && array_filter([
                $this->stockItem['name_en'],
                $this->stockItem['name_ja'],
                $this->stockItem['name_zh_tw'],
                $this->stockItem['name_zh_cn'],
            ]) !== [],
        ])->layout('inventory', [
            'title' => __('skus.sku_edit_page_title'),
            'subtitle' => $this->sku->sku.' — '.$this->sku->tenant->name,
        ]);
    }

    private function authorizeAccess(Sku $sku): void
    {
        if ($this->isInternalUser()) {
            return;
        }

        $allowed = Auth::user()?->activeTenantIds() ?? [];

        if (! in_array($sku->tenant_id, $allowed, true)) {
            abort(403);
        }
    }

    private function stockItemPrimaryBarcode(StockItem $stockItem): ?string
    {
        $alias = $stockItem->barcodeAliases
            ->where('is_active', true)
            ->sortByDesc('is_primary')
            ->first();

        return $alias instanceof BarcodeAlias ? $alias->barcode : null;
    }

    private function stockItemPrimaryBarcodeType(StockItem $stockItem): ?string
    {
        $alias = $stockItem->barcodeAliases
            ->where('is_active', true)
            ->sortByDesc('is_primary')
            ->first();

        return $alias instanceof BarcodeAlias ? $alias->barcode_type : null;
    }

    private function validateInput(): void
    {
        $tenantId = $this->sku->tenant_id;
        $shopId = $this->nullableId($this->shopId);

        validator([
            'sku' => trim($this->skuCode),
            'name' => trim($this->name),
            'name_translations' => $this->nameTranslations,
            'shop_id' => $shopId,
            'default_packaging_material_id' => $this->defaultPackagingMaterialId === '' ? null : $this->defaultPackagingMaterialId,
            'default_shipping_method_id' => $this->defaultShippingMethodId === '' ? null : $this->defaultShippingMethodId,
            'status' => $this->status,
            'stock_item.name' => $this->stockItem['name'] ?? null,
            'stock_item.name_en' => $this->stockItem['name_en'] ?? null,
            'stock_item.name_ja' => $this->stockItem['name_ja'] ?? null,
            'stock_item.name_zh_tw' => $this->stockItem['name_zh_tw'] ?? null,
            'stock_item.name_zh_cn' => $this->stockItem['name_zh_cn'] ?? null,
            'stock_item.weight_value' => $this->stockItem['weight_value'],
            'stock_item.length_value' => $this->stockItem['length_value'],
            'stock_item.width_value' => $this->stockItem['width_value'],
            'stock_item.height_value' => $this->stockItem['height_value'],
        ], [
            'sku' => [
                'required', 'string', 'max:255',
                Rule::unique('skus', 'sku')
                    ->where('tenant_id', $tenantId)
                    ->when($shopId === null, fn ($r) => $r->whereNull('shop_id'), fn ($r) => $r->where('shop_id', $shopId))
                    ->ignore($this->sku->id),
            ],
            'name' => ['required', 'string', 'max:255'],
            'name_translations.*' => ['nullable', 'string', 'max:255'],
            'shop_id' => ['nullable', Rule::exists('shops', 'id')->where('tenant_id', $tenantId)],
            'default_packaging_material_id' => ['nullable', Rule::exists('packaging_materials', 'id')],
            'default_shipping_method_id' => ['nullable', Rule::exists('shipping_methods', 'id')->where('status', 'active')],
            'status' => ['required', Rule::in(['active', 'inactive', 'draft', 'archived'])],
            'stock_item.name' => ['nullable', 'string', 'max:255'],
            'stock_item.name_en' => ['nullable', 'string', 'max:255'],
            'stock_item.name_ja' => ['nullable', 'string', 'max:255'],
            'stock_item.name_zh_tw' => ['nullable', 'string', 'max:255'],
            'stock_item.name_zh_cn' => ['nullable', 'string', 'max:255'],
            'stock_item.weight_value' => ['nullable', 'numeric', 'min:0'],
            'stock_item.length_value' => ['nullable', 'numeric', 'min:0'],
            'stock_item.width_value' => ['nullable', 'numeric', 'min:0'],
            'stock_item.height_value' => ['nullable', 'numeric', 'min:0'],
        ])->validate();
    }

    private function shopOptions(): Collection
    {
        return Shop::query()
            ->where('tenant_id', $this->sku->tenant_id)
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

    private function isInternalUser(): bool
    {
        return Auth::user()?->user_type === 'internal';
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
