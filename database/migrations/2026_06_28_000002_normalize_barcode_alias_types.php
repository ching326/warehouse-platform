<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('barcode_aliases')
            ->where('barcode_type', 'fnsku')
            ->update(['barcode_type' => 'platform_label']);

        DB::table('barcode_aliases')
            ->where('barcode_type', 'internal')
            ->update(['barcode_type' => 'internal_label']);

        DB::table('barcode_aliases')
            ->where('barcode_type', 'supplier')
            ->update(['barcode_type' => 'supplier_label']);

        DB::table('barcode_aliases')
            ->where('barcode_type', 'gtin')
            ->update(['barcode_type' => 'other']);

        DB::table('barcode_aliases')
            ->where('barcode_type', 'unknown')
            ->update(['barcode_type' => 'other']);

        DB::table('stock_items')
            ->where('barcode_type', 'unknown')
            ->update(['barcode_type' => 'other']);
    }

    public function down(): void
    {
        // Intentionally irreversible: the old values were ambiguous aliases of
        // the canonical barcode types and cannot be restored safely.
    }
};
