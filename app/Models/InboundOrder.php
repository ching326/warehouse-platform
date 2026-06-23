<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\InboundOrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InboundOrder extends Model
{
    /** @use HasFactory<InboundOrderFactory> */
    use HasFactory;

    public static function buildRef(int $id, string $tenantCode, ?CarbonInterface $date = null): string
    {
        $date ??= now('Asia/Tokyo');
        $tenantCode = strtoupper(trim($tenantCode));
        $tenantCode = preg_replace('/[^A-Z0-9]+/', '', $tenantCode) ?? '';
        $tenantCode = $tenantCode !== '' ? $tenantCode : 'TENANT';

        return 'IB-'.$tenantCode.'-'.$date->format('ymd').'-'.str_pad((string) $id, 3, '0', STR_PAD_LEFT);
    }

    public const STATUS_PENDING = 'pending';
    public const STATUS_ARRIVED = 'arrived';
    public const STATUS_PARTIALLY_RECEIVED = 'partially_received';
    public const STATUS_RECEIVED = 'received';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'tenant_id',
        'warehouse_id',
        'ref',
        'status',
        'expected_at',
        'expected_carton_count',
        'received_carton_count',
        'carton_mark',
        'note',
        'arrived_at',
        'arrived_by_user_id',
        'received_at',
        'received_by_user_id',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'expected_at' => 'date',
            'expected_carton_count' => 'integer',
            'received_carton_count' => 'integer',
            'arrived_at' => 'datetime',
            'received_at' => 'datetime',
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

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function arrivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'arrived_by_user_id');
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by_user_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InboundOrderLine::class)->orderBy('id');
    }

    public function mediaAssets(): HasMany
    {
        return $this->hasMany(MediaAsset::class, 'model_id')
            ->where('model_type', MediaAsset::MODEL_TYPE_INBOUND_ORDER)
            ->orderBy('sort_order')
            ->orderBy('id');
    }
}
