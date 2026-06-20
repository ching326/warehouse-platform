<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exception_cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_order_id')->nullable()->constrained('sales_orders')->nullOnDelete();
            $table->foreignId('fulfillment_group_id')->nullable()->constrained('fulfillment_groups')->nullOnDelete();
            $table->foreignId('outbound_order_id')->nullable()->constrained('outbound_orders')->nullOnDelete();
            $table->string('case_no')->unique();
            $table->string('case_type');
            $table->string('status')->default('open');
            $table->timestamp('reported_at')->nullable();
            $table->string('reported_by')->nullable();
            $table->text('note')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'case_type']);
            $table->index(['sales_order_id']);
            $table->index(['outbound_order_id']);
            $table->index(['created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exception_cases');
    }
};
