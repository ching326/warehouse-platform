<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('period');
            $table->string('status')->default('draft');
            $table->string('currency', 3);
            $table->decimal('total', 14, 2)->default(0);
            $table->timestamp('finalized_at')->nullable();
            $table->foreignId('generated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('warnings')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'period']);
        });

        Schema::create('invoice_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->string('fee_type');
            $table->string('unit');
            $table->decimal('quantity', 14, 4);
            $table->decimal('rate', 12, 4)->nullable();
            $table->decimal('markup_pct', 8, 4)->nullable();
            $table->decimal('cost_base', 14, 2)->nullable();
            $table->date('rate_from')->nullable();
            $table->date('rate_to')->nullable();
            $table->decimal('amount', 14, 2);
            $table->string('source_summary')->nullable();
            $table->timestamps();
        });

        Schema::create('invoice_line_sources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invoice_line_id')->constrained()->cascadeOnDelete();
            $table->string('source_type');
            $table->unsignedBigInteger('source_id');
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
            $table->date('source_date')->nullable();
            $table->decimal('quantity', 14, 4)->nullable();
            $table->decimal('amount_basis', 14, 2)->nullable();
            $table->timestamps();

            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_line_sources');
        Schema::dropIfExists('invoice_lines');
        Schema::dropIfExists('invoices');
    }
};
