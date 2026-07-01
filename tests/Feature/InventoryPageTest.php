<?php

namespace Tests\Feature;

use App\Livewire\InventoryIndex;
use App\Models\BarcodeAlias;
use App\Models\InventoryBalance;
use App\Models\MediaAsset;
use App\Models\Shop;
use App\Models\Sku;
use App\Models\StockItem;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class InventoryPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_inventory_route_renders_inventory_balances(): void
    {
        $rows = $this->createInventoryRows();

        $this->actingAs($this->internalUser())
            ->get('/inventory')
            ->assertOk()
            ->assertSee('Inventory')
            ->assertSee('Filtered Stock Items')
            ->assertSee('Filtered On Hand')
            ->assertSee('Filtered Available')
            ->assertSee('Filtered Reserved')
            ->assertSee('Stock Item')
            ->assertSee('SKU')
            ->assertSee('Name')
            ->assertSee($rows['alphaStock']->code)
            ->assertSee($rows['alphaSku']->sku)
            ->assertSee((string) $rows['alphaBalance']->available_qty)
            ->assertSee('available-success', false)
            ->assertSee('available-danger', false);
    }

    public function test_root_renders_inventory(): void
    {
        $this->actingAs($this->internalUser())
            ->get('/')
            ->assertOk()
            ->assertSee('Inventory');
    }

    public function test_internal_users_can_see_all_tenant_inventory(): void
    {
        $rows = $this->createInventoryRows();

        Livewire::actingAs($this->internalUser())
            ->test(InventoryIndex::class)
            ->assertSee($rows['alphaTenant']->name)
            ->assertSee($rows['betaTenant']->name)
            ->assertSee($rows['alphaStock']->code)
            ->assertSee($rows['betaStock']->code);
    }

    public function test_tenant_users_can_only_see_their_own_tenant_inventory(): void
    {
        $rows = $this->createInventoryRows();
        $user = User::factory()->create(['user_type' => 'tenant']);

        TenantUser::create([
            'tenant_id' => $rows['alphaTenant']->id,
            'user_id' => $user->id,
            'role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        Livewire::actingAs($user)
            ->test(InventoryIndex::class)
            ->assertSee($rows['alphaTenant']->name)
            ->assertSee($rows['alphaStock']->code)
            ->assertDontSee($rows['betaTenant']->name)
            ->assertDontSee($rows['betaStock']->code);
    }

    public function test_tenant_column_only_shows_for_internal_users_viewing_all_tenants(): void
    {
        $rows = $this->createInventoryRows();
        $tenantUser = User::factory()->create(['user_type' => 'tenant']);

        TenantUser::create([
            'tenant_id' => $rows['alphaTenant']->id,
            'user_id' => $tenantUser->id,
            'role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        Livewire::actingAs($this->internalUser())
            ->test(InventoryIndex::class)
            ->assertSeeHtml('inventory-tenant-column-label')
            ->set('tenantId', (string) $rows['alphaTenant']->id)
            ->assertDontSeeHtml('inventory-tenant-column-label');

        Livewire::actingAs($tenantUser)
            ->test(InventoryIndex::class)
            ->assertDontSeeHtml('inventory-tenant-column-label');
    }

    public function test_inventory_search_matches_stock_item_and_sku_fields(): void
    {
        $rows = $this->createInventoryRows();

        Livewire::actingAs($this->internalUser())
            ->test(InventoryIndex::class)
            ->set('search', 'FNSKU-ALPHA')
            ->assertSee($rows['alphaStock']->code)
            ->assertDontSee($rows['betaStock']->code)
            ->set('search', 'BETA-PLATFORM')
            ->assertSee($rows['betaStock']->code)
            ->assertDontSee($rows['alphaStock']->code)
            ->set('search', '4900000000001')
            ->assertSee($rows['alphaStock']->name)
            ->assertDontSee($rows['betaStock']->name);
    }

    public function test_inventory_uses_tenant_item_code_display_preference_and_search(): void
    {
        $rows = $this->createInventoryRows();
        $rows['alphaStock']->update(['tenant_item_code' => 'TENANT-INV-001']);
        $user = $this->internalUser();

        $user->setPreference('show_tenant_item_code', true);

        Livewire::actingAs($user)
            ->test(InventoryIndex::class)
            ->assertSet('stockItemCodeDisplay', 'both')
            ->assertSee('TENANT-INV-001')
            ->assertSee($rows['alphaStock']->code)
            ->set('search', 'TENANT-INV-001')
            ->assertSee($rows['alphaStock']->name)
            ->assertDontSee($rows['betaStock']->name);
    }

    public function test_inventory_view_settings_save_persists_stock_item_code_display_preference(): void
    {
        $rows = $this->createInventoryRows();
        $rows['alphaStock']->update(['tenant_item_code' => 'TENANT-INV-002']);
        $user = $this->internalUser();

        Livewire::actingAs($user)
            ->test(InventoryIndex::class)
            ->call('openViewSettings')
            ->assertSet('viewSettingsOpen', true)
            ->set('stockItemCodeDisplay', 'tenant')
            ->call('saveViewSettings')
            ->assertSet('viewSettingsOpen', false)
            ->assertSee('TENANT-INV-002')
            ->assertDontSee($rows['alphaStock']->code);

        $this->assertSame('tenant', $user->refresh()->preference('stock_item_code_display'));
    }

    public function test_inventory_filters_by_tenant_warehouse_shop_product_type_and_status(): void
    {
        $rows = $this->createInventoryRows();

        Livewire::actingAs($this->internalUser())
            ->test(InventoryIndex::class)
            ->set('tenantId', (string) $rows['alphaTenant']->id)
            ->assertSee($rows['alphaStock']->code)
            ->assertDontSee($rows['betaStock']->code)
            ->set('tenantId', '')
            ->set('warehouseId', (string) $rows['betaWarehouse']->id)
            ->assertSee($rows['betaStock']->code)
            ->assertDontSee($rows['alphaStock']->code)
            ->set('warehouseId', '')
            ->set('shopId', (string) $rows['alphaShop']->id)
            ->assertSee($rows['alphaStock']->code)
            ->assertDontSee($rows['betaStock']->code)
            ->set('shopId', '')
            ->set('productType', 'cosmetic')
            ->assertSee($rows['betaStock']->code)
            ->assertDontSee($rows['alphaStock']->code)
            ->set('productType', '')
            ->set('status', 'archived')
            ->assertSee($rows['betaStock']->code)
            ->assertDontSee($rows['alphaStock']->code);
    }

    public function test_sku_list_can_expand_beyond_first_three_skus(): void
    {
        $rows = $this->createInventoryRows();

        foreach (range(2, 5) as $index) {
            Sku::factory()->for($rows['alphaTenant'])->for($rows['alphaShop'])->for($rows['alphaStock'])->create([
                'sku' => 'ALPHA-SKU-'.$index,
                'platform_sku' => 'ALPHA-PLATFORM-'.$index,
                'platform_label_code' => 'FNSKU-ALPHA-'.$index,
            ]);
        }

        Livewire::actingAs($this->internalUser())
            ->test(InventoryIndex::class)
            ->assertSee('+2 more')
            ->assertDontSee('ALPHA-PLATFORM')
            ->assertDontSee('ALPHA-SKU-5')
            ->call('toggleSkuList', $rows['alphaStock']->id)
            ->assertSee('ALPHA-SKU-5')
            ->assertSee('ALPHA-PLATFORM-5')
            ->assertSee('Show fewer');
    }

    public function test_exceptions_and_movements_action_render_per_row(): void
    {
        $rows = $this->createInventoryRows();

        Livewire::actingAs($this->internalUser())
            ->test(InventoryIndex::class)
            ->assertSee('Hold 3')
            ->assertSee('Damaged 2')
            ->assertSee('Movements')
            ->assertSee('/inventory/movements?stock_item_id='.$rows['betaStock']->id, false);
    }

    public function test_inventory_page_renders_stock_item_thumbnails(): void
    {
        Storage::fake('public');
        $rows = $this->createInventoryRows();
        $asset = MediaAsset::create([
            'tenant_id' => $rows['alphaStock']->tenant_id,
            'model_type' => 'stock_item',
            'model_id' => $rows['alphaStock']->id,
            'type' => 'main',
            'disk' => 'public',
            'path' => 'product-images/tenant-'.$rows['alphaStock']->tenant_id.'/stock-items/'.$rows['alphaStock']->id.'/thumb.jpg',
            'file_name' => 'thumb.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 10,
            'width' => 10,
            'height' => 10,
            'sort_order' => 1,
            'is_primary' => true,
        ]);

        Livewire::actingAs($this->internalUser())
            ->test(InventoryIndex::class)
            ->assertSee('product-thumbnail', false)
            ->assertSee($asset->path, false)
            ->assertSee($rows['alphaStock']->code);
    }

    public function test_available_status_classes_use_simple_stock_thresholds(): void
    {
        $component = new InventoryIndex;

        $this->assertSame('available available-danger', $component->availableStatusClass(0));
        $this->assertSame('available available-warning', $component->availableStatusClass(10));
        $this->assertSame('available available-success', $component->availableStatusClass(11));
    }

    private function internalUser(): User
    {
        return User::factory()->create([
            'user_type' => 'internal',
            'is_active' => true,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function createInventoryRows(): array
    {
        $alphaTenant = Tenant::factory()->create(['code' => 'ALP', 'name' => 'Alpha Goods']);
        $betaTenant = Tenant::factory()->create(['code' => 'BET', 'name' => 'Beta Supplies']);

        $alphaWarehouse = Warehouse::factory()->create(['code' => 'JP-TOKYO-T', 'name' => 'Tokyo Test Warehouse']);
        $betaWarehouse = Warehouse::factory()->create(['code' => 'US-LA-T', 'name' => 'LA Test Warehouse']);

        $alphaShop = Shop::factory()->for($alphaTenant)->create(['code' => 'AMZ-A', 'name' => 'Alpha Amazon']);
        $betaShop = Shop::factory()->for($betaTenant)->create(['code' => 'SHP-B', 'name' => 'Beta Shopify']);

        $alphaStock = StockItem::factory()->for($alphaTenant)->create([
            'code' => 'STK-ALPHA',
            'name' => 'Alpha Charger',
            'product_type' => 'normal',
            'status' => 'active',
        ]);

        $betaStock = StockItem::factory()->for($betaTenant)->create([
            'code' => 'STK-BETA',
            'name' => 'Beta Skin Cream',
            'product_type' => 'cosmetic',
            'status' => 'archived',
        ]);

        BarcodeAlias::create([
            'tenant_id' => $alphaTenant->id,
            'model_type' => BarcodeAlias::MODEL_TYPE_STOCK_ITEM,
            'model_id' => $alphaStock->id,
            'barcode' => '4900000000001',
            'normalized_barcode' => '4900000000001',
            'barcode_type' => 'jan',
            'is_primary' => true,
            'is_active' => true,
        ]);

        BarcodeAlias::create([
            'tenant_id' => $betaTenant->id,
            'model_type' => BarcodeAlias::MODEL_TYPE_STOCK_ITEM,
            'model_id' => $betaStock->id,
            'barcode' => '4900000000002',
            'normalized_barcode' => '4900000000002',
            'barcode_type' => 'jan',
            'is_primary' => true,
            'is_active' => true,
        ]);

        $alphaSku = Sku::factory()->for($alphaTenant)->for($alphaShop)->for($alphaStock)->create([
            'sku' => 'ALPHA-SKU',
            'platform_sku' => 'ALPHA-PLATFORM',
            'platform_label_code' => 'FNSKU-ALPHA',
        ]);

        $betaSku = Sku::factory()->for($betaTenant)->for($betaShop)->for($betaStock)->create([
            'sku' => 'BETA-SKU',
            'platform_sku' => 'BETA-PLATFORM',
            'platform_label_code' => 'FNSKU-BETA',
        ]);

        $alphaBalance = InventoryBalance::factory()->for($alphaTenant)->for($alphaWarehouse)->for($alphaStock)->create([
            'on_hand_qty' => 100,
            'reserved_qty' => 20,
            'available_qty' => 80,
            'inbound_qty' => 12,
            'hold_qty' => 0,
            'damaged_qty' => 0,
        ]);

        $betaBalance = InventoryBalance::factory()->for($betaTenant)->for($betaWarehouse)->for($betaStock)->create([
            'on_hand_qty' => 10,
            'reserved_qty' => 5,
            'available_qty' => 0,
            'inbound_qty' => 0,
            'hold_qty' => 3,
            'damaged_qty' => 2,
        ]);

        return compact(
            'alphaTenant',
            'betaTenant',
            'alphaWarehouse',
            'betaWarehouse',
            'alphaShop',
            'betaShop',
            'alphaStock',
            'betaStock',
            'alphaSku',
            'betaSku',
            'alphaBalance',
            'betaBalance',
        );
    }
}
