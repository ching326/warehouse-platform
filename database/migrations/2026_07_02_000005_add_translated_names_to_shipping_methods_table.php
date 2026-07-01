<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipping_methods', function (Blueprint $table): void {
            $table->string('name_ja')->nullable()->after('name');
            $table->string('name_zh_tw')->nullable()->after('name_ja');
            $table->string('name_zh_cn')->nullable()->after('name_zh_tw');
        });
    }

    public function down(): void
    {
        Schema::table('shipping_methods', function (Blueprint $table): void {
            $table->dropColumn(['name_ja', 'name_zh_tw', 'name_zh_cn']);
        });
    }
};
