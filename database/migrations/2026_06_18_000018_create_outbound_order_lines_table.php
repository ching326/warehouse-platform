<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbound_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outbound_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_line_id')->nullable()->constrained('outbound_order_lines')->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sku_id')->constrained('skus')->restrictOnDelete();
            $table->foreignId('stock_item_id')->nullable()->constrained('stock_items')->restrictOnDelete();
            $table->unsignedInteger('qty');
            $table->foreignId('inventory_movement_id')->nullable()->constrained('inventory_movements')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['outbound_order_id']);
            $table->index(['parent_line_id']);
            $table->index(['tenant_id', 'stock_item_id']);
            $table->index(['inventory_movement_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbound_order_lines');
    }
};
