<?php

use App\Models\SalesOrder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $this->activeOrdersQuery()
            ->each(function (SalesOrder $order): void {
                $order->recalculateShipTogetherKey();
                $order->saveQuietly();
            });
    }

    public function down(): void
    {
        $this->activeOrdersQuery()
            ->each(function (SalesOrder $order): void {
                $order->ship_together_key = $this->legacyShipTogetherKey($order);
                $order->saveQuietly();
            });
    }

    private function activeOrdersQuery()
    {
        return SalesOrder::query()
            ->whereNotIn('order_status', [
                SalesOrder::ORDER_STATUS_CANCELLED,
                SalesOrder::ORDER_STATUS_COMPLETED,
            ])
            ->whereNotIn('fulfillment_status', [
                SalesOrder::FULFILLMENT_STATUS_CANCELLED,
                SalesOrder::FULFILLMENT_STATUS_SHIPPED,
            ])
            ->orderBy('id');
    }

    private function legacyShipTogetherKey(SalesOrder $order): ?string
    {
        if (empty(trim((string) $order->recipient_address_line1))) {
            return null;
        }

        return md5(implode('|', [
            $order->tenant_id,
            $order->shop_id,
            strtolower(trim((string) $order->recipient_name)),
            strtolower(trim((string) $order->recipient_country_code)),
            strtolower(trim((string) $order->recipient_postal_code)),
            strtolower(trim((string) $order->recipient_state)),
            strtolower(trim((string) $order->recipient_city)),
            strtolower(trim((string) $order->recipient_address_line1)),
            strtolower(trim((string) $order->recipient_address_line2)),
        ]));
    }
};
