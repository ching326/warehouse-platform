<?php

namespace App\Services\Outbound;

use App\Models\FulfillmentGroup;
use App\Models\OutboundOrder;
use App\Models\SalesOrder;
use App\Services\InventoryService;
use App\Support\TrackingNumber;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ShipOutboundOrderService
{
    public function __construct(private readonly InventoryService $inventoryService) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function ship(OutboundOrder $order, array $input = []): void
    {
        DB::transaction(function () use ($order, $input): void {
            $lockedOrder = OutboundOrder::query()
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedOrder->status !== OutboundOrder::STATUS_PENDING) {
                throw new InvalidArgumentException(__('outbound.already_processed'));
            }

            foreach ($lockedOrder->leafLines()->get() as $line) {
                $movement = $this->inventoryService->shipReservedStock(
                    tenantId: $lockedOrder->tenant_id,
                    warehouseId: $lockedOrder->warehouse_id,
                    stockItemId: $line->stock_item_id,
                    quantity: $line->qty,
                    context: [
                        'ref_type' => 'outbound_order',
                        'ref_id' => (string) $lockedOrder->id,
                        'user_id' => Auth::id(),
                    ],
                );

                $line->inventory_movement_id = $movement->id;
                $line->save();
            }

            $shippedAt = now();
            $courier = $this->nullableString($input['courier'] ?? null);
            $trackingNo = TrackingNumber::normalize($this->nullableString($input['tracking_no'] ?? null));

            $lockedOrder->update([
                'status' => OutboundOrder::STATUS_SHIPPED,
                'shipped_at' => $shippedAt,
                'shipped_by_user_id' => Auth::id(),
                'courier' => $courier,
                'tracking_no' => $trackingNo,
                'package_count' => $this->nullableInt($input['package_count'] ?? null),
                'package_weight_g' => $this->nullableInt($input['package_weight_g'] ?? null),
                'ship_note' => $this->nullableString($input['ship_note'] ?? null),
            ]);

            if ($lockedOrder->fulfillment_group_id === null) {
                return;
            }

            $group = FulfillmentGroup::query()
                ->whereKey($lockedOrder->fulfillment_group_id)
                ->lockForUpdate()
                ->first();

            if (! $group) {
                return;
            }

            $group->update([
                'status' => FulfillmentGroup::STATUS_SHIPPED,
                'shipped_at' => $shippedAt,
                'shipped_by_user_id' => Auth::id(),
            ]);

            $groupOrders = $group->groupOrders()->get(['id', 'sales_order_id']);
            $group->groupOrders()->update([
                'tracking_no' => $trackingNo,
                'courier' => $courier,
                'shipped_at' => $shippedAt,
            ]);

            SalesOrder::query()
                ->whereIn('id', $groupOrders->pluck('sales_order_id'))
                ->update([
                    'tracking_no' => $trackingNo,
                    'fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_SHIPPED,
                    'order_status' => SalesOrder::ORDER_STATUS_COMPLETED,
                    'shipped_at' => $shippedAt,
                ]);
        });
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return (int) $value;
    }
}
