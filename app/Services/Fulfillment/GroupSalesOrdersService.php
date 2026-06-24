<?php

namespace App\Services\Fulfillment;

use App\Models\FulfillmentGroup;
use App\Models\OutboundOrder;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\ShippingMethod;
use App\Models\Shop;
use App\Services\InventoryService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class GroupSalesOrdersService
{
    public function __construct(private readonly InventoryService $inventoryService)
    {
    }

    public function createGroup(int $tenantId, int $warehouseId, array $salesOrderIds): FulfillmentGroup
    {
        return DB::transaction(function () use ($tenantId, $warehouseId, $salesOrderIds) {
            $orders = $this->lockReadyOrders($tenantId, $salesOrderIds);
            $this->validateReadyOrders($orders, $tenantId, $salesOrderIds);
            $this->validateSharedShipKey($orders);
            $this->validateConsolidation($orders);

            return $this->createGroupFromOrders($tenantId, $warehouseId, $orders);
        });
    }

    public function singleOrderGroup(int $tenantId, int $warehouseId, int $salesOrderId): FulfillmentGroup
    {
        return $this->createGroup($tenantId, $warehouseId, [$salesOrderId]);
    }

    public function joinGroup(FulfillmentGroup $group, array $salesOrderIds): FulfillmentGroup
    {
        return DB::transaction(function () use ($group, $salesOrderIds) {
            $group = FulfillmentGroup::query()
                ->with(['orders.shop', 'outboundOrder', 'tenant:id,code'])
                ->lockForUpdate()
                ->findOrFail($group->id);

            $this->validateJoinableGroup($group);

            $orders = $this->lockReadyOrders((int) $group->tenant_id, $salesOrderIds);
            $this->validateReadyOrders($orders, (int) $group->tenant_id, $salesOrderIds);

            if ($orders->pluck('ship_together_key')->unique()->count() !== 1 || $orders->first()?->ship_together_key !== $group->ship_together_key) {
                throw ValidationException::withMessages([
                    'selectedOrderIds' => __('fulfillment_groups.orders_must_share_key'),
                ]);
            }

            $this->validateConsolidation($group->orders->concat($orders));
            $this->appendOrdersToGroup($group, $orders);

            return $group->refresh();
        });
    }

    public function canCombineOrders(Collection $orders): bool
    {
        try {
            $this->validateConsolidation($orders);
        } catch (InvalidArgumentException) {
            return false;
        }

        return true;
    }

    public function joinableGroupFor(SalesOrder $order, int $warehouseId): ?FulfillmentGroup
    {
        return FulfillmentGroup::query()
            ->where('tenant_id', $order->tenant_id)
            ->where('warehouse_id', $warehouseId)
            ->where('ship_together_key', $order->ship_together_key)
            ->where('status', FulfillmentGroup::STATUS_RESERVED)
            ->whereDoesntHave('orders', fn ($query) => $query
                ->whereNotNull('courier_csv_exported_at')
                ->orWhere('fulfillment_status', SalesOrder::FULFILLMENT_STATUS_SHIPPED))
            ->with('orders.shop')
            ->orderBy('id')
            ->first();
    }

    /**
     * Put a sales order on hold, clawing it back out of any reserved fulfillment group
     * (release reservation, detach, rebuild or cancel the outbound order) in one transaction.
     * Returns true if the order was held, false if it was not eligible.
     */
    public function releaseOrderForHold(SalesOrder $order): bool
    {
        return DB::transaction(function () use ($order): bool {
            $order = SalesOrder::query()
                ->with([
                    'lines.sku.stockItem',
                    'lines.sku.bundleComponents.componentStockItem',
                ])
                ->lockForUpdate()
                ->findOrFail($order->id);

            if (
                $order->order_status !== SalesOrder::ORDER_STATUS_PENDING
                || ! in_array($order->fulfillment_status, [
                    SalesOrder::FULFILLMENT_STATUS_UNFULFILLED,
                    SalesOrder::FULFILLMENT_STATUS_READY,
                    SalesOrder::FULFILLMENT_STATUS_ARRANGED,
                ], true)
            ) {
                return false;
            }

            $activeOutbound = OutboundOrder::query()
                ->where('status', '!=', OutboundOrder::STATUS_CANCELLED)
                ->whereHas('salesOrders', fn ($query) => $query->where('sales_orders.id', $order->id))
                ->lockForUpdate()
                ->first();

            if ($activeOutbound?->courier_csv_exported_at !== null) {
                return false;
            }

            $group = FulfillmentGroup::query()
                ->where('status', FulfillmentGroup::STATUS_RESERVED)
                ->whereHas('groupOrders', fn ($query) => $query->where('sales_order_id', $order->id))
                ->with('outboundOrder')
                ->lockForUpdate()
                ->first();

            if ($group) {
                [, $heldStockItems] = $this->aggregateLines(collect([$order]));

                foreach ($heldStockItems as $stockItemId => $totalQty) {
                    $this->inventoryService->releaseReserve(
                        tenantId: (int) $group->tenant_id,
                        warehouseId: (int) $group->warehouse_id,
                        stockItemId: (int) $stockItemId,
                        quantity: (int) $totalQty,
                        context: [
                            'ref_type' => 'fulfillment_group',
                            'ref_id' => (string) $group->id,
                            'user_id' => Auth::id(),
                        ],
                    );
                }

                $group->orders()->detach($order->id);

                $outbound = $group->outboundOrder;
                if ($outbound) {
                    $outbound->salesOrders()->detach($order->id);
                    $outbound->lines()->delete();
                }

                $remainingOrders = SalesOrder::query()
                    ->whereHas('fulfillmentGroupOrders', fn ($query) => $query->where('fulfillment_group_id', $group->id))
                    ->with([
                        'lines.sku.stockItem',
                        'lines.sku.bundleComponents.componentStockItem',
                    ])
                    ->lockForUpdate()
                    ->get();

                if ($remainingOrders->isEmpty()) {
                    $group->update(['status' => FulfillmentGroup::STATUS_CANCELLED]);

                    if ($outbound) {
                        $outbound->update([
                            'status' => OutboundOrder::STATUS_CANCELLED,
                            'cancelled_at' => now(),
                            'cancelled_by_user_id' => Auth::id(),
                        ]);
                    }
                } else {
                    if (! $outbound || $outbound->status !== OutboundOrder::STATUS_PENDING) {
                        throw new InvalidArgumentException(__('fulfillment_groups.group_not_joinable'));
                    }

                    [$bySkuAndItem] = $this->aggregateLines($remainingOrders);
                    $this->createOutboundLines($group, $outbound, $bySkuAndItem);
                }
            }

            $order->update([
                'order_status' => SalesOrder::ORDER_STATUS_ON_HOLD,
                'fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_UNFULFILLED,
            ]);

            return true;
        });
    }

    private function createGroupFromOrders(int $tenantId, int $warehouseId, Collection $orders): FulfillmentGroup
    {
        $firstOrder = $orders->firstOrFail();
        $defaultShippingMethodId = $this->resolveGroupShippingMethodId($orders);

        $group = FulfillmentGroup::create([
            'tenant_id' => $tenantId,
            'warehouse_id' => $warehouseId,
            'shipping_method_id' => $defaultShippingMethodId,
            'reference_no' => 'FG-PENDING-'.Str::uuid(),
            'status' => FulfillmentGroup::STATUS_RESERVED,
            'ship_together_key' => $firstOrder->ship_together_key,
            'recipient_name' => $firstOrder->recipient_name,
            'recipient_phone' => $firstOrder->recipient_phone,
            'recipient_country_code' => $firstOrder->recipient_country_code,
            'recipient_postal_code' => $firstOrder->recipient_postal_code,
            'recipient_state' => $firstOrder->recipient_state,
            'recipient_city' => $firstOrder->recipient_city,
            'recipient_address_line1' => $firstOrder->recipient_address_line1,
            'recipient_address_line2' => $firstOrder->recipient_address_line2,
            'created_by_user_id' => Auth::id(),
        ]);
        $group->update(['reference_no' => FulfillmentGroup::buildReferenceNo($group->id, $firstOrder->tenant->code)]);

        $outbound = OutboundOrder::create([
            'fulfillment_group_id' => $group->id,
            'reason' => OutboundOrder::REASON_CUSTOMER_ORDER,
            'ship_mode' => OutboundOrder::SHIP_MODE_PARCEL,
            'shipping_method_id' => $defaultShippingMethodId,
            'tenant_id' => $tenantId,
            'warehouse_id' => $warehouseId,
            'ref' => $group->reference_no,
            'status' => OutboundOrder::STATUS_PENDING,
            'recipient_name' => $firstOrder->recipient_name,
            'recipient_phone' => $firstOrder->recipient_phone,
            'recipient_country_code' => $firstOrder->recipient_country_code,
            'recipient_postal_code' => $firstOrder->recipient_postal_code,
            'recipient_state' => $firstOrder->recipient_state,
            'recipient_city' => $firstOrder->recipient_city,
            'recipient_address_line1' => $firstOrder->recipient_address_line1,
            'recipient_address_line2' => $firstOrder->recipient_address_line2,
            'created_by_user_id' => Auth::id(),
        ]);

        $this->attachAndReserve($group, $outbound, $orders);

        return $group;
    }

    private function appendOrdersToGroup(FulfillmentGroup $group, Collection $orders): void
    {
        $outbound = $group->outboundOrder;

        if (! $outbound || $outbound->status !== OutboundOrder::STATUS_PENDING) {
            throw new InvalidArgumentException(__('fulfillment_groups.group_not_joinable'));
        }

        $this->attachAndReserve($group, $outbound, $orders);
    }

    private function attachAndReserve(FulfillmentGroup $group, OutboundOrder $outbound, Collection $orders): void
    {
        $now = now();
        $attachPayload = $orders
            ->pluck('id')
            ->mapWithKeys(fn ($id) => [(int) $id => ['arranged_at' => $now]])
            ->all();

        $group->orders()->attach($attachPayload);
        $outbound->salesOrders()->attach($attachPayload);

        [$bySkuAndItem, $byStockItem] = $this->aggregateLines($orders);

        foreach ($byStockItem as $stockItemId => $totalQty) {
            $this->inventoryService->reserveStock(
                tenantId: (int) $group->tenant_id,
                warehouseId: (int) $group->warehouse_id,
                stockItemId: (int) $stockItemId,
                quantity: (int) $totalQty,
                context: [
                    'ref_type' => 'fulfillment_group',
                    'ref_id' => (string) $group->id,
                    'user_id' => Auth::id(),
                ],
            );
        }

        $this->createOutboundLines($group, $outbound, $bySkuAndItem);

        SalesOrder::query()
            ->whereIn('id', $orders->pluck('id')->all())
            ->update(['fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_ARRANGED]);
    }

    private function lockReadyOrders(int $tenantId, array $salesOrderIds): Collection
    {
        $ids = array_values(array_unique(array_map('intval', $salesOrderIds)));

        return SalesOrder::query()
            ->whereIn('id', $ids)
            ->where('tenant_id', $tenantId)
            ->with([
                'tenant:id,code',
                'shop:id,tenant_id,consolidation_mode',
                'fulfillmentGroupOrders.fulfillmentGroup',
                'lines.sku.stockItem',
                'lines.sku.bundleComponents.componentStockItem',
            ])
            ->lockForUpdate()
            ->get();
    }

    private function validateReadyOrders(Collection $orders, int $tenantId, array $salesOrderIds): void
    {
        $ids = array_values(array_unique(array_map('intval', $salesOrderIds)));

        if ($orders->count() !== count($ids)) {
            throw ValidationException::withMessages([
                'selectedOrderIds' => __('fulfillment_groups.orders_required'),
            ]);
        }

        foreach ($orders as $order) {
            if ((int) $order->tenant_id !== $tenantId) {
                throw ValidationException::withMessages([
                    'selectedOrderIds' => __('fulfillment_groups.orders_required'),
                ]);
            }

            if (
                $order->order_status !== SalesOrder::ORDER_STATUS_PENDING
                || $order->fulfillment_status !== SalesOrder::FULFILLMENT_STATUS_READY
            ) {
                throw ValidationException::withMessages([
                    'selectedOrderIds' => __('fulfillment_groups.order_no_longer_ready', ['id' => $order->id]),
                ]);
            }

            if ($this->hasActiveGroup($order)) {
                throw ValidationException::withMessages([
                    'selectedOrderIds' => __('fulfillment_groups.order_already_grouped', ['id' => $order->id]),
                ]);
            }

            if (! $this->orderIsShipComplete($order)) {
                throw ValidationException::withMessages([
                    'selectedOrderIds' => __('fulfillment_groups.order_not_ship_complete', ['id' => $order->id]),
                ]);
            }
        }
    }

    private function validateSharedShipKey(Collection $orders): void
    {
        $keys = $orders->pluck('ship_together_key')->unique();

        if ($keys->count() !== 1 || $keys->first() === null) {
            throw ValidationException::withMessages([
                'selectedOrderIds' => __('fulfillment_groups.orders_must_share_key'),
            ]);
        }
    }

    private function validateJoinableGroup(FulfillmentGroup $group): void
    {
        if ($group->status !== FulfillmentGroup::STATUS_RESERVED || $group->shipped_at !== null) {
            throw new InvalidArgumentException(__('fulfillment_groups.group_not_joinable'));
        }

        if ($group->orders()->whereNotNull('courier_csv_exported_at')->exists()) {
            throw new InvalidArgumentException(__('fulfillment_groups.group_not_joinable'));
        }
    }

    private function validateConsolidation(Collection $orders): void
    {
        if ($orders->count() <= 1) {
            return;
        }

        $shops = $orders->pluck('shop')->filter();
        $shopIds = $shops->pluck('id')->unique();
        $modes = $shops->pluck('consolidation_mode')->map(fn ($mode) => $mode ?: Shop::CONSOLIDATION_SAME_SHOP);

        if ($modes->contains(Shop::CONSOLIDATION_NONE)) {
            throw new InvalidArgumentException(__('fulfillment_groups.consolidation_not_allowed'));
        }

        if ($shopIds->count() === 1) {
            if ($modes->every(fn ($mode) => in_array($mode, [Shop::CONSOLIDATION_SAME_SHOP, Shop::CONSOLIDATION_CROSS_SHOP], true))) {
                return;
            }

            throw new InvalidArgumentException(__('fulfillment_groups.consolidation_not_allowed'));
        }

        if ($modes->every(fn ($mode) => $mode === Shop::CONSOLIDATION_CROSS_SHOP)) {
            return;
        }

        throw new InvalidArgumentException(__('fulfillment_groups.consolidation_not_allowed'));
    }

    private function hasActiveGroup(SalesOrder $order): bool
    {
        return $order->fulfillmentGroupOrders
            ->contains(fn ($pivot) => $pivot->fulfillmentGroup
                && $pivot->fulfillmentGroup->status !== FulfillmentGroup::STATUS_CANCELLED);
    }

    private function orderIsShipComplete(SalesOrder $order): bool
    {
        $fulfillable = $order->lines
            ->filter(fn ($line) => $line->line_status !== SalesOrderLine::STATUS_CANCELLED);

        if ($fulfillable->isEmpty()) {
            return false;
        }

        return $fulfillable->every(function ($line): bool {
            if ($line->line_status !== SalesOrderLine::STATUS_READY) {
                return false;
            }

            $sku = $line->sku;
            if (! $sku) {
                return false;
            }

            if ($sku->sku_type === 'virtual_bundle') {
                return $sku->bundleComponents->isNotEmpty();
            }

            return $sku->stock_item_id !== null;
        });
    }

    /**
     * @return array{0: array<string,array{sku_id:int,stock_item_id:?int,qty:int,children:array<int,array{sku_id:int,stock_item_id:int,qty:int}>}>, 1: array<int,int>}
     */
    private function aggregateLines(Collection $orders): array
    {
        $outboundLines = [];
        $byStockItem = [];

        foreach ($orders as $order) {
            foreach ($order->lines as $line) {
                if ($line->line_status !== SalesOrderLine::STATUS_READY) {
                    continue;
                }

                $sku = $line->sku;
                if (! $sku) {
                    continue;
                }

                if ($sku->sku_type === 'virtual_bundle') {
                    if ($sku->bundleComponents->isEmpty()) {
                        throw ValidationException::withMessages([
                            'selectedOrderIds' => __('outbound.bundle_no_components'),
                        ]);
                    }

                    $parentKey = 'bundle:'.$sku->id;
                    $outboundLines[$parentKey] ??= [
                        'sku_id' => $sku->id,
                        'stock_item_id' => null,
                        'qty' => 0,
                        'children' => [],
                    ];
                    $outboundLines[$parentKey]['qty'] += (int) $line->quantity;

                    foreach ($sku->bundleComponents as $component) {
                        if (
                            $component->tenant_id !== $order->tenant_id
                            || ! $component->componentStockItem
                            || $component->componentStockItem->tenant_id !== $order->tenant_id
                        ) {
                            throw ValidationException::withMessages([
                                'selectedOrderIds' => __('outbound.bundle_invalid_tenant'),
                            ]);
                        }

                        $componentQty = (int) $line->quantity * (int) $component->quantity;
                        $stockItemId = (int) $component->component_stock_item_id;

                        $outboundLines[$parentKey]['children'][$stockItemId] ??= [
                            'sku_id' => $sku->id,
                            'stock_item_id' => $stockItemId,
                            'qty' => 0,
                        ];
                        $outboundLines[$parentKey]['children'][$stockItemId]['qty'] += $componentQty;
                        $byStockItem[$stockItemId] = ($byStockItem[$stockItemId] ?? 0) + $componentQty;
                    }

                    continue;
                }

                if (! $sku->stock_item_id) {
                    throw ValidationException::withMessages([
                        'selectedOrderIds' => __('skus.missing_stock_item'),
                    ]);
                }

                $key = $sku->id.':'.$sku->stock_item_id;
                $outboundLines[$key] ??= [
                    'sku_id' => $sku->id,
                    'stock_item_id' => $sku->stock_item_id,
                    'qty' => 0,
                    'children' => [],
                ];
                $outboundLines[$key]['qty'] += (int) $line->quantity;
                $byStockItem[$sku->stock_item_id] = ($byStockItem[$sku->stock_item_id] ?? 0) + (int) $line->quantity;
            }
        }

        return [$outboundLines, $byStockItem];
    }

    private function createOutboundLines(FulfillmentGroup $group, OutboundOrder $outbound, array $bySkuAndItem): void
    {
        foreach ($bySkuAndItem as $line) {
            $outboundLine = $outbound->lines()->create([
                'tenant_id' => (int) $group->tenant_id,
                'sku_id' => $line['sku_id'],
                'stock_item_id' => $line['stock_item_id'],
                'qty' => $line['qty'],
                'inventory_movement_id' => null,
            ]);

            foreach ($line['children'] as $childLine) {
                $outbound->lines()->create([
                    'parent_line_id' => $outboundLine->id,
                    'tenant_id' => (int) $group->tenant_id,
                    'sku_id' => $childLine['sku_id'],
                    'stock_item_id' => $childLine['stock_item_id'],
                    'qty' => $childLine['qty'],
                    'inventory_movement_id' => null,
                ]);
            }
        }
    }

    private function resolveGroupShippingMethodId(Collection $orders): ?int
    {
        $methodIds = $orders->pluck('shipping_method_id');

        if ($methodIds->contains(fn ($id) => $id === null)) {
            return null;
        }

        $candidates = ShippingMethod::query()
            ->whereIn('id', $methodIds->unique()->all())
            ->where('status', 'active')
            ->orderBy('selection_priority')
            ->get(['id', 'selection_priority']);

        if ($candidates->isEmpty()) {
            return null;
        }

        $top = $candidates->where('selection_priority', $candidates->min('selection_priority'));

        return $top->count() === 1 ? (int) $top->first()->id : null;
    }
}
