<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('outbound_orders', 'ship_mode')) {
            return;
        }

        Schema::table('outbound_orders', function (Blueprint $table) {
            $table->dropColumn('ship_mode');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('outbound_orders', 'ship_mode')) {
            return;
        }

        Schema::table('outbound_orders', function (Blueprint $table) {
            $table->string('ship_mode')->nullable()->after('reason');
        });
    }
};
