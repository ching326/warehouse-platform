<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * English name override columns. The base name column still holds the
     * tenant's base-language value and is used as the fallback when any locale
     * override, including English, is empty.
     */
    public function up(): void
    {
        Schema::table('stock_items', function (Blueprint $table): void {
            $table->string('name_en')->nullable()->after('name');
        });

        Schema::table('skus', function (Blueprint $table): void {
            $table->string('name_en')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('stock_items', function (Blueprint $table): void {
            $table->dropColumn('name_en');
        });

        Schema::table('skus', function (Blueprint $table): void {
            $table->dropColumn('name_en');
        });
    }
};
