<?php

namespace App\Services\Outbound;

use App\Models\OutboundOrder;
use App\Models\OutboundOrderLine;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\Sku;
use App\Models\SkuBundleComponent;
use App\Models\StockItem;
use App\Services\InventoryService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class HoldOutboundOrderService
{
    public function __construct(private readonly InventoryService $inventoryService) {}

    public function holdOutbound(
        OutboundOrder $outbound,
        string $source,
        bool $confirmedPrinted = false,
        ?string $reason = null,
    ): HoldOutboundResult {
        return DB::transaction(function () use ($outbound, $source, $confirmedPrinted, $reason): HoldOutboundResult {
            $locked = OutboundOrder::query()
                ->whereKey($outbound->id)
                ->with('salesOrders:id,order_status,fulfillment_status')
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== OutboundOrder::STATUS_PENDING) {
                throw new InvalidArgumentException(__('outbound.already_processed'));
            }

            if ($locked->hold_status === OutboundOrder::HOLD_STATUS_ON_HOLD) {
                return HoldOutboundResult::noOp();
            }

            if ($this->packingStarted($locked)) {
                throw new InvalidArgumentException(__('outbound.cannot_hold_packing'));
            }

            if ($locked->courier_csv_exported_at !== null && $source === 'sales_order') {
                throw new InvalidArgumentException(__('outbound.hold_printed_sales_blocked'));
            }

            if ($locked->courier_csv_exported_at !== null && ! $confirmedPrinted) {
                return HoldOutboundResult::requiresConfirmation();
            }

            $locked->update([
                'hold_status' => OutboundOrder::HOLD_STATUS_ON_HOLD,
                'held_at' => now(),
                'held_by_user_id' => Auth::id(),
                'held_from' => $source,
                'hold_reason' => $reason,
            ]);

            SalesOrder::query()
                ->whereIn('id', $locked->salesOrders->pluck('id'))
                ->update([
                    'order_status' => SalesOrder::ORDER_STATUS_ON_HOLD,
                    'fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_ARRANGED,
                ]);

            return HoldOutboundResult::held();
        });
    }

    public function releaseOutbound(OutboundOrder $outbound, string $source): void
    {
        DB::transaction(function () use ($outbound): void {
            $locked = OutboundOrder::query()
                ->whereKey($outbound->id)
                ->with('salesOrders:id,order_status,fulfillment_status')
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->hold_status !== OutboundOrder::HOLD_STATUS_ON_HOLD) {
                throw new InvalidArgumentException(__('outbound.not_on_hold'));
            }

            $locked->update([
                'hold_status' => OutboundOrder::HOLD_STATUS_ACTIVE,
                'released_at' => now(),
                'released_by_user_id' => Auth::id(),
            ]);

            SalesOrder::query()
                ->whereIn('id', $locked->salesOrders->pluck('id'))
                ->update([
                    'order_status' => SalesOrder::ORDER_STATUS_PENDING,
                    'fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_ARRANGED,
                ]);
        });
    }

    public function splitAndRebuildForSalesOrderHold(SalesOrder $targetOrder): void
    {
        DB::transaction(function () use ($targetOrder): void {
            $target = SalesOrder::query()
                ->whereKey($targetOrder->id)
                ->with('activeOutboundOrders.salesOrders.lines.sku.bundleComponents.componentStockItem')
                ->lockForUpdate()
                ->firstOrFail();

            $outbound = $target->activeOutboundOrders
                ->first(fn (OutboundOrder $order): bool => $order->reason === OutboundOrder::REASON_CUSTOMER_ORDER);

            if (! $outbound instanceof OutboundOrder) {
                throw new InvalidArgumentException(__('sales_orders.cannot_hold'));
            }

            $outbound = OutboundOrder::query()
                ->whereKey($outbound->id)
                ->with(['leafLines', 'salesOrders.lines.sku.bundleComponents.componentStockItem'])
                ->lockForUpdate()
                ->firstOrFail();

            if ($this->packingStarted($outbound)) {
                throw new InvalidArgumentException(__('outbound.cannot_hold_packing'));
            }

            if ($outbound->courier_csv_exported_at !== null) {
                throw new InvalidArgumentException(__('outbound.hold_printed_sales_blocked'));
            }

            if ($outbound->salesOrders->count() <= 1) {
                throw new InvalidArgumentException(__('sales_orders.cannot_hold'));
            }

            foreach ($outbound->leafLines as $line) {
                if (! $line instanceof OutboundOrderLine) {
                    continue;
                }

                $this->inventoryService->releaseReserve(
                    tenantId: (int) $outbound->tenant_id,
                    warehouseId: (int) $outbound->warehouse_id,
                    stockItemId: (int) $line->stock_item_id,
                    quantity: (int) $line->qty,
                    context: [
                        'ref_type' => 'outbound_order',
                        'ref_id' => (string) $outbound->id,
                        'user_id' => Auth::id(),
                    ],
                );
            }

            $linkedOrders = $outbound->salesOrders;

            $outbound->update([
                'status' => OutboundOrder::STATUS_CANCELLED,
                'hold_status' => OutboundOrder::HOLD_STATUS_ACTIVE,
                'cancelled_at' => now(),
                'cancelled_by_user_id' => Auth::id(),
            ]);
            $outbound->salesOrders()->detach();

            foreach ($linkedOrders as $order) {
                if (! $order instanceof SalesOrder) {
                    continue;
                }

                if ((int) $order->id === (int) $target->id) {
                    $order->update([
                        'order_status' => SalesOrder::ORDER_STATUS_ON_HOLD,
                        'fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_UNFULFILLED,
                    ]);

                    continue;
                }

                $order->loadMissing('lines.sku.bundleComponents.componentStockItem');
                $order->update([
                    'order_status' => SalesOrder::ORDER_STATUS_PENDING,
                    'fulfillment_status' => $this->orderIsShipComplete($order)
                        ? SalesOrder::FULFILLMENT_STATUS_READY
                        : SalesOrder::FULFILLMENT_STATUS_UNFULFILLED,
                ]);
            }
        });
    }

    private function orderIsShipComplete(SalesOrder $order): bool
    {
        $fulfillable = $order->lines
            ->filter(fn (SalesOrderLine $line): bool => $line->line_status !== SalesOrderLine::STATUS_CANCELLED);

        if ($fulfillable->isEmpty()) {
            return false;
        }

        return $fulfillable->every(function (SalesOrderLine $line) use ($order): bool {
            $sku = $line->sku;

            if ($line->line_status !== SalesOrderLine::STATUS_READY || ! $sku instanceof Sku) {
                return false;
            }

            if ($sku->sku_type === 'virtual_bundle') {
                return $sku->bundleComponents->isNotEmpty()
                    && $sku->bundleComponents->every(function ($component) use ($order): bool {
                        if (! $component instanceof SkuBundleComponent) {
                            return false;
                        }

                        $stockItem = $component->componentStockItem;

                        return $stockItem instanceof StockItem
                            && (int) $stockItem->tenant_id === (int) $order->tenant_id;
                    });
            }

            return $sku->stock_item_id !== null;
        });
    }

    private function packingStarted(OutboundOrder $outbound): bool
    {
        return $outbound->packScans()->exists();
    }
}
