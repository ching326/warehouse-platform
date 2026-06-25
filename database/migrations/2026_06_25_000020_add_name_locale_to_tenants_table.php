<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('sku_name_locale')->nullable()->after('notes');
            $table->string('stock_item_name_locale')->nullable()->after('sku_name_locale');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['sku_name_locale', 'stock_item_name_locale']);
        });
    }
};
