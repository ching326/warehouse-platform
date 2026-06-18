<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('inventory_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_item_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('on_hand_qty')->default(0);
            $table->unsignedInteger('reserved_qty')->default(0);
            $table->unsignedInteger('available_qty')->default(0);
            $table->unsignedInteger('inbound_qty')->default(0);
            $table->unsignedInteger('hold_qty')->default(0);
            $table->unsignedInteger('damaged_qty')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'warehouse_id', 'stock_item_id']);
            $table->index(['tenant_id', 'stock_item_id']);
            $table->index(['tenant_id', 'warehouse_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_balances');
    }
};
