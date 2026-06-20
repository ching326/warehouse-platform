<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('amazon_spapi_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shop_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('seller_id');
            $table->string('marketplace_id');
            $table->string('region');
            $table->string('endpoint');
            $table->string('lwa_client_id');
            $table->text('lwa_client_secret');
            $table->text('refresh_token');
            $table->boolean('sync_enabled')->default(false);
            $table->string('status')->default('not_tested');
            $table->timestamp('last_tested_at')->nullable();
            $table->timestamp('last_test_successful_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamps();

            $table->index('tenant_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('amazon_spapi_connections');
    }
};
