<?php

namespace App\Models;

use Database\Factories\InventoryBalanceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryBalance extends Model
{
    /** @use HasFactory<InventoryBalanceFactory> */
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'warehouse_id',
        'stock_item_id',
        'on_hand_qty',
        'reserved_qty',
        'available_qty',
        'inbound_qty',
        'hold_qty',
        'damaged_qty',
    ];

    protected function casts(): array
    {
        return [
            'on_hand_qty' => 'integer',
            'reserved_qty' => 'integer',
            'available_qty' => 'integer',
            'inbound_qty' => 'integer',
            'hold_qty' => 'integer',
            'damaged_qty' => 'integer',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(StockItem::class);
    }

}
