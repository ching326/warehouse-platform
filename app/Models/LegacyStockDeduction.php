<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LegacyStockDeduction extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPLIED = 'applied';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'tenant_id',
        'warehouse_id',
        'stock_item_id',
        'sku_id',
        'outbound_order_id',
        'outbound_order_line_id',
        'inventory_movement_id',
        'tenant_code',
        'warehouse_code',
        'stock_item_code',
        'tenant_item_code',
        'sku_code',
        'legacy_item_code',
        'item_code_source',
        'quantity',
        'source_ref',
        'idempotency_key',
        'status',
        'applied_at',
        'failed_at',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'applied_at' => 'datetime',
            'failed_at' => 'datetime',
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

    public function sku(): BelongsTo
    {
        return $this->belongsTo(Sku::class);
    }

    public function outboundOrder(): BelongsTo
    {
        return $this->belongsTo(OutboundOrder::class);
    }
}
