<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outbound_orders', function (Blueprint $table): void {
            $table->string('hold_status')->default('active')->after('status');
            $table->timestamp('held_at')->nullable()->after('hold_status');
            $table->foreignId('held_by_user_id')->nullable()->after('held_at')->constrained('users')->nullOnDelete();
            $table->string('held_from')->nullable()->after('held_by_user_id');
            $table->text('hold_reason')->nullable()->after('held_from');
            $table->timestamp('released_at')->nullable()->after('hold_reason');
            $table->foreignId('released_by_user_id')->nullable()->after('released_at')->constrained('users')->nullOnDelete();

            $table->index(['tenant_id', 'hold_status']);
            $table->index(['status', 'hold_status']);
        });
    }

    public function down(): void
    {
        Schema::table('outbound_orders', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'hold_status']);
            $table->dropIndex(['status', 'hold_status']);
            $table->dropConstrainedForeignId('held_by_user_id');
            $table->dropConstrainedForeignId('released_by_user_id');
            $table->dropColumn([
                'hold_status',
                'held_at',
                'held_from',
                'hold_reason',
                'released_at',
            ]);
        });
    }
};
