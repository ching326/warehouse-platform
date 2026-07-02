<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('role')->nullable()->after('user_type');
            $table->index(['user_type', 'role']);
        });

        DB::table('users')
            ->where('user_type', 'internal')
            ->whereNull('role')
            ->update(['role' => 'internal_admin']);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['user_type', 'role']);
            $table->dropColumn('role');
        });
    }
};
