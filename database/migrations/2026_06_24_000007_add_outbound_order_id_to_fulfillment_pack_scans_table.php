<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fulfillment_pack_scans', function (Blueprint $table): void {
            $table->foreignId('outbound_order_id')
                ->nullable()
                ->after('fulfillment_group_id')
                ->constrained('outbound_orders')
                ->cascadeOnDelete();

            $table->index(['outbound_order_id', 'result', 'sku_id', 'stock_item_id'], 'fps_outbound_progress_idx');
            $table->index(['outbound_order_id', 'created_at'], 'fps_outbound_created_at_idx');
        });
    }

    public function down(): void
    {
        Schema::table('fulfillment_pack_scans', function (Blueprint $table): void {
            $table->dropForeign(['outbound_order_id']);
            $table->dropIndex('fps_outbound_progress_idx');
            $table->dropIndex('fps_outbound_created_at_idx');
            $table->dropColumn('outbound_order_id');
        });
    }
};
