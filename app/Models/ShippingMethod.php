<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
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
        'name_ja',
        'name_zh_tw',
        'name_zh_cn',
        'service_type',
        'sort_order',
        'selection_priority',
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
            'selection_priority' => 'integer',
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

    public function displayName(?string $locale = null): string
    {
        $locale ??= app()->getLocale();
        $column = match (strtolower(str_replace('-', '_', $locale))) {
            'ja', 'jp', 'ja_jp' => 'name_ja',
            'zh_tw', 'zh_hk', 'zh_hant' => 'name_zh_tw',
            'zh_cn', 'zh_sg', 'zh_hans' => 'name_zh_cn',
            default => 'name',
        };

        return trim((string) ($this->{$column} ?: $this->name));
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
