<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
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
        'sort_order',
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
            'sort_order' => 'integer',
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

    public function scopeOrdered(Builder $query): Builder
    {
        return $query
            ->leftJoin('carriers', 'shipping_methods.carrier_id', '=', 'carriers.id')
            ->orderBy('carriers.sort_order')
            ->orderBy('carriers.name')
            ->orderBy('shipping_methods.sort_order')
            ->orderBy('shipping_methods.name')
            ->orderBy('shipping_methods.id')
            ->select('shipping_methods.*');
    }

    protected static function booted(): void
    {
        static::deleting(function () {
            throw new RuntimeException('Shipping methods are deactivated, not deleted.');
        });
    }
}
