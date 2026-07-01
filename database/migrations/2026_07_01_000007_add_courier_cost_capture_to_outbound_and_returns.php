<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outbound_orders', function (Blueprint $table): void {
            $table->decimal('courier_cost', 12, 2)->nullable()->after('package_weight_g');
            $table->string('courier_cost_currency', 3)->nullable()->after('courier_cost');
            $table->foreignId('courier_cost_updated_by_user_id')->nullable()->after('courier_cost_currency')->constrained('users')->nullOnDelete();
            $table->timestamp('courier_cost_updated_at')->nullable()->after('courier_cost_updated_by_user_id');
        });

        Schema::table('return_order_costs', function (Blueprint $table): void {
            $table->timestamp('cost_incurred_at')->nullable()->after('amount');
        });

        DB::table('return_order_costs')
            ->leftJoin('return_orders', 'return_order_costs.return_order_id', '=', 'return_orders.id')
            ->select([
                'return_order_costs.id',
                'return_order_costs.created_at',
                'return_orders.received_at',
            ])
            ->orderBy('return_order_costs.id')
            ->chunkById(100, function ($rows): void {
                foreach ($rows as $row) {
                    DB::table('return_order_costs')
                        ->where('id', $row->id)
                        ->update([
                            'cost_incurred_at' => $row->received_at ?? $row->created_at ?? now(),
                        ]);
                }
            }, 'return_order_costs.id', 'id');
    }

    public function down(): void
    {
        Schema::table('return_order_costs', function (Blueprint $table): void {
            $table->dropColumn('cost_incurred_at');
        });

        Schema::table('outbound_orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('courier_cost_updated_by_user_id');
            $table->dropColumn(['courier_cost', 'courier_cost_currency', 'courier_cost_updated_at']);
        });
    }
};
