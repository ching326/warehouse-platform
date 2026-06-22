<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fulfillment_pack_scans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('fulfillment_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fulfillment_group_order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sales_order_id')->nullable()->constrained('sales_orders')->nullOnDelete();
            $table->foreignId('sku_id')->nullable()->constrained('skus')->nullOnDelete();
            $table->foreignId('stock_item_id')->nullable()->constrained('stock_items')->nullOnDelete();
            $table->string('barcode_scanned');
            $table->string('normalized_barcode');
            $table->string('result');
            $table->string('message');
            $table->foreignId('scanned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['tenant_id', 'fulfillment_group_id']);
            $table->index(['fulfillment_group_id', 'created_at']);
            $table->index('barcode_scanned');
            $table->index(['scanned_by_user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fulfillment_pack_scans');
    }
};
