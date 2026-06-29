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
        Schema::create('stock_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->string('short_name')->nullable();
            $table->string('brand')->nullable();
            $table->string('model_number')->nullable();
            $table->string('variation_code')->nullable();
            $table->string('color')->nullable();
            $table->string('size')->nullable();
            $table->string('barcode')->nullable();
            $table->string('barcode_type')->default('other');
            $table->string('product_type')->default('normal');
            $table->boolean('is_dangerous_goods')->default(false);
            $table->boolean('requires_expiry_tracking')->default(false);
            $table->boolean('requires_lot_tracking')->default(false);
            $table->text('description')->nullable();
            $table->text('note')->nullable();
            $table->text('handling_note')->nullable();
            $table->decimal('weight_value', 10, 3)->nullable();
            $table->string('weight_unit')->default('g');
            $table->decimal('length_value', 10, 2)->nullable();
            $table->decimal('width_value', 10, 2)->nullable();
            $table->decimal('height_value', 10, 2)->nullable();
            $table->string('dimension_unit')->default('cm');
            $table->string('status')->default('active');
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'barcode']);
            $table->index(['tenant_id', 'product_type']);
            $table->index(['tenant_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_items');
    }
};
