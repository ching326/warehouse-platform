<?php

namespace App\Services\Legacy;

use App\Models\InventoryMovement;
use App\Models\LegacyStockDeduction;
use App\Models\OutboundOrder;
use App\Models\OutboundOrderLine;
use App\Models\Sku;
use App\Models\StockItem;
use App\Models\Tenant;

class LegacyStockDeductionQueueService
{
    public function queueForShippedLine(
        OutboundOrder $order,
        OutboundOrderLine $line,
        InventoryMovement $movement,
    ): void {
        if ($line->stock_item_id === null) {
            return;
        }

        $order->loadMissing(['tenant:id,code,fulfillment_item_code_source', 'warehouse:id,code']);
        $line->loadMissing(['sku:id,sku', 'stockItem:id,tenant_item_code,code']);

        $tenant = $order->tenant;
        $sku = $line->sku;
        $stockItem = $line->stockItem;

        if (! $tenant instanceof Tenant || ! $sku instanceof Sku || ! $stockItem instanceof StockItem) {
            return;
        }

        $itemCodeSource = $this->itemCodeSource($tenant);
        $legacyItemCode = $this->legacyItemCode($itemCodeSource, $sku, $stockItem);
        $warehouseCode = $order->warehouse()->value('code');
        $now = now();
        $missingCode = $legacyItemCode === null;

        $attributes = [
            'tenant_id' => $order->tenant_id,
            'warehouse_id' => $order->warehouse_id,
            'stock_item_id' => $line->stock_item_id,
            'sku_id' => $line->sku_id,
            'outbound_order_id' => $order->id,
            'outbound_order_line_id' => $line->id,
            'inventory_movement_id' => $movement->id,
            'tenant_code' => $tenant->code,
            'warehouse_code' => is_string($warehouseCode) ? $warehouseCode : null,
            'stock_item_code' => $stockItem->code,
            'tenant_item_code' => $stockItem->tenant_item_code,
            'sku_code' => $sku->sku,
            'legacy_item_code' => $legacyItemCode,
            'item_code_source' => $itemCodeSource,
            'quantity' => $line->qty,
            'source_ref' => $order->ref,
            'status' => $missingCode ? LegacyStockDeduction::STATUS_FAILED : LegacyStockDeduction::STATUS_PENDING,
            'failed_at' => $missingCode ? $now : null,
            'error_message' => $missingCode ? 'Missing legacy item code for configured source.' : null,
        ];

        $existing = LegacyStockDeduction::query()
            ->where('idempotency_key', $this->idempotencyKey($line))
            ->first();

        if ($existing instanceof LegacyStockDeduction && $existing->status === LegacyStockDeduction::STATUS_APPLIED) {
            return;
        }

        LegacyStockDeduction::query()->updateOrCreate(
            ['idempotency_key' => $this->idempotencyKey($line)],
            $attributes,
        );
    }

    private function itemCodeSource(Tenant $tenant): string
    {
        return in_array($tenant->fulfillment_item_code_source, Tenant::FULFILLMENT_ITEM_CODE_SOURCES, true)
            ? $tenant->fulfillment_item_code_source
            : Tenant::FULFILLMENT_ITEM_CODE_SOURCE_SKU;
    }

    private function legacyItemCode(string $source, Sku $sku, StockItem $stockItem): ?string
    {
        $code = match ($source) {
            Tenant::FULFILLMENT_ITEM_CODE_SOURCE_TENANT_ITEM_CODE => $stockItem->tenant_item_code,
            Tenant::FULFILLMENT_ITEM_CODE_SOURCE_STOCK_ITEM_CODE => $stockItem->code,
            default => $sku->sku,
        };

        $code = trim((string) $code);

        return $code === '' ? null : $code;
    }

    private function idempotencyKey(OutboundOrderLine $line): string
    {
        return 'outbound_order_line:'.$line->id;
    }
}
