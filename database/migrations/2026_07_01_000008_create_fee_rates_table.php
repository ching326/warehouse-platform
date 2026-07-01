<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_rates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('fee_type');
            $table->string('unit');
            $table->decimal('rate', 12, 4)->default(0);
            $table->decimal('markup_pct', 8, 4)->nullable();
            $table->string('currency', 3)->default('JPY');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'fee_type', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_rates');
    }
};
