<?php

namespace App\Models;

use App\Models\Concerns\HasLocalizedAttributes;
use Database\Factories\SkuFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Sku extends Model
{
    /** @use HasFactory<SkuFactory> */
    use HasFactory, HasLocalizedAttributes, LogsActivity;

    /** Columns holding the localized SKU name (base + per-locale overrides). */
    public const DISPLAY_NAME_COLUMNS = [
        'name',
        'name_ja',
        'name_zh_tw',
        'name_zh_cn',
    ];

    protected $fillable = [
        'tenant_id',
        'shop_id',
        'stock_item_id',
        'sku',
        'barcode',
        'name',
        'name_ja',
        'name_zh_tw',
        'name_zh_cn',
        'platform_sku',
        'platform_product_id',
        'platform_variant_id',
        'platform_variant_name',
        'platform_label_code',
        'sku_type',
        'default_packaging_material_id',
        'default_shipping_method_id',
        'status',
        'note',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('sku')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(StockItem::class);
    }

    /** Localized SKU name for the given (or current) locale, base name as fallback. */
    public function localizedName(?string $locale = null): string
    {
        return $this->localized('name', $locale);
    }

    /**
     * Product label for SKU list views: the stock item's localized short name,
     * then this SKU's own localized name, then the stock item's localized full
     * name. Mirrors the long-standing sales/fulfillment index ordering, which
     * prefers the SKU name over the stock item's verbose full name.
     */
    public function displayName(?string $locale = null): string
    {
        $shortName = $this->stockItem?->localizedShortName($locale) ?? '';

        if ($shortName !== '') {
            return $shortName;
        }

        $ownName = $this->localizedName($locale);

        if ($ownName !== '') {
            return $ownName;
        }

        return $this->stockItem?->localizedName($locale) ?? '';
    }

    public function defaultPackagingMaterial(): BelongsTo
    {
        return $this->belongsTo(PackagingMaterial::class, 'default_packaging_material_id');
    }

    public function defaultShippingMethod(): BelongsTo
    {
        return $this->belongsTo(ShippingMethod::class, 'default_shipping_method_id');
    }

    public function bundleComponents(): HasMany
    {
        return $this->hasMany(SkuBundleComponent::class, 'bundle_sku_id');
    }

    public function barcodeAliases(): HasMany
    {
        return $this->hasMany(BarcodeAlias::class, 'model_id')
            ->where('model_type', BarcodeAlias::MODEL_TYPE_SKU);
    }
}
