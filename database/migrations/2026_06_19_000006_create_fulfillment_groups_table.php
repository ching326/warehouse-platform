<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fulfillment_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->string('reference_no')->unique();
            $table->string('status')->default('reserved');
            $table->string('ship_together_key');
            $table->string('recipient_name')->nullable();
            $table->string('recipient_phone')->nullable();
            $table->string('recipient_country_code', 2)->nullable();
            $table->string('recipient_postal_code')->nullable();
            $table->string('recipient_state')->nullable();
            $table->string('recipient_city')->nullable();
            $table->string('recipient_address_line1')->nullable();
            $table->string('recipient_address_line2')->nullable();
            $table->string('courier')->nullable();
            $table->string('tracking_no')->nullable();
            $table->text('note')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->foreignId('shipped_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['ship_together_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fulfillment_groups');
    }
};
