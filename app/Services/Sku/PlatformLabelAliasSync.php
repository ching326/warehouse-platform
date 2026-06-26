<?php

namespace App\Services\Sku;

use App\Exceptions\AliasCollisionException;
use App\Models\BarcodeAlias;
use App\Models\Sku;

class PlatformLabelAliasSync
{
    public function sync(Sku $sku): void
    {
        $raw = trim((string) $sku->platform_label_code);
        $managed = BarcodeAlias::query()
            ->where('tenant_id', $sku->tenant_id)
            ->where('model_type', BarcodeAlias::MODEL_TYPE_SKU)
            ->where('model_id', $sku->id)
            ->where('source', BarcodeAlias::SOURCE_PLATFORM_LABEL_CODE)
            ->first();

        if ($raw === '') {
            $managed?->delete();

            return;
        }

        $normalized = BarcodeAlias::normalize($raw);
        $conflict = BarcodeAlias::query()
            ->where('tenant_id', $sku->tenant_id)
            ->where('normalized_barcode', $normalized)
            ->when($managed, fn ($query) => $query->whereKeyNot($managed->id))
            ->first();

        if ($conflict) {
            if ($conflict->model_type === BarcodeAlias::MODEL_TYPE_SKU && (int) $conflict->model_id === (int) $sku->id) {
                $managed?->delete();

                return;
            }

            throw new AliasCollisionException(__('skus.fnsku_alias_conflict'));
        }

        $payload = [
            'tenant_id' => $sku->tenant_id,
            'model_type' => BarcodeAlias::MODEL_TYPE_SKU,
            'model_id' => $sku->id,
            'barcode' => $raw,
            'normalized_barcode' => $normalized,
            'barcode_type' => 'platform_label',
            'label' => null,
            'is_active' => true,
            'source' => BarcodeAlias::SOURCE_PLATFORM_LABEL_CODE,
        ];

        if ($managed) {
            $managed->update($payload);

            return;
        }

        BarcodeAlias::create($payload);
    }
}
