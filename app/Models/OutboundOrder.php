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

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PENDING = 'pending';

    public const STATUS_RESERVED = 'reserved';

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
        'reship_of_outbound_id',
        'issue_id',
        'courier_label_exported_at',
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
        'courier_cost',
        'courier_cost_currency',
        'courier_cost_updated_by_user_id',
        'courier_cost_updated_at',
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
            'courier_label_exported_at' => 'datetime',
            'held_at' => 'datetime',
            'released_at' => 'datetime',
            'package_count' => 'integer',
            'package_weight_g' => 'integer',
            'courier_cost' => 'decimal:2',
            'courier_cost_updated_at' => 'datetime',
        ];
    }

    public function reasonLabel(): ?string
    {
        return $this->reason ? __('outbound.reason_'.$this->reason) : null;
    }

    /**
     * @return list<string>
     */
    public static function fulfillableReasons(): array
    {
        return [self::REASON_CUSTOMER_ORDER, self::REASON_RE_SHIP];
    }

    public static function statusColorFor(string $status): string
    {
        return match ($status) {
            self::STATUS_DRAFT => 'zinc',
            self::STATUS_PENDING => 'blue',
            self::STATUS_RESERVED => 'blue',
            self::STATUS_SHIPPED => 'green',
            self::STATUS_CANCELLED => 'red',
            default => 'zinc',
        };
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

    public function reshipOfOutbound(): BelongsTo
    {
        return $this->belongsTo(OutboundOrder::class, 'reship_of_outbound_id');
    }

    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class);
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

    public function reships(): HasMany
    {
        return $this->hasMany(OutboundOrder::class, 'reship_of_outbound_id')->orderBy('id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function shippedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'shipped_by_user_id');
    }

    public function courierCostUpdatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'courier_cost_updated_by_user_id');
    }

    /**
     * Courier-cost attributes for an update, stamping the audit fields only when the
     * cost or currency actually changes. Pass an already-normalized 2-decimal cost
     * string (or null) and an uppercase currency code (or null).
     *
     * @return array<string, mixed>
     */
    public function courierCostAttributes(?string $cost, ?string $currency, ?int $userId): array
    {
        $attributes = [
            'courier_cost' => $cost,
            'courier_cost_currency' => $currency,
        ];

        $currentCost = $this->courier_cost === null
            ? null
            : number_format((float) $this->courier_cost, 2, '.', '');

        if ($currentCost !== $cost || ($this->courier_cost_currency ?: null) !== $currency) {
            $attributes['courier_cost_updated_by_user_id'] = $userId;
            $attributes['courier_cost_updated_at'] = now();
        }

        return $attributes;
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

    public function courierExportBatchOrders(): HasMany
    {
        return $this->hasMany(CourierExportBatchOrder::class);
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
