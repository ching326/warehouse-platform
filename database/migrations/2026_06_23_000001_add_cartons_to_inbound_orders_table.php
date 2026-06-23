<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inbound_orders', function (Blueprint $table) {
            $table->unsignedInteger('expected_carton_count')->nullable()->after('expected_at');
            $table->unsignedInteger('received_carton_count')->nullable()->after('expected_carton_count');
            $table->text('carton_mark')->nullable()->after('received_carton_count');
        });
    }

    public function down(): void
    {
        Schema::table('inbound_orders', function (Blueprint $table) {
            $table->dropColumn(['expected_carton_count', 'received_carton_count', 'carton_mark']);
        });
    }
};
