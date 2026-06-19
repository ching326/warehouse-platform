<?php

use App\Actions\BackfillSalesOrderDate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->timestamp('order_date')->nullable()->after('platform_ordered_at');
            $table->index(['tenant_id', 'order_date'], 'sales_orders_tenant_order_date_idx');
            $table->index(['tenant_id', 'fulfillment_status', 'order_date'], 'sales_orders_tenant_fulfillment_order_date_idx');
            $table->index(['tenant_id', 'order_status', 'order_date'], 'sales_orders_tenant_status_order_date_idx');
        });

        app(BackfillSalesOrderDate::class)();
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropIndex('sales_orders_tenant_order_date_idx');
            $table->dropIndex('sales_orders_tenant_fulfillment_order_date_idx');
            $table->dropIndex('sales_orders_tenant_status_order_date_idx');
            $table->dropColumn('order_date');
        });
    }
};
