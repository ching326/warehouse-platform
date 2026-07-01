<?php

namespace App\Models;

use Database\Factories\ShopFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Shop extends Model
{
    /** @use HasFactory<ShopFactory> */
    use HasFactory, LogsActivity;

    public const CONSOLIDATION_NONE = 'none';

    public const CONSOLIDATION_SAME_SHOP = 'same_shop';

    public const CONSOLIDATION_CROSS_SHOP = 'cross_shop';

    public const MARKETPLACE_JP = 'JP';

    public const MARKETPLACE_US = 'US';

    public const MARKETPLACE_CA = 'CA';

    public const MARKETPLACE_AU = 'AU';

    public const MARKETPLACE_EU = 'EU';

    protected $fillable = [
        'tenant_id',
        'platform',
        'marketplace',
        'code',
        'name',
        'consolidation_mode',
        'contact_name',
        'contact_email',
        'ship_label_address',
        'ship_label_phone',
        'ship_label_postcode',
        'status',
        'note',
    ];

    public static function consolidationModes(): array
    {
        return [
            self::CONSOLIDATION_NONE,
            self::CONSOLIDATION_SAME_SHOP,
            self::CONSOLIDATION_CROSS_SHOP,
        ];
    }

    public static function marketplaceOptions(): array
    {
        return [
            self::MARKETPLACE_JP,
            self::MARKETPLACE_US,
            self::MARKETPLACE_CA,
            self::MARKETPLACE_AU,
            self::MARKETPLACE_EU,
        ];
    }

    public static function normalizeMarketplace(?string $marketplace): string
    {
        $marketplace = strtoupper(trim((string) $marketplace));

        if ($marketplace === '') {
            return '';
        }

        if (str_contains($marketplace, '_')) {
            $marketplace = substr($marketplace, strrpos($marketplace, '_') + 1);
        }

        return $marketplace;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('shop')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function skus(): HasMany
    {
        return $this->hasMany(Sku::class);
    }

    public function amazonSpapiConnection(): HasOne
    {
        return $this->hasOne(AmazonSpapiConnection::class);
    }
}
