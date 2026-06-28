<?php

namespace App\Services\Fulfillment;

use App\Models\BarcodeAlias;
use App\Models\FulfillmentPackScan;
use App\Models\OutboundOrder;
use App\Models\Sku;
use App\Support\TrackingNumber;
use Illuminate\Support\Collection;

class FulfillmentPackService
{
    public function normalizeProductBarcode(string $value): string
    {
        return BarcodeAlias::normalize($value);
    }

    /**
     * @param  list<int>  $allowedTenantIds
     */
    public function findOrderForTrackingNo(
        ?string $trackingNo,
        array $allowedTenantIds,
        int $warehouseId,
        int $shippingMethodId,
    ): PackLookupResult {
        $trackingNo = TrackingNumber::normalize($trackingNo);

        if ($trackingNo === null || $allowedTenantIds === [] || $warehouseId <= 0 || $shippingMethodId <= 0) {
            return PackLookupResult::notFound();
        }

        $candidates = OutboundOrder::query()
            ->where('reason', OutboundOrder::REASON_CUSTOMER_ORDER)
            ->whereIn('tenant_id', $allowedTenantIds)
            ->where('status', OutboundOrder::STATUS_PENDING)
            ->where('warehouse_id', $warehouseId)
            ->where('shipping_method_id', $shippingMethodId)
            ->where(function ($query) use ($trackingNo): void {
                $query
                    ->where('outbound_orders.tracking_no', $trackingNo)
                    ->orWhere('outbound_orders.ref', $trackingNo)
                    ->orWhereHas('salesOrders', fn ($q) => $q->where('sales_orders.tracking_no', $trackingNo));
            })
            ->get();

        return $this->resultFromCandidates($candidates);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function packLines(OutboundOrder $order): array
    {
        $order->loadMissing([
            'leafLines.sku.barcodeAliases:id,tenant_id,model_type,model_id,normalized_barcode,is_active',
            'leafLines.stockItem.barcodeAliases:id,tenant_id,model_type,model_id,normalized_barcode,is_active',
            'leafLines.parentLine.sku.barcodeAliases:id,tenant_id,model_type,model_id,normalized_barcode,is_active',
        ]);

        $lines = [];

        foreach ($order->leafLines as $outboundLine) {
            $stockItem = $outboundLine->stockItem;

            if ($outboundLine->parent_line_id !== null) {
                $sku = $outboundLine->parentLine?->sku ?? $outboundLine->sku;
                $key = 'component:'.$outboundLine->stock_item_id;

                $lines[$key] ??= [
                    'key' => $key,
                    'sku' => $sku,
                    'sku_id' => null,
                    'stock_item' => $stockItem,
                    'stock_item_id' => $outboundLine->stock_item_id,
                    'required_qty' => 0,
                ];
                $lines[$key]['required_qty'] += (int) $outboundLine->qty;

                continue;
            }

            $sku = $outboundLine->sku;
            $key = 'sku:'.$outboundLine->sku_id.':stock:'.$outboundLine->stock_item_id;

            $lines[$key] ??= [
                'key' => $key,
                'sku' => $sku,
                'sku_id' => $outboundLine->sku_id,
                'stock_item' => $stockItem,
                'stock_item_id' => $outboundLine->stock_item_id,
                'required_qty' => 0,
            ];
            $lines[$key]['required_qty'] += (int) $outboundLine->qty;
        }

        return array_values($lines);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function packLinesWithProgress(OutboundOrder $order): array
    {
        $lines = $this->packLines($order);
        $acceptedQuantities = $this->acceptedScanQuantitiesByLine($order);

        foreach ($lines as &$line) {
            $line['strict_only'] = $this->lineIsStrictOnly($line);
            $line['scanned_qty'] = $acceptedQuantities[$this->scanQuantityKey($line['sku_id'], $line['stock_item_id'])] ?? 0;
            $line['remaining_qty'] = max(0, $line['required_qty'] - $line['scanned_qty']);
            $line['status'] = match (true) {
                $line['scanned_qty'] <= 0 => 'not_started',
                $line['remaining_qty'] <= 0 => 'complete',
                default => 'in_progress',
            };
        }

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $line
     */
    public function acceptedScanCount(OutboundOrder $order, array $line): int
    {
        return $this->acceptedScanQuantity($order, $line);
    }

    /**
     * @param  array<string, mixed>  $line
     */
    public function acceptedScanQuantity(OutboundOrder $order, array $line): int
    {
        return $this->acceptedScanQuantitiesByLine($order)[$this->scanQuantityKey($line['sku_id'], $line['stock_item_id'])] ?? 0;
    }

    /**
     * @param  array<string, mixed>  $line
     */
    public function lineIsStrictOnly(array $line): bool
    {
        $stockItem = $line['stock_item'] ?? null;

        if (! $stockItem) {
            return false;
        }

        return (bool) $stockItem->is_dangerous_goods
            || (bool) $stockItem->requires_expiry_tracking
            || (bool) $stockItem->requires_lot_tracking
            || in_array((string) $stockItem->product_type, ['food', 'is_battery', 'with_battery'], true);
    }

    public function allLinesComplete(OutboundOrder $order): bool
    {
        $lines = $this->packLinesWithProgress($order);

        return $lines !== [] && collect($lines)->every(fn (array $line): bool => $line['remaining_qty'] <= 0);
    }

    /**
     * @param  array<string, mixed>  $line
     */
    public function lineMatchesScan(array $line, string $normalizedScan): bool
    {
        /** @var Sku|null $sku */
        $sku = $line['sku'];
        $stockItem = $line['stock_item'];

        $candidates = [
            $sku?->barcode,
            $stockItem?->barcode,
            $sku?->sku,
        ];

        foreach ($candidates as $candidate) {
            if ($candidate !== null && $this->normalizeProductBarcode((string) $candidate) === $normalizedScan) {
                return true;
            }
        }

        foreach ($this->activeAliasBarcodes($sku?->barcodeAliases) as $alias) {
            if ($alias === $normalizedScan) {
                return true;
            }
        }

        foreach ($this->activeAliasBarcodes($stockItem?->barcodeAliases) as $alias) {
            if ($alias === $normalizedScan) {
                return true;
            }
        }

        return false;
    }

    private function activeAliasBarcodes(?Collection $aliases): array
    {
        if (! $aliases) {
            return [];
        }

        return $aliases
            ->filter(fn (BarcodeAlias $alias): bool => $alias->is_active)
            ->pluck('normalized_barcode')
            ->map(fn (string $barcode): string => $this->normalizeProductBarcode($barcode))
            ->all();
    }

    /**
     * @return array<string, int>
     */
    private function acceptedScanQuantitiesByLine(OutboundOrder $order): array
    {
        return FulfillmentPackScan::query()
            ->where('outbound_order_id', $order->id)
            ->where('result', FulfillmentPackScan::RESULT_ACCEPTED)
            ->selectRaw('sku_id, stock_item_id, SUM(quantity) as scanned_qty')
            ->groupBy('sku_id', 'stock_item_id')
            ->get()
            ->mapWithKeys(fn (FulfillmentPackScan $row): array => [
                $this->scanQuantityKey($row->sku_id, $row->stock_item_id) => (int) $row->scanned_qty,
            ])
            ->all();
    }

    private function scanQuantityKey(?int $skuId, ?int $stockItemId): string
    {
        return 'sku:'.($skuId ?? 'null').':stock:'.($stockItemId ?? 'null');
    }

    /**
     * @param  Collection<int, OutboundOrder>  $candidates
     */
    private function resultFromCandidates(Collection $candidates): PackLookupResult
    {
        $orders = $candidates->unique('id')->values();

        if ($orders->isEmpty()) {
            return PackLookupResult::notFound();
        }

        if ($orders->count() > 1) {
            return PackLookupResult::multiple();
        }

        $order = $orders->first();

        if ($order->status === OutboundOrder::STATUS_SHIPPED) {
            return PackLookupResult::alreadyShipped($order);
        }

        if ($order->status === OutboundOrder::STATUS_CANCELLED) {
            return PackLookupResult::cancelled($order);
        }

        if ($order->hold_status === OutboundOrder::HOLD_STATUS_ON_HOLD) {
            return PackLookupResult::onHold($order);
        }

        return PackLookupResult::found($order);
    }
}
