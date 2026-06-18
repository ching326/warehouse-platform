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
        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sku_id')->nullable()->constrained('skus')->nullOnDelete();
            $table->foreignId('inventory_balance_id')->nullable()->constrained()->nullOnDelete();
            $table->string('movement_type');
            $table->unsignedInteger('quantity');
            $table->string('reference_type')->nullable();
            $table->string('reference_number')->nullable();
            $table->text('note')->nullable();
            $table->json('metadata')->nullable();
            $table->unsignedInteger('on_hand_before');
            $table->unsignedInteger('on_hand_after');
            $table->unsignedInteger('reserved_before');
            $table->unsignedInteger('reserved_after');
            $table->unsignedInteger('available_before');
            $table->unsignedInteger('available_after');
            $table->unsignedInteger('inbound_before');
            $table->unsignedInteger('inbound_after');
            $table->unsignedInteger('hold_before');
            $table->unsignedInteger('hold_after');
            $table->unsignedInteger('damaged_before');
            $table->unsignedInteger('damaged_after');
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamps();

            $table->index(['tenant_id', 'warehouse_id', 'stock_item_id'], 'inventory_movements_balance_lookup_index');
            $table->index(['tenant_id', 'movement_type']);
            $table->index(['tenant_id', 'reference_type', 'reference_number'], 'inventory_movements_reference_index');
            $table->index(['stock_item_id', 'occurred_at']);
            $table->index('sku_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
    }
};
