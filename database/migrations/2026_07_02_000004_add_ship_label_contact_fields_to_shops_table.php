<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table): void {
            $table->string('ship_label_phone', 50)->nullable()->after('ship_label_address');
            $table->string('ship_label_postcode', 20)->nullable()->after('ship_label_phone');
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table): void {
            $table->dropColumn(['ship_label_phone', 'ship_label_postcode']);
        });
    }
};
