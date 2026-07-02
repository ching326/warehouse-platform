<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legacy_stock_deductions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('stock_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sku_id')->nullable()->constrained('skus')->nullOnDelete();
            $table->foreignId('outbound_order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('outbound_order_line_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('inventory_movement_id')->nullable()->constrained()->nullOnDelete();
            $table->string('tenant_code')->nullable();
            $table->string('warehouse_code')->nullable();
            $table->string('stock_item_code')->nullable();
            $table->string('tenant_item_code')->nullable();
            $table->string('sku_code')->nullable();
            $table->string('legacy_item_code')->nullable();
            $table->string('item_code_source')->nullable();
            $table->unsignedInteger('quantity');
            $table->string('source_ref')->nullable();
            $table->string('idempotency_key')->unique();
            $table->string('status')->default('pending')->index();
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['tenant_id', 'status']);
            $table->index(['outbound_order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_stock_deductions');
    }
};
