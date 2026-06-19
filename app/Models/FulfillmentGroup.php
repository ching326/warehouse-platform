<?php

namespace App\Models;

use Database\Factories\FulfillmentGroupFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class FulfillmentGroup extends Model
{
    /** @use HasFactory<FulfillmentGroupFactory> */
    use HasFactory, LogsActivity;

    public const STATUS_RESERVED = 'reserved';
    public const STATUS_SHIPPED = 'shipped';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'tenant_id',
        'warehouse_id',
        'reference_no',
        'status',
        'ship_together_key',
        'recipient_name',
        'recipient_phone',
        'recipient_country_code',
        'recipient_postal_code',
        'recipient_state',
        'recipient_city',
        'recipient_address_line1',
        'recipient_address_line2',
        'courier',
        'tracking_no',
        'note',
        'shipped_at',
        'shipped_by_user_id',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return ['shipped_at' => 'datetime'];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('fulfillment_group')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function outboundOrder(): HasOne
    {
        return $this->hasOne(OutboundOrder::class);
    }

    public function orders(): BelongsToMany
    {
        return $this->belongsToMany(SalesOrder::class, 'fulfillment_group_orders')->withTimestamps();
    }

    public static function buildReferenceNo(int $id): string
    {
        return 'FG-'.now()->format('Ymd').'-'.str_pad((string) $id, 4, '0', STR_PAD_LEFT);
    }
}
