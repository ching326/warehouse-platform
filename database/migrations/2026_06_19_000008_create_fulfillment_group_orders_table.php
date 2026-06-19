<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fulfillment_group_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fulfillment_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_order_id')->constrained('sales_orders')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['fulfillment_group_id', 'sales_order_id']);
            $table->index(['sales_order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fulfillment_group_orders');
    }
};
