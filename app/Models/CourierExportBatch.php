<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CourierExportBatch extends Model
{
    protected $fillable = [
        'tenant_id',
        'carrier',
        'file_name',
        'disk',
        'path',
        'order_count',
        'exported_by_user_id',
        'exported_at',
    ];

    protected function casts(): array
    {
        return [
            'exported_at' => 'datetime',
            'order_count' => 'integer',
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
        return $this->belongsToMany(SalesOrder::class, 'courier_export_batch_orders')
            ->withPivot(['platform_order_id', 'carrier', 'exported_at'])
            ->withTimestamps();
    }

    public function batchOrders(): HasMany
    {
        return $this->hasMany(CourierExportBatchOrder::class);
    }
}
