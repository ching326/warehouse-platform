<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outbound_orders', function (Blueprint $table) {
            $table->dropColumn('shipping_method');
        });
    }

    public function down(): void
    {
        Schema::table('outbound_orders', function (Blueprint $table) {
            $table->string('shipping_method')->nullable()->after('shipping_method_id');
        });
    }
};
