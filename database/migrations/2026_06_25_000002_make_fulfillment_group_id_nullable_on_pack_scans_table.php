<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fulfillment_pack_scans', function (Blueprint $table): void {
            $table->foreignId('fulfillment_group_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('fulfillment_pack_scans', function (Blueprint $table): void {
            $table->foreignId('fulfillment_group_id')->nullable(false)->change();
        });
    }
};
