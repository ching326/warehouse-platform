<?php

namespace Tests\Feature;

use App\Livewire\SkuCreate;
use App\Livewire\SkusIndex;
use App\Models\Shop;
use App\Models\Sku;
use App\Models\SkuBundleComponent;
use App\Models\StockItem;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
