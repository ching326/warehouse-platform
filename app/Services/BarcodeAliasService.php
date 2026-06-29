<?php

namespace App\Services;

use App\Exceptions\AliasCollisionException;
use App\Models\BarcodeAlias;
use App\Models\Sku;
use App\Models\StockItem;
use Illuminate\Support\Facades\DB;

class BarcodeAliasService
{
    public function createManualAlias(
        int $tenantId,
        string $modelType,
        int $modelId,
        string $barcode,
        string $barcodeType,
        ?string $label = null,
        bool $isActive = true,
        bool $isPrimary = false,
    ): BarcodeAlias {
        return DB::transaction(function () use ($tenantId, $modelType, $modelId, $barcode, $barcodeType, $label, $isActive, $isPrimary): BarcodeAlias {
            $normalized = BarcodeAlias::normalize($barcode);
            $this->guardNonEmpty($normalized);
            $this->guardKnownModel($tenantId, $modelType, $modelId);
            $this->guardNoConflict($tenantId, $normalized, $modelType, $modelId, sameModelMessage: __('skus.alias_duplicate_same_product'));

            return $this->storeAlias(
                tenantId: $tenantId,
                modelType: $modelType,
                modelId: $modelId,
                barcode: trim($barcode),
                normalized: $normalized,
                barcodeType: $barcodeType,
                source: BarcodeAlias::SOURCE_MANUAL,
                label: $label,
                isActive: $isActive,
                isPrimary: $isPrimary,
            );
        });
    }

    public function setPrimaryProductBarcode(StockItem $stockItem, ?string $barcode, string $barcodeType = 'other', ?string $source = null): ?BarcodeAlias
    {
        $barcode = trim((string) $barcode);

        if ($barcode === '') {
            $this->clearPrimaryProductBarcode($stockItem);

            return null;
        }

        return $this->upsertPrimaryAlias(
            tenantId: (int) $stockItem->tenant_id,
            modelType: BarcodeAlias::MODEL_TYPE_STOCK_ITEM,
            modelId: (int) $stockItem->id,
            barcode: $barcode,
            barcodeType: $barcodeType ?: 'other',
            source: $source ?? BarcodeAlias::SOURCE_MANUAL,
            label: null,
            conflictMessage: __('skus.alias_conflict_other_product'),
        );
    }

    public function clearPrimaryProductBarcode(StockItem $stockItem): void
    {
        BarcodeAlias::query()
            ->where('tenant_id', $stockItem->tenant_id)
            ->where('model_type', BarcodeAlias::MODEL_TYPE_STOCK_ITEM)
            ->where('model_id', $stockItem->id)
            ->where('is_primary', true)
            ->where('is_active', true)
            ->delete();
    }

    public function setSkuPlatformLabel(Sku $sku, ?string $barcode, ?string $source = null): void
    {
        $source ??= BarcodeAlias::SOURCE_PLATFORM_LABEL_CODE;
        $barcode = trim((string) $barcode);

        DB::transaction(function () use ($sku, $barcode, $source): void {
            if ($barcode === '') {
                BarcodeAlias::query()
                    ->where('tenant_id', $sku->tenant_id)
                    ->where('model_type', BarcodeAlias::MODEL_TYPE_SKU)
                    ->where('model_id', $sku->id)
                    ->where('barcode_type', 'platform_label')
                    ->where('is_active', true)
                    ->delete();

                $this->syncSkuPlatformLabelMirror($sku);

                return;
            }

            $alias = BarcodeAlias::query()
                ->where('tenant_id', $sku->tenant_id)
                ->where('model_type', BarcodeAlias::MODEL_TYPE_SKU)
                ->where('model_id', $sku->id)
                ->where('barcode_type', 'platform_label')
                ->where('source', $source)
                ->first();

            if ($alias) {
                $normalized = BarcodeAlias::normalize($barcode);
                $this->guardNonEmpty($normalized);

                $conflict = $this->conflictingAliasQuery((int) $sku->tenant_id, $normalized)
                    ->whereKeyNot($alias->id)
                    ->first();

                if ($conflict && ($conflict->model_type !== BarcodeAlias::MODEL_TYPE_SKU || (int) $conflict->model_id !== (int) $sku->id)) {
                    throw new AliasCollisionException(__('skus.fnsku_alias_conflict'));
                }

                if ($conflict) {
                    $alias->delete();
                    $alias = $conflict;
                } else {
                    $this->unsetOtherPrimaryAliases((int) $sku->tenant_id, BarcodeAlias::MODEL_TYPE_SKU, (int) $sku->id, 'platform_label', (int) $alias->id);
                    $alias->update([
                        'barcode' => $barcode,
                        'normalized_barcode' => $normalized,
                        'label' => null,
                        'is_primary' => true,
                        'is_active' => true,
                    ]);
                }
            } else {
                $alias = $this->upsertPrimaryAlias(
                    tenantId: (int) $sku->tenant_id,
                    modelType: BarcodeAlias::MODEL_TYPE_SKU,
                    modelId: (int) $sku->id,
                    barcode: $barcode,
                    barcodeType: 'platform_label',
                    source: $source,
                    label: null,
                    conflictMessage: __('skus.fnsku_alias_conflict'),
                );
            }

            BarcodeAlias::query()
                ->where('tenant_id', $sku->tenant_id)
                ->where('model_type', BarcodeAlias::MODEL_TYPE_SKU)
                ->where('model_id', $sku->id)
                ->where('barcode_type', 'platform_label')
                ->where('source', $source)
                ->whereKeyNot($alias->id)
                ->delete();

            $this->syncSkuPlatformLabelMirror($sku);
        });
    }

    public function syncSkuPlatformLabelMirror(Sku $sku): void
    {
        $alias = BarcodeAlias::query()
            ->where('tenant_id', $sku->tenant_id)
            ->where('model_type', BarcodeAlias::MODEL_TYPE_SKU)
            ->where('model_id', $sku->id)
            ->where('barcode_type', 'platform_label')
            ->where('is_active', true)
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->first();

        $sku->forceFill(['platform_label_code' => $alias?->barcode])->save();
    }

    private function upsertPrimaryAlias(
        int $tenantId,
        string $modelType,
        int $modelId,
        string $barcode,
        string $barcodeType,
        ?string $source,
        ?string $label,
        string $conflictMessage,
    ): BarcodeAlias {
        return DB::transaction(function () use ($tenantId, $modelType, $modelId, $barcode, $barcodeType, $source, $label, $conflictMessage): BarcodeAlias {
            $normalized = BarcodeAlias::normalize($barcode);
            $this->guardNonEmpty($normalized);
            $this->guardKnownModel($tenantId, $modelType, $modelId);

            $existing = $this->conflictingAliasQuery($tenantId, $normalized)->first();

            if ($existing && ($existing->model_type !== $modelType || (int) $existing->model_id !== $modelId)) {
                throw new AliasCollisionException($conflictMessage);
            }

            if ($existing) {
                $this->unsetOtherPrimaryAliases($tenantId, $modelType, $modelId, $barcodeType, (int) $existing->id);
                $existing->update([
                    'barcode' => trim($barcode),
                    'barcode_type' => $barcodeType,
                    'label' => $label,
                    'is_primary' => true,
                    'is_active' => true,
                ]);

                return $existing;
            }

            return $this->storeAlias(
                tenantId: $tenantId,
                modelType: $modelType,
                modelId: $modelId,
                barcode: trim($barcode),
                normalized: $normalized,
                barcodeType: $barcodeType,
                source: $source,
                label: $label,
                isActive: true,
                isPrimary: true,
            );
        });
    }

    private function storeAlias(
        int $tenantId,
        string $modelType,
        int $modelId,
        string $barcode,
        string $normalized,
        string $barcodeType,
        ?string $source,
        ?string $label,
        bool $isActive,
        bool $isPrimary,
    ): BarcodeAlias {
        if ($isPrimary) {
            $this->unsetOtherPrimaryAliases($tenantId, $modelType, $modelId, $barcodeType);
        }

        return BarcodeAlias::create([
            'tenant_id' => $tenantId,
            'model_type' => $modelType,
            'model_id' => $modelId,
            'barcode' => $barcode,
            'normalized_barcode' => $normalized,
            'barcode_type' => $barcodeType,
            'label' => $label,
            'is_primary' => $isPrimary,
            'is_active' => $isActive,
            'source' => $source,
        ]);
    }

    private function unsetOtherPrimaryAliases(int $tenantId, string $modelType, int $modelId, string $barcodeType, ?int $exceptId = null): void
    {
        BarcodeAlias::query()
            ->where('tenant_id', $tenantId)
            ->where('model_type', $modelType)
            ->where('model_id', $modelId)
            ->where('barcode_type', $barcodeType)
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->update(['is_primary' => false]);
    }

    private function guardNoConflict(int $tenantId, string $normalized, string $modelType, int $modelId, string $sameModelMessage): void
    {
        $existing = $this->conflictingAliasQuery($tenantId, $normalized)->first();

        if (! $existing) {
            return;
        }

        if ($existing->model_type === $modelType && (int) $existing->model_id === $modelId) {
            throw new AliasCollisionException($sameModelMessage);
        }

        throw new AliasCollisionException(__('skus.alias_conflict_other_product'));
    }

    private function conflictingAliasQuery(int $tenantId, string $normalized)
    {
        return BarcodeAlias::query()
            ->where('tenant_id', $tenantId)
            ->where('normalized_barcode', $normalized);
    }

    private function guardKnownModel(int $tenantId, string $modelType, int $modelId): void
    {
        $exists = match ($modelType) {
            BarcodeAlias::MODEL_TYPE_SKU => Sku::query()->where('tenant_id', $tenantId)->whereKey($modelId)->exists(),
            BarcodeAlias::MODEL_TYPE_STOCK_ITEM => StockItem::query()->where('tenant_id', $tenantId)->whereKey($modelId)->exists(),
            default => false,
        };

        if (! $exists) {
            throw new AliasCollisionException(__('skus.alias_conflict_other_product'));
        }
    }

    private function guardNonEmpty(string $normalized): void
    {
        if ($normalized === '') {
            throw new AliasCollisionException(__('skus.alias_barcode_required'));
        }
    }
}
