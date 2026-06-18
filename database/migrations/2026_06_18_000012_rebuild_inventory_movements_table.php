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
        Schema::dropIfExists('inventory_movements');

        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_item_id')->constrained()->cascadeOnDelete();
            $table->string('movement_type');
            $table->integer('quantity_delta');
            $table->integer('balance_after')->nullable();
            $table->integer('on_hand_delta')->default(0);
            $table->integer('reserved_delta')->default(0);
            $table->integer('available_delta')->default(0);
            $table->integer('inbound_delta')->default(0);
            $table->integer('hold_delta')->default(0);
            $table->integer('damaged_delta')->default(0);
            $table->integer('on_hand_after')->default(0);
            $table->integer('reserved_after')->default(0);
            $table->integer('available_after')->default(0);
            $table->integer('inbound_after')->default(0);
            $table->integer('hold_after')->default(0);
            $table->integer('damaged_after')->default(0);
            $table->string('ref_type')->nullable();
            $table->string('ref_id')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['tenant_id', 'stock_item_id']);
            $table->index(['tenant_id', 'warehouse_id']);
            $table->index(['ref_type', 'ref_id']);
            $table->index('user_id');
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
