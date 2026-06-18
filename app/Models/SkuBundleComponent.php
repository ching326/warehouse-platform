<?php

namespace App\Models;

use Database\Factories\SkuBundleComponentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SkuBundleComponent extends Model
{
    /** @use HasFactory<SkuBundleComponentFactory> */
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'bundle_sku_id',
        'component_stock_item_id',
        'quantity',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function bundleSku(): BelongsTo
    {
        return $this->belongsTo(Sku::class, 'bundle_sku_id');
    }

    public function componentStockItem(): BelongsTo
    {
        return $this->belongsTo(StockItem::class, 'component_stock_item_id');
    }
}
