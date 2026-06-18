<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbound_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inbound_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inbound_order_line_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_location_id')->constrained()->restrictOnDelete();
            $table->foreignId('sku_id')->constrained('skus')->restrictOnDelete();
            $table->foreignId('stock_item_id')->constrained('stock_items')->restrictOnDelete();
            $table->foreignId('inventory_movement_id')->nullable()->constrained('inventory_movements')->nullOnDelete();
            $table->unsignedInteger('received_qty');
            $table->foreignId('received_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('received_at');
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['inbound_order_id']);
            $table->index(['inbound_order_line_id']);
            $table->index(['tenant_id', 'stock_item_id']);
            $table->index(['warehouse_id', 'warehouse_location_id']);
            $table->index(['received_by_user_id']);
            $table->index(['inventory_movement_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbound_receipts');
    }
};
