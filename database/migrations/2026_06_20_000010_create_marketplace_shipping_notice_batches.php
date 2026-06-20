<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_shipping_notice_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('platform');
            $table->string('marketplace')->default('');
            $table->string('file_name');
            $table->string('disk')->default('local');
            $table->string('path');
            $table->unsignedInteger('order_count')->default(0);
            $table->unsignedInteger('line_count')->default(0);
            $table->foreignId('exported_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('exported_at');
            $table->timestamps();

            $table->index(['platform', 'marketplace', 'exported_at'], 'msn_batches_platform_marketplace_exported_idx');
            $table->index(['tenant_id', 'exported_at']);
        });

        Schema::create('marketplace_shipping_notice_batch_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('marketplace_shipping_notice_batch_id')
                ->constrained('marketplace_shipping_notice_batches')
                ->cascadeOnDelete();
            $table->foreignId('sales_order_id')->constrained()->cascadeOnDelete();
            $table->string('platform_order_id')->nullable();
            $table->string('tracking_no')->nullable();
            $table->foreignId('shipping_method_id')->nullable()->constrained('shipping_methods')->nullOnDelete();
            $table->timestamp('exported_at');
            $table->timestamps();

            $table->index('marketplace_shipping_notice_batch_id', 'msn_batch_orders_batch_idx');
            $table->index('sales_order_id', 'msn_batch_orders_sales_order_idx');
            $table->index('platform_order_id', 'msn_batch_orders_platform_order_idx');
        });

        Schema::table('sales_orders', function (Blueprint $table) {
            $table->timestamp('marketplace_shipping_notice_exported_at')->nullable()->after('courier_csv_exported_at');
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropColumn('marketplace_shipping_notice_exported_at');
        });

        Schema::dropIfExists('marketplace_shipping_notice_batch_orders');
        Schema::dropIfExists('marketplace_shipping_notice_batches');
    }
};
