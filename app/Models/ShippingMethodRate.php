<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShippingMethodRate extends Model
{
    protected $fillable = [
        'shipping_method_id',
        'tenant_id',
        'rate_type',
        'currency',
        'price',
        'size_code',
        'origin_zone',
        'destination_zone',
        'effective_from',
        'effective_to',
        'status',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    public function shippingMethod(): BelongsTo
    {
        return $this->belongsTo(ShippingMethod::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
