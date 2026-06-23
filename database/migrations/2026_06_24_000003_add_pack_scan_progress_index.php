<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fulfillment_pack_scans', function (Blueprint $table): void {
            $table->index(
                ['fulfillment_group_id', 'result', 'sku_id', 'stock_item_id'],
                'pack_scans_group_result_item_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('fulfillment_pack_scans', function (Blueprint $table): void {
            $table->dropIndex('pack_scans_group_result_item_idx');
        });
    }
};
