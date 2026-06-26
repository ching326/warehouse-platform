<?php

use App\Models\BarcodeAlias;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('barcode_aliases', function (Blueprint $table): void {
            $table->boolean('is_primary')->default(false)->after('label');
            $table->index(['tenant_id', 'model_type', 'model_id', 'barcode_type', 'is_primary'], 'barcode_alias_primary_lookup');
        });

        $now = now();

        DB::table('stock_items')
            ->whereNotNull('barcode')
            ->where('barcode', '!=', '')
            ->orderBy('id')
            ->chunkById(100, function ($stockItems) use ($now): void {
                foreach ($stockItems as $stockItem) {
                    $this->createAliasIfMissing(
                        tenantId: (int) $stockItem->tenant_id,
                        modelType: BarcodeAlias::MODEL_TYPE_STOCK_ITEM,
                        modelId: (int) $stockItem->id,
                        barcode: (string) $stockItem->barcode,
                        barcodeType: (string) ($stockItem->barcode_type ?: 'unknown'),
                        label: 'Legacy stock item barcode',
                        now: $now,
                    );
                }
            });

        DB::table('skus')
            ->whereNotNull('barcode')
            ->where('barcode', '!=', '')
            ->orderBy('id')
            ->chunkById(100, function ($skus) use ($now): void {
                foreach ($skus as $sku) {
                    $this->createAliasIfMissing(
                        tenantId: (int) $sku->tenant_id,
                        modelType: BarcodeAlias::MODEL_TYPE_SKU,
                        modelId: (int) $sku->id,
                        barcode: (string) $sku->barcode,
                        barcodeType: 'other',
                        label: 'Legacy SKU barcode',
                        now: $now,
                    );
                }
            });

        DB::table('skus')
            ->whereNotNull('platform_label_code')
            ->where('platform_label_code', '!=', '')
            ->orderBy('id')
            ->chunkById(100, function ($skus) use ($now): void {
                foreach ($skus as $sku) {
                    $this->createAliasIfMissing(
                        tenantId: (int) $sku->tenant_id,
                        modelType: BarcodeAlias::MODEL_TYPE_SKU,
                        modelId: (int) $sku->id,
                        barcode: (string) $sku->platform_label_code,
                        barcodeType: 'platform_label',
                        label: 'Legacy platform label code',
                        now: $now,
                    );
                }
            });
    }

    public function down(): void
    {
        DB::table('barcode_aliases')
            ->where('source', BarcodeAlias::SOURCE_SYSTEM)
            ->whereIn('label', [
                'Legacy stock item barcode',
                'Legacy SKU barcode',
                'Legacy platform label code',
            ])
            ->delete();

        Schema::table('barcode_aliases', function (Blueprint $table): void {
            $table->dropIndex('barcode_alias_primary_lookup');
            $table->dropColumn('is_primary');
        });
    }

    private function createAliasIfMissing(
        int $tenantId,
        string $modelType,
        int $modelId,
        string $barcode,
        string $barcodeType,
        string $label,
        mixed $now,
    ): void {
        $normalized = BarcodeAlias::normalize($barcode);

        if ($normalized === '') {
            return;
        }

        $existing = DB::table('barcode_aliases')
            ->where('tenant_id', $tenantId)
            ->where('normalized_barcode', $normalized)
            ->first();

        if ($existing !== null) {
            return;
        }

        DB::table('barcode_aliases')->insert([
            'tenant_id' => $tenantId,
            'model_type' => $modelType,
            'model_id' => $modelId,
            'barcode' => trim($barcode),
            'normalized_barcode' => $normalized,
            'barcode_type' => $barcodeType,
            'label' => $label,
            'is_primary' => true,
            'is_active' => true,
            'source' => BarcodeAlias::SOURCE_SYSTEM,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
};
