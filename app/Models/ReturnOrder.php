<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\ReturnOrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class ReturnOrder extends Model
{
    /** @use HasFactory<ReturnOrderFactory> */
    use HasFactory, LogsActivity;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_ANNOUNCED = 'announced';
    public const STATUS_IN_TRANSIT = 'in_transit';
    public const STATUS_ARRIVED = 'arrived';
    public const STATUS_RECEIVED = 'received';
    public const STATUS_INSPECTED = 'inspected';
    public const STATUS_AWAITING_DISPOSITION = 'awaiting_disposition';
    public const STATUS_DISPOSITIONED = 'dispositioned';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_EXPIRED = 'expired';
    public const TYPE_CUSTOMER_RETURN = 'customer_return';
    public const TYPE_FBA_REMOVAL = 'fba_removal';
    public const TYPE_MARKETPLACE_RETURN = 'marketplace_return';
    public const TYPE_REFUSED_DELIVERY = 'refused_delivery';
    public const TYPE_MANUAL = 'manual';
    public const TYPE_UNKNOWN = 'unknown';
    public const REASON_DEFECTIVE = 'defective';
    public const REASON_WRONG_ITEM = 'wrong_item';
    public const REASON_CUSTOMER_CHANGED_MIND = 'customer_changed_mind';
    public const REASON_REFUSED_UNDELIVERED = 'refused_undelivered';
    public const REASON_DAMAGED_IN_TRANSIT = 'damaged_in_transit';
    public const REASON_FBA_REMOVAL = 'fba_removal';
    public const REASON_RECALL = 'recall';
    public const REASON_OVERSTOCK = 'overstock';
    public const REASON_OTHER = 'other';
    public const PAYMENT_PREPAID = 'prepaid';
    public const PAYMENT_COLLECT = 'collect';
    public const PAYMENT_UNKNOWN = 'unknown';

    protected $fillable = ['tenant_id', 'warehouse_id', 'issue_id', 'sales_order_id', 'outbound_order_id', 'fulfillment_group_id', 'return_no', 'status', 'return_type', 'return_reason', 'reason_note', 'external_return_id', 'original_order_no', 'customer_name', 'sender_name', 'sender_phone', 'shipping_method', 'tracking_no', 'payment_type', 'collect_amount', 'collect_currency', 'package_count', 'expected_arrival_date', 'arrived_at', 'received_at', 'inspected_at', 'dispositioned_at', 'closed_at', 'cancelled_at', 'note', 'created_by_user_id', 'arrived_by_user_id', 'received_by_user_id', 'inspected_by_user_id', 'dispositioned_by_user_id', 'cancelled_by_user_id'];

    protected function casts(): array
    {
        return ['expected_arrival_date' => 'date', 'arrived_at' => 'datetime', 'received_at' => 'datetime', 'inspected_at' => 'datetime', 'dispositioned_at' => 'datetime', 'closed_at' => 'datetime', 'cancelled_at' => 'datetime', 'collect_amount' => 'decimal:2'];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('return_order')->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function tenant(): BelongsTo { return $this->belongsTo(Tenant::class); }
    public function warehouse(): BelongsTo { return $this->belongsTo(Warehouse::class); }
    public function issue(): BelongsTo { return $this->belongsTo(Issue::class); }
    public function salesOrder(): BelongsTo { return $this->belongsTo(SalesOrder::class); }
    public function outboundOrder(): BelongsTo { return $this->belongsTo(OutboundOrder::class); }
    public function fulfillmentGroup(): BelongsTo { return $this->belongsTo(FulfillmentGroup::class); }
    public function lines(): HasMany { return $this->hasMany(ReturnOrderLine::class)->orderBy('id'); }
    public function costs(): HasMany { return $this->hasMany(ReturnOrderCost::class)->orderBy('id'); }
    public function mediaAssets(): HasMany { return $this->hasMany(MediaAsset::class, 'model_id')->where('model_type', MediaAsset::MODEL_TYPE_RETURN_ORDER)->orderBy('sort_order')->orderBy('id'); }
    public function createdBy(): BelongsTo { return $this->belongsTo(User::class, 'created_by_user_id'); }
    public function arrivedBy(): BelongsTo { return $this->belongsTo(User::class, 'arrived_by_user_id'); }
    public function receivedBy(): BelongsTo { return $this->belongsTo(User::class, 'received_by_user_id'); }
    public function inspectedBy(): BelongsTo { return $this->belongsTo(User::class, 'inspected_by_user_id'); }
    public function dispositionedBy(): BelongsTo { return $this->belongsTo(User::class, 'dispositioned_by_user_id'); }

    public static function buildReturnNo(int $id, ?CarbonInterface $date = null): string
    {
        $date ??= now('Asia/Tokyo');
        return 'RTN-'.$date->format('Ymd').'-'.str_pad((string) $id, 4, '0', STR_PAD_LEFT);
    }

    public function statusLabel(): string { return __('return_orders.statuses.'.$this->status); }
    public function statusColor(): string { return match ($this->status) { self::STATUS_CANCELLED, self::STATUS_EXPIRED => 'red', self::STATUS_CLOSED, self::STATUS_DISPOSITIONED => 'green', default => 'blue' }; }
    public function typeLabel(): string { return __('return_orders.types.'.$this->return_type); }
    public function reasonLabel(): string { return $this->return_reason ? __('return_orders.reasons.'.$this->return_reason) : '-'; }
    public function paymentTypeLabel(): string { return __('return_orders.payment_types.'.$this->payment_type); }
    public function isTenantEditable(): bool { return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_ANNOUNCED], true); }
    public function isStaffEditable(): bool { return ! $this->isClosed(); }
    public function isClosed(): bool { return in_array($this->status, [self::STATUS_CLOSED, self::STATUS_CANCELLED], true); }
    public static function statusOptions(): array { return self::options('statuses', [self::STATUS_DRAFT, self::STATUS_ANNOUNCED, self::STATUS_IN_TRANSIT, self::STATUS_ARRIVED, self::STATUS_RECEIVED, self::STATUS_INSPECTED, self::STATUS_AWAITING_DISPOSITION, self::STATUS_DISPOSITIONED, self::STATUS_CLOSED, self::STATUS_CANCELLED, self::STATUS_EXPIRED]); }
    public static function typeOptions(): array { return self::options('types', [self::TYPE_CUSTOMER_RETURN, self::TYPE_FBA_REMOVAL, self::TYPE_MARKETPLACE_RETURN, self::TYPE_REFUSED_DELIVERY, self::TYPE_MANUAL, self::TYPE_UNKNOWN]); }
    public static function reasonOptions(): array { return self::options('reasons', [self::REASON_DEFECTIVE, self::REASON_WRONG_ITEM, self::REASON_CUSTOMER_CHANGED_MIND, self::REASON_REFUSED_UNDELIVERED, self::REASON_DAMAGED_IN_TRANSIT, self::REASON_FBA_REMOVAL, self::REASON_RECALL, self::REASON_OVERSTOCK, self::REASON_OTHER]); }
    public static function paymentTypeOptions(): array { return self::options('payment_types', [self::PAYMENT_PREPAID, self::PAYMENT_COLLECT, self::PAYMENT_UNKNOWN]); }
    private static function options(string $group, array $values): array { return collect($values)->mapWithKeys(fn ($v) => [$v => __('return_orders.'.$group.'.'.$v)])->all(); }
}

