<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouse_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->string('code');
            $table->string('name')->nullable();
            $table->string('zone_type')->default('storage');
            $table->string('storage_unit_type')->nullable();
            $table->string('status')->default('active');
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['warehouse_id', 'code']);
            $table->index(['warehouse_id', 'status']);
            $table->index(['warehouse_id', 'zone_type']);
            $table->index(['warehouse_id', 'storage_unit_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_locations');
    }
};
