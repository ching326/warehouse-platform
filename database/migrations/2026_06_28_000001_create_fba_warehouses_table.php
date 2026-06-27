<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fba_warehouses', function (Blueprint $table): void {
            $table->id();
            $table->string('country_code', 2)->default('JP');
            $table->string('code');
            $table->string('name');
            $table->string('postal_code')->nullable();
            $table->string('state')->nullable();
            $table->string('city')->nullable();
            $table->string('address_line1')->nullable();
            $table->string('address_line2')->nullable();
            $table->string('phone')->nullable();
            $table->string('status')->default('active');
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['country_code', 'code']);
            $table->index(['country_code', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fba_warehouses');
    }
};
