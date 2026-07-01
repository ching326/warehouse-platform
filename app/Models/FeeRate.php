<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class FeeRate extends Model
{
    use LogsActivity;

    public const TYPE_STORAGE = 'storage';

    public const TYPE_HANDLING_INBOUND = 'handling_inbound';

    public const TYPE_HANDLING_OUTBOUND_ORDER = 'handling_outbound_order';

    public const TYPE_HANDLING_OUTBOUND_UNIT = 'handling_outbound_unit';

    public const TYPE_QC = 'qc';

    public const TYPE_RETURN_SHIPPING = 'return_shipping';

    public const TYPE_POSTAGE = 'postage';

    public const UNIT_PER_M3_MONTH = 'per_m3_month';

    public const UNIT_PER_UNIT_MONTH = 'per_unit_month';

    public const UNIT_PER_UNIT = 'per_unit';

    public const UNIT_PER_ORDER = 'per_order';

    public const UNIT_PERCENT = 'percent';

    public const FEE_TYPES = [
        self::TYPE_STORAGE,
        self::TYPE_HANDLING_INBOUND,
        self::TYPE_HANDLING_OUTBOUND_ORDER,
        self::TYPE_HANDLING_OUTBOUND_UNIT,
        self::TYPE_QC,
        self::TYPE_RETURN_SHIPPING,
        self::TYPE_POSTAGE,
    ];

    public const UNITS = [
        self::UNIT_PER_M3_MONTH,
        self::UNIT_PER_UNIT_MONTH,
        self::UNIT_PER_UNIT,
        self::UNIT_PER_ORDER,
        self::UNIT_PERCENT,
    ];

    protected $fillable = [
        'tenant_id',
        'fee_type',
        'unit',
        'rate',
        'markup_pct',
        'currency',
        'effective_from',
        'effective_to',
    ];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'rate' => 'decimal:4',
            'markup_pct' => 'decimal:4',
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('fee_rate')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public static function allowedUnitsFor(string $feeType): array
    {
        return match ($feeType) {
            self::TYPE_STORAGE => [self::UNIT_PER_M3_MONTH, self::UNIT_PER_UNIT_MONTH],
            self::TYPE_HANDLING_INBOUND, self::TYPE_HANDLING_OUTBOUND_UNIT, self::TYPE_QC => [self::UNIT_PER_UNIT],
            self::TYPE_HANDLING_OUTBOUND_ORDER => [self::UNIT_PER_ORDER],
            self::TYPE_RETURN_SHIPPING, self::TYPE_POSTAGE => [self::UNIT_PERCENT],
            default => [],
        };
    }

    public static function percentFeeTypes(): array
    {
        return [
            self::TYPE_RETURN_SHIPPING,
            self::TYPE_POSTAGE,
        ];
    }

    public static function isPercentFeeType(string $feeType): bool
    {
        return in_array($feeType, self::percentFeeTypes(), true);
    }

    public static function resolveForDate(int $tenantId, string $feeType, CarbonInterface|string $date): ?self
    {
        $dateString = $date instanceof CarbonInterface
            ? $date->toDateString()
            : CarbonImmutable::parse($date)->toDateString();

        return self::query()
            ->where('tenant_id', $tenantId)
            ->where('fee_type', $feeType)
            ->where('effective_from', '<=', $dateString)
            ->where(fn (Builder $query) => $query
                ->whereNull('effective_to')
                ->orWhere('effective_to', '>=', $dateString))
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();
    }

    public static function hasOverlap(
        int $tenantId,
        string $feeType,
        string $effectiveFrom,
        ?string $effectiveTo = null,
        ?int $ignoreId = null
    ): bool {
        $end = $effectiveTo ?: '9999-12-31';

        return self::query()
            ->where('tenant_id', $tenantId)
            ->where('fee_type', $feeType)
            ->when($ignoreId !== null, fn (Builder $query) => $query->whereKeyNot($ignoreId))
            ->where('effective_from', '<=', $end)
            ->where(fn (Builder $query) => $query
                ->whereNull('effective_to')
                ->orWhere('effective_to', '>=', $effectiveFrom))
            ->exists();
    }
}
