<?php

namespace Tests\Feature;

use App\Actions\BackfillShippingMethodIds;
use App\Livewire\SalesOrderIndex;
use App\Livewire\ShippingMethodCreate;
use App\Livewire\ShippingMethodEdit;
use App\Livewire\ShippingMethodIndex;
use App\Models\Carrier;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\ShippingMethod;
use App\Models\ShippingMethodMarketplaceMapping;
use App\Models\ShippingMethodRate;
use App\Models\Shop;
use App\Models\Sku;
use App\Models\StockItem;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\Courier\CourierExportService;
use App\Services\ShippingRateResolver;
use App\Support\CourierCarrier;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class ShippingMethodTest extends TestCase
{
    use RefreshDatabase;

    public function test_shipping_method_crud_creates_method_with_flat_fee(): void
    {
        $carrier = Carrier::where('code', 'yamato')->firstOrFail();

        Livewire::actingAs($this->internalUser())
            ->test(ShippingMethodCreate::class)
            ->set('carrierId', (string) $carrier->id)
            ->set('code', 'Yamato Test Method')
            ->set('name', 'Yamato Test Method')
            ->set('serviceType', 'parcel')
            ->set('flatFee', '350')
            ->set('currency', 'jpy')
            ->call('save')
            ->assertRedirect(route('setup.shipping-methods.index'));

        $method = ShippingMethod::where('code', 'yamato_test_method')->firstOrFail();

        $this->assertSame($carrier->id, $method->carrier_id);
        $this->assertDatabaseHas('shipping_method_rates', [
            'shipping_method_id' => $method->id,
            'tenant_id' => null,
            'rate_type' => 'flat',
            'currency' => 'JPY',
            'price' => 350,
            'status' => 'active',
        ]);
    }

    public function test_shipping_method_code_is_unique(): void
    {
        $carrier = Carrier::where('code', 'yamato')->firstOrFail();

        Livewire::actingAs($this->internalUser())
            ->test(ShippingMethodCreate::class)
            ->set('carrierId', (string) $carrier->id)
            ->set('code', 'Yamato TQB')
            ->set('name', 'Duplicate TQB')
            ->call('save')
            ->assertHasErrors(['code']);

        $this->assertSame(1, ShippingMethod::where('code', 'yamato_tqb')->count());
    }

    public function test_shipping_method_can_store_marketplace_mapping(): void
    {
        $carrier = Carrier::where('code', 'yamato')->firstOrFail();

        Livewire::actingAs($this->internalUser())
            ->test(ShippingMethodCreate::class)
            ->set('carrierId', (string) $carrier->id)
            ->set('code', 'yamato_amazon_test')
            ->set('name', 'Yamato Amazon Test')
            ->set('mappingPlatform', 'Amazon')
            ->set('mappingMarketplace', 'jp')
            ->set('mappingCarrierCode', 'YAMATO')
            ->set('mappingCarrierName', 'Yamato Transport')
            ->set('mappingServiceName', 'Nekopos')
            ->call('save')
            ->assertRedirect(route('setup.shipping-methods.index'));

        $method = ShippingMethod::where('code', 'yamato_amazon_test')->firstOrFail();
        $this->assertDatabaseHas('shipping_method_marketplace_mappings', [
            'shipping_method_id' => $method->id,
            'platform' => 'amazon',
            'marketplace' => 'JP',
            'carrier_code' => 'YAMATO',
            'carrier_name' => 'Yamato Transport',
            'service_name' => 'Nekopos',
        ]);
    }

    public function test_sales_order_index_uses_active_shipping_methods(): void
    {
        [, $shop, $sku] = $this->salesSku();
        $this->order($shop, $sku);

        $active = ShippingMethod::where('code', 'yamato_nekopos')->firstOrFail();
        $inactive = ShippingMethod::where('code', 'yamato_tqb')->firstOrFail();
        $inactive->update(['status' => 'inactive']);

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderIndex::class)
            ->assertSee($active->name)
            ->assertDontSee($inactive->name);
    }

    public function test_rate_lookup_prefers_tenant_specific_rate(): void
    {
        $tenant = Tenant::factory()->create();
        $method = ShippingMethod::where('code', 'yamato_nekopos')->firstOrFail();

        ShippingMethodRate::create([
            'shipping_method_id' => $method->id,
            'tenant_id' => $tenant->id,
            'rate_type' => 'flat',
            'currency' => 'JPY',
            'price' => 250,
            'effective_from' => '2026-01-01',
            'status' => 'active',
        ]);

        $rate = app(ShippingRateResolver::class)->resolve($tenant->id, $method, '2026-06-20');

        $this->assertSame($tenant->id, $rate?->tenant_id);
        $this->assertSame('250.00', (string) $rate?->price);
    }

    public function test_rate_lookup_falls_back_to_default_rate(): void
    {
        $tenant = Tenant::factory()->create();
        $method = ShippingMethod::where('code', 'yamato_nekopos')->firstOrFail();

        $rate = app(ShippingRateResolver::class)->resolve($tenant->id, $method, '2026-06-20');

        $this->assertNull($rate?->tenant_id);
        $this->assertSame('198.00', (string) $rate?->price);
    }

    public function test_rate_lookup_uses_latest_effective_matching_rate(): void
    {
        $method = ShippingMethod::where('code', 'yamato_tqb')->firstOrFail();

        ShippingMethodRate::create([
            'shipping_method_id' => $method->id,
            'tenant_id' => null,
            'rate_type' => 'flat',
            'currency' => 'JPY',
            'price' => 900,
            'effective_from' => '2026-01-01',
            'status' => 'active',
        ]);
        ShippingMethodRate::create([
            'shipping_method_id' => $method->id,
            'tenant_id' => null,
            'rate_type' => 'flat',
            'currency' => 'JPY',
            'price' => 950,
            'effective_from' => '2026-03-01',
            'status' => 'active',
        ]);

        $rate = app(ShippingRateResolver::class)->resolve(null, $method->id, '2026-06-20');

        $this->assertSame('950.00', (string) $rate?->price);
    }

    public function test_marketplace_mapping_uses_empty_marketplace_for_default_and_is_unique(): void
    {
        $method = ShippingMethod::where('code', 'yamato_nekopos')->firstOrFail();

        ShippingMethodMarketplaceMapping::create([
            'shipping_method_id' => $method->id,
            'platform' => 'amazon',
            'marketplace' => '',
            'carrier_code' => 'YAMATO',
        ]);

        $this->assertDatabaseHas('shipping_method_marketplace_mappings', [
            'shipping_method_id' => $method->id,
            'platform' => 'amazon',
            'marketplace' => '',
        ]);

        $this->expectException(QueryException::class);
        ShippingMethodMarketplaceMapping::create([
            'shipping_method_id' => $method->id,
            'platform' => 'amazon',
            'marketplace' => '',
            'carrier_code' => 'YAMATO',
        ]);
    }

    public function test_legacy_shipping_method_backfill_action_sets_method_id(): void
    {
        [, $shop, $sku] = $this->salesSku();
        $order = $this->order($shop, $sku, ['shipping_method' => CourierCarrier::YAMATO, 'shipping_method_id' => null]);

        app(BackfillShippingMethodIds::class)();

        $this->assertSame(
            ShippingMethod::where('code', 'yamato_tqb')->firstOrFail()->id,
            $order->refresh()->shipping_method_id,
        );
    }

    public function test_shipping_method_setup_pages_are_internal_only(): void
    {
        $method = ShippingMethod::where('code', 'yamato_nekopos')->firstOrFail();

        $this->actingAs($this->internalUser())->get('/setup/shipping-methods')->assertOk();
        $this->actingAs($this->internalUser())->get('/setup/shipping-methods/create')->assertOk();
        $this->actingAs($this->internalUser())->get("/setup/shipping-methods/{$method->id}/edit")->assertOk();

        $tenantUser = $this->tenantUser();
        $this->actingAs($tenantUser)->get('/setup/shipping-methods')->assertForbidden();
        $this->actingAs($tenantUser)->get('/setup/shipping-methods/create')->assertForbidden();
        $this->actingAs($tenantUser)->get("/setup/shipping-methods/{$method->id}/edit")->assertForbidden();

        Livewire::actingAs($tenantUser)->test(ShippingMethodCreate::class)->assertForbidden();
        Livewire::actingAs($tenantUser)->test(ShippingMethodEdit::class, ['method' => $method])->assertForbidden();
        Livewire::actingAs($tenantUser)->test(ShippingMethodIndex::class)->assertForbidden();
    }

    public function test_shipping_method_index_can_create_and_update_carrier(): void
    {
        Livewire::actingAs($this->internalUser())
            ->test(ShippingMethodIndex::class)
            ->set('carrierCode', 'SF Express')
            ->set('carrierName', 'SF Express')
            ->set('carrierCountryCode', 'cn')
            ->call('saveCarrier')
            ->assertSet('carrierCode', '')
            ->assertSee('SF Express');

        $carrier = Carrier::where('code', 'sf_express')->firstOrFail();

        $this->assertSame('SF Express', $carrier->name);
        $this->assertSame('CN', $carrier->country_code);
        $this->assertSame('active', $carrier->status);

        Livewire::actingAs($this->internalUser())
            ->test(ShippingMethodIndex::class)
            ->call('editCarrier', $carrier->id)
            ->assertSet('carrierCode', 'sf_express')
            ->set('carrierName', 'SF International')
            ->set('carrierCountryCode', 'hk')
            ->set('carrierStatus', 'inactive')
            ->call('saveCarrier')
            ->assertSet('editingCarrierId', null);

        $carrier->refresh();
        $this->assertSame('SF International', $carrier->name);
        $this->assertSame('HK', $carrier->country_code);
        $this->assertSame('inactive', $carrier->status);
    }

    public function test_shipping_method_index_rejects_duplicate_carrier_code(): void
    {
        Livewire::actingAs($this->internalUser())
            ->test(ShippingMethodIndex::class)
            ->set('carrierCode', 'Yamato')
            ->set('carrierName', 'Duplicate Yamato')
            ->call('saveCarrier')
            ->assertHasErrors(['carrier_code']);

        $this->assertSame(1, Carrier::where('code', 'yamato')->count());
    }

    public function test_shipping_method_index_can_deactivate_carrier_without_deleting_it(): void
    {
        $carrier = Carrier::where('code', 'japan_post')->firstOrFail();

        Livewire::actingAs($this->internalUser())
            ->test(ShippingMethodIndex::class)
            ->call('toggleCarrierStatus', $carrier->id)
            ->assertSee(__('shipping.carrier_status_updated'));

        $this->assertSame('inactive', $carrier->refresh()->status);
        $this->assertDatabaseHas('carriers', ['id' => $carrier->id]);
    }

    public function test_courier_export_accepts_method_by_carrier(): void
    {
        [$tenant, $shop, $sku] = $this->salesSku();
        $method = ShippingMethod::where('code', 'yamato_nekopos')->firstOrFail();
        $order = $this->order($shop, $sku, [
            'shipping_method_id' => $method->id,
            'shipping_method' => null,
        ]);

        $yamato = app(CourierExportService::class)->validateExport([$order->id], CourierCarrier::YAMATO, [$tenant->id]);
        $sagawa = app(CourierExportService::class)->validateExport([$order->id], CourierCarrier::SAGAWA, [$tenant->id]);

        $this->assertTrue($yamato->ok);
        $this->assertFalse($sagawa->ok);
        $this->assertSame([$order->id], $sagawa->wrongCarrierOrderIds);
    }

    public function test_courier_export_hard_blocks_method_without_courier_csv_support(): void
    {
        [$tenant, $shop, $sku] = $this->salesSku();
        $method = ShippingMethod::where('code', 'yamato_nekopos')->firstOrFail();
        $method->update(['supports_courier_csv' => false]);
        $methodOrder = $this->order($shop, $sku, [
            'shipping_method_id' => $method->id,
            'shipping_method' => CourierCarrier::YAMATO,
        ]);
        $legacyOrder = $this->order($shop, $sku, [
            'shipping_method_id' => null,
            'shipping_method' => CourierCarrier::YAMATO,
            'platform_order_id' => 'LEGACY-CSV-OK',
        ]);

        $methodResult = app(CourierExportService::class)->validateExport([$methodOrder->id], CourierCarrier::YAMATO, [$tenant->id]);
        $legacyResult = app(CourierExportService::class)->validateExport([$legacyOrder->id], CourierCarrier::YAMATO, [$tenant->id]);

        $this->assertFalse($methodResult->ok);
        $this->assertTrue($methodResult->hasHardBlock());
        $this->assertSame([$methodOrder->id], $methodResult->unsupportedCourierOrderIds);
        $this->assertTrue($legacyResult->ok);
    }

    public function test_shipping_method_in_use_cannot_be_deleted(): void
    {
        [, $shop, $sku] = $this->salesSku();
        $method = ShippingMethod::where('code', 'yamato_nekopos')->firstOrFail();
        $order = $this->order($shop, $sku, [
            'shipping_method_id' => $method->id,
            'shipping_method' => CourierCarrier::YAMATO,
        ]);

        try {
            $method->delete();
            $this->fail('Shipping method deletion should be blocked.');
        } catch (RuntimeException) {
            $this->assertDatabaseHas('shipping_methods', ['id' => $method->id]);
        }

        $method->refresh()->update(['status' => 'inactive']);
        $this->assertTrue($order->refresh()->shippingMethod->is($method));

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderIndex::class)
            ->assertDontSee($method->name);
    }

    private function salesSku(): array
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        $shop = Shop::factory()->for($tenant)->create(['status' => 'active']);
        $stockItem = StockItem::factory()->for($tenant)->create();
        $sku = Sku::factory()->for($tenant)->for($shop)->for($stockItem)->create(['sku_type' => 'single']);

        return [$tenant, $shop, $sku];
    }

    private function order(Shop $shop, Sku $sku, array $attributes = []): SalesOrder
    {
        $order = SalesOrder::factory()->create(array_merge([
            'tenant_id' => $shop->tenant_id,
            'shop_id' => $shop->id,
            'order_status' => SalesOrder::ORDER_STATUS_PENDING,
            'fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_READY,
        ], $attributes));

        $order->lines()->create([
            'sku_id' => $sku->id,
            'quantity' => 1,
            'line_status' => SalesOrderLine::STATUS_READY,
        ]);

        return $order;
    }

    private function internalUser(): User
    {
        return User::factory()->create([
            'user_type' => 'internal',
            'is_active' => true,
        ]);
    }

    private function tenantUser(): User
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create([
            'user_type' => 'tenant',
            'is_active' => true,
        ]);

        TenantUser::factory()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'status' => 'active',
        ]);

        return $user;
    }
}
