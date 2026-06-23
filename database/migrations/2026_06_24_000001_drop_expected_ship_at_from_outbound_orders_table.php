<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outbound_orders', function (Blueprint $table) {
            $table->dropColumn('expected_ship_at');
        });
    }

    public function down(): void
    {
        Schema::table('outbound_orders', function (Blueprint $table) {
            $table->date('expected_ship_at')->nullable()->after('status');
        });
    }
};
