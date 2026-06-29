<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('barcode_aliases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->string('barcode');
            $table->string('normalized_barcode');
            $table->string('barcode_type')->default('other');
            $table->string('label')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'normalized_barcode']);
            $table->index(['tenant_id', 'model_type', 'model_id']);
            $table->index(['tenant_id', 'normalized_barcode', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('barcode_aliases');
    }
};
