<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_count_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stock_count_run_id')->constrained('stock_count_runs')->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete()->index();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('stock_item_id')->constrained()->restrictOnDelete();
            $table->string('identifier_raw')->nullable();
            $table->unsignedInteger('counted_qty');
            $table->integer('previous_on_hand_qty');
            $table->integer('delta_qty');
            $table->foreignId('movement_id')->nullable()->constrained('inventory_movements')->nullOnDelete();
            $table->text('line_note')->nullable();
            $table->string('reference_no')->nullable();
            $table->string('status');
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'warehouse_id', 'stock_item_id']);
            $table->index(['stock_count_run_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_count_lines');
    }
};
