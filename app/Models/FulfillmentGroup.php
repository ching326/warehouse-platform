<?php

namespace App\Models;

use Database\Factories\FulfillmentGroupFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
        'shipping_method_id',
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

    public function shippingMethod(): BelongsTo
    {
        return $this->belongsTo(ShippingMethod::class);
    }

    public function outboundOrder(): HasOne
    {
        return $this->hasOne(OutboundOrder::class);
    }

    public function orders(): BelongsToMany
    {
        return $this->belongsToMany(SalesOrder::class, 'fulfillment_group_orders')->withTimestamps();
    }

    public function groupOrders(): HasMany
    {
        return $this->hasMany(FulfillmentGroupOrder::class);
    }

    public static function buildReferenceNo(int $id, string $tenantCode): string
    {
        $tenantCode = strtoupper(trim($tenantCode));
        $tenantCode = preg_replace('/[^A-Z0-9]+/', '', $tenantCode) ?? '';
        $tenantCode = str_pad(substr($tenantCode, 0, 3), 3, 'X');

        return 'F'.$tenantCode.now()->format('ymd').str_pad((string) $id, 5, '0', STR_PAD_LEFT);
    }
}
