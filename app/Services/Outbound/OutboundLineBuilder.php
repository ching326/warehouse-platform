<?php

namespace App\Services\Outbound;

use App\Models\OutboundOrder;
use App\Models\Sku;
use App\Models\SkuBundleComponent;
use App\Models\StockItem;
use App\Services\InventoryService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class OutboundLineBuilder
{
    public function __construct(private readonly InventoryService $inventoryService) {}

    public function addLine(
        OutboundOrder $order,
        int $tenantId,
        int $warehouseId,
        Sku $sku,
        int $qty,
        ?string $note,
        bool $reserve,
        string $errorKey = 'lines',
    ): void {
        $sku->loadMissing('bundleComponents');

        if ($sku->sku_type === 'virtual_bundle') {
            $this->createVirtualBundleLines($order, $tenantId, $warehouseId, $sku, $qty, $note, $reserve, $errorKey);

            return;
        }

        if ($sku->stock_item_id === null) {
            throw ValidationException::withMessages([
                $errorKey.'.sku_id' => __('outbound.sku_not_shippable'),
            ]);
        }

        if ($reserve) {
            $this->reserveLine($tenantId, $warehouseId, (int) $sku->stock_item_id, $qty, (int) $order->id, $errorKey);
        }

        $order->lines()->create([
            'tenant_id' => $tenantId,
            'sku_id' => $sku->id,
            'stock_item_id' => $sku->stock_item_id,
            'qty' => $qty,
            'note' => $note,
        ]);
    }

    private function createVirtualBundleLines(
        OutboundOrder $order,
        int $tenantId,
        int $warehouseId,
        Sku $sku,
        int $qty,
        ?string $note,
        bool $reserve,
        string $errorKey,
    ): void {
        $components = SkuBundleComponent::query()
            ->where('bundle_sku_id', $sku->id)
            ->get();

        if ($components->isEmpty()) {
            throw ValidationException::withMessages([
                $errorKey.'.sku_id' => __('outbound.bundle_no_components'),
            ]);
        }

        foreach ($components as $component) {
            if ($component->tenant_id !== $tenantId) {
                throw ValidationException::withMessages([
                    $errorKey.'.sku_id' => __('outbound.bundle_invalid_tenant'),
                ]);
            }
        }

        $componentStockItemIds = $components->pluck('component_stock_item_id')->all();
        $invalidCount = StockItem::query()
            ->whereIn('id', $componentStockItemIds)
            ->where('tenant_id', '!=', $tenantId)
            ->count();

        if ($invalidCount > 0) {
            throw ValidationException::withMessages([
                $errorKey.'.sku_id' => __('outbound.bundle_invalid_tenant'),
            ]);
        }

        $parentLine = $order->lines()->create([
            'tenant_id' => $tenantId,
            'sku_id' => $sku->id,
            'stock_item_id' => null,
            'qty' => $qty,
            'note' => $note,
        ]);

        foreach ($components as $component) {
            $componentQty = $qty * $component->quantity;

            if ($reserve) {
                $this->reserveLine($tenantId, $warehouseId, (int) $component->component_stock_item_id, $componentQty, (int) $order->id, $errorKey);
            }

            $order->lines()->create([
                'parent_line_id' => $parentLine->getKey(),
                'tenant_id' => $tenantId,
                'sku_id' => $sku->id,
                'stock_item_id' => $component->component_stock_item_id,
                'qty' => $componentQty,
            ]);
        }
    }

    private function reserveLine(int $tenantId, int $warehouseId, int $stockItemId, int $quantity, int $orderId, string $errorKey): void
    {
        try {
            $this->inventoryService->reserveStock(
                tenantId: $tenantId,
                warehouseId: $warehouseId,
                stockItemId: $stockItemId,
                quantity: $quantity,
                context: [
                    'ref_type' => 'outbound_order',
                    'ref_id' => (string) $orderId,
                    'user_id' => Auth::id(),
                ],
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                $errorKey.'.qty' => $exception->getMessage(),
            ]);
        }
    }
}
