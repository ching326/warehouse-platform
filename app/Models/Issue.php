<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\IssueFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Issue extends Model
{
    /** @use HasFactory<IssueFactory> */
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
        'issue_no',
        'issue_type',
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
            ->useLogName('issue')
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
        return $this->hasMany(IssueLine::class)->orderBy('id');
    }

    public function returnOrders(): HasMany
    {
        return $this->hasMany(ReturnOrder::class)->orderBy('id');
    }

    public function mediaAssets(): HasMany
    {
        return $this->hasMany(MediaAsset::class, 'model_id')
            ->where('model_type', MediaAsset::MODEL_TYPE_ISSUE)
            ->orderBy('sort_order')
            ->orderBy('id');
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
        return __('issues.types.'.$this->issue_type);
    }

    public function statusLabel(): string
    {
        return __('issues.statuses.'.$this->status);
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            self::STATUS_OPEN => 'red',
            self::STATUS_RESOLVED, self::STATUS_CLOSED => 'green',
            default => 'blue',
        };
    }

    public function isClosed(): bool
    {
        return in_array($this->status, [self::STATUS_RESOLVED, self::STATUS_CLOSED], true);
    }

    public static function buildIssueNo(int $id, ?CarbonInterface $date = null): string
    {
        $date ??= now('Asia/Tokyo');

        return 'ISS-'.$date->format('Ymd').'-'.str_pad((string) $id, 4, '0', STR_PAD_LEFT);
    }

    public static function typeOptions(): array
    {
        return [
            self::TYPE_MISSING => __('issues.types.missing'),
            self::TYPE_DAMAGED => __('issues.types.damaged'),
            self::TYPE_RETURNED => __('issues.types.returned'),
            self::TYPE_WRONG_ITEM => __('issues.types.wrong_item'),
            self::TYPE_LOST_IN_TRANSIT => __('issues.types.lost_in_transit'),
            self::TYPE_CUSTOMER_REFUSED => __('issues.types.customer_refused'),
            self::TYPE_OTHER => __('issues.types.other'),
        ];
    }

    public static function statusOptions(): array
    {
        return [
            self::STATUS_OPEN => __('issues.statuses.open'),
            self::STATUS_INVESTIGATING => __('issues.statuses.investigating'),
            self::STATUS_WAITING_RETURN => __('issues.statuses.waiting_return'),
            self::STATUS_RECEIVED_RETURN => __('issues.statuses.received_return'),
            self::STATUS_RESOLVED => __('issues.statuses.resolved'),
            self::STATUS_CLOSED => __('issues.statuses.closed'),
        ];
    }
}


