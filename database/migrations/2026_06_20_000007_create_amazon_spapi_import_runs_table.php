<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('amazon_spapi_connections', function (Blueprint $table) {
            $table->timestamp('last_orders_imported_at')->nullable()->after('last_error');
            $table->timestamp('last_orders_import_window_from')->nullable()->after('last_orders_imported_at');
            $table->timestamp('last_orders_import_window_to')->nullable()->after('last_orders_import_window_from');
            $table->string('last_orders_import_status')->nullable()->after('last_orders_import_window_to');
            $table->text('last_orders_import_error')->nullable()->after('last_orders_import_status');
        });

        Schema::create('amazon_spapi_import_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('amazon_spapi_connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('triggered_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('mode')->default('manual');
            $table->string('status')->default('previewed');
            $table->string('window_type')->default('last_updated');
            $table->timestamp('window_from')->nullable();
            $table->timestamp('window_to')->nullable();
            $table->unsignedInteger('api_order_count')->default(0);
            $table->unsignedInteger('api_line_count')->default(0);
            $table->unsignedInteger('new_order_count')->default(0);
            $table->unsignedInteger('new_line_count')->default(0);
            $table->unsignedInteger('duplicate_order_count')->default(0);
            $table->unsignedInteger('missing_sku_count')->default(0);
            $table->unsignedInteger('cancel_requested_count')->default(0);
            $table->unsignedInteger('imported_order_count')->default(0);
            $table->unsignedInteger('skipped_order_count')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'shop_id', 'created_at'], 'amazon_spapi_runs_tenant_shop_created_idx');
            $table->index('status');
            $table->index('amazon_spapi_connection_id', 'amazon_spapi_runs_connection_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('amazon_spapi_import_runs');

        Schema::table('amazon_spapi_connections', function (Blueprint $table) {
            $table->dropColumn([
                'last_orders_imported_at',
                'last_orders_import_window_from',
                'last_orders_import_window_to',
                'last_orders_import_status',
                'last_orders_import_error',
            ]);
        });
    }
};
