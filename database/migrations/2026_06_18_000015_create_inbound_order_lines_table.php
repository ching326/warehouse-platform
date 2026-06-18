<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbound_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inbound_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sku_id')->constrained('skus')->restrictOnDelete();
            $table->foreignId('stock_item_id')->constrained('stock_items')->restrictOnDelete();
            $table->unsignedInteger('expected_qty');
            $table->unsignedInteger('received_qty')->default(0);
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['inbound_order_id']);
            $table->index(['tenant_id', 'sku_id']);
            $table->index(['tenant_id', 'stock_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbound_order_lines');
    }
};
