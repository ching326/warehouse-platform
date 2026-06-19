<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->string('shipping_method')->nullable()->after('fulfillment_status');
            $table->string('tracking_no')->nullable()->after('shipping_method');
            $table->timestamp('courier_csv_exported_at')->nullable()->after('tracking_no');
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropColumn([
                'shipping_method',
                'tracking_no',
                'courier_csv_exported_at',
            ]);
        });
    }
};
