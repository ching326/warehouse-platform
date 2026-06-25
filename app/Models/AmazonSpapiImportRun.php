<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class AmazonSpapiImportRun extends Model
{
    use LogsActivity;

    public const MODE_MANUAL = 'manual';

    public const MODE_SCHEDULED = 'scheduled';

    public const STATUS_PREVIEWED = 'previewed';

    public const STATUS_IMPORTING = 'importing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const WINDOW_LAST_UPDATED = 'last_updated';

    public const WINDOW_CREATED = 'created';

    protected $fillable = [
        'tenant_id',
        'shop_id',
        'amazon_spapi_connection_id',
        'triggered_by_user_id',
        'mode',
        'status',
        'window_type',
        'window_from',
        'window_to',
        'api_order_count',
        'api_line_count',
        'new_order_count',
        'new_line_count',
        'duplicate_order_count',
        'missing_sku_count',
        'cancel_requested_count',
        'imported_order_count',
        'skipped_order_count',
        'error_message',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'window_from' => 'datetime',
            'window_to' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('amazon_spapi_import_run')
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

    public function connection(): BelongsTo
    {
        return $this->belongsTo(AmazonSpapiConnection::class, 'amazon_spapi_connection_id');
    }

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by_user_id');
    }
}
