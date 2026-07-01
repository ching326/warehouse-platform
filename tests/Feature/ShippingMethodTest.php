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
            ->set('nameJa', 'ヤマトテスト便')
            ->set('nameZhTw', '黑貓測試配送')
            ->set('nameZhCn', '黑猫测试配送')
            ->set('serviceType', 'parcel')
            ->set('selectionPriority', '25')
            ->set('flatFee', '350')
            ->set('currency', 'jpy')
            ->call('save')
            ->assertRedirect(route('setup.shipping-methods.index'));

        $method = ShippingMethod::where('code', 'yamato_test_method')->firstOrFail();

        $this->assertSame($carrier->id, $method->carrier_id);
        $this->assertSame('ヤマトテスト便', $method->name_ja);
        $this->assertSame('黑貓測試配送', $method->name_zh_tw);
        $this->assertSame('黑猫测试配送', $method->name_zh_cn);
        $this->assertSame(25, $method->selection_priority);
        $this->assertDatabaseHas('shipping_method_rates', [
            'shipping_method_id' => $method->id,
            'tenant_id' => null,
            'rate_type' => 'flat',
            'currency' => 'JPY',
            'price' => 350,
            'status' => 'active',
        ]);
    }

    public function test_shipping_method_display_name_uses_locale_name_with_fallback(): void
    {
        $method = ShippingMethod::where('code', 'yamato_nekopos')->firstOrFail();
        $method->update([
            'name' => 'Yamato Nekopos',
            'name_ja' => 'ネコポス',
            'name_zh_tw' => '黑貓投函',
            'name_zh_cn' => null,
        ]);

        $this->assertSame('ネコポス', $method->displayName('ja'));
        $this->assertSame('黑貓投函', $method->displayName('zh_TW'));
        $this->assertSame('Yamato Nekopos', $method->displayName('zh_CN'));

        app()->setLocale('ja');

        Livewire::actingAs($this->internalUser())
            ->test(ShippingMethodIndex::class)
            ->assertSee('ネコポス')
            ->assertSee('Yamato Nekopos');

        app()->setLocale('en');
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

    public function test_shipping_method_edit_can_add_multiple_marketplace_mappings(): void
    {
        $method = ShippingMethod::where('code', 'yamato_nekopos')->firstOrFail();

        Livewire::actingAs($this->internalUser())
            ->test(ShippingMethodEdit::class, ['method' => $method])
            ->assertSee('Amazon')
            ->assertSee('Rakuten')
            ->assertSee('JP')
            ->assertSee('US')
            ->assertSee('CA')
            ->set('mappingPlatform', 'amazon')
            ->set('mappingMarketplace', 'JP')
            ->set('mappingCarrierCode', 'YAMATO')
            ->set('mappingCarrierName', 'Yamato Transport')
            ->call('saveMarketplaceMapping')
            ->assertSet('mappingPlatform', '')
            ->assertSee(__('shipping.mapping_saved'))
            ->set('mappingPlatform', 'rakuten')
            ->set('mappingMarketplace', 'JP')
            ->set('mappingCarrierCode', '1001')
            ->set('mappingCarrierName', 'Yamato')
            ->call('saveMarketplaceMapping')
            ->assertSee('YAMATO')
            ->assertSee('1001');

        $this->assertDatabaseHas('shipping_method_marketplace_mappings', [
            'shipping_method_id' => $method->id,
            'platform' => 'amazon',
            'marketplace' => 'JP',
            'carrier_code' => 'YAMATO',
        ]);
        $this->assertDatabaseHas('shipping_method_marketplace_mappings', [
            'shipping_method_id' => $method->id,
            'platform' => 'rakuten',
            'marketplace' => 'JP',
            'carrier_code' => '1001',
        ]);
    }

    public function test_shipping_method_selection_priority_is_editable_and_validated(): void
    {
        $method = ShippingMethod::where('code', 'yamato_nekopos')->firstOrFail();

        Livewire::actingAs($this->internalUser())
            ->test(ShippingMethodEdit::class, ['method' => $method])
            ->assertSet('selectionPriority', (string) $method->selection_priority)
            ->set('selectionPriority', '65536')
            ->call('save')
            ->assertHasErrors(['selection_priority'])
            ->set('selectionPriority', '')
            ->call('save')
            ->assertRedirect(route('setup.shipping-methods.index'));

        $this->assertSame(0, $method->refresh()->selection_priority);

        Livewire::actingAs($this->internalUser())
            ->test(ShippingMethodEdit::class, ['method' => $method])
            ->set('selectionPriority', '35')
            ->call('save')
            ->assertRedirect(route('setup.shipping-methods.index'));

        $this->assertSame(35, $method->refresh()->selection_priority);
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

    public function test_shipping_method_order_controls_sales_order_dropdown_order(): void
    {
        [, $shop, $sku] = $this->salesSku();
        $this->order($shop, $sku);

        $yamato = Carrier::where('code', 'yamato')->firstOrFail();
        $japanPost = Carrier::where('code', 'japan_post')->firstOrFail();
        $compact = ShippingMethod::where('code', 'yamato_compact')->firstOrFail();
        $nekopos = ShippingMethod::where('code', 'yamato_nekopos')->firstOrFail();
        $tqb = ShippingMethod::where('code', 'yamato_tqb')->firstOrFail();

        Livewire::actingAs($this->internalUser())
            ->test(ShippingMethodIndex::class)
            ->set("carrierSortOrders.{$yamato->id}", '5')
            ->set("carrierSortOrders.{$japanPost->id}", '50')
            ->call('saveCarrierOrder')
            ->set("methodSortOrders.{$tqb->id}", '5')
            ->set("methodSortOrders.{$compact->id}", '20')
            ->set("methodSortOrders.{$nekopos->id}", '30')
            ->call('saveMethodOrder')
            ->assertSee(__('shipping.order_updated'));

        $html = Livewire::actingAs($this->internalUser())
            ->test(SalesOrderIndex::class)
            ->html();

        $this->assertStringOrder($html, 'Yamato TQB', 'Yamato Compact');
        $this->assertStringOrder($html, 'Yamato Compact', 'Yamato Nekopos');
        $this->assertStringOrder($html, 'Yamato Nekopos', 'Japan Post Yu-Pack');
    }

    public function test_shipping_method_index_edits_selection_priority(): void
    {
        $method = ShippingMethod::where('code', 'yamato_nekopos')->firstOrFail();

        Livewire::actingAs($this->internalUser())
            ->test(ShippingMethodIndex::class)
            ->assertSee(__('shipping.field_selection_priority'))
            ->assertSet("methodSelectionPriorities.{$method->id}", (string) $method->selection_priority)
            ->set("methodSelectionPriorities.{$method->id}", '65536')
            ->call('saveMethodOrder')
            ->assertHasErrors(["methodSelectionPriorities.{$method->id}"])
            ->set("methodSelectionPriorities.{$method->id}", '45')
            ->call('saveMethodOrder')
            ->assertSee(__('shipping.order_updated'));

        $this->assertSame(45, $method->refresh()->selection_priority);
    }

    public function test_shipping_method_index_search_filters_methods_with_ordered_scope(): void
    {
        Livewire::actingAs($this->internalUser())
            ->test(ShippingMethodIndex::class)
            ->set('search', 'Nekopos')
            ->assertSee('Yamato Nekopos')
            ->assertDontSee('Sagawa THB');
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
        $yamato = Carrier::where('code', 'yamato')->firstOrFail();

        Livewire::actingAs($this->internalUser())
            ->test(ShippingMethodIndex::class)
            ->assertSee('Yamato (yamato)')
            ->assertSee('Sagawa (sagawa)')
            ->assertSee('Japan Post (japan_post)')
            ->call('editCarrier', $yamato->id)
            ->assertSet('carrierCode', 'yamato')
            ->set('carrierName', 'Yamato Transport')
            ->set('carrierCountryCode', 'jp')
            ->set('carrierStatus', 'inactive')
            ->call('saveCarrier')
            ->assertSet('editingCarrierId', null);

        $yamato->refresh();
        $this->assertSame('Yamato Transport', $yamato->name);
        $this->assertSame('JP', $yamato->country_code);
        $this->assertSame('inactive', $yamato->status);
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

    public function test_shipping_method_index_normalizes_legacy_carrier_code_aliases(): void
    {
        $yamato = Carrier::where('code', 'yamato')->firstOrFail();

        Livewire::actingAs($this->internalUser())
            ->test(ShippingMethodIndex::class)
            ->call('editCarrier', $yamato->id)
            ->set('carrierCode', 'ymt')
            ->set('carrierName', 'Yamato')
            ->call('saveCarrier')
            ->assertSet('editingCarrierId', null);

        $this->assertSame('yamato', $yamato->refresh()->code);
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

    private function assertStringOrder(string $haystack, string $before, string $after): void
    {
        $beforePosition = strpos($haystack, $before);
        $afterPosition = strpos($haystack, $after);

        $this->assertNotFalse($beforePosition, "{$before} was not found.");
        $this->assertNotFalse($afterPosition, "{$after} was not found.");
        $this->assertLessThan($afterPosition, $beforePosition, "{$before} should appear before {$after}.");
    }
}
