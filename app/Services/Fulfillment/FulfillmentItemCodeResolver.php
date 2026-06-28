<?php

namespace App\Services\Fulfillment;

use App\Models\Sku;
use App\Models\StockItem;
use App\Models\Tenant;

class FulfillmentItemCodeResolver
{
    public function resolve(Tenant $tenant, Sku $sku, ?StockItem $stockItem): string
    {
        $code = match ($tenant->fulfillment_item_code_source) {
            Tenant::FULFILLMENT_ITEM_CODE_SOURCE_TENANT_ITEM_CODE => $stockItem?->tenant_item_code,
            Tenant::FULFILLMENT_ITEM_CODE_SOURCE_STOCK_ITEM_CODE => $stockItem?->code,
            default => $sku->sku,
        };

        return trim((string) $code) !== '' ? (string) $code : $sku->sku;
    }
}
