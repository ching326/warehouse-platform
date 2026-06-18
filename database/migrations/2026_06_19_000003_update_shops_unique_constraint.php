<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'code']);
        });

        DB::table('shops')->whereNull('marketplace')->update(['marketplace' => '']);

        Schema::table('shops', function (Blueprint $table) {
            $table->string('marketplace')->default('')->nullable(false)->change();
            $table->unique(['tenant_id', 'platform', 'marketplace', 'code']);
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'platform', 'marketplace', 'code']);
            $table->string('marketplace')->nullable()->change();
            $table->unique(['tenant_id', 'code']);
        });
    }
};
