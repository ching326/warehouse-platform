<?php

namespace App\Observers;

use App\Models\FulfillmentGroup;
use App\Models\OutboundOrder;
use App\Models\SalesOrder;

class OutboundOrderObserver
{
    public function updated(OutboundOrder $order): void
    {
        if ($order->fulfillment_group_id === null || ! $order->wasChanged('status')) {
            return;
        }

        $group = FulfillmentGroup::query()->find($order->fulfillment_group_id);

        if (! $group) {
            return;
        }

        if ($order->status === OutboundOrder::STATUS_SHIPPED) {
            $group->update([
                'status' => FulfillmentGroup::STATUS_SHIPPED,
                'shipped_at' => $order->shipped_at,
                'shipped_by_user_id' => $order->shipped_by_user_id,
                'courier' => $order->courier,
                'tracking_no' => $order->tracking_no,
            ]);

            $group->orders()->update([
                'fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_SHIPPED,
                'order_status' => SalesOrder::ORDER_STATUS_COMPLETED,
            ]);
        }

        if ($order->status === OutboundOrder::STATUS_CANCELLED) {
            $group->update(['status' => FulfillmentGroup::STATUS_CANCELLED]);

            $group->orders()->update([
                'fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_READY,
            ]);
        }
    }
}
