<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('outbound_orders', 'courier_csv_exported_at')
            || Schema::hasColumn('outbound_orders', 'courier_label_exported_at')) {
            return;
        }

        Schema::table('outbound_orders', function (Blueprint $table): void {
            $table->renameColumn('courier_csv_exported_at', 'courier_label_exported_at');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('outbound_orders', 'courier_label_exported_at')
            || Schema::hasColumn('outbound_orders', 'courier_csv_exported_at')) {
            return;
        }

        Schema::table('outbound_orders', function (Blueprint $table): void {
            $table->renameColumn('courier_label_exported_at', 'courier_csv_exported_at');
        });
    }
};
