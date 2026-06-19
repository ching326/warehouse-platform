<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

class Carrier extends Model
{
    protected $fillable = [
        'code',
        'name',
        'country_code',
        'status',
    ];

    public function shippingMethods(): HasMany
    {
        return $this->hasMany(ShippingMethod::class);
    }

    protected static function booted(): void
    {
        static::deleting(function () {
            throw new RuntimeException('Carriers are deactivated, not deleted.');
        });
    }
}
