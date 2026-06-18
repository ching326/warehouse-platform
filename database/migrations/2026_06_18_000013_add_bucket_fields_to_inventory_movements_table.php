<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->integer('on_hand_delta')->default(0)->after('balance_after');
            $table->integer('reserved_delta')->default(0)->after('on_hand_delta');
            $table->integer('available_delta')->default(0)->after('reserved_delta');
            $table->integer('inbound_delta')->default(0)->after('available_delta');
            $table->integer('hold_delta')->default(0)->after('inbound_delta');
            $table->integer('damaged_delta')->default(0)->after('hold_delta');
            $table->integer('on_hand_after')->default(0)->after('damaged_delta');
            $table->integer('reserved_after')->default(0)->after('on_hand_after');
            $table->integer('available_after')->default(0)->after('reserved_after');
            $table->integer('inbound_after')->default(0)->after('available_after');
            $table->integer('hold_after')->default(0)->after('inbound_after');
            $table->integer('damaged_after')->default(0)->after('hold_after');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->dropColumn([
                'on_hand_delta',
                'reserved_delta',
                'available_delta',
                'inbound_delta',
                'hold_delta',
                'damaged_delta',
                'on_hand_after',
                'reserved_after',
                'available_after',
                'inbound_after',
                'hold_after',
                'damaged_after',
            ]);
        });
    }
};
