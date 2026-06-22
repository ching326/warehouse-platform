<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FulfillmentPackScan extends Model
{
    public const RESULT_ACCEPTED = 'accepted';
    public const RESULT_WRONG_ITEM = 'wrong_item';
    public const RESULT_OVER_SCAN = 'over_scan';
    public const RESULT_NOT_FOUND = 'not_found';
    public const RESULT_BLOCKED_STATUS = 'blocked_status';

    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id',
        'fulfillment_group_id',
        'fulfillment_group_order_id',
        'sales_order_id',
        'sku_id',
        'stock_item_id',
        'barcode_scanned',
        'normalized_barcode',
        'result',
        'message',
        'scanned_by_user_id',
    ];

    public function fulfillmentGroup(): BelongsTo
    {
        return $this->belongsTo(FulfillmentGroup::class);
    }

    public function fulfillmentGroupOrder(): BelongsTo
    {
        return $this->belongsTo(FulfillmentGroupOrder::class);
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function sku(): BelongsTo
    {
        return $this->belongsTo(Sku::class);
    }

    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(StockItem::class);
    }

    public function scannedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scanned_by_user_id');
    }
}
