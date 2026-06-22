<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FulfillmentGroupOrder extends Model
{
    protected $fillable = [
        'fulfillment_group_id',
        'sales_order_id',
        'tracking_no',
        'courier',
        'arranged_at',
        'shipped_at',
    ];

    protected function casts(): array
    {
        return [
            'arranged_at' => 'datetime',
            'shipped_at' => 'datetime',
        ];
    }

    public function fulfillmentGroup(): BelongsTo
    {
        return $this->belongsTo(FulfillmentGroup::class);
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }
}
