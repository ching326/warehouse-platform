<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShippingMethodMarketplaceMapping extends Model
{
    protected $fillable = [
        'shipping_method_id',
        'platform',
        'marketplace',
        'carrier_code',
        'carrier_name',
        'service_code',
        'service_name',
        'note',
    ];

    public function shippingMethod(): BelongsTo
    {
        return $this->belongsTo(ShippingMethod::class);
    }
}
