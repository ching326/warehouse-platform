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
        Schema::create('skus', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shop_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('stock_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('sku');
            $table->string('name');
            $table->string('platform_sku')->nullable();
            $table->string('platform_product_id')->nullable();
            $table->string('platform_variant_id')->nullable();
            $table->string('platform_variant_name')->nullable();
            $table->string('platform_label_code')->nullable();
            $table->string('sku_type')->default('single');
            $table->foreignId('default_packaging_material_id')->nullable()->constrained('packaging_materials')->nullOnDelete();
            $table->string('status')->default('active');
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'shop_id', 'sku']);
            $table->index(['tenant_id', 'stock_item_id']);
            $table->index(['tenant_id', 'platform_product_id']);
            $table->index(['tenant_id', 'platform_label_code']);
            $table->index(['tenant_id', 'sku_type']);
            $table->index(['tenant_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('skus');
    }
};
