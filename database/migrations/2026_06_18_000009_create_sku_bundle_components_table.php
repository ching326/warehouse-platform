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
        Schema::create('sku_bundle_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bundle_sku_id')->constrained('skus')->cascadeOnDelete();
            $table->foreignId('component_stock_item_id')->constrained('stock_items')->cascadeOnDelete();
            $table->unsignedInteger('quantity')->default(1);
            $table->timestamps();

            $table->unique(['bundle_sku_id', 'component_stock_item_id'], 'sku_bundle_components_bundle_component_unique');
            $table->index(['tenant_id', 'bundle_sku_id']);
            $table->index(['tenant_id', 'component_stock_item_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sku_bundle_components');
    }
};
