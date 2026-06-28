<?php

namespace App\Models;

use App\Models\Concerns\HasLocalizedAttributes;
use Database\Factories\StockItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class StockItem extends Model
{
    /** @use HasFactory<StockItemFactory> */
    use HasFactory, HasLocalizedAttributes, LogsActivity;

    /**
     * Columns needed to render the display name: the localized full name (base +
     * per-locale overrides) plus the language-neutral short_name shorthand.
     */
    public const DISPLAY_NAME_COLUMNS = [
        'name',
        'name_en',
        'name_ja',
        'name_zh_tw',
        'name_zh_cn',
        'short_name',
    ];

    protected $fillable = [
        'tenant_id',
        'code',
        'tenant_item_code',
        'name',
        'name_en',
        'name_ja',
        'name_zh_tw',
        'name_zh_cn',
        'short_name',
        'brand',
        'model_number',
        'variation_code',
        'color',
        'size',
        'barcode',
        'barcode_type',
        'product_type',
        'is_dangerous_goods',
        'requires_expiry_tracking',
        'requires_lot_tracking',
        'description',
        'note',
        'handling_note',
        'weight_value',
        'weight_unit',
        'length_value',
        'width_value',
        'height_value',
        'dimension_unit',
        'status',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('stock_item')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected function casts(): array
    {
        return [
            'is_dangerous_goods' => 'boolean',
            'requires_expiry_tracking' => 'boolean',
            'requires_lot_tracking' => 'boolean',
            'weight_value' => 'decimal:3',
            'length_value' => 'decimal:2',
            'width_value' => 'decimal:2',
            'height_value' => 'decimal:2',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** Localized full name for the given (or current) locale, base name as fallback. */
    public function localizedName(?string $locale = null): string
    {
        return $this->localized('name', $locale);
    }

    /**
     * Operator-facing display name: the language-neutral short_name shorthand,
     * falling back to the localized full name when no short_name is set.
     */
    public function displayName(?string $locale = null): string
    {
        return ($this->short_name ?: '') ?: $this->localizedName($locale);
    }

    public function preferredDisplayCode(bool $showTenantCode): string
    {
        if ($showTenantCode && filled($this->tenant_item_code)) {
            return (string) $this->tenant_item_code;
        }

        return (string) $this->code;
    }

    public function secondaryDisplayCode(bool $showTenantCode): ?string
    {
        if ($showTenantCode && filled($this->tenant_item_code)) {
            return (string) $this->code;
        }

        return null;
    }

    public function skus(): HasMany
    {
        return $this->hasMany(Sku::class);
    }

    public function mediaAssets(): HasMany
    {
        return $this->hasMany(MediaAsset::class, 'model_id')
            ->where('model_type', MediaAsset::MODEL_TYPE_STOCK_ITEM)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function primaryImage(): HasOne
    {
        return $this->hasOne(MediaAsset::class, 'model_id')
            ->where('model_type', MediaAsset::MODEL_TYPE_STOCK_ITEM)
            ->orderByDesc('is_primary')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function bundleComponents(): HasMany
    {
        return $this->hasMany(SkuBundleComponent::class, 'component_stock_item_id');
    }

    public function barcodeAliases(): HasMany
    {
        return $this->hasMany(BarcodeAlias::class, 'model_id')
            ->where('model_type', BarcodeAlias::MODEL_TYPE_STOCK_ITEM);
    }

    public function inventoryBalances(): HasMany
    {
        return $this->hasMany(InventoryBalance::class);
    }

    public function inventoryMovements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }
}
