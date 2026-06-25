<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class AmazonSpapiConnection extends Model
{
    use LogsActivity;

    public const STATUS_NOT_TESTED = 'not_tested';

    public const STATUS_CONNECTED = 'connected';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'tenant_id',
        'shop_id',
        'seller_id',
        'marketplace_id',
        'region',
        'endpoint',
        'lwa_client_id',
        'lwa_client_secret',
        'refresh_token',
        'sync_enabled',
        'status',
        'last_tested_at',
        'last_test_successful_at',
        'last_error',
        'last_orders_imported_at',
        'last_orders_import_window_from',
        'last_orders_import_window_to',
        'last_orders_import_status',
        'last_orders_import_error',
    ];

    protected function casts(): array
    {
        return [
            'lwa_client_secret' => 'encrypted',
            'refresh_token' => 'encrypted',
            'sync_enabled' => 'boolean',
            'last_tested_at' => 'datetime',
            'last_test_successful_at' => 'datetime',
            'last_orders_imported_at' => 'datetime',
            'last_orders_import_window_from' => 'datetime',
            'last_orders_import_window_to' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('amazon_spapi_connection')
            ->logOnly([
                'seller_id',
                'marketplace_id',
                'region',
                'endpoint',
                'sync_enabled',
                'status',
                'last_tested_at',
                'last_test_successful_at',
                'last_error',
                'last_orders_imported_at',
                'last_orders_import_window_from',
                'last_orders_import_window_to',
                'last_orders_import_status',
                'last_orders_import_error',
            ])
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

    public function importRuns(): HasMany
    {
        return $this->hasMany(AmazonSpapiImportRun::class);
    }
}
