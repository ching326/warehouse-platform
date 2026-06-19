<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shop_id')->constrained('shops')->restrictOnDelete();
            $table->string('source')->default('manual');
            $table->string('platform_order_id')->nullable();
            $table->string('order_status')->default('pending');
            $table->string('fulfillment_status')->default('unfulfilled');
            $table->string('recipient_name')->nullable();
            $table->string('recipient_phone')->nullable();
            $table->string('recipient_country_code', 2)->nullable();
            $table->string('recipient_postal_code')->nullable();
            $table->string('recipient_state')->nullable();
            $table->string('recipient_city')->nullable();
            $table->string('recipient_address_line1')->nullable();
            $table->string('recipient_address_line2')->nullable();
            $table->string('ship_together_key')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'fulfillment_status']);
            $table->index(['tenant_id', 'order_status']);
            $table->index(['shop_id']);
            $table->index(['ship_together_key']);
            $table->index(['tenant_id', 'shop_id', 'platform_order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_orders');
    }
};
