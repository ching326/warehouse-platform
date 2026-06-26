<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('barcode_aliases', function (Blueprint $table): void {
            $table->string('source')->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('barcode_aliases', function (Blueprint $table): void {
            $table->dropColumn('source');
        });
    }
};
