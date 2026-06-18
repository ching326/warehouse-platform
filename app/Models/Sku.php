<?php

namespace App\Models;

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
    use HasFactory, LogsActivity;

    protected $fillable = [
        'tenant_id',
        'shop_id',
        'stock_item_id',
        'sku',
        'name',
        'platform_sku',
        'platform_product_id',
        'platform_variant_id',
        'platform_variant_name',
        'platform_label_code',
        'sku_type',
        'default_packaging_material_id',
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

    public function defaultPackagingMaterial(): BelongsTo
    {
        return $this->belongsTo(PackagingMaterial::class, 'default_packaging_material_id');
    }

    public function bundleComponents(): HasMany
    {
        return $this->hasMany(SkuBundleComponent::class, 'bundle_sku_id');
    }

}
