<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-locale name override columns. The base name column holds the default
     * (source) value and is used as the fallback when a locale override is empty.
     * Locales mirror the app target locales: ja, zh_TW, zh_CN (en falls back to
     * the base column). short_name is intentionally not localized; it is a
     * language-neutral operator shorthand like brand or model_number.
     */
    public function up(): void
    {
        Schema::table('stock_items', function (Blueprint $table) {
            $table->string('name_ja')->nullable()->after('name');
            $table->string('name_zh_tw')->nullable()->after('name_ja');
            $table->string('name_zh_cn')->nullable()->after('name_zh_tw');
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
            $table->dropColumn(['name_ja', 'name_zh_tw', 'name_zh_cn']);
        });

        Schema::table('skus', function (Blueprint $table) {
            $table->dropColumn(['name_ja', 'name_zh_tw', 'name_zh_cn']);
        });
    }
};
