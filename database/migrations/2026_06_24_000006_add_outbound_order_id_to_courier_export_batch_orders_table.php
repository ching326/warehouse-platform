<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courier_export_batch_orders', function (Blueprint $table): void {
            $table->foreignId('outbound_order_id')
                ->nullable()
                ->after('sales_order_id')
                ->constrained('outbound_orders')
                ->cascadeOnDelete();
        });

        Schema::table('courier_export_batch_orders', function (Blueprint $table): void {
            $table->foreignId('sales_order_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('courier_export_batch_orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('outbound_order_id');
        });

        Schema::table('courier_export_batch_orders', function (Blueprint $table): void {
            $table->foreignId('sales_order_id')->nullable(false)->change();
        });
    }
};
