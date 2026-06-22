<?php

namespace App\Models;

use Database\Factories\ReturnOrderLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReturnOrderLine extends Model
{
    /** @use HasFactory<ReturnOrderLineFactory> */
    use HasFactory;
    public const CONDITION_UNKNOWN = 'unknown';
    public const CONDITION_RESELLABLE = 'resellable';
    public const CONDITION_DAMAGED = 'damaged';
    public const CONDITION_OPENED_USED = 'opened_used';
    public const CONDITION_WRONG_ITEM = 'wrong_item';
    public const CONDITION_MISSING = 'missing';
    public const DISPOSITION_UNDECIDED = 'undecided';
    public const DISPOSITION_RETURN_TO_INVENTORY = 'return_to_inventory';
    public const DISPOSITION_MARK_DAMAGED = 'mark_damaged';
    public const DISPOSITION_HOLD_QUARANTINE = 'hold_quarantine';
    public const DISPOSITION_RESEND_TO_CUSTOMER = 'resend_to_customer';
    public const DISPOSITION_RESEND_TO_FBA = 'resend_to_fba';
    public const DISPOSITION_FORWARD_ELSEWHERE = 'forward_elsewhere';
    public const DISPOSITION_RETURN_TO_TENANT = 'return_to_tenant';
    public const DISPOSITION_DESTROY = 'destroy';
    public const DISPOSITION_WRITE_OFF = 'write_off';
    public const DISPOSITION_INVESTIGATE = 'investigate';
    protected $fillable = ['return_order_id', 'tenant_id', 'sales_order_line_id', 'sku_id', 'stock_item_id', 'expected_qty', 'received_qty', 'condition', 'disposition', 'received_location_id', 'disposition_location_id', 'note', 'received_at', 'inspected_at', 'dispositioned_at'];
    protected function casts(): array { return ['received_at' => 'datetime', 'inspected_at' => 'datetime', 'dispositioned_at' => 'datetime']; }
    public function returnOrder(): BelongsTo { return $this->belongsTo(ReturnOrder::class); }
    public function tenant(): BelongsTo { return $this->belongsTo(Tenant::class); }
    public function salesOrderLine(): BelongsTo { return $this->belongsTo(SalesOrderLine::class); }
    public function sku(): BelongsTo { return $this->belongsTo(Sku::class); }
    public function stockItem(): BelongsTo { return $this->belongsTo(StockItem::class); }
    public function mediaAssets(): HasMany { return $this->hasMany(MediaAsset::class, 'model_id')->where('model_type', MediaAsset::MODEL_TYPE_RETURN_ORDER_LINE)->orderBy('sort_order')->orderBy('id'); }
    public function receivedLocation(): BelongsTo { return $this->belongsTo(WarehouseLocation::class, 'received_location_id'); }
    public function dispositionLocation(): BelongsTo { return $this->belongsTo(WarehouseLocation::class, 'disposition_location_id'); }
    public function conditionLabel(): string { return __('return_orders.conditions.'.$this->condition); }
    public function dispositionLabel(): string { return __('return_orders.dispositions.'.$this->disposition); }
    public function hasInventoryDisposition(): bool { return in_array($this->disposition, [self::DISPOSITION_RETURN_TO_INVENTORY, self::DISPOSITION_MARK_DAMAGED, self::DISPOSITION_HOLD_QUARANTINE], true); }
    public static function conditionOptions(): array { return self::options('conditions', [self::CONDITION_UNKNOWN, self::CONDITION_RESELLABLE, self::CONDITION_DAMAGED, self::CONDITION_OPENED_USED, self::CONDITION_WRONG_ITEM, self::CONDITION_MISSING]); }
    public static function dispositionOptions(): array { return self::options('dispositions', [self::DISPOSITION_UNDECIDED, self::DISPOSITION_RETURN_TO_INVENTORY, self::DISPOSITION_MARK_DAMAGED, self::DISPOSITION_HOLD_QUARANTINE, self::DISPOSITION_RESEND_TO_CUSTOMER, self::DISPOSITION_RESEND_TO_FBA, self::DISPOSITION_FORWARD_ELSEWHERE, self::DISPOSITION_RETURN_TO_TENANT, self::DISPOSITION_DESTROY, self::DISPOSITION_WRITE_OFF, self::DISPOSITION_INVESTIGATE]); }
    private static function options(string $group, array $values): array { return collect($values)->mapWithKeys(fn ($v) => [$v => __('return_orders.'.$group.'.'.$v)])->all(); }
}

