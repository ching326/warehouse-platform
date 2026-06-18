<?php

namespace App\Livewire;

use App\Models\PackagingMaterial;
use App\Models\Shop;
use App\Models\Sku;
use App\Models\StockItem;
use App\Models\Tenant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class SkuCreate extends Component
{
    public string $tenantId = '';

    public string $shopId = '';

    public string $sku = '';

    public string $name = '';

    public string $platformSku = '';

    public string $platformProductId = '';

    public string $platformVariantId = '';

    public string $platformVariantName = '';

    public string $platformLabelCode = '';

    public string $skuType = 'single';

    public string $defaultPackagingMaterialId = '';

    public string $status = 'active';

    public string $note = '';

    public string $stockItemMode = 'create';

    public string $existingStockItemId = '';

    public string $stockItemSearch = '';

    public array $stockItem = [
        'name' => '',
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
    }

    public function updatedTenantId(): void
    {
        $this->shopId = '';
        $this->existingStockItemId = '';
    }

    public function updatedSkuType(): void
    {
        if ($this->skuType === 'virtual_bundle' && $this->stockItemMode === 'link') {
            $this->existingStockItemId = '';
        }
    }

    public function save()
    {
        $tenantId = $this->validatedTenantId();
        $this->validateInput($tenantId);

        DB::transaction(function () use ($tenantId) {
            $stockItemId = null;

            if ($this->stockItemMode === 'create') {
                $stockItem = StockItem::create($this->stockItemPayload($tenantId));
                $stockItemId = $stockItem->id;
            } elseif ($this->existingStockItemId !== '') {
                $stockItem = StockItem::query()
                    ->where('tenant_id', $tenantId)
                    ->findOrFail($this->existingStockItemId);
                $stockItemId = $stockItem->id;
            }

            Sku::create([
                'tenant_id' => $tenantId,
                'shop_id' => $this->nullableId($this->shopId),
                'stock_item_id' => $stockItemId,
                'sku' => trim($this->sku),
                'name' => trim($this->name),
                'platform_sku' => $this->nullableString($this->platformSku),
                'platform_product_id' => $this->nullableString($this->platformProductId),
                'platform_variant_id' => $this->nullableString($this->platformVariantId),
                'platform_variant_name' => $this->nullableString($this->platformVariantName),
                'platform_label_code' => $this->nullableString($this->platformLabelCode),
                'sku_type' => $this->skuType,
                'default_packaging_material_id' => $this->nullableId($this->defaultPackagingMaterialId),
                'status' => $this->status,
                'note' => $this->nullableString($this->note),
            ]);
        });

        session()->flash('status', __('skus.sku_created'));

        return redirect()->route('skus.index');
    }

    public function render()
    {
        return view('livewire.sku-create', [
            'tenants' => $this->tenantOptions(),
            'shops' => $this->shopOptions(),
            'packagingMaterials' => $this->packagingMaterialOptions(),
            'stockItems' => $this->stockItemOptions(),
            'showTenantSelect' => $this->isInternalUser(),
            'currentTenant' => $this->currentTenant(),
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
            'sku_type' => ['required', Rule::in(['single', 'virtual_bundle', 'physical_bundle'])],
            'default_packaging_material_id' => ['nullable', Rule::exists('packaging_materials', 'id')],
            'status' => ['required', Rule::in(['active', 'inactive', 'draft', 'archived'])],
            'stock_item_mode' => ['required', Rule::in(['create', 'link'])],
            'existing_stock_item_id' => [
                Rule::requiredIf(fn () => $this->stockItemMode === 'link' && in_array($this->skuType, ['single', 'physical_bundle'], true)),
                'nullable',
                Rule::exists('stock_items', 'id')->where('tenant_id', $tenantId),
            ],
            'stock_item.name' => [Rule::requiredIf(fn () => $this->stockItemMode === 'create'), 'nullable', 'string', 'max:255'],
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
            'sku_type' => $this->skuType,
            'default_packaging_material_id' => $this->defaultPackagingMaterialId === '' ? null : $this->defaultPackagingMaterialId,
            'status' => $this->status,
            'stock_item_mode' => $this->stockItemMode,
            'existing_stock_item_id' => $this->existingStockItemId === '' ? null : $this->existingStockItemId,
            'stock_item' => $this->stockItem,
        ];
    }

    private function stockItemPayload(int $tenantId): array
    {
        return [
            'tenant_id' => $tenantId,
            'code' => $this->nextStockItemCode($tenantId),
            'name' => trim($this->stockItem['name']),
            'short_name' => $this->nullableString($this->stockItem['short_name']),
            'brand' => $this->nullableString($this->stockItem['brand']),
            'model_number' => $this->nullableString($this->stockItem['model_number']),
            'variation_code' => $this->nullableString($this->stockItem['variation_code']),
            'color' => $this->nullableString($this->stockItem['color']),
            'size' => $this->nullableString($this->stockItem['size']),
            'barcode' => $this->nullableString($this->stockItem['barcode']),
            'barcode_type' => $this->stockItem['barcode_type'] ?: 'unknown',
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
        return Auth::user()
            ->tenantUsers()
            ->where('status', 'active')
            ->pluck('tenant_id')
            ->all();
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

    private function stockItemOptions(): Collection
    {
        $search = '%'.$this->stockItemSearch.'%';

        return StockItem::query()
            ->where('tenant_id', $this->tenantId)
            ->when($this->stockItemSearch !== '', function ($query) use ($search) {
                $query->where(function ($query) use ($search) {
                    $query
                        ->where('code', 'like', $search)
                        ->orWhere('name', 'like', $search)
                        ->orWhere('barcode', 'like', $search);
                });
            })
            ->orderBy('code')
            ->limit(30)
            ->get(['id', 'code', 'name', 'barcode']);
    }

    private function currentTenant(): ?Tenant
    {
        if ($this->tenantId === '') {
            return null;
        }

        return Tenant::query()->find($this->tenantId, ['id', 'code', 'name']);
    }

    private function isInternalUser(): bool
    {
        $user = Auth::user();

        return ! $user || $user->user_type === 'internal';
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
