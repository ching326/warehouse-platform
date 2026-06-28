<?php

namespace App\Services\Outbound;

use App\Models\OutboundOrder;
use App\Models\ShippingMethod;
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

            if ($lockedOrder->status !== OutboundOrder::STATUS_RESERVED) {
                throw new InvalidArgumentException(__('outbound.already_processed'));
            }

            if ($lockedOrder->hold_status === OutboundOrder::HOLD_STATUS_ON_HOLD) {
                throw new InvalidArgumentException(__('outbound.cannot_ship_on_hold'));
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
            $shippingMethodId = array_key_exists('shipping_method_id', $input)
                ? $this->nullableInt($input['shipping_method_id'])
                : $lockedOrder->shipping_method_id;
            $carrierCode = $shippingMethodId
                ? ShippingMethod::query()
                    ->join('carriers', 'shipping_methods.carrier_id', '=', 'carriers.id')
                    ->where('shipping_methods.id', $shippingMethodId)
                    ->value('carriers.code')
                : null;
            $courier = is_string($carrierCode)
                ? $carrierCode
                : $this->nullableString($input['courier'] ?? null);
            $trackingNo = TrackingNumber::normalize($this->nullableString($input['tracking_no'] ?? null));

            $lockedOrder->update([
                'status' => OutboundOrder::STATUS_SHIPPED,
                'shipped_at' => $shippedAt,
                'shipped_by_user_id' => Auth::id(),
                'shipping_method_id' => $shippingMethodId,
                'courier' => $courier,
                'tracking_no' => $trackingNo,
                'package_count' => $this->nullableInt($input['package_count'] ?? null),
                'package_weight_g' => $this->nullableInt($input['package_weight_g'] ?? null),
                'ship_note' => $this->nullableString($input['ship_note'] ?? null),
            ]);

            // Linked sales orders (if any) are back-written by OutboundOrderObserver
            // on the status change to shipped.
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
