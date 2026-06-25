<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('return_order_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('return_order_id')->constrained('return_orders')->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_order_line_id')->nullable()->constrained('sales_order_lines')->nullOnDelete();
            $table->foreignId('sku_id')->nullable()->constrained('skus')->nullOnDelete();
            $table->foreignId('stock_item_id')->nullable()->constrained('stock_items')->nullOnDelete();
            $table->unsignedInteger('expected_qty')->default(0);
            $table->unsignedInteger('received_qty')->default(0);
            $table->string('condition')->default('unknown');
            $table->string('disposition')->default('undecided');
            $table->foreignId('received_location_id')->nullable()->constrained('warehouse_locations')->nullOnDelete();
            $table->foreignId('disposition_location_id')->nullable()->constrained('warehouse_locations')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('inspected_at')->nullable();
            $table->timestamp('dispositioned_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'sku_id']);
            $table->index(['tenant_id', 'stock_item_id']);
            $table->index(['return_order_id', 'disposition']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('return_order_lines');
    }
};
