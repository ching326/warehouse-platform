<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fulfillment_groups', function (Blueprint $table) {
            $table->foreignId('shipping_method_id')
                ->nullable()
                ->after('warehouse_id')
                ->constrained()
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('fulfillment_groups', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shipping_method_id');
        });
    }
};
