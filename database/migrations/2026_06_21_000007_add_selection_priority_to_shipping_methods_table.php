<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipping_methods', function (Blueprint $table) {
            $table->unsignedSmallInteger('selection_priority')->default(0)->after('sort_order');
        });

        $priorities = [
            'yamato_tqb' => 40,
            'sagawa_thb' => 40,
            'japan_post_yupack' => 40,
            'yamato_compact' => 30,
            'yamato_nekopos' => 20,
            'other' => 10,
        ];

        foreach ($priorities as $code => $priority) {
            DB::table('shipping_methods')
                ->where('code', $code)
                ->update(['selection_priority' => $priority]);
        }
    }

    public function down(): void
    {
        Schema::table('shipping_methods', function (Blueprint $table) {
            $table->dropColumn('selection_priority');
        });
    }
};
