<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbound_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->string('ref')->nullable();
            $table->string('status');
            $table->date('expected_ship_at')->nullable();
            $table->text('note')->nullable();
            $table->string('recipient_name')->nullable();
            $table->string('recipient_phone')->nullable();
            $table->string('recipient_country_code', 2)->nullable();
            $table->string('recipient_postal_code')->nullable();
            $table->string('recipient_state')->nullable();
            $table->string('recipient_city')->nullable();
            $table->string('recipient_address_line1')->nullable();
            $table->string('recipient_address_line2')->nullable();
            $table->string('shipping_method')->nullable();
            $table->string('courier')->nullable();
            $table->string('tracking_no')->nullable();
            $table->unsignedSmallInteger('package_count')->nullable();
            $table->unsignedInteger('package_weight_g')->nullable();
            $table->text('ship_note')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->foreignId('shipped_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['warehouse_id']);
            $table->index(['created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbound_orders');
    }
};
