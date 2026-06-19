<?php

namespace App\Observers;

use App\Models\SalesOrder;

class SalesOrderObserver
{
    public function saving(SalesOrder $order): void
    {
        $recipientFields = [
            'shop_id',
            'recipient_name',
            'recipient_country_code',
            'recipient_postal_code',
            'recipient_state',
            'recipient_city',
            'recipient_address_line1',
            'recipient_address_line2',
        ];

        if (! $order->exists || $order->isDirty($recipientFields)) {
            $order->recalculateShipTogetherKey();
        }
    }
}
