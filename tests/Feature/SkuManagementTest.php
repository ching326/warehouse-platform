<?php

namespace Tests\Feature;

use App\Livewire\SkuCreate;
use App\Livewire\SkusIndex;
use App\Models\Carrier;
use App\Models\PackagingMaterial;
use App\Models\ShippingMethod;
use App\Models\Shop;
use App\Models\Sku;
use App\Models\SkuBundleComponent;
use App\Models\StockItem;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

class SkuManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_internal_user_can_create_sku_with_new_stock_item_for_selected_tenant(): void
    {
        $tenant = Tenant::factory()->create(['code' => 'ABC']);
        $shop = Shop::factory()->for($tenant)->create();
        $user = $this->internalUser();

        Livewire::actingAs($user)
            ->test(SkuCreate::class)
            ->set('tenantId', (string) $tenant->id)
            ->set('shopId', (string) $shop->id)
            ->set('sku', 'SKU-NEW-001')
            ->set('name', 'New marketplace SKU')
            ->set('stockItem.name', 'New Stock Item')
            ->set('stockItem.barcode', '4900000000001')
            ->call('save')
            ->assertRedirect(route('skus.index'));

        $sku = Sku::where('sku', 'SKU-NEW-001')->firstOrFail();

        $this->assertSame($tenant->id, $sku->tenant_id);
        $this->assertSame($shop->id, $sku->shop_id);
        $this->assertSame('single', $sku->sku_type);
        $this->assertNotNull($sku->stock_item_id);
        $this->assertSame('ABC-000001', $sku->stockItem->code);
        $this->assertSame('New Stock Item', $sku->stockItem->name);
    }

    public function test_tenant_user_can_create_sku_only_for_own_tenant(): void
    {
        [$ownTenant, $user] = $this->tenantUser();
        $otherTenant = Tenant::factory()->create();

        Livewire::actingAs($user)
            ->test(SkuCreate::class)
            ->set('tenantId', (string) $otherTenant->id)
            ->set('sku', 'SKU-BAD-TENANT')
            ->set('name', 'Wrong tenant SKU')
            ->set('stockItem.name', 'Wrong Tenant Stock')
            ->call('save')
            ->assertHasErrors(['tenantId']);

        $this->assertDatabaseMissing('skus', ['sku' => 'SKU-BAD-TENANT']);

        Livewire::actingAs($user)
            ->test(SkuCreate::class)
            ->assertSet('tenantId', (string) $ownTenant->id)
            ->set('sku', 'SKU-OWN-TENANT')
            ->set('name', 'Own tenant SKU')
            ->set('stockItem.name', 'Own Tenant Stock')
            ->call('save')
            ->assertRedirect(route('skus.index'));

        $this->assertDatabaseHas('skus', [
            'tenant_id' => $ownTenant->id,
            'sku' => 'SKU-OWN-TENANT',
        ]);
    }

    public function test_create_page_prefills_from_query_parameters(): void
    {
        $tenant = Tenant::factory()->create();
        $shop = Shop::factory()->for($tenant)->create();

        Livewire::actingAs($this->internalUser())
            ->withQueryParams([
                'tenant_id' => (string) $tenant->id,
                'shop_id' => (string) $shop->id,
                'sku' => 'AMZ-MISSING-SKU',
                'name' => 'Missing Amazon Product',
                'platform_sku' => 'AMZ-MISSING-SKU',
            ])
            ->test(SkuCreate::class)
            ->assertSet('tenantId', (string) $tenant->id)
            ->assertSet('shopId', (string) $shop->id)
            ->assertSet('sku', 'AMZ-MISSING-SKU')
            ->assertSet('name', 'Missing Amazon Product')
            ->assertSet('platformSku', 'AMZ-MISSING-SKU');
    }

    public function test_tenant_user_cannot_link_to_another_tenants_stock_item(): void
    {
        [, $user] = $this->tenantUser();
        $otherTenant = Tenant::factory()->create();
        $otherStockItem = StockItem::factory()->for($otherTenant)->create();

        Livewire::actingAs($user)
            ->test(SkuCreate::class)
            ->set('stockItemMode', 'link')
            ->set('existingStockItemId', (string) $otherStockItem->id)
            ->set('sku', 'SKU-CROSS-LINK')
            ->set('name', 'Cross link SKU')
            ->call('save')
            ->assertHasErrors(['existing_stock_item_id']);

        $this->assertDatabaseMissing('skus', ['sku' => 'SKU-CROSS-LINK']);
    }

    public function test_duplicate_sku_is_rejected_for_nullable_shop_scope(): void
    {
        $tenant = Tenant::factory()->create();
        Sku::factory()->for($tenant)->create([
            'shop_id' => null,
            'sku' => 'SKU-DUP-NULL-SHOP',
        ]);

        Livewire::actingAs($this->internalUser())
            ->test(SkuCreate::class)
            ->set('tenantId', (string) $tenant->id)
            ->set('sku', 'SKU-DUP-NULL-SHOP')
            ->set('name', 'Duplicate SKU')
            ->set('stockItem.name', 'Duplicate Stock Item')
            ->call('save')
            ->assertHasErrors(['sku']);
    }

    public function test_single_sku_requires_stock_item_when_linking_existing_stock_item(): void
    {
        $tenant = Tenant::factory()->create();

        Livewire::actingAs($this->internalUser())
            ->test(SkuCreate::class)
            ->set('tenantId', (string) $tenant->id)
            ->set('stockItemMode', 'link')
            ->set('skuType', 'single')
            ->set('sku', 'SKU-SINGLE-NO-STOCK')
            ->set('name', 'Single no stock')
            ->call('save')
            ->assertHasErrors(['existing_stock_item_id']);
    }

    public function test_virtual_bundle_can_be_created_without_stock_item(): void
    {
        $tenant = Tenant::factory()->create();

        $this->assertSame(0, StockItem::count());

        Livewire::actingAs($this->internalUser())
            ->test(SkuCreate::class)
            ->set('tenantId', (string) $tenant->id)
            ->set('stockItemMode', 'link')
            ->set('skuType', 'virtual_bundle')
            ->set('sku', 'SKU-VIRTUAL-001')
            ->set('name', 'Virtual bundle')
            ->call('save')
            ->assertRedirect(route('skus.index'));

        $sku = Sku::where('sku', 'SKU-VIRTUAL-001')->firstOrFail();

        $this->assertNull($sku->stock_item_id);
        $this->assertSame(0, StockItem::count());
    }

    public function test_switching_sku_type_to_virtual_bundle_does_not_require_stock_item_fields(): void
    {
        $tenant = Tenant::factory()->create();

        Livewire::actingAs($this->internalUser())
            ->test(SkuCreate::class)
            ->set('tenantId', (string) $tenant->id)
            ->set('stockItemMode', 'create')
            ->set('stockItem.name', '')
            ->set('skuType', 'virtual_bundle')
            ->assertSet('stockItemMode', 'create')
            ->set('sku', 'SKU-VIRTUAL-NO-STOCK-FIELDS')
            ->set('name', 'Virtual no stock fields')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('skus.index'));

        $sku = Sku::where('sku', 'SKU-VIRTUAL-NO-STOCK-FIELDS')->firstOrFail();

        $this->assertNull($sku->stock_item_id);
        $this->assertSame(0, StockItem::count());
    }

    public function test_single_sku_create_new_still_auto_creates_stock_item(): void
    {
        $tenant = Tenant::factory()->create(['code' => 'ABC']);

        Livewire::actingAs($this->internalUser())
            ->test(SkuCreate::class)
            ->set('tenantId', (string) $tenant->id)
            ->set('stockItemMode', 'create')
            ->set('skuType', 'single')
            ->set('sku', 'SKU-SINGLE-CREATE-STOCK')
            ->set('name', 'Single create stock')
            ->set('stockItem.name', 'Single Created Stock')
            ->call('save')
            ->assertRedirect(route('skus.index'));

        $sku = Sku::where('sku', 'SKU-SINGLE-CREATE-STOCK')->firstOrFail();

        $this->assertNotNull($sku->stock_item_id);
        $this->assertSame('ABC-000001', $sku->stockItem->code);
        $this->assertSame('Single Created Stock', $sku->stockItem->name);
        $this->assertSame(1, StockItem::count());
    }

    public function test_physical_bundle_requires_stock_item_when_linking_existing_stock_item(): void
    {
        $tenant = Tenant::factory()->create();

        Livewire::actingAs($this->internalUser())
            ->test(SkuCreate::class)
            ->set('tenantId', (string) $tenant->id)
            ->set('stockItemMode', 'link')
            ->set('skuType', 'physical_bundle')
            ->set('sku', 'SKU-PHYSICAL-NO-STOCK')
            ->set('name', 'Physical no stock')
            ->call('save')
            ->assertHasErrors(['existing_stock_item_id']);

        $this->assertDatabaseMissing('skus', ['sku' => 'SKU-PHYSICAL-NO-STOCK']);
    }

    public function test_stock_item_code_auto_generates_per_tenant(): void
    {
        $tenant = Tenant::factory()->create(['code' => 'ABC']);
        StockItem::factory()->for($tenant)->create(['code' => 'ABC-000009']);

        Livewire::actingAs($this->internalUser())
            ->test(SkuCreate::class)
            ->set('tenantId', (string) $tenant->id)
            ->set('sku', 'SKU-AUTO-CODE')
            ->set('name', 'Auto code SKU')
            ->set('stockItem.name', 'Auto Code Stock')
            ->call('save')
            ->assertRedirect(route('skus.index'));

        $this->assertDatabaseHas('stock_items', [
            'tenant_id' => $tenant->id,
            'code' => 'ABC-000010',
            'name' => 'Auto Code Stock',
        ]);
    }

    public function test_created_sku_appears_on_index(): void
    {
        $tenant = Tenant::factory()->create();

        Livewire::actingAs($this->internalUser())
            ->test(SkuCreate::class)
            ->set('tenantId', (string) $tenant->id)
            ->set('sku', 'SKU-INDEX-001')
            ->set('name', 'Index visible SKU')
            ->set('stockItem.name', 'Index Visible Stock')
            ->call('save');

        $this->actingAs($this->internalUser())
            ->get('/skus')
            ->assertOk()
            ->assertSee('SKU-INDEX-001')
            ->assertSee('Index Visible Stock');
    }

    public function test_tenant_user_index_only_shows_own_tenant_skus(): void
    {
        [$tenant, $user] = $this->tenantUser();
        $otherTenant = Tenant::factory()->create();

        $ownStock = StockItem::factory()->for($tenant)->create();
        $otherStock = StockItem::factory()->for($otherTenant)->create();
        Sku::factory()->for($tenant)->for($ownStock)->create(['sku' => 'SKU-OWN-VISIBLE']);
        Sku::factory()->for($otherTenant)->for($otherStock)->create(['sku' => 'SKU-HIDDEN']);

        Livewire::actingAs($user)
            ->test(SkusIndex::class)
            ->assertSee('SKU-OWN-VISIBLE')
            ->assertDontSee('SKU-HIDDEN');
    }

    public function test_virtual_bundle_index_displays_component_composition_instead_of_no_stock_item(): void
    {
        $tenant = Tenant::factory()->create(['code' => 'ABC']);
        $bundleSku = Sku::factory()->virtualBundle()->for($tenant)->create([
            'sku' => 'SKU-VIRTUAL-BUNDLE',
            'name' => 'Virtual kit',
        ]);
        $firstStock = StockItem::factory()->for($tenant)->create(['code' => 'ABC-000001']);
        $secondStock = StockItem::factory()->for($tenant)->create(['code' => 'ABC-000002']);
        $thirdStock = StockItem::factory()->for($tenant)->create(['code' => 'ABC-000003']);

        SkuBundleComponent::factory()->for($tenant)->for($bundleSku, 'bundleSku')->for($firstStock, 'componentStockItem')->create(['quantity' => 1]);
        SkuBundleComponent::factory()->for($tenant)->for($bundleSku, 'bundleSku')->for($secondStock, 'componentStockItem')->create(['quantity' => 2]);
        SkuBundleComponent::factory()->for($tenant)->for($bundleSku, 'bundleSku')->for($thirdStock, 'componentStockItem')->create(['quantity' => 1]);

        Livewire::actingAs($this->internalUser())
            ->test(SkusIndex::class)
            ->assertSee('SKU-VIRTUAL-BUNDLE')
            ->assertSee('Virtual bundle')
            ->assertSee('ABC-000001 x1 + ABC-000002 x2 +1 more')
            ->assertSee('ABC-000001 x1 + ABC-000002 x2 + ABC-000003 x1')
            ->assertDontSee('No stock item');
    }

    public function test_single_sku_without_stock_item_displays_missing_stock_item_warning(): void
    {
        $tenant = Tenant::factory()->create();

        Sku::factory()->for($tenant)->create([
            'sku' => 'SKU-MISSING-STOCK',
            'stock_item_id' => null,
            'sku_type' => 'single',
        ]);

        Livewire::actingAs($this->internalUser())
            ->test(SkusIndex::class)
            ->assertSee('SKU-MISSING-STOCK')
            ->assertSee('Missing stock item')
            ->assertDontSee('No stock item');
    }

    public function test_sku_can_store_and_read_default_shipping_method_relation(): void
    {
        $method = $this->shippingMethod('default_ship_method');
        $sku = Sku::factory()->create(['default_shipping_method_id' => $method->id]);

        $this->assertTrue(Schema::hasColumn('skus', 'default_shipping_method_id'));
        $this->assertSame($method->id, $sku->fresh()->defaultShippingMethod->id);
    }

    public function test_sku_create_form_renders_and_saves_default_shipping_method(): void
    {
        $tenant = Tenant::factory()->create();
        $method = $this->shippingMethod('create_ship_method', 'Create Ship Method');

        Livewire::actingAs($this->internalUser())
            ->test(SkuCreate::class)
            ->set('tenantId', (string) $tenant->id)
            ->assertSee(__('skus.field_default_shipping_method'))
            ->assertSee('Create Ship Method')
            ->set('sku', 'SKU-DEFAULT-SHIP')
            ->set('name', 'Default ship SKU')
            ->set('defaultShippingMethodId', (string) $method->id)
            ->call('save')
            ->assertRedirect(route('skus.index'));

        $this->assertSame($method->id, Sku::where('sku', 'SKU-DEFAULT-SHIP')->firstOrFail()->default_shipping_method_id);
    }

    public function test_sku_create_rejects_invalid_or_inactive_default_shipping_method(): void
    {
        $tenant = Tenant::factory()->create();
        $inactive = $this->shippingMethod('inactive_ship_method', 'Inactive Ship Method', 'inactive');

        Livewire::actingAs($this->internalUser())
            ->test(SkuCreate::class)
            ->set('tenantId', (string) $tenant->id)
            ->set('sku', 'SKU-BAD-SHIP')
            ->set('name', 'Bad ship SKU')
            ->set('defaultShippingMethodId', '999999')
            ->call('save')
            ->assertHasErrors(['default_shipping_method_id']);

        Livewire::actingAs($this->internalUser())
            ->test(SkuCreate::class)
            ->set('tenantId', (string) $tenant->id)
            ->set('sku', 'SKU-INACTIVE-SHIP')
            ->set('name', 'Inactive ship SKU')
            ->set('defaultShippingMethodId', (string) $inactive->id)
            ->call('save')
            ->assertHasErrors(['default_shipping_method_id']);
    }

    public function test_logistics_view_shows_saved_inactive_shipping_method_but_rejects_switching_to_it(): void
    {
        $tenant = Tenant::factory()->create();
        $inactive = $this->shippingMethod('legacy_ship_method', 'Legacy Ship Method', 'inactive');
        $active = $this->shippingMethod('active_ship_method', 'Active Ship Method');
        $first = Sku::factory()->for($tenant)->create(['sku' => 'SKU-LEGACY-SHIP', 'default_shipping_method_id' => $inactive->id]);
        $second = Sku::factory()->for($tenant)->create(['sku' => 'SKU-ACTIVE-SHIP', 'default_shipping_method_id' => $active->id]);

        Livewire::actingAs($this->internalUser())
            ->withQueryParams(['view' => 'logistics'])
            ->test(SkusIndex::class)
            ->assertSee('Legacy Ship Method')
            ->assertSee(__('skus.inactive_shipping_method'))
            ->set("logisticsDrafts.{$first->id}.default_shipping_method_id", (string) $inactive->id)
            ->call('saveLogisticsField', $first->id, 'default_shipping_method_id')
            ->assertHasNoErrors()
            ->set("logisticsDrafts.{$second->id}.default_shipping_method_id", (string) $inactive->id)
            ->call('saveLogisticsField', $second->id, 'default_shipping_method_id')
            ->assertHasErrors(['default_shipping_method_id']);
    }

    public function test_logistics_view_only_shows_inactive_shipping_method_for_its_current_row(): void
    {
        $tenant = Tenant::factory()->create();
        $inactive = $this->shippingMethod('legacy_row_only_method', 'Legacy Row Only Method', 'inactive');
        $active = $this->shippingMethod('active_row_method', 'Active Row Method');

        Sku::factory()->for($tenant)->create(['sku' => 'SKU-LEGACY-ROW', 'default_shipping_method_id' => $inactive->id]);
        Sku::factory()->for($tenant)->create(['sku' => 'SKU-ACTIVE-ROW', 'default_shipping_method_id' => $active->id]);

        $html = Livewire::actingAs($this->internalUser())
            ->withQueryParams(['view' => 'logistics'])
            ->test(SkusIndex::class)
            ->html();

        $this->assertSame(1, substr_count($html, 'Legacy Row Only Method'));
    }

    public function test_sku_index_catalog_marketplace_and_unknown_views_render_correctly(): void
    {
        $tenant = Tenant::factory()->create();
        $shop = Shop::factory()->for($tenant)->create(['code' => 'SHOP1']);
        $stockItem = StockItem::factory()->for($tenant)->create(['code' => 'STOCK1', 'short_name' => 'Shorty']);
        Sku::factory()->for($tenant)->for($shop)->for($stockItem)->create([
            'sku' => 'SKU-FLAT-VIEWS',
            'name' => 'Flat view SKU',
            'platform_sku' => 'SELLER-SKU',
            'platform_product_id' => 'B000ASIN',
            'platform_label_code' => 'FNSKU123',
            'platform_variant_name' => 'Blue / M',
        ]);

        Livewire::actingAs($this->internalUser())
            ->withQueryParams(['view' => 'catalog'])
            ->test(SkusIndex::class)
            ->assertSet('view', 'catalog')
            ->assertSee(__('skus.col_short_name'))
            ->assertSee('Shorty')
            ->assertDontSee(__('skus.col_platform_ids'));

        Livewire::actingAs($this->internalUser())
            ->withQueryParams(['view' => 'marketplace'])
            ->test(SkusIndex::class)
            ->assertSet('view', 'marketplace')
            ->assertSee(__('skus.col_seller_sku'))
            ->assertSee(__('skus.col_asin'))
            ->assertSee(__('skus.col_fnsku'))
            ->assertSee('SELLER-SKU')
            ->assertSee('B000ASIN')
            ->assertSee('FNSKU123')
            ->assertSee('Blue / M');

        Livewire::actingAs($this->internalUser())
            ->withQueryParams(['view' => 'unknown'])
            ->test(SkusIndex::class)
            ->assertSet('view', 'detailed')
            ->assertSee(__('skus.col_platform_ids'));
    }

    public function test_empty_state_colspan_matches_current_view(): void
    {
        $this->actingAs($this->internalUser())
            ->get('/skus?view=catalog')
            ->assertOk()
            ->assertSee('colspan="7"', false);

        $this->actingAs($this->internalUser())
            ->get('/skus?view=marketplace')
            ->assertOk()
            ->assertSee('colspan="6"', false);

        $this->actingAs($this->internalUser())
            ->get('/skus?view=logistics')
            ->assertOk()
            ->assertSee('colspan="8"', false);
    }

    public function test_logistics_view_renders_editable_fields_and_saves_stock_item_and_sku_defaults(): void
    {
        $tenant = Tenant::factory()->create();
        $stockItem = StockItem::factory()->for($tenant)->create();
        $packaging = PackagingMaterial::factory()->create();
        $method = $this->shippingMethod('logistics_ship_method', 'Logistics Ship Method');
        $sku = Sku::factory()->for($tenant)->for($stockItem)->create(['sku' => 'SKU-LOGISTICS']);

        Livewire::actingAs($this->internalUser())
            ->withQueryParams(['view' => 'logistics'])
            ->test(SkusIndex::class)
            ->assertSee(__('skus.col_weight'))
            ->assertSee(__('skus.col_default_shipping_method'))
            ->set("logisticsDrafts.{$sku->id}.short_name", 'Tiny name')
            ->call('saveLogisticsField', $sku->id, 'short_name')
            ->set("logisticsDrafts.{$sku->id}.weight_value", '12.345')
            ->call('saveLogisticsField', $sku->id, 'weight_value')
            ->set("logisticsDrafts.{$sku->id}.length_value", '10.5')
            ->call('saveLogisticsField', $sku->id, 'length_value')
            ->set("logisticsDrafts.{$sku->id}.width_value", '8.5')
            ->call('saveLogisticsField', $sku->id, 'width_value')
            ->set("logisticsDrafts.{$sku->id}.height_value", '3.5')
            ->call('saveLogisticsField', $sku->id, 'height_value')
            ->set("logisticsDrafts.{$sku->id}.default_packaging_material_id", (string) $packaging->id)
            ->call('saveLogisticsField', $sku->id, 'default_packaging_material_id')
            ->set("logisticsDrafts.{$sku->id}.default_shipping_method_id", (string) $method->id)
            ->call('saveLogisticsField', $sku->id, 'default_shipping_method_id')
            ->assertHasNoErrors();

        $stockItem->refresh();
        $sku->refresh();
        $this->assertSame('Tiny name', $stockItem->short_name);
        $this->assertSame('12.345', (string) $stockItem->weight_value);
        $this->assertSame('10.50', (string) $stockItem->length_value);
        $this->assertSame('8.50', (string) $stockItem->width_value);
        $this->assertSame('3.50', (string) $stockItem->height_value);
        $this->assertSame($packaging->id, $sku->default_packaging_material_id);
        $this->assertSame($method->id, $sku->default_shipping_method_id);
    }

    public function test_logistics_shared_stock_item_drafts_refresh_after_save(): void
    {
        $tenant = Tenant::factory()->create();
        $stockItem = StockItem::factory()->for($tenant)->create(['short_name' => 'Old shared']);
        $first = Sku::factory()->for($tenant)->for($stockItem)->create(['sku' => 'SKU-SHARED-A']);
        $second = Sku::factory()->for($tenant)->for($stockItem)->create(['sku' => 'SKU-SHARED-B']);

        Livewire::actingAs($this->internalUser())
            ->withQueryParams(['view' => 'logistics'])
            ->test(SkusIndex::class)
            ->assertSet("logisticsDrafts.{$first->id}.short_name", 'Old shared')
            ->assertSet("logisticsDrafts.{$second->id}.short_name", 'Old shared')
            ->set("logisticsDrafts.{$first->id}.short_name", 'Fresh shared')
            ->call('saveLogisticsField', $first->id, 'short_name')
            ->assertSet("logisticsDrafts.{$second->id}.short_name", 'Fresh shared')
            ->call('saveLogisticsField', $second->id, 'short_name')
            ->assertHasNoErrors();

        $this->assertSame('Fresh shared', $stockItem->refresh()->short_name);
    }

    public function test_virtual_bundle_logistics_disables_physical_fields_but_allows_sku_defaults(): void
    {
        $tenant = Tenant::factory()->create();
        $packaging = PackagingMaterial::factory()->create();
        $method = $this->shippingMethod('bundle_ship_method', 'Bundle Ship Method');
        $sku = Sku::factory()->virtualBundle()->for($tenant)->create(['sku' => 'SKU-BUNDLE-LOGISTICS']);

        Livewire::actingAs($this->internalUser())
            ->withQueryParams(['view' => 'logistics'])
            ->test(SkusIndex::class)
            ->assertSee('SKU-BUNDLE-LOGISTICS')
            ->set("logisticsDrafts.{$sku->id}.default_packaging_material_id", (string) $packaging->id)
            ->call('saveLogisticsField', $sku->id, 'default_packaging_material_id')
            ->set("logisticsDrafts.{$sku->id}.default_shipping_method_id", (string) $method->id)
            ->call('saveLogisticsField', $sku->id, 'default_shipping_method_id')
            ->set("logisticsDrafts.{$sku->id}.weight_value", '5')
            ->call('saveLogisticsField', $sku->id, 'weight_value')
            ->assertHasNoErrors();

        $sku->refresh();
        $this->assertNull($sku->stock_item_id);
        $this->assertSame($packaging->id, $sku->default_packaging_material_id);
        $this->assertSame($method->id, $sku->default_shipping_method_id);
    }

    public function test_logistics_rejects_negative_values_and_empty_input_clears_to_null(): void
    {
        $tenant = Tenant::factory()->create();
        $stockItem = StockItem::factory()->for($tenant)->create(['weight_value' => 10]);
        $sku = Sku::factory()->for($tenant)->for($stockItem)->create();

        Livewire::actingAs($this->internalUser())
            ->withQueryParams(['view' => 'logistics'])
            ->test(SkusIndex::class)
            ->set("logisticsDrafts.{$sku->id}.weight_value", '-1')
            ->call('saveLogisticsField', $sku->id, 'weight_value')
            ->assertHasErrors(['weight_value'])
            ->set("logisticsDrafts.{$sku->id}.weight_value", '')
            ->call('saveLogisticsField', $sku->id, 'weight_value')
            ->assertHasNoErrors();

        $this->assertNull($stockItem->refresh()->weight_value);
    }

    public function test_tenant_user_cannot_inline_edit_another_tenants_sku(): void
    {
        [, $user] = $this->tenantUser();
        $otherTenant = Tenant::factory()->create();
        $stockItem = StockItem::factory()->for($otherTenant)->create(['short_name' => 'Original']);
        $sku = Sku::factory()->for($otherTenant)->for($stockItem)->create();

        Livewire::actingAs($user)
            ->withQueryParams(['view' => 'logistics'])
            ->test(SkusIndex::class)
            ->set("logisticsDrafts.{$sku->id}.short_name", 'Hacked')
            ->call('saveLogisticsField', $sku->id, 'short_name')
            ->assertNotFound();

        $this->assertSame('Original', $stockItem->refresh()->short_name);
    }

    public function test_tenant_user_cannot_inline_edit_corrupt_cross_tenant_stock_item(): void
    {
        [$tenant, $user] = $this->tenantUser();
        $otherTenant = Tenant::factory()->create();
        $stockItem = StockItem::factory()->for($otherTenant)->create(['short_name' => 'Protected']);
        $sku = Sku::factory()->for($tenant)->create([
            'sku' => 'SKU-CORRUPT-STOCK',
            'stock_item_id' => $stockItem->id,
        ]);

        Livewire::actingAs($user)
            ->withQueryParams(['view' => 'logistics'])
            ->test(SkusIndex::class)
            ->set("logisticsDrafts.{$sku->id}.short_name", 'Hacked')
            ->call('saveLogisticsField', $sku->id, 'short_name')
            ->assertNotFound();

        $this->assertSame('Protected', $stockItem->refresh()->short_name);
    }

    public function test_sku_view_default_preference_is_saved_and_query_parameter_wins(): void
    {
        $user = $this->internalUser();

        Livewire::actingAs($user)
            ->withQueryParams(['view' => 'logistics'])
            ->test(SkusIndex::class)
            ->call('saveDefaultView')
            ->assertSee(__('skus.default_view_saved'));

        $this->assertSame('logistics', $user->refresh()->preference('skus_view'));

        Livewire::actingAs($user)
            ->test(SkusIndex::class)
            ->assertSet('view', 'logistics');

        Livewire::actingAs($user)
            ->withQueryParams(['view' => 'catalog'])
            ->test(SkusIndex::class)
            ->assertSet('view', 'catalog');
    }

    public function test_saved_sku_view_preference_applies_on_plain_skus_request(): void
    {
        $user = $this->internalUser();
        $user->setPreference('skus_view', 'marketplace');

        $this->actingAs($user)
            ->get('/skus')
            ->assertOk()
            ->assertSee(__('skus.col_seller_sku'))
            ->assertDontSee(__('skus.col_platform_ids'));
    }

    public function test_guest_user_is_not_treated_as_internal_on_sku_index(): void
    {
        $tenant = Tenant::factory()->create();
        $stockItem = StockItem::factory()->for($tenant)->create();
        Sku::factory()->for($tenant)->for($stockItem)->create(['sku' => 'SKU-GUEST-HIDDEN']);

        Livewire::test(SkusIndex::class)
            ->assertDontSee('SKU-GUEST-HIDDEN');
    }

    private function internalUser(): User
    {
        return User::factory()->create([
            'user_type' => 'internal',
            'is_active' => true,
        ]);
    }

    /**
     * @return array{0: Tenant, 1: User}
     */
    private function tenantUser(): array
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

        return [$tenant, $user];
    }

    private function shippingMethod(string $code, ?string $name = null, string $status = 'active'): ShippingMethod
    {
        $carrier = Carrier::query()->firstOrCreate(
            ['code' => 'test_carrier'],
            ['name' => 'Test Carrier', 'country_code' => 'JP', 'status' => 'active'],
        );

        return ShippingMethod::query()->create([
            'carrier_id' => $carrier->id,
            'code' => $code,
            'name' => $name ?? str($code)->replace('_', ' ')->title()->toString(),
            'service_type' => 'parcel',
            'status' => $status,
        ]);
    }
}
