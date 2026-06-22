<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('skus', function (Blueprint $table): void {
            $table->string('barcode')->nullable()->after('sku');
            $table->index(['tenant_id', 'barcode']);
        });
    }

    public function down(): void
    {
        Schema::table('skus', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'barcode']);
            $table->dropColumn('barcode');
        });
    }
};
