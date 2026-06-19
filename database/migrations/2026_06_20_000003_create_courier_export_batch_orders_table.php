<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courier_export_batch_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('courier_export_batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_order_id')->constrained()->cascadeOnDelete();
            $table->string('platform_order_id')->nullable();
            $table->string('carrier');
            $table->timestamp('exported_at');
            $table->timestamps();

            $table->unique(['courier_export_batch_id', 'sales_order_id'], 'ceb_orders_batch_order_unique');
            $table->index(['sales_order_id', 'exported_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courier_export_batch_orders');
    }
};
