<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fulfillment_group_orders', function (Blueprint $table) {
            $table->string('tracking_no', 255)->nullable()->after('sales_order_id');
            $table->string('courier', 100)->nullable()->after('tracking_no');
            $table->timestamp('arranged_at')->nullable()->after('courier');
            $table->timestamp('shipped_at')->nullable()->after('arranged_at');
        });

        DB::table('fulfillment_group_orders')
            ->whereNull('arranged_at')
            ->update(['arranged_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('fulfillment_group_orders', function (Blueprint $table) {
            $table->dropColumn(['tracking_no', 'courier', 'arranged_at', 'shipped_at']);
        });
    }
};
