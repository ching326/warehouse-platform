<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exception_case_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exception_case_id')->constrained('exception_cases')->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_order_line_id')->nullable()->constrained('sales_order_lines')->nullOnDelete();
            $table->foreignId('outbound_order_line_id')->nullable()->constrained('outbound_order_lines')->nullOnDelete();
            $table->foreignId('sku_id')->nullable()->constrained('skus')->nullOnDelete();
            $table->foreignId('stock_item_id')->nullable()->constrained('stock_items')->nullOnDelete();
            $table->unsignedInteger('qty');
            $table->string('condition')->default('unknown');
            $table->string('action')->default('investigate');
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'sku_id']);
            $table->index(['tenant_id', 'stock_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exception_case_lines');
    }
};
