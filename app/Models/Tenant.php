<?php

namespace App\Models;

use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory, LogsActivity;

    public const FULFILLMENT_ITEM_CODE_SOURCE_SKU = 'sku';

    public const FULFILLMENT_ITEM_CODE_SOURCE_TENANT_ITEM_CODE = 'tenant_item_code';

    public const FULFILLMENT_ITEM_CODE_SOURCE_STOCK_ITEM_CODE = 'stock_item_code';

    public const FULFILLMENT_ITEM_CODE_SOURCES = [
        self::FULFILLMENT_ITEM_CODE_SOURCE_SKU,
        self::FULFILLMENT_ITEM_CODE_SOURCE_TENANT_ITEM_CODE,
        self::FULFILLMENT_ITEM_CODE_SOURCE_STOCK_ITEM_CODE,
    ];

    protected $fillable = [
        'code',
        'name',
        'contact_name',
        'contact_email',
        'contact_phone',
        'billing_terms',
        'status',
        'notes',
        'sku_name_locale',
        'stock_item_name_locale',
        'fulfillment_item_code_source',
        'default_warehouse_id',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('tenant')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function tenantUsers(): HasMany
    {
        return $this->hasMany(TenantUser::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'tenant_users')
            ->withPivot(['role', 'status', 'invited_at', 'joined_at'])
            ->withTimestamps();
    }

    public function shops(): HasMany
    {
        return $this->hasMany(Shop::class);
    }

    public function stockItems(): HasMany
    {
        return $this->hasMany(StockItem::class);
    }

    public function skus(): HasMany
    {
        return $this->hasMany(Sku::class);
    }

    public function skuBundleComponents(): HasMany
    {
        return $this->hasMany(SkuBundleComponent::class);
    }

    public function inventoryBalances(): HasMany
    {
        return $this->hasMany(InventoryBalance::class);
    }

    public function defaultWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'default_warehouse_id');
    }

    public function inventoryMovements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }

    public function feeRates(): HasMany
    {
        return $this->hasMany(FeeRate::class);
    }
}
