<?php

namespace App\Services\SkuImport;

use App\Models\Sku;
use App\Models\StockItem;
use App\Models\Tenant;
use App\Services\Sku\PlatformLabelAliasSync;
use Illuminate\Support\Facades\DB;

class SkuWriter
{
    public function __construct(private readonly PlatformLabelAliasSync $platformLabelAliasSync) {}

    public function upsert(
        int $tenantId,
        ?int $shopId,
        array $skuData,
        array $stockItemData,
        bool $allowUpdate,
    ): SkuWriteResult {
        return DB::transaction(fn () => $this->upsertInsideTransaction(
            $tenantId,
            $shopId,
            $skuData,
            $stockItemData,
            $allowUpdate,
        ));
    }

    private function upsertInsideTransaction(
        int $tenantId,
        ?int $shopId,
        array $skuData,
        array $stockItemData,
        bool $allowUpdate,
    ): SkuWriteResult {
        $skuCode = trim($skuData['sku'] ?? '');

        $existing = Sku::query()
            ->where('tenant_id', $tenantId)
            ->when(
                $shopId !== null,
                fn ($q) => $q->where('shop_id', $shopId),
                fn ($q) => $q->whereNull('shop_id'),
            )
            ->where('sku', $skuCode)
            ->first();

        if ($existing !== null && ! $allowUpdate) {
            return new SkuWriteResult('skipped', $existing);
        }

        $stockItemId = $this->resolveStockItem(
            $tenantId,
            $stockItemData,
            $skuData,
            $existing?->stock_item_id,
            $allowUpdate,
        );

        $payload = $this->buildSkuPayload($skuData, $stockItemId);

        if ($existing !== null) {
            $existing->update($payload);
            $existing = $existing->refresh();
            $this->platformLabelAliasSync->sync($existing);

            return new SkuWriteResult('updated', $existing);
        }

        $sku = Sku::create([
            'tenant_id' => $tenantId,
            'shop_id' => $shopId,
            'sku_type' => 'single',
            ...$payload,
        ]);
        $this->platformLabelAliasSync->sync($sku);

        return new SkuWriteResult('created', $sku);
    }

    private function resolveStockItem(
        int $tenantId,
        array $stockItemData,
        array $skuData,
        ?int $existingStockItemId,
        bool $allowUpdate,
    ): int {
        $linkCode = $this->nullableString($stockItemData['stock_item_code'] ?? '');

        if ($linkCode !== null) {
            $linked = StockItem::query()
                ->where('tenant_id', $tenantId)
                ->where('code', $linkCode)
                ->lockForUpdate()
                ->first();

            if ($linked !== null) {
                if ($allowUpdate) {
                    $linked->update($this->buildStockItemUpdatePayload($stockItemData, $skuData));
                }

                return $linked->id;
            }
        }

        if ($existingStockItemId !== null) {
            if ($allowUpdate) {
                $existing = StockItem::lockForUpdate()->find($existingStockItemId);
                $existing?->update($this->buildStockItemUpdatePayload($stockItemData, $skuData));
            }

            return $existingStockItemId;
        }

        $code = $this->nextStockItemCode($tenantId);
        $stockItem = StockItem::create($this->buildStockItemCreatePayload($stockItemData, $skuData, $tenantId, $code));

        return $stockItem->id;
    }

    private function buildSkuPayload(array $skuData, ?int $stockItemId): array
    {
        return [
            'stock_item_id' => $stockItemId,
            'sku' => trim($skuData['sku'] ?? ''),
            'name' => trim($skuData['name'] ?? ''),
            'name_ja' => $this->nullableString($skuData['name_ja'] ?? ''),
            'name_zh_tw' => $this->nullableString($skuData['name_zh_tw'] ?? ''),
            'name_zh_cn' => $this->nullableString($skuData['name_zh_cn'] ?? ''),
            'platform_sku' => $this->nullableString($skuData['platform_sku'] ?? ''),
            'platform_product_id' => $this->nullableString($skuData['platform_product_id'] ?? ''),
            'platform_variant_id' => $this->nullableString($skuData['platform_variant_id'] ?? ''),
            'platform_variant_name' => $this->nullableString($skuData['platform_variant_name'] ?? ''),
            'platform_label_code' => $this->nullableString($skuData['platform_label_code'] ?? ''),
            'status' => ($skuData['status'] ?? '') ?: 'active',
            'note' => $this->nullableString($skuData['note'] ?? ''),
        ];
    }

    private function buildStockItemCreatePayload(array $data, array $skuData, int $tenantId, string $code): array
    {
        return array_merge(
            ['tenant_id' => $tenantId, 'code' => $code],
            $this->buildStockItemUpdatePayload($data, $skuData),
        );
    }

    private function buildStockItemUpdatePayload(array $data, array $skuData): array
    {
        $siNameJa = $this->nullableString($data['si_name_ja'] ?? '');
        $siNameZhTw = $this->nullableString($data['si_name_zh_tw'] ?? '');
        $siNameZhCn = $this->nullableString($data['si_name_zh_cn'] ?? '');

        return [
            'name' => trim($skuData['name'] ?? ''),
            'name_ja' => $siNameJa,
            'name_zh_tw' => $siNameZhTw,
            'name_zh_cn' => $siNameZhCn,
            'short_name' => $this->nullableString($data['short_name'] ?? ''),
            'brand' => $this->nullableString($data['brand'] ?? ''),
            'model_number' => $this->nullableString($data['model_number'] ?? ''),
            'variation_code' => $this->nullableString($data['variation_code'] ?? ''),
            'color' => $this->nullableString($data['color'] ?? ''),
            'size' => $this->nullableString($data['size'] ?? ''),
            'barcode' => $this->nullableString($data['barcode'] ?? ''),
            'barcode_type' => ($data['barcode_type'] ?? '') ?: 'unknown',
            'product_type' => ($data['product_type'] ?? '') ?: 'normal',
            'is_dangerous_goods' => $this->castBool($data['is_dangerous_goods'] ?? ''),
            'requires_expiry_tracking' => $this->castBool($data['requires_expiry_tracking'] ?? ''),
            'requires_lot_tracking' => $this->castBool($data['requires_lot_tracking'] ?? ''),
            'weight_value' => $this->nullableDecimal($data['weight_value'] ?? ''),
            'weight_unit' => ($data['weight_unit'] ?? '') ?: 'g',
            'length_value' => $this->nullableDecimal($data['length_value'] ?? ''),
            'width_value' => $this->nullableDecimal($data['width_value'] ?? ''),
            'height_value' => $this->nullableDecimal($data['height_value'] ?? ''),
            'dimension_unit' => ($data['dimension_unit'] ?? '') ?: 'cm',
            'description' => $this->nullableString($data['description'] ?? ''),
            'note' => $this->nullableString($data['si_note'] ?? ''),
            'handling_note' => $this->nullableString($data['handling_note'] ?? ''),
            'status' => ($data['si_status'] ?? '') ?: 'active',
        ];
    }

    private function nextStockItemCode(int $tenantId): string
    {
        $tenantCode = Tenant::query()->whereKey($tenantId)->value('code');
        $prefix = $tenantCode ?: 'TENANT';

        $lastCode = StockItem::query()
            ->where('tenant_id', $tenantId)
            ->where('code', 'like', $prefix.'-%')
            ->lockForUpdate()
            ->orderByDesc('code')
            ->value('code');

        $next = $lastCode && preg_match('/^'.preg_quote($prefix, '/').'-(\d+)$/', $lastCode, $matches)
            ? ((int) $matches[1]) + 1
            : 1;

        return $prefix.'-'.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }

    private function nullableString(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function nullableDecimal(string $value): ?string
    {
        return trim($value) === '' ? null : trim($value);
    }

    private function castBool(string $value): bool
    {
        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'y', 'on'], true);
    }
}
