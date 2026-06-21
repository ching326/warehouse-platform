<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('skus', function (Blueprint $table) {
            $table->foreignId('default_shipping_method_id')
                ->nullable()
                ->after('default_packaging_material_id')
                ->constrained('shipping_methods')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('skus', function (Blueprint $table) {
            $table->dropConstrainedForeignId('default_shipping_method_id');
        });
    }
};
