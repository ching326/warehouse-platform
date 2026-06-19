<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outbound_orders', function (Blueprint $table) {
            $table->foreignId('fulfillment_group_id')
                ->nullable()
                ->after('id')
                ->constrained('fulfillment_groups')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('outbound_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('fulfillment_group_id');
        });
    }
};
