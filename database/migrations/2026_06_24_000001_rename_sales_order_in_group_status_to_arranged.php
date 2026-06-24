<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('sales_orders')
            ->where('fulfillment_status', 'in_group')
            ->update(['fulfillment_status' => 'arranged']);
    }

    public function down(): void
    {
        DB::table('sales_orders')
            ->where('fulfillment_status', 'arranged')
            ->update(['fulfillment_status' => 'in_group']);
    }
};
