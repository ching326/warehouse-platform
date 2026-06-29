<?php

namespace App\Support;

use App\Models\Shop;
use App\Models\Sku;
use App\Models\StockItem;
use Illuminate\Support\Collection;

class SalesOrderSkuIdentifierMap
{
    public static function forShop(Shop $shop, string $status): Collection
    {
        $skus = Sku::query()
            ->where('tenant_id', $shop->tenant_id)
            ->where('shop_id', $shop->id)
            ->where('status', $status)
            ->where(fn ($query) => $query
                ->where('sku_type', 'virtual_bundle')
                ->orWhereNotNull('stock_item_id'))
            ->with('stockItem:id,tenant_item_code')
            ->get(['id', 'stock_item_id', 'sku']);

        $map = $skus->pluck('id', 'sku');

        $skus
            ->filter(fn (Sku $sku): bool => $sku->stockItem instanceof StockItem && filled($sku->stockItem->tenant_item_code))
            ->groupBy(function (Sku $sku): string {
                $stockItem = $sku->stockItem;

                return $stockItem instanceof StockItem ? (string) $stockItem->tenant_item_code : '';
            })
            ->each(function (Collection $group, string $tenantItemCode) use ($map): void {
                if ($group->pluck('id')->unique()->count() === 1 && ! $map->has($tenantItemCode)) {
                    $map->put($tenantItemCode, $group->first()->id);
                }
            });

        return $map;
    }
}
