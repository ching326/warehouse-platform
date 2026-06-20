<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketplaceShippingNoticeBatch extends Model
{
    protected $fillable = [
        'tenant_id',
        'platform',
        'marketplace',
        'file_name',
        'disk',
        'path',
        'order_count',
        'line_count',
        'exported_by_user_id',
        'exported_at',
    ];

    protected function casts(): array
    {
        return [
            'order_count' => 'integer',
            'line_count' => 'integer',
            'exported_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function exportedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'exported_by_user_id');
    }

    public function orders(): BelongsToMany
    {
        return $this->belongsToMany(SalesOrder::class, 'marketplace_shipping_notice_batch_orders')
            ->withPivot(['platform_order_id', 'tracking_no', 'shipping_method_id', 'exported_at'])
            ->withTimestamps();
    }

    public function batchOrders(): HasMany
    {
        return $this->hasMany(MarketplaceShippingNoticeBatchOrder::class);
    }
}
