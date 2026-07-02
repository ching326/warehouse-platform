<?php

namespace App\Services\Fulfillment;

use App\Models\OutboundOrder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class RequeuePrintService
{
    /**
     * @param  array<int, int>  $allowedTenantIds
     */
    public function requeue(OutboundOrder $order, array $allowedTenantIds): bool
    {
        $allowedTenantIds = array_values(array_unique(array_map('intval', $allowedTenantIds)));

        if ($allowedTenantIds === []) {
            return false;
        }

        return DB::transaction(function () use ($order, $allowedTenantIds): bool {
            $locked = OutboundOrder::query()
                ->whereKey($order->id)
                ->whereIn('tenant_id', $allowedTenantIds)
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                return false;
            }

            if (in_array($locked->status, [OutboundOrder::STATUS_SHIPPED, OutboundOrder::STATUS_CANCELLED], true)) {
                return false;
            }

            if ($locked->courier_label_exported_at === null) {
                return false;
            }

            $previous = $locked->courier_label_exported_at?->toISOString();

            $locked->forceFill(['courier_label_exported_at' => null])->save();

            activity('outbound_order')
                ->performedOn($locked)
                ->causedBy(Auth::user())
                ->event('courier_label_requeued')
                ->withProperties([
                    'outbound_order_id' => $locked->id,
                    'outbound_order_ref' => $locked->ref,
                    'previous_courier_label_exported_at' => $previous,
                    'user_id' => Auth::id(),
                ])
                ->log('courier_label_requeued');

            return true;
        });
    }
}
