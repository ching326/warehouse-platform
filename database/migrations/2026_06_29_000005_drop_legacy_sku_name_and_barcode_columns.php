<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_items', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'barcode']);
            $table->dropColumn(['barcode', 'barcode_type']);
        });

        Schema::table('skus', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'barcode']);
            $table->dropColumn(['barcode', 'name', 'name_en', 'name_ja', 'name_zh_tw', 'name_zh_cn']);
        });
    }

    public function down(): void
    {
        Schema::table('stock_items', function (Blueprint $table): void {
            $table->string('barcode')->nullable()->after('size');
            $table->string('barcode_type')->default('other')->after('barcode');
            $table->index(['tenant_id', 'barcode']);
        });

        Schema::table('skus', function (Blueprint $table): void {
            $table->string('barcode')->nullable()->after('sku');
            $table->string('name')->default('')->after('barcode');
            $table->string('name_en')->nullable()->after('name');
            $table->string('name_ja')->nullable()->after('name_en');
            $table->string('name_zh_tw')->nullable()->after('name_ja');
            $table->string('name_zh_cn')->nullable()->after('name_zh_tw');
            $table->index(['tenant_id', 'barcode']);
        });
    }
};
