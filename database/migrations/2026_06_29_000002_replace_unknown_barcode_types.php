<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('barcode_aliases')
            ->where('barcode_type', 'unknown')
            ->update(['barcode_type' => 'other']);

        DB::table('stock_items')
            ->where('barcode_type', 'unknown')
            ->update(['barcode_type' => 'other']);
    }

    public function down(): void
    {
        // Intentionally irreversible: "unknown" has been removed from the
        // barcode type list and should remain grouped under "other".
    }
};
