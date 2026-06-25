<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outbound_orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('fulfillment_group_id');
        });

        // Drop the foreign keys before the indexes: MySQL refuses to drop an
        // index that still backs an FK constraint.
        Schema::table('fulfillment_pack_scans', function (Blueprint $table): void {
            $table->dropForeign(['fulfillment_group_id']);
            $table->dropForeign(['fulfillment_group_order_id']);
        });

        Schema::table('fulfillment_pack_scans', function (Blueprint $table): void {
            $table->dropIndex('fulfillment_pack_scans_tenant_id_fulfillment_group_id_index');
            $table->dropIndex('fulfillment_pack_scans_fulfillment_group_id_created_at_index');
            $table->dropIndex('pack_scans_group_result_item_idx');
            $table->dropColumn(['fulfillment_group_id', 'fulfillment_group_order_id']);
        });

        Schema::table('issues', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('fulfillment_group_id');
        });

        Schema::table('return_orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('fulfillment_group_id');
        });

        Schema::table('sales_orders', function (Blueprint $table): void {
            $table->dropColumn('courier_csv_exported_at');
        });

        Schema::dropIfExists('fulfillment_group_orders');
        Schema::dropIfExists('fulfillment_groups');
    }

    public function down(): void
    {
        Schema::create('fulfillment_groups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('shipping_method_id')->nullable()->constrained('shipping_methods')->nullOnDelete();
            $table->string('reference_no')->nullable();
            $table->string('status');
            $table->string('ship_together_key')->nullable();
            $table->string('recipient_name')->nullable();
            $table->string('recipient_phone')->nullable();
            $table->string('recipient_country_code', 2)->nullable();
            $table->string('recipient_postal_code')->nullable();
            $table->string('recipient_state')->nullable();
            $table->string('recipient_city')->nullable();
            $table->string('recipient_address_line1')->nullable();
            $table->string('recipient_address_line2')->nullable();
            $table->string('courier')->nullable();
            $table->string('tracking_no')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->foreignId('shipped_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('fulfillment_group_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fulfillment_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_order_id')->constrained()->cascadeOnDelete();
            $table->string('courier')->nullable();
            $table->string('tracking_no')->nullable();
            $table->timestamp('arranged_at')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamps();
        });

        Schema::table('sales_orders', function (Blueprint $table): void {
            $table->timestamp('courier_csv_exported_at')->nullable()->after('tracking_no');
        });

        Schema::table('return_orders', function (Blueprint $table): void {
            $table->foreignId('fulfillment_group_id')->nullable()->after('outbound_order_id')->constrained('fulfillment_groups')->nullOnDelete();
        });

        Schema::table('issues', function (Blueprint $table): void {
            $table->foreignId('fulfillment_group_id')->nullable()->after('sales_order_id')->constrained('fulfillment_groups')->nullOnDelete();
        });

        Schema::table('fulfillment_pack_scans', function (Blueprint $table): void {
            $table->foreignId('fulfillment_group_id')->nullable()->after('tenant_id')->constrained('fulfillment_groups')->cascadeOnDelete();
            $table->foreignId('fulfillment_group_order_id')->nullable()->after('outbound_order_id')->constrained('fulfillment_group_orders')->nullOnDelete();
        });

        Schema::table('outbound_orders', function (Blueprint $table): void {
            $table->foreignId('fulfillment_group_id')->nullable()->after('id')->constrained('fulfillment_groups')->nullOnDelete();
        });
    }
};
