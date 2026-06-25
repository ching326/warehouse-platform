<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReturnOrderCost extends Model
{
    public const COST_FREIGHT_COLLECT = 'freight_collect';

    public const COST_INSPECTION = 'inspection';

    public const COST_RESTOCKING = 'restocking';

    public const COST_DISPOSAL = 'disposal';

    public const COST_RESEND_SHIPPING = 'resend_shipping';

    public const COST_OTHER = 'other';

    protected $fillable = ['return_order_id', 'tenant_id', 'cost_type', 'amount', 'currency', 'note', 'created_by_user_id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    public function returnOrder(): BelongsTo
    {
        return $this->belongsTo(ReturnOrder::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public static function costTypeOptions(): array
    {
        return collect([self::COST_FREIGHT_COLLECT, self::COST_INSPECTION, self::COST_RESTOCKING, self::COST_DISPOSAL, self::COST_RESEND_SHIPPING, self::COST_OTHER])->mapWithKeys(fn ($v) => [$v => __('return_orders.cost_types.'.$v)])->all();
    }
}
