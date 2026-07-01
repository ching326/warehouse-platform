<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockCountLine extends Model
{
    public const STATUS_ADJUSTED = 'adjusted';

    public const STATUS_NO_CHANGE = 'no_change';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'stock_count_run_id',
        'tenant_id',
        'warehouse_id',
        'stock_item_id',
        'identifier_raw',
        'counted_qty',
        'previous_on_hand_qty',
        'delta_qty',
        'movement_id',
        'line_note',
        'reference_no',
        'status',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'counted_qty' => 'integer',
            'previous_on_hand_qty' => 'integer',
            'delta_qty' => 'integer',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(StockCountRun::class, 'stock_count_run_id');
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

    public function movement(): BelongsTo
    {
        return $this->belongsTo(InventoryMovement::class, 'movement_id');
    }
}
