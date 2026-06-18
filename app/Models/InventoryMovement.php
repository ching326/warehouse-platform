<?php

namespace App\Models;

use Database\Factories\InventoryMovementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryMovement extends Model
{
    /** @use HasFactory<InventoryMovementFactory> */
    use HasFactory;

    public const TYPE_OPENING_BALANCE = 'opening_balance';
    public const TYPE_RECEIVE = 'receive';
    public const TYPE_RESERVE = 'reserve';
    public const TYPE_RELEASE_RESERVE = 'release_reserve';
    public const TYPE_SHIP = 'ship';
    public const TYPE_HOLD = 'hold';
    public const TYPE_RELEASE_HOLD = 'release_hold';
    public const TYPE_MARK_DAMAGED = 'mark_damaged';
    public const TYPE_ADJUST = 'adjust';

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'warehouse_id',
        'stock_item_id',
        'movement_type',
        'quantity_delta',
        'balance_after',
        'on_hand_delta',
        'reserved_delta',
        'available_delta',
        'inbound_delta',
        'hold_delta',
        'damaged_delta',
        'on_hand_after',
        'reserved_after',
        'available_after',
        'inbound_after',
        'hold_after',
        'damaged_after',
        'ref_type',
        'ref_id',
        'user_id',
        'note',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity_delta' => 'integer',
            'balance_after' => 'integer',
            'on_hand_delta' => 'integer',
            'reserved_delta' => 'integer',
            'available_delta' => 'integer',
            'inbound_delta' => 'integer',
            'hold_delta' => 'integer',
            'damaged_delta' => 'integer',
            'on_hand_after' => 'integer',
            'reserved_after' => 'integer',
            'available_after' => 'integer',
            'inbound_after' => 'integer',
            'hold_after' => 'integer',
            'damaged_after' => 'integer',
            'created_at' => 'datetime',
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
