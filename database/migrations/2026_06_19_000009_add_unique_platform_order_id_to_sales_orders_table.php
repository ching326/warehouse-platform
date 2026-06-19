<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropIndex('sales_orders_tenant_id_shop_id_platform_order_id_index');
            $table->unique(
                ['tenant_id', 'shop_id', 'platform_order_id'],
                'sales_orders_tenant_shop_platform_order_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropUnique('sales_orders_tenant_shop_platform_order_unique');
            $table->index(['tenant_id', 'shop_id', 'platform_order_id']);
        });
    }
};
