<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->timestamp('platform_ordered_at')->nullable()->after('platform_order_id');
            $table->timestamp('latest_ship_at')->nullable()->after('platform_ordered_at');
        });

        Schema::table('sales_order_lines', function (Blueprint $table) {
            $table->string('platform_line_id')->nullable()->after('sales_order_id');
            $table->string('platform_product_name')->nullable()->after('platform_line_id');
            $table->index(['platform_line_id']);
        });
    }

    public function down(): void
    {
        Schema::table('sales_order_lines', function (Blueprint $table) {
            $table->dropIndex(['platform_line_id']);
            $table->dropColumn(['platform_line_id', 'platform_product_name']);
        });

        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropColumn(['platform_ordered_at', 'latest_ship_at']);
        });
    }
};
