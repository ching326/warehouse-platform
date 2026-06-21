<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $aliases = [
            'ymt' => 'yamato',
            'sgw' => 'sagawa',
            'jpo' => 'japan_post',
            'oth' => 'other',
        ];

        foreach ($aliases as $alias => $canonical) {
            DB::table('carriers')
                ->where('code', $alias)
                ->whereNotExists(function ($query) use ($canonical) {
                    $query->selectRaw('1')
                        ->from('carriers as canonical_carriers')
                        ->where('canonical_carriers.code', $canonical);
                })
                ->update(['code' => $canonical]);

            DB::table('sales_orders')
                ->where('shipping_method', $alias)
                ->update(['shipping_method' => $canonical]);

            DB::table('courier_export_batches')
                ->where('carrier', $alias)
                ->update(['carrier' => $canonical]);

            DB::table('courier_export_batch_orders')
                ->where('carrier', $alias)
                ->update(['carrier' => $canonical]);
        }
    }

    public function down(): void
    {
        // Canonical carrier codes are intentionally not reverted.
    }
};
