<?php

use App\Actions\BackfillShippingMethodIds;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carriers', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('country_code', 2)->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
        });

        Schema::create('shipping_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('carrier_id')->constrained()->restrictOnDelete();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('service_type')->nullable();
            $table->boolean('is_trackable')->default(true);
            $table->boolean('requires_size')->default(false);
            $table->boolean('requires_zone')->default(false);
            $table->boolean('supports_courier_csv')->default(true);
            $table->string('status')->default('active');
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['carrier_id', 'status']);
        });

        Schema::table('sales_orders', function (Blueprint $table) {
            $table->foreignId('shipping_method_id')
                ->nullable()
                ->after('shipping_method')
                ->constrained('shipping_methods')
                ->restrictOnDelete();
        });

        Schema::create('shipping_method_marketplace_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipping_method_id')->constrained()->cascadeOnDelete();
            $table->string('platform');
            $table->string('marketplace')->default('');
            $table->string('carrier_code')->nullable();
            $table->string('carrier_name')->nullable();
            $table->string('service_code')->nullable();
            $table->string('service_name')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['shipping_method_id', 'platform', 'marketplace'], 'shipping_method_marketplace_unique');
        });

        Schema::create('shipping_method_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipping_method_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('rate_type')->default('flat');
            $table->string('currency', 3)->default('JPY');
            $table->decimal('price', 10, 2)->nullable();
            $table->string('size_code')->nullable();
            $table->string('origin_zone')->nullable();
            $table->string('destination_zone')->nullable();
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->string('status')->default('active');
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['shipping_method_id', 'tenant_id', 'status']);
            $table->index(['rate_type', 'size_code', 'destination_zone']);
        });

        $this->insertCanonicalRows();
        app(BackfillShippingMethodIds::class)();
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_method_rates');
        Schema::dropIfExists('shipping_method_marketplace_mappings');

        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shipping_method_id');
        });

        Schema::dropIfExists('shipping_methods');
        Schema::dropIfExists('carriers');
    }

    private function insertCanonicalRows(): void
    {
        $now = now();
        $carriers = [
            ['code' => 'yamato', 'name' => 'Yamato', 'country_code' => 'JP'],
            ['code' => 'sagawa', 'name' => 'Sagawa', 'country_code' => 'JP'],
            ['code' => 'japan_post', 'name' => 'Japan Post', 'country_code' => 'JP'],
            ['code' => 'other', 'name' => 'Other', 'country_code' => null],
        ];

        foreach ($carriers as $carrier) {
            DB::table('carriers')->updateOrInsert(
                ['code' => $carrier['code']],
                $carrier + ['status' => 'active', 'created_at' => $now, 'updated_at' => $now],
            );
        }

        $carrierIds = DB::table('carriers')->pluck('id', 'code');
        $methods = [
            ['carrier' => 'yamato', 'code' => 'yamato_nekopos', 'name' => 'Yamato Nekopos', 'service_type' => 'mail', 'requires_size' => false, 'requires_zone' => false],
            ['carrier' => 'yamato', 'code' => 'yamato_tqb', 'name' => 'Yamato TQB', 'service_type' => 'parcel', 'requires_size' => true, 'requires_zone' => true],
            ['carrier' => 'yamato', 'code' => 'yamato_compact', 'name' => 'Yamato Compact', 'service_type' => 'compact', 'requires_size' => false, 'requires_zone' => true],
            ['carrier' => 'sagawa', 'code' => 'sagawa_thb', 'name' => 'Sagawa THB', 'service_type' => 'parcel', 'requires_size' => true, 'requires_zone' => true],
            ['carrier' => 'japan_post', 'code' => 'japan_post_yupack', 'name' => 'Japan Post Yu-Pack', 'service_type' => 'parcel', 'requires_size' => true, 'requires_zone' => true],
            ['carrier' => 'other', 'code' => 'other', 'name' => 'Other', 'service_type' => 'other', 'requires_size' => false, 'requires_zone' => false],
        ];

        foreach ($methods as $method) {
            DB::table('shipping_methods')->updateOrInsert(
                ['code' => $method['code']],
                [
                    'carrier_id' => $carrierIds[$method['carrier']],
                    'name' => $method['name'],
                    'service_type' => $method['service_type'],
                    'is_trackable' => true,
                    'requires_size' => $method['requires_size'],
                    'requires_zone' => $method['requires_zone'],
                    'supports_courier_csv' => $method['carrier'] !== 'other',
                    'status' => 'active',
                    'note' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }

        $nekoposId = DB::table('shipping_methods')->where('code', 'yamato_nekopos')->value('id');
        DB::table('shipping_method_rates')->updateOrInsert(
            ['shipping_method_id' => $nekoposId, 'tenant_id' => null, 'rate_type' => 'flat', 'currency' => 'JPY'],
            ['price' => 198, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
        );
    }
};
