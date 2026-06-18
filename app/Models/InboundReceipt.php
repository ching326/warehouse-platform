<?php

namespace App\Models;

use Database\Factories\InboundReceiptFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InboundReceipt extends Model
{
    /** @use HasFactory<InboundReceiptFactory> */
    use HasFactory;

    protected $fillable = [
        'inbound_order_id',
        'inbound_order_line_id',
        'tenant_id',
        'warehouse_id',
        'warehouse_location_id',
        'sku_id',
        'stock_item_id',
        'inventory_movement_id',
        'received_qty',
        'received_by_user_id',
        'received_at',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'received_qty' => 'integer',
            'received_at' => 'datetime',
        ];
    }

    public function inboundOrder(): BelongsTo
    {
        return $this->belongsTo(InboundOrder::class);
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(InboundOrderLine::class, 'inbound_order_line_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function warehouseLocation(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class);
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

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by_user_id');
    }
}
