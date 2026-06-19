<?php

namespace App\Models;

use Database\Factories\SalesOrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class SalesOrder extends Model
{
    /** @use HasFactory<SalesOrderFactory> */
    use HasFactory, LogsActivity;

    public const ORDER_STATUS_PENDING = 'pending';
    public const ORDER_STATUS_CANCELLED = 'cancelled';

    public const FULFILLMENT_STATUS_UNFULFILLED = 'unfulfilled';
    public const FULFILLMENT_STATUS_READY = 'ready';
    public const FULFILLMENT_STATUS_IN_GROUP = 'in_group';
    public const FULFILLMENT_STATUS_SHIPPED = 'shipped';
    public const FULFILLMENT_STATUS_CANCELLED = 'cancelled';

    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_CSV = 'csv';
    public const SOURCE_API = 'api';

    protected $fillable = [
        'tenant_id',
        'shop_id',
        'source',
        'platform_order_id',
        'order_status',
        'fulfillment_status',
        'recipient_name',
        'recipient_phone',
        'recipient_country_code',
        'recipient_postal_code',
        'recipient_state',
        'recipient_city',
        'recipient_address_line1',
        'recipient_address_line2',
        'ship_together_key',
        'note',
        'created_by_user_id',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('sales_order')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SalesOrderLine::class)->orderBy('id');
    }

    public function recalculateShipTogetherKey(): void
    {
        if (empty(trim((string) $this->recipient_address_line1))) {
            $this->ship_together_key = null;

            return;
        }

        $this->ship_together_key = md5(implode('|', [
            $this->tenant_id,
            $this->shop_id,
            strtolower(trim((string) $this->recipient_name)),
            strtolower(trim((string) $this->recipient_country_code)),
            strtolower(trim((string) $this->recipient_postal_code)),
            strtolower(trim((string) $this->recipient_state)),
            strtolower(trim((string) $this->recipient_city)),
            strtolower(trim((string) $this->recipient_address_line1)),
            strtolower(trim((string) $this->recipient_address_line2)),
        ]));
    }
}
