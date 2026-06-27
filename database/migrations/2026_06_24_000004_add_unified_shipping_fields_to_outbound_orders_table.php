<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('outbound_orders', 'reason')) {
            Schema::table('outbound_orders', function (Blueprint $table) {
                $table->string('reason')->nullable()->after('fulfillment_group_id');
                $table->foreignId('source_sales_order_id')
                    ->nullable()
                    ->after('reason')
                    ->constrained('sales_orders')
                    ->nullOnDelete();
                $table->timestamp('courier_csv_exported_at')->nullable()->after('source_sales_order_id');
                $table->foreignId('shipping_method_id')
                    ->nullable()
                    ->after('courier_csv_exported_at')
                    ->constrained('shipping_methods')
                    ->nullOnDelete();
            });
        }

        DB::table('outbound_orders')
            ->whereNotNull('fulfillment_group_id')
            ->update([
                'reason' => 'customer_order',
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasColumn('outbound_orders', 'reason')) {
            return;
        }

        Schema::table('outbound_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shipping_method_id');
            $table->dropColumn('courier_csv_exported_at');
            $table->dropConstrainedForeignId('source_sales_order_id');
            $table->dropColumn('reason');
        });
    }
};
