<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('packaging_materials', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('type')->index();
            $table->decimal('length_value', 10, 2)->nullable();
            $table->decimal('width_value', 10, 2)->nullable();
            $table->decimal('height_value', 10, 2)->nullable();
            $table->string('dimension_unit')->default('cm');
            $table->decimal('weight_value', 10, 3)->nullable();
            $table->string('weight_unit')->default('g');
            $table->decimal('cost', 12, 2)->nullable();
            $table->string('currency', 3)->default('JPY');
            $table->string('status')->default('active')->index();
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('packaging_materials');
    }
};
