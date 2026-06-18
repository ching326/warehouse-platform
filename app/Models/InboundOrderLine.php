<?php

namespace App\Models;

use Database\Factories\InboundOrderLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InboundOrderLine extends Model
{
    /** @use HasFactory<InboundOrderLineFactory> */
    use HasFactory;

    protected $fillable = [
        'inbound_order_id',
        'tenant_id',
        'sku_id',
        'stock_item_id',
        'expected_qty',
        'received_qty',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'expected_qty' => 'integer',
            'received_qty' => 'integer',
        ];
    }

    public function inboundOrder(): BelongsTo
    {
        return $this->belongsTo(InboundOrder::class);
    }

    public function sku(): BelongsTo
    {
        return $this->belongsTo(Sku::class);
    }

    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(StockItem::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(InboundReceipt::class);
    }
}
