<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $aliases = [
            'ymt_' => 'yamato_',
            'sgw_' => 'sagawa_',
            'jpo_' => 'japan_post_',
        ];

        foreach ($aliases as $aliasPrefix => $canonicalPrefix) {
            $methods = DB::table('shipping_methods')
                ->where('code', 'like', $aliasPrefix.'%')
                ->get(['id', 'code']);

            foreach ($methods as $method) {
                $canonicalCode = $canonicalPrefix.substr($method->code, strlen($aliasPrefix));

                if (DB::table('shipping_methods')->where('code', $canonicalCode)->where('id', '!=', $method->id)->exists()) {
                    continue;
                }

                DB::table('shipping_methods')
                    ->where('id', $method->id)
                    ->update(['code' => $canonicalCode]);
            }
        }
    }

    public function down(): void
    {
        // Canonical shipping method codes are intentionally not reverted.
    }
};
