<?php

namespace App\Models;

use Database\Factories\ExceptionCaseLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class ExceptionCaseLine extends Model
{
    /** @use HasFactory<ExceptionCaseLineFactory> */
    use HasFactory, LogsActivity;

    public const CONDITION_MISSING = 'missing';
    public const CONDITION_DAMAGED = 'damaged';
    public const CONDITION_GOOD = 'good';
    public const CONDITION_UNKNOWN = 'unknown';

    public const ACTION_NONE = 'none';
    public const ACTION_RESEND = 'resend';
    public const ACTION_REFUND = 'refund';
    public const ACTION_RETURN_TO_STOCK = 'return_to_stock';
    public const ACTION_MARK_DAMAGED = 'mark_damaged';
    public const ACTION_WRITE_OFF = 'write_off';
    public const ACTION_INVESTIGATE = 'investigate';

    protected $fillable = [
        'exception_case_id',
        'tenant_id',
        'sales_order_line_id',
        'outbound_order_line_id',
        'sku_id',
        'stock_item_id',
        'qty',
        'condition',
        'action',
        'note',
    ];

    protected function casts(): array
    {
        return ['qty' => 'integer'];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('exception_case_line')
            ->logOnly(['condition', 'action', 'note'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function exceptionCase(): BelongsTo
    {
        return $this->belongsTo(ExceptionCase::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function salesOrderLine(): BelongsTo
    {
        return $this->belongsTo(SalesOrderLine::class);
    }

    public function outboundOrderLine(): BelongsTo
    {
        return $this->belongsTo(OutboundOrderLine::class);
    }

    public function sku(): BelongsTo
    {
        return $this->belongsTo(Sku::class);
    }

    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(StockItem::class);
    }

    public static function conditionOptions(): array
    {
        return [
            self::CONDITION_MISSING => __('exception_cases.conditions.missing'),
            self::CONDITION_DAMAGED => __('exception_cases.conditions.damaged'),
            self::CONDITION_GOOD => __('exception_cases.conditions.good'),
            self::CONDITION_UNKNOWN => __('exception_cases.conditions.unknown'),
        ];
    }

    public static function actionOptions(): array
    {
        return [
            self::ACTION_NONE => __('exception_cases.actions.none'),
            self::ACTION_RESEND => __('exception_cases.actions.resend'),
            self::ACTION_REFUND => __('exception_cases.actions.refund'),
            self::ACTION_RETURN_TO_STOCK => __('exception_cases.actions.return_to_stock'),
            self::ACTION_MARK_DAMAGED => __('exception_cases.actions.mark_damaged'),
            self::ACTION_WRITE_OFF => __('exception_cases.actions.write_off'),
            self::ACTION_INVESTIGATE => __('exception_cases.actions.investigate'),
        ];
    }
}
