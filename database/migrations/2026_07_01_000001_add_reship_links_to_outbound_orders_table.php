<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outbound_orders', function (Blueprint $table): void {
            $table->foreignId('reship_of_outbound_id')
                ->nullable()
                ->after('source_sales_order_id')
                ->constrained('outbound_orders')
                ->nullOnDelete();

            $table->foreignId('issue_id')
                ->nullable()
                ->after('reship_of_outbound_id')
                ->constrained('issues')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('outbound_orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('issue_id');
            $table->dropConstrainedForeignId('reship_of_outbound_id');
        });
    }
};
