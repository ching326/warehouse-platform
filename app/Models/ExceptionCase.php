<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\ExceptionCaseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class ExceptionCase extends Model
{
    /** @use HasFactory<ExceptionCaseFactory> */
    use HasFactory, LogsActivity;

    public const TYPE_MISSING = 'missing';
    public const TYPE_DAMAGED = 'damaged';
    public const TYPE_RETURNED = 'returned';
    public const TYPE_WRONG_ITEM = 'wrong_item';
    public const TYPE_LOST_IN_TRANSIT = 'lost_in_transit';
    public const TYPE_CUSTOMER_REFUSED = 'customer_refused';
    public const TYPE_OTHER = 'other';

    public const STATUS_OPEN = 'open';
    public const STATUS_INVESTIGATING = 'investigating';
    public const STATUS_WAITING_RETURN = 'waiting_return';
    public const STATUS_RECEIVED_RETURN = 'received_return';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'tenant_id',
        'sales_order_id',
        'fulfillment_group_id',
        'outbound_order_id',
        'case_no',
        'case_type',
        'status',
        'reported_at',
        'reported_by',
        'note',
        'resolved_at',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'reported_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('exception_case')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function fulfillmentGroup(): BelongsTo
    {
        return $this->belongsTo(FulfillmentGroup::class);
    }

    public function outboundOrder(): BelongsTo
    {
        return $this->belongsTo(OutboundOrder::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ExceptionCaseLine::class)->orderBy('id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public function typeLabel(): string
    {
        return __('exception_cases.types.'.$this->case_type);
    }

    public function statusLabel(): string
    {
        return __('exception_cases.statuses.'.$this->status);
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            self::STATUS_INVESTIGATING => 'blue',
            self::STATUS_WAITING_RETURN => 'amber',
            self::STATUS_RECEIVED_RETURN => 'purple',
            self::STATUS_RESOLVED => 'green',
            self::STATUS_CLOSED => 'zinc',
            default => 'red',
        };
    }

    public function isClosed(): bool
    {
        return in_array($this->status, [self::STATUS_RESOLVED, self::STATUS_CLOSED], true);
    }

    public static function buildCaseNo(int $id, ?CarbonInterface $date = null): string
    {
        $date ??= now('Asia/Tokyo');

        return 'EC-'.$date->format('Ymd').'-'.str_pad((string) $id, 4, '0', STR_PAD_LEFT);
    }

    public static function typeOptions(): array
    {
        return [
            self::TYPE_MISSING => __('exception_cases.types.missing'),
            self::TYPE_DAMAGED => __('exception_cases.types.damaged'),
            self::TYPE_RETURNED => __('exception_cases.types.returned'),
            self::TYPE_WRONG_ITEM => __('exception_cases.types.wrong_item'),
            self::TYPE_LOST_IN_TRANSIT => __('exception_cases.types.lost_in_transit'),
            self::TYPE_CUSTOMER_REFUSED => __('exception_cases.types.customer_refused'),
            self::TYPE_OTHER => __('exception_cases.types.other'),
        ];
    }

    public static function statusOptions(): array
    {
        return [
            self::STATUS_OPEN => __('exception_cases.statuses.open'),
            self::STATUS_INVESTIGATING => __('exception_cases.statuses.investigating'),
            self::STATUS_WAITING_RETURN => __('exception_cases.statuses.waiting_return'),
            self::STATUS_RECEIVED_RETURN => __('exception_cases.statuses.received_return'),
            self::STATUS_RESOLVED => __('exception_cases.statuses.resolved'),
            self::STATUS_CLOSED => __('exception_cases.statuses.closed'),
        ];
    }
}
