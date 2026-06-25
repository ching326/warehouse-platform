<?php

namespace App\Models;

use Database\Factories\SalesOrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class SalesOrder extends Model
{
    /** @use HasFactory<SalesOrderFactory> */
    use HasFactory, LogsActivity;

    public const ORDER_STATUS_PENDING = 'pending';

    public const ORDER_STATUS_ON_HOLD = 'on_hold';

    public const ORDER_STATUS_BACKORDER = 'backorder';

    public const ORDER_STATUS_CANCEL_REQUESTED = 'cancel_requested';

    public const ORDER_STATUS_CANCELLED = 'cancelled';

    public const ORDER_STATUS_COMPLETED = 'completed';

    public const FULFILLMENT_STATUS_UNFULFILLED = 'unfulfilled';

    public const FULFILLMENT_STATUS_READY = 'ready';

    public const FULFILLMENT_STATUS_ARRANGED = 'arranged';

    public const FULFILLMENT_STATUS_SHIPPED = 'shipped';

    public const FULFILLMENT_STATUS_CANCELLED = 'cancelled';

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_CSV = 'csv';

    public const SOURCE_AMAZON_REPORT = 'amazon_report';

    public const SOURCE_API = 'api';

    protected $fillable = [
        'tenant_id',
        'shop_id',
        'source',
        'platform_order_id',
        'platform_ordered_at',
        'order_date',
        'latest_ship_at',
        'order_status',
        'fulfillment_status',
        'shipping_method',
        'shipping_method_id',
        'tracking_no',
        'marketplace_shipping_notice_exported_at',
        'shipped_at',
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

    protected function casts(): array
    {
        return [
            'platform_ordered_at' => 'datetime',
            'order_date' => 'datetime',
            'latest_ship_at' => 'datetime',
            'marketplace_shipping_notice_exported_at' => 'datetime',
            'shipped_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (SalesOrder $order): void {
            if ($order->order_date !== null) {
                return;
            }

            if ($order->platform_ordered_at !== null) {
                $order->order_date = $order->platform_ordered_at;

                return;
            }

            $timestamp = $order->created_at ?? $order->freshTimestamp();

            $order->created_at ??= $timestamp;
            $order->updated_at ??= $timestamp;
            $order->order_date = $timestamp;
        });
    }

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

    public function shippingMethod(): BelongsTo
    {
        return $this->belongsTo(ShippingMethod::class);
    }

    public function courierExportBatchOrders(): HasMany
    {
        return $this->hasMany(CourierExportBatchOrder::class);
    }

    public function marketplaceShippingNoticeBatchOrders(): HasMany
    {
        return $this->hasMany(MarketplaceShippingNoticeBatchOrder::class);
    }

    public function issues(): HasMany
    {
        return $this->hasMany(Issue::class);
    }

    public function outboundOrders(): BelongsToMany
    {
        return $this->belongsToMany(OutboundOrder::class, 'outbound_order_sales_order')
            ->withPivot('arranged_at');
    }

    public function activeOutboundOrders(): BelongsToMany
    {
        return $this->outboundOrders()
            ->where('outbound_orders.status', '!=', OutboundOrder::STATUS_CANCELLED);
    }

    public function activeOutboundOrder(): ?OutboundOrder
    {
        if ($this->relationLoaded('activeOutboundOrders')) {
            return $this->activeOutboundOrders->first();
        }

        return $this->activeOutboundOrders()->first();
    }

    public function isPacking(): bool
    {
        return $this->activeOutboundOrder()?->courier_csv_exported_at !== null;
    }

    public function recalculateShipTogetherKey(): void
    {
        if (empty(trim((string) $this->recipient_address_line1))) {
            $this->ship_together_key = null;

            return;
        }

        $this->ship_together_key = md5(implode('|', [
            $this->tenant_id,
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
