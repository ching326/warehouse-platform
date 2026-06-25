<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('return_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->foreignId('issue_id')->nullable()->constrained('issues')->nullOnDelete();
            $table->foreignId('sales_order_id')->nullable()->constrained('sales_orders')->nullOnDelete();
            $table->foreignId('outbound_order_id')->nullable()->constrained('outbound_orders')->nullOnDelete();
            $table->foreignId('fulfillment_group_id')->nullable()->constrained('fulfillment_groups')->nullOnDelete();
            $table->string('return_no')->unique();
            $table->string('status')->default('announced');
            $table->string('return_type')->default('customer_return');
            $table->string('return_reason')->nullable();
            $table->text('reason_note')->nullable();
            $table->string('external_return_id')->nullable();
            $table->string('original_order_no')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('sender_name')->nullable();
            $table->string('sender_phone')->nullable();
            $table->string('shipping_method')->nullable();
            $table->string('tracking_no')->nullable();
            $table->string('payment_type')->default('unknown');
            $table->decimal('collect_amount', 12, 2)->nullable();
            $table->string('collect_currency', 3)->default('JPY');
            $table->unsignedInteger('package_count')->nullable();
            $table->date('expected_arrival_date')->nullable();
            $table->timestamp('arrived_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('inspected_at')->nullable();
            $table->timestamp('dispositioned_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('arrived_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('received_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('inspected_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('dispositioned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'return_type']);
            $table->index(['warehouse_id', 'status']);
            $table->index(['tracking_no']);
            $table->index(['external_return_id']);
            $table->index(['expected_arrival_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('return_orders');
    }
};
