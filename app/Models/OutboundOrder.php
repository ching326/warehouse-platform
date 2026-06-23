<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\OutboundOrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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

        return 'OB-'.$tenantCode.'-'.$date->format('ymd').'-'.str_pad((string) $id, 3, '0', STR_PAD_LEFT);
    }

    public const STATUS_PENDING = 'pending';
    public const STATUS_SHIPPED = 'shipped';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'fulfillment_group_id',
        'tenant_id',
        'warehouse_id',
        'ref',
        'status',
        'note',
        'recipient_name',
        'recipient_phone',
        'recipient_country_code',
        'recipient_postal_code',
        'recipient_state',
        'recipient_city',
        'recipient_address_line1',
        'recipient_address_line2',
        'shipping_method',
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
            'package_count' => 'integer',
            'package_weight_g' => 'integer',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function fulfillmentGroup(): BelongsTo
    {
        return $this->belongsTo(FulfillmentGroup::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function shippedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'shipped_by_user_id');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_user_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(OutboundOrderLine::class)->orderBy('id');
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
