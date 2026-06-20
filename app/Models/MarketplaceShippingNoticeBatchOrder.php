<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketplaceShippingNoticeBatchOrder extends Model
{
    protected $fillable = [
        'marketplace_shipping_notice_batch_id',
        'sales_order_id',
        'platform_order_id',
        'tracking_no',
        'shipping_method_id',
        'exported_at',
    ];

    protected function casts(): array
    {
        return [
            'exported_at' => 'datetime',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(MarketplaceShippingNoticeBatch::class, 'marketplace_shipping_notice_batch_id');
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function shippingMethod(): BelongsTo
    {
        return $this->belongsTo(ShippingMethod::class);
    }
}
