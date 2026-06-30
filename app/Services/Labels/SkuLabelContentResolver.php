<?php

namespace App\Services\Labels;

use App\Models\Sku;
use App\Models\StockItem;
use Illuminate\Support\Str;

class SkuLabelContentResolver
{
    /**
     * @return array<string, string>
     */
    public function options(Sku $sku): array
    {
        $stockItem = $this->stockItem($sku);

        $options = [
            'sku' => __('skus.label_content_sku'),
        ];

        if (filled($sku->platform_label_code)) {
            $options['fnsku'] = __('skus.label_content_fnsku');
        }

        foreach ($stockItem?->primaryBarcodeTypes() ?? [] as $type) {
            $options['barcode:'.$type] = __('skus.label_content_barcode_type', [
                'type' => __('common.barcode_types.'.$type),
            ]);
        }

        return $options;
    }

    public function resolveValue(Sku $sku, string $content): ?string
    {
        if ($content === 'sku') {
            return trim((string) $sku->sku);
        }

        if ($content === 'fnsku') {
            $value = trim((string) $sku->platform_label_code);

            return $value !== '' ? $value : null;
        }

        if (! Str::startsWith($content, 'barcode:')) {
            return null;
        }

        $type = Str::after($content, 'barcode:');
        $alias = $this->stockItem($sku)?->primaryBarcodeAliasOfType($type);
        $value = trim((string) ($alias ? $alias->barcode : ''));

        return $value !== '' ? $value : null;
    }

    private function stockItem(Sku $sku): ?StockItem
    {
        if (! $sku->stock_item_id) {
            return null;
        }

        return StockItem::query()
            ->where('tenant_id', $sku->tenant_id)
            ->find((int) $sku->stock_item_id);
    }
}
