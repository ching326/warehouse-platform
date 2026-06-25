<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-locale name override columns. The base name/short_name columns hold
     * the default (source) value and are used as the fallback when a locale
     * override is empty. Locales mirror the app target locales: ja, zh_TW, zh_CN
     * (en falls back to the base column).
     */
    public function up(): void
    {
        Schema::table('stock_items', function (Blueprint $table) {
            $table->string('name_ja')->nullable()->after('name');
            $table->string('name_zh_tw')->nullable()->after('name_ja');
            $table->string('name_zh_cn')->nullable()->after('name_zh_tw');
            $table->string('short_name_ja')->nullable()->after('short_name');
            $table->string('short_name_zh_tw')->nullable()->after('short_name_ja');
            $table->string('short_name_zh_cn')->nullable()->after('short_name_zh_tw');
        });

        Schema::table('skus', function (Blueprint $table) {
            $table->string('name_ja')->nullable()->after('name');
            $table->string('name_zh_tw')->nullable()->after('name_ja');
            $table->string('name_zh_cn')->nullable()->after('name_zh_tw');
        });
    }

    public function down(): void
    {
        Schema::table('stock_items', function (Blueprint $table) {
            $table->dropColumn([
                'name_ja',
                'name_zh_tw',
                'name_zh_cn',
                'short_name_ja',
                'short_name_zh_tw',
                'short_name_zh_cn',
            ]);
        });

        Schema::table('skus', function (Blueprint $table) {
            $table->dropColumn(['name_ja', 'name_zh_tw', 'name_zh_cn']);
        });
    }
};
