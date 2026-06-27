<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_order_paste_import_mappings', function (Blueprint $table) {
            $table->boolean('is_default')->default(false)->after('data_start_row')->index();
        });
    }

    public function down(): void
    {
        Schema::table('sales_order_paste_import_mappings', function (Blueprint $table) {
            $table->dropColumn('is_default');
        });
    }
};
