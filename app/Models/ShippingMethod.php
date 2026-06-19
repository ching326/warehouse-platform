<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

class ShippingMethod extends Model
{
    protected $fillable = [
        'carrier_id',
        'code',
        'name',
        'service_type',
        'is_trackable',
        'requires_size',
        'requires_zone',
        'supports_courier_csv',
        'status',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'is_trackable' => 'boolean',
            'requires_size' => 'boolean',
            'requires_zone' => 'boolean',
            'supports_courier_csv' => 'boolean',
        ];
    }

    public function carrier(): BelongsTo
    {
        return $this->belongsTo(Carrier::class);
    }

    public function rates(): HasMany
    {
        return $this->hasMany(ShippingMethodRate::class);
    }

    public function marketplaceMappings(): HasMany
    {
        return $this->hasMany(ShippingMethodMarketplaceMapping::class);
    }

    protected static function booted(): void
    {
        static::deleting(function () {
            throw new RuntimeException('Shipping methods are deactivated, not deleted.');
        });
    }
}
