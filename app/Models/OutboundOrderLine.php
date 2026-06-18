<?php

namespace App\Models;

use Database\Factories\OutboundOrderLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OutboundOrderLine extends Model
{
    /** @use HasFactory<OutboundOrderLineFactory> */
    use HasFactory;

    protected $fillable = [
        'outbound_order_id',
        'parent_line_id',
        'tenant_id',
        'sku_id',
        'stock_item_id',
        'qty',
        'inventory_movement_id',
        'note',
    ];

    protected function casts(): array
    {
        return ['qty' => 'integer'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(OutboundOrder::class, 'outbound_order_id');
    }

    public function parentLine(): BelongsTo
    {
        return $this->belongsTo(OutboundOrderLine::class, 'parent_line_id');
    }

    public function childLines(): HasMany
    {
        return $this->hasMany(OutboundOrderLine::class, 'parent_line_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function sku(): BelongsTo
    {
        return $this->belongsTo(Sku::class);
    }

    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(StockItem::class);
    }

    public function inventoryMovement(): BelongsTo
    {
        return $this->belongsTo(InventoryMovement::class);
    }
}
