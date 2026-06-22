<?php

namespace App\Services\Fulfillment;

use App\Models\FulfillmentGroup;
use App\Models\FulfillmentPackScan;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\Sku;
use Illuminate\Support\Collection;

class FulfillmentPackService
{
    public function normalizeTrackingNo(string $value): string
    {
        $value = strtoupper(trim($value));

        return preg_replace('/[\s\-\._\/\\\\:;|]+/', '', $value) ?? '';
    }

    public function normalizeProductBarcode(string $value): string
    {
        return trim($value);
    }

    /**
     * @param  list<int>  $allowedTenantIds
     */
    public function findGroupForScan(string $scan, array $allowedTenantIds): PackLookupResult
    {
        $scan = trim($scan);

        if ($scan === '' || $allowedTenantIds === []) {
            return PackLookupResult::notFound();
        }

        $exactCandidates = $this->baseGroupQuery($allowedTenantIds)
            ->where('reference_no', $scan)
            ->get();

        if ($exactCandidates->isEmpty()) {
            $exactCandidates = $this->baseGroupQuery($allowedTenantIds)
                ->whereHas('orders', fn ($query) => $query->where('platform_order_id', $scan))
                ->orWhere(fn ($query) => $query
                    ->whereIn('tenant_id', $allowedTenantIds)
                    ->whereHas('outboundOrder', fn ($outbound) => $outbound->where('ref', $scan)))
                ->get();
        }

        if ($exactCandidates->isNotEmpty()) {
            return $this->resultFromCandidates($exactCandidates);
        }

        $normalized = $this->normalizeTrackingNo($scan);
        $trackingCandidates = $this->baseGroupQuery($allowedTenantIds)
            ->with(['groupOrders.salesOrder', 'outboundOrder'])
            ->get()
            ->filter(function (FulfillmentGroup $group) use ($normalized): bool {
                if ($this->normalizeTrackingNo((string) $group->tracking_no) === $normalized) {
                    return true;
                }

                if ($this->normalizeTrackingNo((string) $group->outboundOrder?->tracking_no) === $normalized) {
                    return true;
                }

                foreach ($group->groupOrders as $groupOrder) {
                    if ($this->normalizeTrackingNo((string) $groupOrder->tracking_no) === $normalized) {
                        return true;
                    }

                    if ($this->normalizeTrackingNo((string) $groupOrder->salesOrder?->tracking_no) === $normalized) {
                        return true;
                    }
                }

                return false;
            })
            ->values();

        return $this->resultFromCandidates($trackingCandidates);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function packLines(FulfillmentGroup $group): array
    {
        $group->loadMissing([
            'orders.lines.sku.stockItem',
            'orders.lines.sku.bundleComponents.componentStockItem',
        ]);

        $lines = [];

        foreach ($group->orders as $order) {
            foreach ($order->lines as $orderLine) {
                if ($orderLine->line_status !== SalesOrderLine::STATUS_READY || ! $orderLine->sku) {
                    continue;
                }

                $sku = $orderLine->sku;

                if ($sku->sku_type === 'virtual_bundle') {
                    foreach ($sku->bundleComponents as $component) {
                        if (! $component->componentStockItem) {
                            continue;
                        }

                        $key = 'component:'.$component->component_stock_item_id;
                        $lines[$key] ??= [
                            'key' => $key,
                            'sku' => $sku,
                            'sku_id' => null,
                            'stock_item' => $component->componentStockItem,
                            'stock_item_id' => $component->component_stock_item_id,
                            'required_qty' => 0,
                        ];
                        $lines[$key]['required_qty'] += $orderLine->quantity * $component->quantity;
                    }

                    continue;
                }

                $stockItem = $sku->stockItem;
                $key = 'sku:'.$sku->id.':stock:'.($stockItem?->id ?? 'none');
                $lines[$key] ??= [
                    'key' => $key,
                    'sku' => $sku,
                    'sku_id' => $sku->id,
                    'stock_item' => $stockItem,
                    'stock_item_id' => $stockItem?->id,
                    'required_qty' => 0,
                ];
                $lines[$key]['required_qty'] += $orderLine->quantity;
            }
        }

        return array_values($lines);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function packLinesWithProgress(FulfillmentGroup $group): array
    {
        $lines = $this->packLines($group);

        foreach ($lines as &$line) {
            $line['scanned_qty'] = $this->acceptedScanCount($group, $line);
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
    public function acceptedScanCount(FulfillmentGroup $group, array $line): int
    {
        return FulfillmentPackScan::query()
            ->where('fulfillment_group_id', $group->id)
            ->where('result', FulfillmentPackScan::RESULT_ACCEPTED)
            ->when($line['sku_id'] !== null, fn ($query) => $query->where('sku_id', $line['sku_id']))
            ->when($line['sku_id'] === null, fn ($query) => $query->whereNull('sku_id'))
            ->when($line['stock_item_id'] !== null, fn ($query) => $query->where('stock_item_id', $line['stock_item_id']))
            ->when($line['stock_item_id'] === null, fn ($query) => $query->whereNull('stock_item_id'))
            ->count();
    }

    public function allLinesComplete(FulfillmentGroup $group): bool
    {
        $lines = $this->packLinesWithProgress($group);

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

        return false;
    }

    /**
     * @param  Collection<int, FulfillmentGroup>  $candidates
     */
    private function resultFromCandidates(Collection $candidates): PackLookupResult
    {
        $groups = $candidates->unique('id')->values();

        if ($groups->isEmpty()) {
            return PackLookupResult::notFound();
        }

        if ($groups->count() > 1) {
            return PackLookupResult::multiple();
        }

        $group = $groups->first();

        if ($group->status === FulfillmentGroup::STATUS_SHIPPED) {
            return PackLookupResult::alreadyShipped($group);
        }

        if ($group->status === FulfillmentGroup::STATUS_CANCELLED) {
            return PackLookupResult::cancelled($group);
        }

        return PackLookupResult::found($group);
    }

    /**
     * @param  list<int>  $allowedTenantIds
     */
    private function baseGroupQuery(array $allowedTenantIds)
    {
        return FulfillmentGroup::query()->whereIn('tenant_id', $allowedTenantIds);
    }
}
