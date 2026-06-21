<?php

namespace App\Services\SalesOrders;

use App\Models\ShippingMethod;
use App\Models\Sku;

class SkuDefaultShippingMethodResolver
{
    /**
     * @param array<int> $skuIds
     * @return array{status: 'winner'|'tie'|'none', shipping_method_id: ?int, shipping_method: ?string}
     */
    public function resolve(int $tenantId, array $skuIds): array
    {
        $skuIds = collect($skuIds)
            ->map(fn ($skuId) => (int) $skuId)
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($skuIds === []) {
            return $this->none();
        }

        $methodIds = Sku::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $skuIds)
            ->whereNotNull('default_shipping_method_id')
            ->pluck('default_shipping_method_id')
            ->unique()
            ->values()
            ->all();

        if ($methodIds === []) {
            return $this->none();
        }

        $methods = ShippingMethod::query()
            ->whereIn('id', $methodIds)
            ->where('status', 'active')
            ->with('carrier:id,code')
            ->get(['id', 'carrier_id', 'selection_priority']);

        if ($methods->isEmpty()) {
            return $this->none();
        }

        $highestPriority = $methods->max('selection_priority');
        $topMethods = $methods
            ->where('selection_priority', $highestPriority)
            ->unique('id')
            ->values();

        if ($topMethods->count() !== 1) {
            return [
                'status' => 'tie',
                'shipping_method_id' => null,
                'shipping_method' => null,
            ];
        }

        $method = $topMethods->first();

        return [
            'status' => 'winner',
            'shipping_method_id' => $method->id,
            'shipping_method' => $method->carrier?->code,
        ];
    }

    /**
     * @return array{status: 'none', shipping_method_id: null, shipping_method: null}
     */
    private function none(): array
    {
        return [
            'status' => 'none',
            'shipping_method_id' => null,
            'shipping_method' => null,
        ];
    }
}
