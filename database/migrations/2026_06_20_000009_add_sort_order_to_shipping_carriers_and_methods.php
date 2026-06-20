<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carriers', function (Blueprint $table) {
            $table->unsignedSmallInteger('sort_order')->default(0)->after('country_code');
        });

        Schema::table('shipping_methods', function (Blueprint $table) {
            $table->unsignedSmallInteger('sort_order')->default(0)->after('service_type');
            $table->index(['status', 'sort_order']);
        });

        $carrierOrders = [
            'japan_post' => 10,
            'other' => 20,
            'sagawa' => 30,
            'yamato' => 40,
        ];

        foreach ($carrierOrders as $code => $sortOrder) {
            DB::table('carriers')->where('code', $code)->update(['sort_order' => $sortOrder]);
        }

        $methodOrders = [
            'japan_post_yupack' => 10,
            'other' => 10,
            'sagawa_thb' => 10,
            'yamato_compact' => 10,
            'yamato_nekopos' => 20,
            'yamato_tqb' => 30,
        ];

        foreach ($methodOrders as $code => $sortOrder) {
            DB::table('shipping_methods')->where('code', $code)->update(['sort_order' => $sortOrder]);
        }
    }

    public function down(): void
    {
        Schema::table('shipping_methods', function (Blueprint $table) {
            $table->dropIndex(['status', 'sort_order']);
            $table->dropColumn('sort_order');
        });

        Schema::table('carriers', function (Blueprint $table) {
            $table->dropColumn('sort_order');
        });
    }
};
