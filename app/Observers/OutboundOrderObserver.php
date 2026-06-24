<?php

namespace App\Observers;

use App\Models\OutboundOrder;
use App\Models\SalesOrder;

class OutboundOrderObserver
{
    public function updated(OutboundOrder $order): void
    {
        if (! $order->wasChanged('status')) {
            return;
        }

        $salesOrderIds = $order->salesOrders()->pluck('sales_orders.id');

        if ($salesOrderIds->isEmpty()) {
            return;
        }

        if ($order->status === OutboundOrder::STATUS_SHIPPED) {
            SalesOrder::query()
                ->whereIn('id', $salesOrderIds)
                ->update([
                    'tracking_no' => $order->tracking_no,
                    'fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_SHIPPED,
                    'order_status' => SalesOrder::ORDER_STATUS_COMPLETED,
                    'shipped_at' => $order->shipped_at,
                ]);
        }

        if ($order->status === OutboundOrder::STATUS_CANCELLED) {
            SalesOrder::query()
                ->whereIn('id', $salesOrderIds)
                ->update([
                    'fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_READY,
                ]);
        }
    }
}
