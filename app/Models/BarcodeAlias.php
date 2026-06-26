<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class BarcodeAlias extends Model
{
    use LogsActivity;

    public const MODEL_TYPE_SKU = 'sku';

    public const MODEL_TYPE_STOCK_ITEM = 'stock_item';

    public const SOURCE_PLATFORM_LABEL_CODE = 'platform_label_code';

    public const SOURCE_IMPORT = 'import';

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_SYSTEM = 'system';

    public const BARCODE_TYPES = [
        'jan',
        'ean',
        'upc',
        'gtin',
        'fnsku',
        'platform_label',
        'internal',
        'supplier',
        'carton',
        'internal_label',
        'supplier_label',
        'other',
        'unknown',
    ];

    protected $fillable = [
        'tenant_id',
        'model_type',
        'model_id',
        'barcode',
        'normalized_barcode',
        'barcode_type',
        'label',
        'is_primary',
        'is_active',
        'source',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('barcode_alias')
            ->logOnly(['barcode', 'barcode_type', 'label', 'is_primary', 'is_active'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public static function normalize(string $value): string
    {
        return strtoupper(str_replace([' ', '-'], '', trim($value)));
    }
}
