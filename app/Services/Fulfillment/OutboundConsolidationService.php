<?php

namespace App\Services\Fulfillment;

use App\Models\InventoryBalance;
use App\Models\OutboundOrder;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\ShippingMethod;
use App\Models\Shop;
use App\Models\Sku;
use App\Models\StockItem;
use App\Services\InventoryService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class OutboundConsolidationService
{
    public function __construct(private readonly InventoryService $inventoryService) {}

    public function createGroup(int $tenantId, int $warehouseId, array $salesOrderIds): OutboundOrder
    {
        return DB::transaction(function () use ($tenantId, $warehouseId, $salesOrderIds) {
            $orders = $this->lockReadyOrders($tenantId, $salesOrderIds);
            $this->validateReadyOrders($orders, $tenantId, $salesOrderIds);
            $this->validateSharedShipKey($orders);
            $this->validateConsolidation($orders);

            return $this->createOutboundFromOrders($tenantId, $warehouseId, $orders);
        });
    }

    public function singleOrderGroup(int $tenantId, int $warehouseId, int $salesOrderId): OutboundOrder
    {
        return $this->createGroup($tenantId, $warehouseId, [$salesOrderId]);
    }

    public function joinGroup(OutboundOrder $outbound, array $salesOrderIds): OutboundOrder
    {
        return DB::transaction(function () use ($outbound, $salesOrderIds) {
            $outbound = OutboundOrder::query()
                ->with(['salesOrders.shop', 'tenant:id,code'])
                ->lockForUpdate()
                ->findOrFail($outbound->id);

            $this->validateJoinableOutbound($outbound);

            $orders = $this->lockReadyOrders((int) $outbound->tenant_id, $salesOrderIds);
            $this->validateReadyOrders($orders, (int) $outbound->tenant_id, $salesOrderIds);

            $shipKey = $outbound->salesOrders->first()?->ship_together_key;

            if ($orders->pluck('ship_together_key')->unique()->count() !== 1 || $orders->first()?->ship_together_key !== $shipKey) {
                throw ValidationException::withMessages([
                    'selectedOrderIds' => __('fulfillment.orders_must_share_key'),
                ]);
            }

            $this->validateConsolidation($outbound->salesOrders->concat($orders));
            $this->appendOrdersToOutbound($outbound, $orders);

            return $outbound->refresh();
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

    public function joinableGroupFor(SalesOrder $order, int $warehouseId): ?OutboundOrder
    {
        return OutboundOrder::query()
            ->where('tenant_id', $order->tenant_id)
            ->where('warehouse_id', $warehouseId)
            ->where('reason', OutboundOrder::REASON_CUSTOMER_ORDER)
            ->where('status', OutboundOrder::STATUS_RESERVED)
            ->where('hold_status', OutboundOrder::HOLD_STATUS_ACTIVE)
            ->whereNull('courier_csv_exported_at')
            ->whereHas('salesOrders', fn ($query) => $query->where('ship_together_key', $order->ship_together_key))
            ->whereDoesntHave('salesOrders', fn ($query) => $query->where('fulfillment_status', SalesOrder::FULFILLMENT_STATUS_SHIPPED))
            ->with('salesOrders.shop')
            ->orderBy('id')
            ->first();
    }

    private function createOutboundFromOrders(int $tenantId, int $warehouseId, Collection $orders): OutboundOrder
    {
        $firstOrder = $orders->firstOrFail();
        $defaultShippingMethodId = $this->resolveGroupShippingMethodId($orders);

        $outbound = OutboundOrder::create([
            'reason' => OutboundOrder::REASON_CUSTOMER_ORDER,
            'shipping_method_id' => $defaultShippingMethodId,
            'tenant_id' => $tenantId,
            'warehouse_id' => $warehouseId,
            'ref' => 'OB-PENDING-'.Str::uuid(),
            'status' => OutboundOrder::STATUS_RESERVED,
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
        $outbound->update(['ref' => OutboundOrder::buildRef($outbound->id, $firstOrder->tenant->code)]);

        $this->attachAndReserve($outbound, $orders);

        return $outbound;
    }

    private function appendOrdersToOutbound(OutboundOrder $outbound, Collection $orders): void
    {
        if ($outbound->status !== OutboundOrder::STATUS_RESERVED) {
            throw new InvalidArgumentException(__('fulfillment.group_not_joinable'));
        }

        $this->attachAndReserve($outbound, $orders);
    }

    private function attachAndReserve(OutboundOrder $outbound, Collection $orders): void
    {
        $now = now();
        $attachPayload = $orders
            ->pluck('id')
            ->mapWithKeys(fn ($id) => [(int) $id => ['arranged_at' => $now]])
            ->all();

        $outbound->salesOrders()->attach($attachPayload);

        [$bySkuAndItem, $byStockItem] = $this->aggregateLines($orders);
        $this->assertEnoughAvailableStock($outbound, $bySkuAndItem, $byStockItem);

        foreach ($byStockItem as $stockItemId => $totalQty) {
            $this->inventoryService->reserveStock(
                tenantId: (int) $outbound->tenant_id,
                warehouseId: (int) $outbound->warehouse_id,
                stockItemId: (int) $stockItemId,
                quantity: (int) $totalQty,
                context: [
                    'ref_type' => 'outbound_order',
                    'ref_id' => (string) $outbound->id,
                    'user_id' => Auth::id(),
                ],
            );
        }

        $this->createOutboundLines($outbound, $bySkuAndItem);

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
                'outboundOrders:id,status',
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
                'selectedOrderIds' => __('fulfillment.orders_required'),
            ]);
        }

        foreach ($orders as $order) {
            if ((int) $order->tenant_id !== $tenantId) {
                throw ValidationException::withMessages([
                    'selectedOrderIds' => __('fulfillment.orders_required'),
                ]);
            }

            if (
                $order->order_status !== SalesOrder::ORDER_STATUS_PENDING
                || $order->fulfillment_status !== SalesOrder::FULFILLMENT_STATUS_READY
            ) {
                throw ValidationException::withMessages([
                    'selectedOrderIds' => __('fulfillment.order_no_longer_ready', ['id' => $order->id]),
                ]);
            }

            if ($this->hasActiveOutbound($order)) {
                throw ValidationException::withMessages([
                    'selectedOrderIds' => __('fulfillment.order_already_grouped', ['id' => $order->id]),
                ]);
            }

            if (! $this->orderIsShipComplete($order)) {
                throw ValidationException::withMessages([
                    'selectedOrderIds' => __('fulfillment.order_not_ship_complete', ['id' => $order->id]),
                ]);
            }
        }
    }

    private function validateSharedShipKey(Collection $orders): void
    {
        $keys = $orders->pluck('ship_together_key')->unique();

        if ($keys->count() !== 1 || $keys->first() === null) {
            throw ValidationException::withMessages([
                'selectedOrderIds' => __('fulfillment.orders_must_share_key'),
            ]);
        }
    }

    private function validateJoinableOutbound(OutboundOrder $outbound): void
    {
        if ($outbound->reason !== OutboundOrder::REASON_CUSTOMER_ORDER || $outbound->status !== OutboundOrder::STATUS_RESERVED) {
            throw new InvalidArgumentException(__('fulfillment.group_not_joinable'));
        }

        if ($outbound->courier_csv_exported_at !== null) {
            throw new InvalidArgumentException(__('fulfillment.group_not_joinable'));
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
            throw new InvalidArgumentException(__('fulfillment.consolidation_not_allowed'));
        }

        if ($shopIds->count() === 1) {
            if ($modes->every(fn ($mode) => in_array($mode, [Shop::CONSOLIDATION_SAME_SHOP, Shop::CONSOLIDATION_CROSS_SHOP], true))) {
                return;
            }

            throw new InvalidArgumentException(__('fulfillment.consolidation_not_allowed'));
        }

        if ($modes->every(fn ($mode) => $mode === Shop::CONSOLIDATION_CROSS_SHOP)) {
            return;
        }

        throw new InvalidArgumentException(__('fulfillment.consolidation_not_allowed'));
    }

    private function hasActiveOutbound(SalesOrder $order): bool
    {
        return $order->outboundOrders
            ->contains(fn ($outbound) => $outbound->status !== OutboundOrder::STATUS_CANCELLED);
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

    /**
     * @param  array<string,array{sku_id:int,stock_item_id:?int,qty:int,children:array<int,array{sku_id:int,stock_item_id:int,qty:int}>}>  $bySkuAndItem
     * @param  array<int,int>  $byStockItem
     */
    private function assertEnoughAvailableStock(OutboundOrder $outbound, array $bySkuAndItem, array $byStockItem): void
    {
        if ($byStockItem === []) {
            return;
        }

        $availableByStockItem = InventoryBalance::query()
            ->where('tenant_id', $outbound->tenant_id)
            ->where('warehouse_id', $outbound->warehouse_id)
            ->whereIn('stock_item_id', array_keys($byStockItem))
            ->pluck('available_qty', 'stock_item_id');

        $shortStockItemIds = [];

        foreach ($byStockItem as $stockItemId => $requiredQty) {
            $availableQty = (int) ($availableByStockItem[$stockItemId] ?? 0);

            if ($requiredQty > $availableQty) {
                $shortStockItemIds[] = (int) $stockItemId;
            }
        }

        if ($shortStockItemIds === []) {
            return;
        }

        $shortStockItemIds = array_flip($shortStockItemIds);
        $skuIds = [];

        foreach ($bySkuAndItem as $line) {
            $stockItemId = $line['stock_item_id'];

            if ($stockItemId !== null && isset($shortStockItemIds[(int) $stockItemId])) {
                $skuIds[] = (int) $line['sku_id'];
            }

            foreach ($line['children'] as $childLine) {
                if (isset($shortStockItemIds[(int) $childLine['stock_item_id']])) {
                    $skuIds[] = (int) $childLine['sku_id'];
                }
            }
        }

        $skuIds = array_values(array_unique($skuIds));

        if ($skuIds === []) {
            throw new InvalidArgumentException(__('fulfillment.not_enough_available_stock'));
        }

        $skus = Sku::query()
            ->with(['stockItem:id,short_name,'.implode(',', StockItem::DISPLAY_NAME_COLUMNS)])
            ->whereIn('id', $skuIds)
            ->orderBy('sku')
            ->with('stockItem:id,name,short_name,name_en,name_ja,name_zh_tw,name_zh_cn')
            ->get(['id', 'stock_item_id', 'sku']);

        $skuRows = $skus
            ->map(function (Sku $sku): string {
                $name = trim($sku->displayName());

                return $name === '' ? $sku->sku : $sku->sku.' - '.$name;
            })
            ->all();

        throw new InvalidArgumentException(__('fulfillment.not_enough_available_stock')."\n".implode("\n", $skuRows));
    }

    private function createOutboundLines(OutboundOrder $outbound, array $bySkuAndItem): void
    {
        foreach ($bySkuAndItem as $line) {
            $outboundLine = $outbound->lines()->create([
                'tenant_id' => (int) $outbound->tenant_id,
                'sku_id' => $line['sku_id'],
                'stock_item_id' => $line['stock_item_id'],
                'qty' => $line['qty'],
                'inventory_movement_id' => null,
            ]);

            foreach ($line['children'] as $childLine) {
                $outbound->lines()->create([
                    'parent_line_id' => $outboundLine->id,
                    'tenant_id' => (int) $outbound->tenant_id,
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
