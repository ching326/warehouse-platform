<?php

namespace App\Models;

use Database\Factories\SkuFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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

    /** Product label for SKU list views, sourced only from the linked stock item. */
    public function displayName(?string $locale = null): string
    {
        return $this->stockItem?->displayName($locale) ?? '';
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

    public function canBeDeleted(): bool
    {
        return ! $this->hasBusinessUsage();
    }

    public function hasBusinessUsage(): bool
    {
        foreach ($this->businessUsageChecks() as [$table, $column]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            if (DB::table($table)->where($column, $this->id)->exists()) {
                return true;
            }
        }

        return false;
    }

    public function deleteOwnedBarcodeAliases(): void
    {
        BarcodeAlias::query()
            ->where('model_type', BarcodeAlias::MODEL_TYPE_SKU)
            ->where('model_id', $this->id)
            ->delete();
    }

    /**
     * @return array<int, array{0: string, 1: string}>
     */
    private function businessUsageChecks(): array
    {
        return [
            ['sales_order_lines', 'sku_id'],
            ['inbound_order_lines', 'sku_id'],
            ['inbound_receipts', 'sku_id'],
            ['outbound_order_lines', 'sku_id'],
            ['sku_bundle_components', 'bundle_sku_id'],
            ['sku_bundle_components', 'component_sku_id'],
            ['issue_lines', 'sku_id'],
            ['exception_case_lines', 'sku_id'],
            ['return_order_lines', 'sku_id'],
            ['fulfillment_pack_scans', 'sku_id'],
        ];
    }
}
