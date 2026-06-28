<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\OutboundOrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OutboundOrder extends Model
{
    /** @use HasFactory<OutboundOrderFactory> */
    use HasFactory;

    public static function buildRef(int $id, string $tenantCode, ?CarbonInterface $date = null): string
    {
        $date ??= now('Asia/Tokyo');
        $tenantCode = strtoupper(trim($tenantCode));
        $tenantCode = preg_replace('/[^A-Z0-9]+/', '', $tenantCode) ?? '';
        $tenantCode = $tenantCode !== '' ? $tenantCode : 'TENANT';
        $tenantCode = substr($tenantCode, 0, 5);

        return 'OB-'.$tenantCode.'-'.$date->format('ymd').'-'.str_pad((string) $id, 4, '0', STR_PAD_LEFT);
    }

    public const STATUS_PENDING = 'pending';

    public const STATUS_SHIPPED = 'shipped';

    public const STATUS_CANCELLED = 'cancelled';

    public const HOLD_STATUS_ACTIVE = 'active';

    public const HOLD_STATUS_ON_HOLD = 'on_hold';

    public const REASON_CUSTOMER_ORDER = 'customer_order';

    public const REASON_RE_SHIP = 're_ship';

    public const REASON_REPLACEMENT = 'replacement';

    public const REASON_GIFT = 'gift';

    public const REASON_FBA = 'fba';

    public const REASON_RETURN_TO_TENANT = 'return_to_tenant';

    public const REASON_B2B = 'b2b';

    public const REASON_SAMPLE = 'sample';

    public const REASON_OTHER = 'other';

    protected $fillable = [
        'reason',
        'source_sales_order_id',
        'courier_csv_exported_at',
        'shipping_method_id',
        'tenant_id',
        'warehouse_id',
        'ref',
        'status',
        'hold_status',
        'held_at',
        'held_by_user_id',
        'held_from',
        'hold_reason',
        'released_at',
        'released_by_user_id',
        'note',
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
        'package_count',
        'package_weight_g',
        'ship_note',
        'shipped_at',
        'shipped_by_user_id',
        'cancelled_at',
        'cancelled_by_user_id',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'shipped_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'courier_csv_exported_at' => 'datetime',
            'held_at' => 'datetime',
            'released_at' => 'datetime',
            'package_count' => 'integer',
            'package_weight_g' => 'integer',
        ];
    }

    public function reasonLabel(): ?string
    {
        return $this->reason ? __('outbound.reason_'.$this->reason) : null;
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function sourceSalesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'source_sales_order_id');
    }

    public function shippingMethod(): BelongsTo
    {
        return $this->belongsTo(ShippingMethod::class);
    }

    public function salesOrders(): BelongsToMany
    {
        return $this->belongsToMany(SalesOrder::class, 'outbound_order_sales_order')
            ->withPivot('arranged_at');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function shippedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'shipped_by_user_id');
    }

    public function heldBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'held_by_user_id');
    }

    public function releasedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by_user_id');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_user_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(OutboundOrderLine::class)->orderBy('id');
    }

    public function packScans(): HasMany
    {
        return $this->hasMany(FulfillmentPackScan::class);
    }

    public function parentLines(): HasMany
    {
        return $this->hasMany(OutboundOrderLine::class)
            ->whereNull('parent_line_id')
            ->orderBy('id');
    }

    public function leafLines(): HasMany
    {
        return $this->hasMany(OutboundOrderLine::class)
            ->whereNotNull('stock_item_id');
    }
}
