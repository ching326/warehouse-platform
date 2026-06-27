<?php

namespace App\Models;

use Database\Factories\WarehouseLocationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WarehouseLocation extends Model
{
    /** @use HasFactory<WarehouseLocationFactory> */
    use HasFactory;

    protected $fillable = ['warehouse_id', 'code', 'name', 'zone_type', 'storage_unit_type', 'status', 'note'];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function inboundReceipts(): HasMany
    {
        return $this->hasMany(InboundReceipt::class);
    }
}
