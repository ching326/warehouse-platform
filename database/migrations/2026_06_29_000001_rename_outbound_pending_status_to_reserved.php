<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('outbound_orders')
            ->where('status', 'pending')
            ->update(['status' => 'reserved']);
    }

    public function down(): void
    {
        DB::table('outbound_orders')
            ->where('status', 'reserved')
            ->update(['status' => 'pending']);
    }
};
