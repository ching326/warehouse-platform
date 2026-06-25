<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbound_order_sales_order', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outbound_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_order_id')->constrained()->cascadeOnDelete();
            $table->timestamp('arranged_at')->nullable();

            $table->unique(['outbound_order_id', 'sales_order_id'], 'oo_so_outbound_sales_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbound_order_sales_order');
    }
};
