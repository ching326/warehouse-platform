<?php

namespace Tests\Feature;

use App\Livewire\SalesOrderImport;
use App\Livewire\SkuCreate;
use App\Livewire\SkuEdit;
use App\Livewire\SkusIndex;
use App\Models\AmazonSpapiConnection;
use App\Models\BarcodeAlias;
use App\Models\Carrier;
use App\Models\MediaAsset;
use App\Models\PackagingMaterial;
use App\Models\ProductType;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\ShippingMethod;
use App\Models\Shop;
use App\Models\Sku;
use App\Models\SkuBundleComponent;
use App\Models\StockItem;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\Fulfillment\FulfillmentItemCodeResolver;
use App\Services\Sku\PlatformLabelAliasSync;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
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
        $this->assertNull($sku->stockItem->barcode);
        $this->assertDatabaseHas('barcode_aliases', [
            'tenant_id' => $tenant->id,
            'model_type' => BarcodeAlias::MODEL_TYPE_STOCK_ITEM,
            'model_id' => $sku->stock_item_id,
            'barcode' => '4900000000001',
            'normalized_barcode' => '4900000000001',
            'is_primary' => true,
            'is_active' => true,
        ]);
    }

    public function test_create_sku_persists_localized_name_overrides(): void
    {
        $tenant = Tenant::factory()->create(['code' => 'LOC']);
        $shop = Shop::factory()->for($tenant)->create();

        Livewire::actingAs($this->internalUser())
            ->test(SkuCreate::class)
            ->set('tenantId', (string) $tenant->id)
            ->set('shopId', (string) $shop->id)
            ->set('sku', 'SKU-LOCALIZED')
            ->set('name', 'Default SKU Name')
            ->set('nameTranslations.en', 'English SKU Name')
            ->set('nameTranslations.ja', 'SKU日本語名')
            ->set('nameTranslations.zh_TW', 'SKU繁中名')
            ->set('stockItem.name', 'Default Stock Name')
            ->set('stockItem.name_en', 'English Stock Name')
            ->set('stockItem.name_ja', '在庫日本語名')
            ->set('stockItem.name_zh_cn', '库存简中名')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('skus.index'));

        $sku = Sku::where('sku', 'SKU-LOCALIZED')->firstOrFail();

        $this->assertSame('English SKU Name', $sku->name_en);
        $this->assertSame('SKU日本語名', $sku->name_ja);
        $this->assertSame('SKU繁中名', $sku->name_zh_tw);
        $this->assertNull($sku->name_zh_cn);

        $this->assertSame('English Stock Name', $sku->stockItem->name_en);
        $this->assertSame('在庫日本語名', $sku->stockItem->name_ja);
        $this->assertNull($sku->stockItem->name_zh_tw);
        $this->assertSame('库存简中名', $sku->stockItem->name_zh_cn);

        $this->assertSame('English SKU Name', $sku->localizedName('en'));
        $this->assertSame('English Stock Name', $sku->stockItem->localizedName('en'));
        app()->setLocale('ja');
        $this->assertSame('在庫日本語名', $sku->stockItem->displayName());
        app()->setLocale('en');
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

    public function test_platform_label_alias_is_automatically_attached_to_sku(): void
    {
        $tenant = Tenant::factory()->create();
        $stockItem = StockItem::factory()->for($tenant)->create();
        $sku = Sku::factory()->for($tenant)->for($stockItem)->create(['sku' => 'SKU-ALIAS-UI']);

        Livewire::actingAs($this->internalUser())
            ->test(SkusIndex::class)
            ->call('openAliasPanel', $sku->id)
            ->set('aliasBarcode', ' 49-0123 4567894 ')
            ->set('aliasBarcodeType', 'platform_label')
            ->set('aliasLabel', 'Old package barcode')
            ->call('createBarcodeAlias')
            ->assertSee(__('skus.alias_created'));

        $this->assertDatabaseHas('barcode_aliases', [
            'tenant_id' => $tenant->id,
            'model_type' => BarcodeAlias::MODEL_TYPE_SKU,
            'model_id' => $sku->id,
            'barcode' => '49-0123 4567894',
            'normalized_barcode' => '4901234567894',
            'barcode_type' => 'platform_label',
            'label' => 'Old package barcode',
            'is_active' => true,
        ]);
        $this->assertSame('49-0123 4567894', $sku->refresh()->platform_label_code);
    }

    public function test_product_barcode_alias_is_automatically_attached_to_stock_item(): void
    {
        $tenant = Tenant::factory()->create();
        $stockItem = StockItem::factory()->for($tenant)->create();
        $sku = Sku::factory()->for($tenant)->for($stockItem)->create(['sku' => 'SKU-STOCK-ALIAS-UI']);

        Livewire::actingAs($this->internalUser())
            ->test(SkusIndex::class)
            ->call('openAliasPanel', $sku->id)
            ->set('aliasBarcode', 'x00abc123')
            ->set('aliasBarcodeType', 'jan')
            ->call('createBarcodeAlias');

        $this->assertDatabaseHas('barcode_aliases', [
            'tenant_id' => $tenant->id,
            'model_type' => BarcodeAlias::MODEL_TYPE_STOCK_ITEM,
            'model_id' => $stockItem->id,
            'barcode' => 'x00abc123',
            'normalized_barcode' => 'X00ABC123',
            'barcode_type' => 'jan',
            'is_active' => true,
        ]);
    }

    public function test_barcode_alias_panel_does_not_expose_storage_target(): void
    {
        $tenant = Tenant::factory()->create();
        $stockItem = StockItem::factory()->for($tenant)->create();
        $sku = Sku::factory()->for($tenant)->for($stockItem)->create(['sku' => 'SKU-ALIAS-NO-TARGET']);

        Livewire::actingAs($this->internalUser())
            ->test(SkusIndex::class)
            ->call('openAliasPanel', $sku->id)
            ->assertDontSee(__('skus.alias_target'));
    }

    public function test_create_sku_with_platform_label_code_creates_managed_alias(): void
    {
        $tenant = Tenant::factory()->create(['code' => 'FNS']);

        Livewire::actingAs($this->internalUser())
            ->test(SkuCreate::class)
            ->set('tenantId', (string) $tenant->id)
            ->set('sku', 'SKU-FNSKU-CREATE')
            ->set('name', 'FNSKU create')
            ->set('platformLabelCode', 'x00-abc 123')
            ->set('stockItem.name', 'FNSKU stock')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('skus.index'));

        $sku = Sku::where('sku', 'SKU-FNSKU-CREATE')->firstOrFail();

        $this->assertSame('x00-abc 123', $sku->platform_label_code);
        $this->assertDatabaseHas('barcode_aliases', [
            'tenant_id' => $tenant->id,
            'model_type' => BarcodeAlias::MODEL_TYPE_SKU,
            'model_id' => $sku->id,
            'barcode' => 'x00-abc 123',
            'normalized_barcode' => 'X00ABC123',
            'barcode_type' => 'platform_label',
            'is_active' => true,
            'is_primary' => true,
            'source' => BarcodeAlias::SOURCE_PLATFORM_LABEL_CODE,
        ]);
    }

    public function test_editing_platform_label_code_updates_single_managed_alias(): void
    {
        [$tenant, $sku] = $this->skuForAliasSync('SKU-FNSKU-EDIT', 'OLD-FNSKU');
        app(PlatformLabelAliasSync::class)->sync($sku);
        $aliasId = BarcodeAlias::where('model_id', $sku->id)->value('id');

        Livewire::actingAs($this->internalUser())
            ->test(SkuEdit::class, ['sku' => $sku])
            ->set('platformLabelCode', 'new-fnsku')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('skus.index'));

        $this->assertSame(1, BarcodeAlias::where('model_id', $sku->id)->where('source', BarcodeAlias::SOURCE_PLATFORM_LABEL_CODE)->count());
        $this->assertDatabaseHas('barcode_aliases', [
            'id' => $aliasId,
            'tenant_id' => $tenant->id,
            'barcode' => 'new-fnsku',
            'normalized_barcode' => 'NEWFNSKU',
            'source' => BarcodeAlias::SOURCE_PLATFORM_LABEL_CODE,
        ]);
        $this->assertSame('new-fnsku', $sku->refresh()->platform_label_code);
    }

    public function test_clearing_platform_label_code_removes_managed_alias(): void
    {
        [, $sku] = $this->skuForAliasSync('SKU-FNSKU-CLEAR', 'CLEAR-ME');
        app(PlatformLabelAliasSync::class)->sync($sku);

        Livewire::actingAs($this->internalUser())
            ->test(SkuEdit::class, ['sku' => $sku])
            ->set('platformLabelCode', '')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('skus.index'));

        $this->assertDatabaseMissing('barcode_aliases', [
            'model_type' => BarcodeAlias::MODEL_TYPE_SKU,
            'model_id' => $sku->id,
            'source' => BarcodeAlias::SOURCE_PLATFORM_LABEL_CODE,
        ]);
        $this->assertNull($sku->refresh()->platform_label_code);
    }

    public function test_clearing_platform_label_code_removes_imported_alias_and_mirror(): void
    {
        [$tenant, $sku] = $this->skuForAliasSync('SKU-FNSKU-CLEAR-IMPORT');
        BarcodeAlias::create([
            'tenant_id' => $tenant->id,
            'model_type' => BarcodeAlias::MODEL_TYPE_SKU,
            'model_id' => $sku->id,
            'barcode' => 'IMPORTED-FNSKU',
            'normalized_barcode' => 'IMPORTEDFNSKU',
            'barcode_type' => 'platform_label',
            'is_primary' => true,
            'is_active' => true,
            'source' => BarcodeAlias::SOURCE_IMPORT,
        ]);
        $sku->update(['platform_label_code' => 'IMPORTED-FNSKU']);

        Livewire::actingAs($this->internalUser())
            ->test(SkuEdit::class, ['sku' => $sku])
            ->set('platformLabelCode', '')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('skus.index'));

        $this->assertDatabaseMissing('barcode_aliases', [
            'model_type' => BarcodeAlias::MODEL_TYPE_SKU,
            'model_id' => $sku->id,
            'barcode_type' => 'platform_label',
        ]);
        $this->assertNull($sku->refresh()->platform_label_code);
    }

    public function test_edit_page_reads_and_clears_stock_item_primary_barcode_alias(): void
    {
        $tenant = Tenant::factory()->create();
        $stockItem = StockItem::factory()->for($tenant)->create(['barcode' => null]);
        $shop = Shop::factory()->for($tenant)->create();
        $sku = Sku::factory()->for($tenant)->for($shop)->for($stockItem)->create(['sku' => 'SKU-EDIT-ALIAS-BARCODE']);
        BarcodeAlias::create([
            'tenant_id' => $tenant->id,
            'model_type' => BarcodeAlias::MODEL_TYPE_STOCK_ITEM,
            'model_id' => $stockItem->id,
            'barcode' => 'PRIMARY-ALIAS-BAR',
            'normalized_barcode' => 'PRIMARYALIASBAR',
            'barcode_type' => 'jan',
            'is_primary' => true,
            'is_active' => true,
        ]);

        Livewire::actingAs($this->internalUser())
            ->test(SkuEdit::class, ['sku' => $sku])
            ->assertSet('stockItem.barcode', 'PRIMARY-ALIAS-BAR')
            ->set('stockItem.barcode', '')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('skus.index'));

        $this->assertDatabaseMissing('barcode_aliases', [
            'model_type' => BarcodeAlias::MODEL_TYPE_STOCK_ITEM,
            'model_id' => $stockItem->id,
            'barcode' => 'PRIMARY-ALIAS-BAR',
        ]);
    }

    public function test_manual_alias_on_same_sku_wins_over_managed_alias(): void
    {
        [$tenant, $sku] = $this->skuForAliasSync('SKU-FNSKU-MANUAL', 'MANUAL-1');
        BarcodeAlias::create([
            'tenant_id' => $tenant->id,
            'model_type' => BarcodeAlias::MODEL_TYPE_SKU,
            'model_id' => $sku->id,
            'barcode' => 'manual 1',
            'normalized_barcode' => 'MANUAL1',
            'barcode_type' => 'platform_label',
            'is_active' => true,
        ]);

        app(PlatformLabelAliasSync::class)->sync($sku);

        $this->assertSame(1, BarcodeAlias::where('tenant_id', $tenant->id)->where('normalized_barcode', 'MANUAL1')->count());
        $this->assertDatabaseMissing('barcode_aliases', [
            'model_type' => BarcodeAlias::MODEL_TYPE_SKU,
            'model_id' => $sku->id,
            'source' => BarcodeAlias::SOURCE_PLATFORM_LABEL_CODE,
        ]);
    }

    public function test_platform_label_code_collision_rejects_save_and_rolls_back(): void
    {
        [$tenant, $firstSku] = $this->skuForAliasSync('SKU-FNSKU-FIRST');
        $secondStockItem = StockItem::factory()->for($tenant)->create();
        $secondSku = Sku::factory()->for($tenant)->for($secondStockItem)->create([
            'sku' => 'SKU-FNSKU-SECOND',
            'name' => 'Second SKU',
            'shop_id' => null,
        ]);
        BarcodeAlias::create([
            'tenant_id' => $tenant->id,
            'model_type' => BarcodeAlias::MODEL_TYPE_SKU,
            'model_id' => $firstSku->id,
            'barcode' => 'DUP-FNSKU',
            'normalized_barcode' => 'DUPFNSKU',
            'barcode_type' => 'platform_label',
            'is_active' => true,
        ]);

        Livewire::actingAs($this->internalUser())
            ->test(SkuEdit::class, ['sku' => $secondSku])
            ->set('name', 'Should Roll Back')
            ->set('platformLabelCode', 'dup fnsku')
            ->call('save')
            ->assertHasErrors(['platformLabelCode']);

        $this->assertNotSame('Should Roll Back', $secondSku->refresh()->name);
        $this->assertDatabaseMissing('barcode_aliases', [
            'model_id' => $secondSku->id,
            'normalized_barcode' => 'DUPFNSKU',
        ]);
    }

    public function test_same_platform_label_code_across_tenants_is_allowed(): void
    {
        [$firstTenant, $firstSku] = $this->skuForAliasSync('SKU-FNSKU-TENANT-A', 'SHARED-FNSKU');
        app(PlatformLabelAliasSync::class)->sync($firstSku);
        $secondTenant = Tenant::factory()->create(['code' => 'FNB']);

        Livewire::actingAs($this->internalUser())
            ->test(SkuCreate::class)
            ->set('tenantId', (string) $secondTenant->id)
            ->set('sku', 'SKU-FNSKU-TENANT-B')
            ->set('name', 'Cross tenant FNSKU')
            ->set('platformLabelCode', 'shared fnsku')
            ->set('stockItem.name', 'Cross tenant stock')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('skus.index'));

        $this->assertSame(1, BarcodeAlias::where('tenant_id', $firstTenant->id)->where('normalized_barcode', 'SHAREDFNSKU')->count());
        $this->assertSame(1, BarcodeAlias::where('tenant_id', $secondTenant->id)->where('normalized_barcode', 'SHAREDFNSKU')->count());
    }

    public function test_managed_alias_is_read_only_in_alias_panel(): void
    {
        [, $sku] = $this->skuForAliasSync('SKU-FNSKU-READONLY', 'READONLY-FNSKU');
        app(PlatformLabelAliasSync::class)->sync($sku);
        $alias = BarcodeAlias::where('model_id', $sku->id)->firstOrFail();

        Livewire::actingAs($this->internalUser())
            ->test(SkusIndex::class)
            ->call('openAliasPanel', $sku->id)
            ->assertSee(__('skus.alias_source_fnsku_field'))
            ->call('editBarcodeAlias', $alias->id)
            ->assertForbidden();

        Livewire::actingAs($this->internalUser())
            ->test(SkusIndex::class)
            ->call('openAliasPanel', $sku->id)
            ->call('deactivateBarcodeAlias', $alias->id)
            ->assertForbidden();

        $this->assertTrue($alias->refresh()->is_active);
        $this->assertSame('platform_label', $alias->barcode_type);
    }

    public function test_imported_stock_item_barcode_alias_can_be_updated_and_deactivated(): void
    {
        $tenant = Tenant::factory()->create();
        $stockItem = StockItem::factory()->for($tenant)->create();
        $sku = Sku::factory()->for($tenant)->for($stockItem)->create();
        $alias = BarcodeAlias::create([
            'tenant_id' => $tenant->id,
            'model_type' => BarcodeAlias::MODEL_TYPE_STOCK_ITEM,
            'model_id' => $stockItem->id,
            'barcode' => '4912345678901',
            'normalized_barcode' => '4912345678901',
            'barcode_type' => 'unknown',
            'is_active' => true,
            'source' => BarcodeAlias::SOURCE_IMPORT,
        ]);

        Livewire::actingAs($this->internalUser())
            ->test(SkusIndex::class)
            ->call('openAliasPanel', $sku->id)
            ->call('editBarcodeAlias', $alias->id)
            ->set('aliasEdit.barcode', '49-1234-5678-902')
            ->set('aliasEdit.barcode_type', 'jan')
            ->set('aliasEdit.label', 'Updated package barcode')
            ->call('saveBarcodeAlias', $alias->id)
            ->assertHasNoErrors()
            ->assertSee(__('skus.alias_updated'))
            ->call('deactivateBarcodeAlias', $alias->id)
            ->assertSee(__('skus.alias_deactivated'))
            ->call('reactivateBarcodeAlias', $alias->id)
            ->assertSee(__('skus.alias_reactivated'));

        $alias->refresh();

        $this->assertSame('49-1234-5678-902', $alias->barcode);
        $this->assertSame('4912345678902', $alias->normalized_barcode);
        $this->assertSame('jan', $alias->barcode_type);
        $this->assertSame('Updated package barcode', $alias->label);
        $this->assertTrue($alias->is_active);
    }

    public function test_duplicate_normalized_barcode_in_same_tenant_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $firstStockItem = StockItem::factory()->for($tenant)->create();
        $secondStockItem = StockItem::factory()->for($tenant)->create();
        $firstSku = Sku::factory()->for($tenant)->for($firstStockItem)->create();
        $secondSku = Sku::factory()->for($tenant)->for($secondStockItem)->create();

        BarcodeAlias::create([
            'tenant_id' => $tenant->id,
            'model_type' => BarcodeAlias::MODEL_TYPE_SKU,
            'model_id' => $firstSku->id,
            'barcode' => '49-0123',
            'normalized_barcode' => '490123',
            'barcode_type' => 'jan',
            'is_active' => true,
        ]);

        Livewire::actingAs($this->internalUser())
            ->test(SkusIndex::class)
            ->call('openAliasPanel', $secondSku->id)
            ->set('aliasBarcode', '49 0123')
            ->call('createBarcodeAlias')
            ->assertHasErrors(['normalized_barcode']);
    }

    public function test_same_normalized_barcode_across_different_tenants_is_allowed(): void
    {
        $firstTenant = Tenant::factory()->create();
        $secondTenant = Tenant::factory()->create();
        $firstStockItem = StockItem::factory()->for($firstTenant)->create();
        $secondStockItem = StockItem::factory()->for($secondTenant)->create();
        $firstSku = Sku::factory()->for($firstTenant)->for($firstStockItem)->create();
        $secondSku = Sku::factory()->for($secondTenant)->for($secondStockItem)->create();

        BarcodeAlias::create([
            'tenant_id' => $firstTenant->id,
            'model_type' => BarcodeAlias::MODEL_TYPE_SKU,
            'model_id' => $firstSku->id,
            'barcode' => '49-0123',
            'normalized_barcode' => '490123',
            'barcode_type' => 'jan',
            'is_active' => true,
        ]);

        Livewire::actingAs($this->internalUser())
            ->test(SkusIndex::class)
            ->call('openAliasPanel', $secondSku->id)
            ->set('aliasBarcode', '49 0123')
            ->call('createBarcodeAlias')
            ->assertHasNoErrors();

        $this->assertSame(2, BarcodeAlias::where('normalized_barcode', '490123')->count());
    }

    public function test_tenant_user_cannot_create_alias_for_another_tenant_sku(): void
    {
        [, $user] = $this->tenantUser();
        $otherTenant = Tenant::factory()->create();
        $stockItem = StockItem::factory()->for($otherTenant)->create();
        $sku = Sku::factory()->for($otherTenant)->for($stockItem)->create();

        Livewire::actingAs($user)
            ->test(SkusIndex::class)
            ->call('openAliasPanel', $sku->id)
            ->assertNotFound();

        $this->assertDatabaseMissing('barcode_aliases', [
            'tenant_id' => $otherTenant->id,
            'model_id' => $sku->id,
        ]);
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

    public function test_sku_index_rows_per_page_control_updates_pagination(): void
    {
        $tenant = Tenant::factory()->create();

        foreach (range(1, 20) as $index) {
            $stockItem = StockItem::factory()->for($tenant)->create();
            Sku::factory()->for($tenant)->for($stockItem)->create([
                'sku' => sprintf('SKU-PER-PAGE-%02d', $index),
            ]);
        }

        Livewire::actingAs($this->internalUser())
            ->test(SkusIndex::class)
            ->assertSee('Showing 1 to 15 of 20 results')
            ->set('perPage', 30)
            ->assertSet('perPage', 30)
            ->assertSee('Showing 1 to 20 of 20 results');
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
        ProductType::create(['slug' => 'normal', 'name' => 'Normal', 'sort_order' => 0]);
        ProductType::create(['slug' => 'apparel', 'name' => 'Apparel', 'sort_order' => 10]);

        $tenant = Tenant::factory()->create();
        $shop = Shop::factory()->for($tenant)->create(['code' => 'SHOP1']);
        $stockItem = StockItem::factory()->for($tenant)->create([
            'code' => 'STOCK1',
            'short_name' => 'Shorty',
            'brand' => 'Acme',
            'variation_code' => 'BLUE-M',
            'size' => 'M',
            'color' => 'Blue',
            'barcode' => null,
            'product_type' => 'normal',
        ]);
        $sku = Sku::factory()->for($tenant)->for($shop)->for($stockItem)->create([
            'sku' => 'SKU-FLAT-VIEWS',
            'name' => 'Flat view SKU',
            'platform_sku' => 'SELLER-SKU',
            'platform_product_id' => 'B000ASIN',
            'platform_label_code' => 'FNSKU123',
            'platform_variant_name' => 'Blue / M',
        ]);
        BarcodeAlias::create([
            'tenant_id' => $tenant->id,
            'model_type' => BarcodeAlias::MODEL_TYPE_STOCK_ITEM,
            'model_id' => $stockItem->id,
            'barcode' => '4900000000001',
            'normalized_barcode' => '4900000000001',
            'barcode_type' => 'jan',
            'is_primary' => true,
            'is_active' => true,
        ]);

        Livewire::actingAs($this->internalUser())
            ->withQueryParams(['view' => 'catalog'])
            ->test(SkusIndex::class)
            ->assertSet('view', 'catalog')
            ->assertSee(__('skus.col_brand'))
            ->assertSee(__('skus.col_variation_code'))
            ->assertSee(__('skus.col_barcode'))
            ->assertSee(__('skus.col_size'))
            ->assertSee(__('skus.col_color'))
            ->assertSee(__('skus.col_product_type'))
            ->assertSee('Acme')
            ->assertSee('BLUE-M')
            ->assertSee('M')
            ->assertSee('Blue')
            ->assertSee('4900000000001')
            ->assertDontSee('Shorty')
            ->assertDontSee('STOCK1')
            ->assertDontSee(__('skus.col_platform_ids'))
            ->set("catalogDrafts.{$sku->id}.product_type", 'apparel')
            ->call('saveCatalogField', $sku->id, 'product_type')
            ->assertHasNoErrors();

        $this->assertSame('apparel', $stockItem->refresh()->product_type);

        Livewire::actingAs($this->internalUser())
            ->withQueryParams(['view' => 'marketplace'])
            ->test(SkusIndex::class)
            ->assertSet('view', 'marketplace')
            ->assertSee(__('skus.col_name'))
            ->assertSee(__('skus.col_asin'))
            ->assertSee(__('skus.col_fnsku'))
            ->assertDontSee(__('skus.col_seller_sku'))
            ->assertDontSee(__('skus.col_variant'))
            ->assertDontSee('SELLER-SKU')
            ->assertSee('B000ASIN')
            ->assertSee('FNSKU123')
            ->assertDontSee('Blue / M');

        Livewire::actingAs($this->internalUser())
            ->withQueryParams(['view' => 'catalog'])
            ->test(SkusIndex::class)
            ->set('search', '4900 0000 00001')
            ->assertSee('SKU-FLAT-VIEWS');

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
            ->assertSee('colspan="11"', false);

        $this->actingAs($this->internalUser())
            ->get('/skus?view=marketplace')
            ->assertOk()
            ->assertSee('colspan="6"', false);

        $this->actingAs($this->internalUser())
            ->get('/skus?view=logistics')
            ->assertOk()
            ->assertSee('colspan="12"', false);
    }

    public function test_internal_user_can_upload_stock_item_image(): void
    {
        Storage::fake('public');
        $tenant = Tenant::factory()->create();
        $stockItem = StockItem::factory()->for($tenant)->create();
        Sku::factory()->for($tenant)->for($stockItem)->create();

        Livewire::actingAs($this->internalUser())
            ->test(SkusIndex::class)
            ->call('openImagePanel', $stockItem->id)
            ->set('stockImages', [UploadedFile::fake()->image('front.jpg', 64, 48)])
            ->call('uploadStockImage')
            ->assertHasNoErrors();

        $asset = MediaAsset::firstOrFail();

        $this->assertSame($tenant->id, $asset->tenant_id);
        $this->assertSame('stock_item', $asset->model_type);
        $this->assertSame($stockItem->id, $asset->model_id);
        $this->assertSame('product', $asset->type);
        $this->assertSame($stockItem->code.'-01.jpg', $asset->file_name);
        $this->assertTrue($asset->is_primary);
        $this->assertSame(64, $asset->width);
        $this->assertSame(48, $asset->height);
        $this->assertSame('/media/'.$asset->id, parse_url(route('media.show', $asset), PHP_URL_PATH));
        Storage::disk('public')->assertExists($asset->path);
    }

    public function test_tenant_user_can_upload_image_for_own_tenant_stock_item(): void
    {
        Storage::fake('public');
        [$tenant, $user] = $this->tenantUser();
        $stockItem = StockItem::factory()->for($tenant)->create();
        Sku::factory()->for($tenant)->for($stockItem)->create();

        Livewire::actingAs($user)
            ->test(SkusIndex::class)
            ->call('openImagePanel', $stockItem->id)
            ->set('stockImages', [UploadedFile::fake()->image('own.png')])
            ->call('uploadStockImage')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('media_assets', [
            'tenant_id' => $tenant->id,
            'model_type' => 'stock_item',
            'model_id' => $stockItem->id,
        ]);
    }

    public function test_tenant_user_cannot_upload_image_for_another_tenant_stock_item(): void
    {
        Storage::fake('public');
        [, $user] = $this->tenantUser();
        $otherTenant = Tenant::factory()->create();
        $stockItem = StockItem::factory()->for($otherTenant)->create();

        Livewire::actingAs($user)
            ->test(SkusIndex::class)
            ->call('openImagePanel', $stockItem->id)
            ->assertNotFound();

        $this->assertSame(0, MediaAsset::count());
    }

    public function test_stock_image_upload_rejects_non_image_and_oversized_image(): void
    {
        Storage::fake('public');
        $tenant = Tenant::factory()->create();
        $stockItem = StockItem::factory()->for($tenant)->create();

        Livewire::actingAs($this->internalUser())
            ->test(SkusIndex::class)
            ->call('openImagePanel', $stockItem->id)
            ->set('stockImages', [UploadedFile::fake()->create('notes.txt', 1, 'text/plain')])
            ->call('uploadStockImage')
            ->assertHasErrors(['stockImages.0']);

        Livewire::actingAs($this->internalUser())
            ->test(SkusIndex::class)
            ->call('openImagePanel', $stockItem->id)
            ->set('stockImages', [UploadedFile::fake()->image('bad.gif')])
            ->call('uploadStockImage')
            ->assertHasErrors(['stockImages.0']);

        Livewire::actingAs($this->internalUser())
            ->test(SkusIndex::class)
            ->call('openImagePanel', $stockItem->id)
            ->set('stockImages', [UploadedFile::fake()->image('large.jpg')->size(5121)])
            ->call('uploadStockImage')
            ->assertHasErrors(['stockImages.0']);
    }

    public function test_primary_image_logic_uses_first_uploaded_image_until_changed(): void
    {
        Storage::fake('public');
        $tenant = Tenant::factory()->create();
        $stockItem = StockItem::factory()->for($tenant)->create();

        Livewire::actingAs($this->internalUser())
            ->test(SkusIndex::class)
            ->call('openImagePanel', $stockItem->id)
            ->set('stockImages', [UploadedFile::fake()->image('first.jpg')])
            ->call('uploadStockImage');

        Livewire::actingAs($this->internalUser())
            ->test(SkusIndex::class)
            ->call('openImagePanel', $stockItem->id)
            ->set('stockImages', [UploadedFile::fake()->image('main.jpg')])
            ->call('uploadStockImage')
            ->assertHasNoErrors();

        $first = MediaAsset::where('file_name', $stockItem->code.'-01.jpg')->firstOrFail();
        $second = MediaAsset::where('file_name', $stockItem->code.'-02.jpg')->firstOrFail();

        $this->assertTrue($first->is_primary);
        $this->assertFalse($second->is_primary);

        Livewire::actingAs($this->internalUser())
            ->test(SkusIndex::class)
            ->call('openImagePanel', $stockItem->id)
            ->set('stockImages', [UploadedFile::fake()->image('packaging.jpg')])
            ->call('uploadStockImage')
            ->assertHasNoErrors();

        $this->assertTrue($first->refresh()->is_primary);
        $this->assertFalse(MediaAsset::where('file_name', $stockItem->code.'-03.jpg')->firstOrFail()->is_primary);
    }

    public function test_upload_can_add_multiple_stock_item_images_and_order_them(): void
    {
        Storage::fake('public');
        $tenant = Tenant::factory()->create();
        $stockItem = StockItem::factory()->for($tenant)->create(['code' => 'STK-MULTI']);

        Livewire::actingAs($this->internalUser())
            ->test(SkusIndex::class)
            ->call('openImagePanel', $stockItem->id)
            ->set('stockImages', [
                UploadedFile::fake()->image('first.jpg'),
                UploadedFile::fake()->image('second.png'),
            ])
            ->call('uploadStockImage')
            ->assertHasNoErrors();

        $first = MediaAsset::where('file_name', 'STK-MULTI-01.jpg')->firstOrFail();
        $second = MediaAsset::where('file_name', 'STK-MULTI-02.png')->firstOrFail();

        $this->assertTrue($first->is_primary);
        $this->assertFalse($second->is_primary);
        $this->assertSame(1, $first->sort_order);
        $this->assertSame(2, $second->sort_order);
        Storage::disk('public')->assertExists($first->path);
        Storage::disk('public')->assertExists($second->path);

        Livewire::actingAs($this->internalUser())
            ->test(SkusIndex::class)
            ->call('moveImageUp', $second->id)
            ->assertHasNoErrors();

        $this->assertTrue($second->refresh()->is_primary);
        $this->assertSame(1, $second->sort_order);
        $this->assertFalse($first->refresh()->is_primary);
        $this->assertSame(2, $first->sort_order);
    }

    public function test_stock_image_upload_respects_preview_order_and_removed_images(): void
    {
        Storage::fake('public');
        $tenant = Tenant::factory()->create();
        $stockItem = StockItem::factory()->for($tenant)->create(['code' => 'STK-PREVIEW']);
        $first = UploadedFile::fake()->image('first.jpg', 80, 80);
        $second = UploadedFile::fake()->image('second.jpg', 80, 80);
        $third = UploadedFile::fake()->image('third.jpg', 80, 80);

        Livewire::actingAs($this->internalUser())
            ->test(SkusIndex::class)
            ->call('openImagePanel', $stockItem->id)
            ->set('stockImages', [$first, $second, $third])
            ->set('stockImageOrder', [2, 0])
            ->call('uploadStockImage')
            ->assertHasNoErrors();

        $this->assertSame(['STK-PREVIEW-01.jpg', 'STK-PREVIEW-02.jpg'], MediaAsset::orderBy('sort_order')->pluck('file_name')->all());
        $this->assertSame(2, MediaAsset::count());
        $this->assertTrue(MediaAsset::where('file_name', 'STK-PREVIEW-01.jpg')->firstOrFail()->is_primary);
    }

    public function test_image_panel_opens_gallery_for_existing_images_and_manage_for_empty_stock_item(): void
    {
        $tenant = Tenant::factory()->create();
        $emptyStockItem = StockItem::factory()->for($tenant)->create();
        $stockItem = StockItem::factory()->for($tenant)->create();
        $asset = $this->mediaAsset($stockItem, ['is_primary' => true, 'sort_order' => 1]);

        Livewire::actingAs($this->internalUser())
            ->test(SkusIndex::class)
            ->call('openImagePanel', $emptyStockItem->id)
            ->assertSet('imagePanelMode', 'manage')
            ->assertSet('viewingImageId', null);

        Livewire::actingAs($this->internalUser())
            ->test(SkusIndex::class)
            ->call('openImagePanel', $stockItem->id)
            ->assertSet('imagePanelMode', 'gallery')
            ->assertSet('viewingImageId', $asset->id)
            ->call('manageImages')
            ->assertSet('imagePanelMode', 'manage')
            ->call('showImageGallery', $asset->id)
            ->assertSet('imagePanelMode', 'gallery')
            ->assertSet('viewingImageId', $asset->id);
    }

    public function test_upload_resizes_large_stock_item_image_to_max_2000px_side(): void
    {
        Storage::fake('public');
        $tenant = Tenant::factory()->create();
        $stockItem = StockItem::factory()->for($tenant)->create(['code' => 'STK-LARGE']);

        Livewire::actingAs($this->internalUser())
            ->test(SkusIndex::class)
            ->call('openImagePanel', $stockItem->id)
            ->set('stockImages', [UploadedFile::fake()->image('large.jpg', 3000, 1200)])
            ->call('uploadStockImage')
            ->assertHasNoErrors();

        $asset = MediaAsset::firstOrFail();

        $this->assertSame('STK-LARGE-01.jpg', $asset->file_name);
        $this->assertSame(2000, $asset->width);
        $this->assertSame(800, $asset->height);
        Storage::disk('public')->assertExists($asset->path);

        $storedSize = getimagesize(Storage::disk('public')->path($asset->path));

        $this->assertSame(2000, $storedSize[0]);
        $this->assertSame(800, $storedSize[1]);
    }

    public function test_set_primary_action_changes_primary_image(): void
    {
        $tenant = Tenant::factory()->create();
        $stockItem = StockItem::factory()->for($tenant)->create();
        $first = $this->mediaAsset($stockItem, ['is_primary' => true, 'sort_order' => 1]);
        $second = $this->mediaAsset($stockItem, ['is_primary' => false, 'sort_order' => 2]);

        Livewire::actingAs($this->internalUser())
            ->test(SkusIndex::class)
            ->call('setPrimaryImage', $second->id)
            ->assertHasNoErrors();

        $this->assertFalse($first->refresh()->is_primary);
        $this->assertTrue($second->refresh()->is_primary);
    }

    public function test_delete_image_removes_media_row_and_file(): void
    {
        Storage::fake('public');
        $tenant = Tenant::factory()->create();
        $stockItem = StockItem::factory()->for($tenant)->create();
        Storage::disk('public')->put('product-images/tenant-'.$tenant->id.'/stock-items/'.$stockItem->id.'/delete.jpg', 'image');
        $asset = $this->mediaAsset($stockItem, [
            'path' => 'product-images/tenant-'.$tenant->id.'/stock-items/'.$stockItem->id.'/delete.jpg',
            'is_primary' => true,
        ]);

        Livewire::actingAs($this->internalUser())
            ->test(SkusIndex::class)
            ->call('deleteImage', $asset->id)
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('media_assets', ['id' => $asset->id]);
        Storage::disk('public')->assertMissing($asset->path);
    }

    public function test_deleting_primary_image_promotes_next_image(): void
    {
        Storage::fake('public');
        $tenant = Tenant::factory()->create();
        $stockItem = StockItem::factory()->for($tenant)->create();
        $first = $this->mediaAsset($stockItem, ['is_primary' => true, 'sort_order' => 1]);
        $second = $this->mediaAsset($stockItem, ['is_primary' => false, 'sort_order' => 2]);

        Livewire::actingAs($this->internalUser())
            ->test(SkusIndex::class)
            ->call('deleteImage', $first->id)
            ->assertHasNoErrors();

        $this->assertTrue($second->refresh()->is_primary);
    }

    public function test_virtual_bundle_sku_does_not_show_upload_action(): void
    {
        $tenant = Tenant::factory()->create();
        Sku::factory()->virtualBundle()->for($tenant)->create(['sku' => 'SKU-NO-IMAGE-UPLOAD']);

        Livewire::actingAs($this->internalUser())
            ->test(SkusIndex::class)
            ->assertSee('SKU-NO-IMAGE-UPLOAD')
            ->assertDontSee(__('skus.upload_image'));
    }

    public function test_sku_index_eager_loads_primary_images_without_n_plus_one(): void
    {
        $tenant = Tenant::factory()->create();

        foreach (range(1, 3) as $index) {
            $stockItem = StockItem::factory()->for($tenant)->create();
            Sku::factory()->for($tenant)->for($stockItem)->create(['sku' => 'SKU-IMAGE-'.$index]);
            $this->mediaAsset($stockItem, ['file_name' => 'image-'.$index.'.jpg', 'is_primary' => true]);
        }

        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            if (str_contains($query->sql, 'media_assets')) {
                $queries[] = $query->sql;
            }
        });

        Livewire::actingAs($this->internalUser())
            ->test(SkusIndex::class)
            ->assertSee('SKU-IMAGE-1')
            ->assertSee('image-1.jpg', false);

        $this->assertLessThanOrEqual(2, count($queries));
    }

    public function test_unauthenticated_user_cannot_upload_image(): void
    {
        Storage::fake('public');
        $tenant = Tenant::factory()->create();
        $stockItem = StockItem::factory()->for($tenant)->create();

        Livewire::test(SkusIndex::class)
            ->set('managingStockItemId', $stockItem->id)
            ->set('stockImages', [UploadedFile::fake()->image('guest.jpg')])
            ->call('uploadStockImage')
            ->assertNotFound();

        $this->assertSame(0, MediaAsset::count());
    }

    public function test_upload_rejects_eleventh_image_for_same_stock_item(): void
    {
        Storage::fake('public');
        $tenant = Tenant::factory()->create();
        $stockItem = StockItem::factory()->for($tenant)->create();

        foreach (range(1, 10) as $index) {
            $this->mediaAsset($stockItem, ['file_name' => 'existing-'.$index.'.jpg']);
        }

        Livewire::actingAs($this->internalUser())
            ->test(SkusIndex::class)
            ->call('openImagePanel', $stockItem->id)
            ->set('stockImages', [UploadedFile::fake()->image('eleventh.jpg')])
            ->call('uploadStockImage')
            ->assertHasErrors(['stockImages']);

        $this->assertSame(10, MediaAsset::count());
    }

    public function test_import_amazon_image_creates_public_amazon_media_row(): void
    {
        Storage::fake('public');
        $tenant = Tenant::factory()->create();
        $shop = Shop::factory()->for($tenant)->create(['platform' => 'amazon']);
        $connection = $this->amazonConnection($shop);
        $stockItem = StockItem::factory()->for($tenant)->create();
        $sku = Sku::factory()->for($tenant)->for($shop)->for($stockItem)->create([
            'platform_product_id' => 'B000TEST01',
        ]);
        $imageUrl = 'https://m.media-amazon.com/images/I/main.jpg';
        $this->fakeAmazonImageImport($connection, $imageUrl, UploadedFile::fake()->image('main.jpg', 70, 50));

        Livewire::actingAs($this->internalUser())
            ->test(SkusIndex::class)
            ->call('importAmazonImage', $sku->id)
            ->assertSee(__('skus.amazon_image_imported'));

        $asset = MediaAsset::firstOrFail();

        $this->assertSame($tenant->id, $asset->tenant_id);
        $this->assertSame(MediaAsset::MODEL_TYPE_STOCK_ITEM, $asset->model_type);
        $this->assertSame($stockItem->id, $asset->model_id);
        $this->assertSame('amazon', $asset->type);
        $this->assertSame('public', $asset->disk);
        $this->assertSame($imageUrl, $asset->original_url);
        $this->assertTrue($asset->is_primary);
        $this->assertSame(70, $asset->width);
        $this->assertSame(50, $asset->height);
        Storage::disk('public')->assertExists($asset->path);
    }

    public function test_import_amazon_image_does_not_replace_existing_primary(): void
    {
        Storage::fake('public');
        $tenant = Tenant::factory()->create();
        $shop = Shop::factory()->for($tenant)->create(['platform' => 'amazon']);
        $connection = $this->amazonConnection($shop);
        $stockItem = StockItem::factory()->for($tenant)->create();
        $sku = Sku::factory()->for($tenant)->for($shop)->for($stockItem)->create(['platform_product_id' => 'B000TEST02']);
        $primary = $this->mediaAsset($stockItem, ['is_primary' => true, 'type' => 'main']);
        $this->fakeAmazonImageImport($connection, 'https://m.media-amazon.com/images/I/second.jpg', UploadedFile::fake()->image('second.jpg'));

        Livewire::actingAs($this->internalUser())
            ->test(SkusIndex::class)
            ->call('importAmazonImage', $sku->id)
            ->assertSee(__('skus.amazon_image_imported'));

        $this->assertTrue($primary->refresh()->is_primary);
        $this->assertFalse(MediaAsset::where('type', 'amazon')->firstOrFail()->is_primary);
    }

    public function test_import_amazon_image_requires_asin_and_amazon_shop(): void
    {
        Storage::fake('public');
        $tenant = Tenant::factory()->create();
        $stockItem = StockItem::factory()->for($tenant)->create();
        $amazonShop = Shop::factory()->for($tenant)->create(['platform' => 'amazon']);
        $manualShop = Shop::factory()->for($tenant)->create(['platform' => 'manual']);
        $missingAsin = Sku::factory()->for($tenant)->for($amazonShop)->for($stockItem)->create(['platform_product_id' => null]);
        $notAmazon = Sku::factory()->for($tenant)->for($manualShop)->for($stockItem)->create(['platform_product_id' => 'B000TEST03']);

        Livewire::actingAs($this->internalUser())
            ->test(SkusIndex::class)
            ->call('importAmazonImage', $missingAsin->id)
            ->assertSee(__('skus.amazon_image_requires_asin'))
            ->call('importAmazonImage', $notAmazon->id)
            ->assertSee(__('skus.amazon_image_requires_amazon_shop'));

        $this->assertSame(0, MediaAsset::count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_import_amazon_image_handles_api_error_without_orphan_file(): void
    {
        Storage::fake('public');
        $tenant = Tenant::factory()->create();
        $shop = Shop::factory()->for($tenant)->create(['platform' => 'amazon']);
        $this->amazonConnection($shop);
        $stockItem = StockItem::factory()->for($tenant)->create();
        $sku = Sku::factory()->for($tenant)->for($shop)->for($stockItem)->create(['platform_product_id' => 'B000TEST04']);

        Http::fake([
            'https://api.amazon.com/auth/o2/token' => Http::response(['access_token' => 'token', 'expires_in' => 3600], 200),
            'https://sellingpartnerapi-fe.amazon.com/catalog/2022-04-01/items/*' => Http::response(['errors' => [['message' => 'Catalog unavailable']]], 500),
        ]);

        Livewire::actingAs($this->internalUser())
            ->test(SkusIndex::class)
            ->call('importAmazonImage', $sku->id)
            ->assertSee('Amazon image lookup failed');

        $this->assertSame(0, MediaAsset::count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_import_amazon_image_is_idempotent_for_same_original_url(): void
    {
        Storage::fake('public');
        $tenant = Tenant::factory()->create();
        $shop = Shop::factory()->for($tenant)->create(['platform' => 'amazon']);
        $connection = $this->amazonConnection($shop);
        $stockItem = StockItem::factory()->for($tenant)->create();
        $sku = Sku::factory()->for($tenant)->for($shop)->for($stockItem)->create(['platform_product_id' => 'B000TEST05']);
        $imageUrl = 'https://m.media-amazon.com/images/I/idempotent.jpg';
        $this->fakeAmazonImageImport($connection, $imageUrl, UploadedFile::fake()->image('idempotent.jpg'));

        Livewire::actingAs($this->internalUser())
            ->test(SkusIndex::class)
            ->call('importAmazonImage', $sku->id)
            ->call('importAmazonImage', $sku->id)
            ->assertSee(__('skus.amazon_image_already_imported'));

        $this->assertSame(1, MediaAsset::where('type', 'amazon')->count());
    }

    public function test_import_amazon_image_replaces_previous_amazon_row_only(): void
    {
        Storage::fake('public');
        $tenant = Tenant::factory()->create();
        $shop = Shop::factory()->for($tenant)->create(['platform' => 'amazon']);
        $connection = $this->amazonConnection($shop);
        $stockItem = StockItem::factory()->for($tenant)->create();
        $sku = Sku::factory()->for($tenant)->for($shop)->for($stockItem)->create(['platform_product_id' => 'B000TEST06']);
        $userImage = $this->mediaAsset($stockItem, ['type' => 'main', 'file_name' => 'user.jpg']);
        $oldAmazon = $this->mediaAsset($stockItem, [
            'type' => 'amazon',
            'original_url' => 'https://m.media-amazon.com/images/I/old.jpg',
            'file_name' => 'old.jpg',
        ]);
        Storage::disk('public')->put($oldAmazon->path, 'old');
        $this->fakeAmazonImageImport($connection, 'https://m.media-amazon.com/images/I/new.jpg', UploadedFile::fake()->image('new.jpg'));

        Livewire::actingAs($this->internalUser())
            ->test(SkusIndex::class)
            ->call('importAmazonImage', $sku->id)
            ->assertSee(__('skus.amazon_image_imported'));

        $this->assertDatabaseHas('media_assets', ['id' => $userImage->id]);
        $this->assertDatabaseMissing('media_assets', ['id' => $oldAmazon->id]);
        $this->assertSame(1, MediaAsset::where('type', 'amazon')->count());
        Storage::disk('public')->assertMissing($oldAmazon->path);
    }

    public function test_tenant_user_cannot_import_amazon_image_for_another_tenants_sku(): void
    {
        Storage::fake('public');
        [, $user] = $this->tenantUser();
        $otherTenant = Tenant::factory()->create();
        $shop = Shop::factory()->for($otherTenant)->create(['platform' => 'amazon']);
        $stockItem = StockItem::factory()->for($otherTenant)->create();
        $sku = Sku::factory()->for($otherTenant)->for($shop)->for($stockItem)->create(['platform_product_id' => 'B000TEST07']);

        Livewire::actingAs($user)
            ->test(SkusIndex::class)
            ->call('importAmazonImage', $sku->id)
            ->assertNotFound();

        $this->assertSame(0, MediaAsset::count());
    }

    public function test_virtual_bundle_sku_does_not_allow_amazon_image_import(): void
    {
        $tenant = Tenant::factory()->create();
        $shop = Shop::factory()->for($tenant)->create(['platform' => 'amazon']);
        $sku = Sku::factory()->virtualBundle()->for($tenant)->for($shop)->create([
            'sku' => 'SKU-AMAZON-BUNDLE',
            'platform_product_id' => 'B000TEST08',
        ]);

        Livewire::actingAs($this->internalUser())
            ->test(SkusIndex::class)
            ->assertSee('SKU-AMAZON-BUNDLE')
            ->assertDontSee(__('skus.fetch_amazon_image'))
            ->call('importAmazonImage', $sku->id)
            ->assertNotFound();
    }

    public function test_import_amazon_image_rejects_non_image_and_oversized_downloads(): void
    {
        Storage::fake('public');
        $tenant = Tenant::factory()->create();
        $shop = Shop::factory()->for($tenant)->create(['platform' => 'amazon']);
        $connection = $this->amazonConnection($shop);
        $stockItem = StockItem::factory()->for($tenant)->create();
        $sku = Sku::factory()->for($tenant)->for($shop)->for($stockItem)->create(['platform_product_id' => 'B000TEST09']);

        $this->fakeAmazonImageImport($connection, 'https://m.media-amazon.com/images/I/not-image.jpg', 'not image');

        Livewire::actingAs($this->internalUser())
            ->test(SkusIndex::class)
            ->call('importAmazonImage', $sku->id)
            ->assertHasErrors(['amazonImage']);

        $this->assertSame(0, MediaAsset::count());

        $this->fakeAmazonImageImport($connection, 'https://m.media-amazon.com/images/I/large.jpg', str_repeat('x', 5 * 1024 * 1024 + 1));

        Livewire::actingAs($this->internalUser())
            ->test(SkusIndex::class)
            ->call('importAmazonImage', $sku->id)
            ->assertHasErrors(['amazonImage']);

        $this->assertSame(0, MediaAsset::count());

        $redirectUrl = 'https://m.media-amazon.com/images/I/redirect.jpg';
        Http::fake([
            'https://api.amazon.com/auth/o2/token' => Http::response(['access_token' => 'token', 'expires_in' => 3600], 200),
            rtrim($connection->endpoint, '/').'/catalog/2022-04-01/items/*' => Http::response([
                'images' => [[
                    'marketplaceId' => $connection->marketplace_id,
                    'images' => [[
                        'variant' => 'MAIN',
                        'link' => $redirectUrl,
                        'width' => 1000,
                        'height' => 1000,
                    ]],
                ]],
            ], 200),
            $redirectUrl => Http::response('', 302, ['Location' => 'https://example.test/redirected.jpg']),
        ]);

        Livewire::actingAs($this->internalUser())
            ->test(SkusIndex::class)
            ->call('importAmazonImage', $sku->id)
            ->assertHasErrors(['amazonImage']);

        $this->assertSame(0, MediaAsset::count());

        $lengthUrl = 'https://m.media-amazon.com/images/I/header-large.jpg';
        $smallImage = UploadedFile::fake()->image('small.jpg');
        Http::fake([
            'https://api.amazon.com/auth/o2/token' => Http::response(['access_token' => 'token', 'expires_in' => 3600], 200),
            rtrim($connection->endpoint, '/').'/catalog/2022-04-01/items/*' => Http::response([
                'images' => [[
                    'marketplaceId' => $connection->marketplace_id,
                    'images' => [[
                        'variant' => 'MAIN',
                        'link' => $lengthUrl,
                        'width' => 1000,
                        'height' => 1000,
                    ]],
                ]],
            ], 200),
            $lengthUrl => Http::response((string) file_get_contents($smallImage->getRealPath()), 200, ['Content-Length' => (string) (5 * 1024 * 1024 + 1)]),
        ]);

        Livewire::actingAs($this->internalUser())
            ->test(SkusIndex::class)
            ->call('importAmazonImage', $sku->id)
            ->assertHasErrors(['amazonImage']);

        $this->assertSame(0, MediaAsset::count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_logistics_view_renders_editable_fields_and_saves_stock_item_and_sku_defaults(): void
    {
        $tenant = Tenant::factory()->create();
        $stockItem = StockItem::factory()->for($tenant)->create([
            'code' => 'LOG-STOCK',
            'name' => 'Logistics Stock Item',
            'name_en' => 'English logistics stock item',
            'weight_value' => '12.345',
            'length_value' => '10.50',
            'width_value' => '8.50',
            'height_value' => '3.50',
        ]);
        $packaging = PackagingMaterial::factory()->create();
        $method = $this->shippingMethod('logistics_ship_method', 'Logistics Ship Method');
        $sku = Sku::factory()->for($tenant)->for($stockItem)->create(['sku' => 'SKU-LOGISTICS', 'name' => 'Hidden logistics SKU name']);

        Livewire::actingAs($this->internalUser())
            ->withQueryParams(['view' => 'logistics'])
            ->test(SkusIndex::class)
            ->assertSee(__('skus.col_stock_item'))
            ->assertSee(__('skus.col_name'))
            ->assertSee(__('skus.col_weight_g'))
            ->assertSee(__('skus.col_length_cm'))
            ->assertSee(__('skus.col_width_cm'))
            ->assertSee(__('skus.col_height_cm'))
            ->assertSee(__('skus.col_packaging'))
            ->assertSee(__('skus.col_shipping_method'))
            ->assertSee('LOG-STOCK')
            ->assertDontSee('Logistics Stock Item')
            ->assertSee('English logistics stock item')
            ->assertDontSee('Hidden logistics SKU name')
            ->assertDontSee('<span class="subtle">g</span>', false)
            ->assertDontSee('<span class="subtle">cm</span>', false)
            ->assertDontSee(__('skus.col_default_packaging'))
            ->assertDontSee(__('skus.col_default_shipping_method'))
            ->assertSet("logisticsDrafts.{$sku->id}.weight_value", '12')
            ->assertSet("logisticsDrafts.{$sku->id}.length_value", '10.5')
            ->assertSet("logisticsDrafts.{$sku->id}.width_value", '8.5')
            ->assertSet("logisticsDrafts.{$sku->id}.height_value", '3.5')
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

    public function test_logistics_view_edits_current_locale_stock_item_name_without_fallback(): void
    {
        $originalLocale = app()->getLocale();
        app()->setLocale('zh_CN');

        try {
            $tenant = Tenant::factory()->create();
            $stockItem = StockItem::factory()->for($tenant)->create([
                'name' => 'Base stock item name',
                'name_en' => 'English stock item name',
                'name_zh_tw' => '繁中庫存品名',
                'name_zh_cn' => null,
            ]);
            $sku = Sku::factory()->for($tenant)->for($stockItem)->create([
                'sku' => 'SKU-LOCALE-NAME',
                'name' => 'SKU fallback name',
            ]);

            Livewire::actingAs($this->internalUser())
                ->withQueryParams(['view' => 'logistics'])
                ->test(SkusIndex::class)
                ->assertSet("logisticsDrafts.{$sku->id}.localized_name", '')
                ->assertSee('wire:model="logisticsDrafts.'.$sku->id.'.localized_name"', false)
                ->assertDontSee('Base stock item name')
                ->assertDontSee('English stock item name')
                ->assertDontSee('繁中庫存品名')
                ->assertDontSee('SKU fallback name')
                ->set("logisticsDrafts.{$sku->id}.localized_name", '简中库存品名')
                ->call('saveLogisticsField', $sku->id, 'localized_name')
                ->assertHasNoErrors();

            $stockItem->refresh();
            $this->assertSame('简中库存品名', $stockItem->name_zh_cn);
            $this->assertSame('Base stock item name', $stockItem->name);
        } finally {
            app()->setLocale($originalLocale);
        }
    }

    public function test_logistics_view_keeps_null_dropdown_defaults_blank(): void
    {
        $tenant = Tenant::factory()->create();
        $stockItem = StockItem::factory()->for($tenant)->create();
        $sku = Sku::factory()->for($tenant)->for($stockItem)->create([
            'default_packaging_material_id' => null,
            'default_shipping_method_id' => null,
        ]);

        Livewire::actingAs($this->internalUser())
            ->withQueryParams(['view' => 'logistics'])
            ->test(SkusIndex::class)
            ->assertSet("logisticsDrafts.{$sku->id}.default_packaging_material_id", '')
            ->assertSet("logisticsDrafts.{$sku->id}.default_shipping_method_id", '')
            ->assertDontSee(__('skus.no_packaging'))
            ->assertDontSee(__('skus.no_shipping_method'));
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
            ->assertSet('currentViewIsDefault', false)
            ->set('currentViewIsDefault', true)
            ->assertSee(__('skus.default_view_saved'));

        $this->assertSame('logistics', $user->refresh()->preference('skus_view'));

        Livewire::actingAs($user)
            ->test(SkusIndex::class)
            ->assertSet('view', 'logistics');

        Livewire::actingAs($user)
            ->withQueryParams(['view' => 'catalog'])
            ->test(SkusIndex::class)
            ->assertSet('view', 'catalog')
            ->assertSet('currentViewIsDefault', false);
    }

    public function test_default_view_checkbox_can_clear_saved_preference(): void
    {
        $user = $this->internalUser();
        $user->setPreference('skus_view', 'catalog');

        Livewire::actingAs($user)
            ->withQueryParams(['view' => 'catalog'])
            ->test(SkusIndex::class)
            ->assertSet('currentViewIsDefault', true)
            ->set('currentViewIsDefault', false)
            ->assertSee(__('skus.default_view_cleared'));

        $this->assertNull($user->refresh()->preference('skus_view'));
    }

    public function test_saved_sku_view_preference_applies_on_plain_skus_request(): void
    {
        $user = $this->internalUser();
        $user->setPreference('skus_view', 'marketplace');

        $this->actingAs($user)
            ->get('/skus')
            ->assertOk()
            ->assertSee(__('skus.col_asin'))
            ->assertDontSee(__('skus.col_platform_ids'));
    }

    public function test_sku_can_be_deactivated(): void
    {
        $tenant = Tenant::factory()->create();
        $stockItem = StockItem::factory()->for($tenant)->create();
        $sku = Sku::factory()->for($tenant)->for($stockItem)->create(['status' => 'active']);

        Livewire::actingAs($this->internalUser())
            ->test(SkusIndex::class)
            ->call('deactivateSku', $sku->id)
            ->assertSee(__('skus.deactivated'));

        $sku->refresh();
        $this->assertSame('inactive', $sku->status);
        $this->assertSame($stockItem->id, $sku->stock_item_id);
        $this->assertDatabaseHas('stock_items', ['id' => $stockItem->id]);
    }

    public function test_deactivated_sku_is_hidden_from_default_sku_index(): void
    {
        $tenant = Tenant::factory()->create();
        $active = Sku::factory()->for($tenant)->create(['sku' => 'SKU-ACTIVE-VISIBLE', 'status' => 'active']);
        $inactive = Sku::factory()->for($tenant)->create(['sku' => 'SKU-INACTIVE-HIDDEN', 'status' => 'inactive']);

        Livewire::actingAs($this->internalUser())
            ->test(SkusIndex::class)
            ->assertSee($active->sku)
            ->assertDontSee($inactive->sku)
            ->set('status', 'inactive')
            ->assertSee($inactive->sku)
            ->assertDontSee($active->sku)
            ->set('status', 'all')
            ->assertSee($active->sku)
            ->assertSee($inactive->sku);
    }

    public function test_sku_index_has_selection_actions_and_checkboxes(): void
    {
        $tenant = Tenant::factory()->create();
        $sku = Sku::factory()->for($tenant)->create(['sku' => 'SKU-CHECKBOX-ACTIONS']);

        Livewire::actingAs($this->internalUser())
            ->test(SkusIndex::class)
            ->assertSee('data-testid="sku-selection-actions"', false)
            ->assertSee(__('skus.btn_edit'))
            ->assertSee(__('skus.action_deactivate'))
            ->assertSee(__('skus.action_delete_permanently'))
            ->assertSee(__('skus.select_visible_skus'))
            ->assertSee(__('skus.select_sku').' '.$sku->sku);
    }

    public function test_sku_can_be_reactivated(): void
    {
        $sku = Sku::factory()->create(['status' => 'inactive']);

        Livewire::actingAs($this->internalUser())
            ->test(SkusIndex::class)
            ->set('status', 'inactive')
            ->call('reactivateSku', $sku->id)
            ->assertSee(__('skus.reactivated'));

        $this->assertSame('active', $sku->refresh()->status);
    }

    public function test_selected_skus_can_be_deactivated_and_reactivated(): void
    {
        $tenant = Tenant::factory()->create();
        $first = Sku::factory()->for($tenant)->create(['status' => 'active']);
        $second = Sku::factory()->for($tenant)->create(['status' => 'active']);

        Livewire::actingAs($this->internalUser())
            ->test(SkusIndex::class)
            ->set('selectedIds', [$first->id, $second->id])
            ->call('bulkDeactivate')
            ->assertSee(__('skus.bulk_deactivated', ['count' => 2]))
            ->assertSet('selectedIds', [])
            ->set('status', 'inactive')
            ->set('selectedIds', [$first->id, $second->id])
            ->call('bulkReactivate')
            ->assertSee(__('skus.bulk_reactivated', ['count' => 2]));

        $this->assertSame('active', $first->refresh()->status);
        $this->assertSame('active', $second->refresh()->status);
    }

    public function test_selected_unused_sku_can_be_deleted(): void
    {
        $tenant = Tenant::factory()->create();
        $stockItem = StockItem::factory()->for($tenant)->create();
        $sku = Sku::factory()->for($tenant)->for($stockItem)->create();

        Livewire::actingAs($this->internalUser())
            ->test(SkusIndex::class)
            ->set('selectedIds', [$sku->id])
            ->call('bulkDelete')
            ->assertSee(__('skus.bulk_deleted', ['count' => 1]))
            ->assertSet('selectedIds', []);

        $this->assertDatabaseMissing('skus', ['id' => $sku->id]);
        $this->assertDatabaseHas('stock_items', ['id' => $stockItem->id]);
    }

    public function test_unused_sku_can_be_permanently_deleted(): void
    {
        $tenant = Tenant::factory()->create();
        $stockItem = StockItem::factory()->for($tenant)->create();
        $sku = Sku::factory()->for($tenant)->for($stockItem)->create();

        BarcodeAlias::create([
            'tenant_id' => $tenant->id,
            'model_type' => BarcodeAlias::MODEL_TYPE_SKU,
            'model_id' => $sku->id,
            'barcode' => 'DELETE-ME',
            'normalized_barcode' => 'DELETE-ME',
            'barcode_type' => 'unknown',
            'is_primary' => false,
            'is_active' => true,
            'source' => BarcodeAlias::SOURCE_MANUAL,
        ]);

        Livewire::actingAs($this->internalUser())
            ->test(SkusIndex::class)
            ->call('deleteSku', $sku->id)
            ->assertSee(__('skus.deleted'));

        $this->assertDatabaseMissing('skus', ['id' => $sku->id]);
        $this->assertDatabaseMissing('barcode_aliases', [
            'model_type' => BarcodeAlias::MODEL_TYPE_SKU,
            'model_id' => $sku->id,
        ]);
        $this->assertDatabaseHas('stock_items', ['id' => $stockItem->id]);
    }

    public function test_used_sku_cannot_be_permanently_deleted(): void
    {
        $tenant = Tenant::factory()->create();
        $shop = Shop::factory()->for($tenant)->create();
        $sku = Sku::factory()->for($tenant)->for($shop)->create(['status' => 'active']);
        $order = SalesOrder::factory()->for($tenant)->for($shop)->create();
        SalesOrderLine::factory()->for($order)->for($sku)->create();

        Livewire::actingAs($this->internalUser())
            ->test(SkusIndex::class)
            ->call('deleteSku', $sku->id)
            ->assertSee(__('skus.delete_blocked_deactivate_instead'))
            ->call('deactivateSku', $sku->id)
            ->assertSee(__('skus.deactivated'));

        $this->assertDatabaseHas('skus', ['id' => $sku->id, 'status' => 'inactive']);
    }

    public function test_tenant_user_cannot_deactivate_other_tenants_sku(): void
    {
        [, $user] = $this->tenantUser();
        $otherTenant = Tenant::factory()->create();
        $sku = Sku::factory()->for($otherTenant)->create(['status' => 'active']);

        Livewire::actingAs($user)
            ->test(SkusIndex::class)
            ->call('deactivateSku', $sku->id)
            ->assertNotFound();

        $this->assertSame('active', $sku->refresh()->status);
    }

    public function test_inactive_sku_is_rejected_by_sales_order_import(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        $shop = Shop::factory()->for($tenant)->create(['status' => 'active']);
        $sku = Sku::factory()
            ->for($tenant)
            ->for($shop)
            ->for(StockItem::factory()->for($tenant)->create())
            ->create(['sku' => 'SKU-INACTIVE-IMPORT', 'status' => 'inactive']);

        $file = UploadedFile::fake()->createWithContent(
            'orders.csv',
            $this->salesOrderImportCsv([['SO-INACTIVE', $sku->sku, '1']]),
        );

        $component = Livewire::actingAs($this->internalUser())
            ->test(SalesOrderImport::class)
            ->set('shopId', (string) $shop->id)
            ->set('file', $file)
            ->call('parse')
            ->assertSee(__('skus.inactive_import_blocked'));

        $this->assertTrue($component->get('hasErrors'));
        $this->assertSame([__('skus.inactive_import_blocked')], $component->get('parsedRows')[0]['errors']);
    }

    public function test_existing_order_with_inactive_sku_still_renders(): void
    {
        $tenant = Tenant::factory()->create();
        $shop = Shop::factory()->for($tenant)->create();
        $sku = Sku::factory()->for($tenant)->for($shop)->create([
            'sku' => 'SKU-INACTIVE-HISTORY',
            'status' => 'inactive',
        ]);
        $order = SalesOrder::factory()->for($tenant)->for($shop)->create(['platform_order_id' => 'SO-INACTIVE-HISTORY']);
        SalesOrderLine::factory()->for($order)->for($sku)->create();

        $this->actingAs($this->internalUser())
            ->get(route('sales.orders.show', $order))
            ->assertOk()
            ->assertSee('SKU-INACTIVE-HISTORY');
    }

    public function test_guest_user_is_not_treated_as_internal_on_sku_index(): void
    {
        $tenant = Tenant::factory()->create();
        $stockItem = StockItem::factory()->for($tenant)->create();
        Sku::factory()->for($tenant)->for($stockItem)->create(['sku' => 'SKU-GUEST-HIDDEN']);

        Livewire::test(SkusIndex::class)
            ->assertDontSee('SKU-GUEST-HIDDEN');
    }

    public function test_sku_create_and_edit_save_tenant_item_code(): void
    {
        $tenant = Tenant::factory()->create(['code' => 'TIC']);
        $shop = Shop::factory()->for($tenant)->create();

        Livewire::actingAs($this->internalUser())
            ->test(SkuCreate::class)
            ->set('tenantId', (string) $tenant->id)
            ->set('shopId', (string) $shop->id)
            ->set('sku', 'SKU-TENANT-CODE')
            ->set('name', 'SKU tenant code')
            ->set('stockItem.name', 'Stock tenant code')
            ->set('stockItem.tenant_item_code', 'CUSTOM-001')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('skus.index'));

        $sku = Sku::where('sku', 'SKU-TENANT-CODE')->firstOrFail();

        $this->assertSame('CUSTOM-001', $sku->stockItem->tenant_item_code);

        Livewire::actingAs($this->internalUser())
            ->test(SkuEdit::class, ['sku' => $sku])
            ->set('stockItem.tenant_item_code', '')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('skus.index'));

        $this->assertNull($sku->stockItem->refresh()->tenant_item_code);
    }

    public function test_tenant_item_code_is_unique_per_tenant_only(): void
    {
        $tenant = Tenant::factory()->create(['code' => 'TUA']);
        $shop = Shop::factory()->for($tenant)->create();

        StockItem::factory()->for($tenant)->create(['tenant_item_code' => 'DUP-001']);

        Livewire::actingAs($this->internalUser())
            ->test(SkuCreate::class)
            ->set('tenantId', (string) $tenant->id)
            ->set('shopId', (string) $shop->id)
            ->set('sku', 'SKU-DUP-TENANT')
            ->set('name', 'Duplicate tenant code')
            ->set('stockItem.name', 'Duplicate stock')
            ->set('stockItem.tenant_item_code', 'DUP-001')
            ->call('save')
            ->assertHasErrors(['stock_item.tenant_item_code']);

        $otherTenant = Tenant::factory()->create(['code' => 'TUB']);
        $otherStock = StockItem::factory()->for($otherTenant)->create(['tenant_item_code' => 'DUP-001']);

        $this->assertSame('DUP-001', $otherStock->tenant_item_code);
    }

    public function test_sku_index_tenant_code_preference_controls_stock_item_display_and_search(): void
    {
        $tenant = Tenant::factory()->create(['code' => 'DSP']);
        $shop = Shop::factory()->for($tenant)->create();
        $stockItem = StockItem::factory()->for($tenant)->create([
            'code' => 'DSP-000001',
            'tenant_item_code' => 'TENANT-DISPLAY-001',
            'name' => 'Display Stock',
        ]);
        $sku = Sku::factory()->for($tenant)->for($shop)->for($stockItem)->create(['sku' => 'SKU-DISPLAY']);
        $user = $this->internalUser();

        Livewire::actingAs($user)
            ->test(SkusIndex::class)
            ->assertSet('showTenantItemCode', false)
            ->assertSee('DSP-000001')
            ->assertDontSee('TENANT-DISPLAY-001')
            ->set('showTenantItemCode', true)
            ->assertSee('TENANT-DISPLAY-001')
            ->assertSee('DSP-000001');

        $this->assertTrue((bool) $user->refresh()->preference('show_tenant_item_code'));

        Livewire::actingAs($user)
            ->test(SkusIndex::class)
            ->assertSet('showTenantItemCode', true)
            ->set('search', 'TENANT-DISPLAY-001')
            ->assertSee($sku->sku);
    }

    public function test_fulfillment_item_code_resolver_uses_tenant_setting_not_user_preference(): void
    {
        $tenant = Tenant::factory()->create([
            'fulfillment_item_code_source' => Tenant::FULFILLMENT_ITEM_CODE_SOURCE_SKU,
        ]);
        $stockItem = StockItem::factory()->for($tenant)->create([
            'code' => 'SYS-RESOLVE',
            'tenant_item_code' => 'TEN-RESOLVE',
        ]);
        $sku = Sku::factory()->for($tenant)->for($stockItem)->create(['sku' => 'SKU-RESOLVE']);
        $user = $this->internalUser();

        $user->setPreference('show_tenant_item_code', true);
        $resolver = app(FulfillmentItemCodeResolver::class);

        $this->assertSame('SKU-RESOLVE', $resolver->resolve($tenant, $sku, $stockItem));

        $tenant->update(['fulfillment_item_code_source' => Tenant::FULFILLMENT_ITEM_CODE_SOURCE_TENANT_ITEM_CODE]);
        $this->assertSame('TEN-RESOLVE', $resolver->resolve($tenant->refresh(), $sku, $stockItem));

        $stockItem->update(['tenant_item_code' => null]);
        $this->assertSame('SKU-RESOLVE', $resolver->resolve($tenant, $sku, $stockItem->refresh()));

        $tenant->update(['fulfillment_item_code_source' => Tenant::FULFILLMENT_ITEM_CODE_SOURCE_STOCK_ITEM_CODE]);
        $this->assertSame('SYS-RESOLVE', $resolver->resolve($tenant->refresh(), $sku, $stockItem));
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

    private function salesOrderImportCsv(array $rows): string
    {
        $lines = ['platform_order_id,sku,quantity,line_note,recipient_name,recipient_phone,recipient_country_code,recipient_postal_code,recipient_state,recipient_city,recipient_address_line1,recipient_address_line2,order_note'];

        foreach ($rows as $row) {
            $lines[] = implode(',', array_map(
                fn ($value) => str_contains((string) $value, ',')
                    ? '"'.str_replace('"', '""', (string) $value).'"'
                    : (string) $value,
                array_pad($row, 13, ''),
            ));
        }

        return implode("\n", $lines)."\n";
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

    /**
     * @return array{0: Tenant, 1: Sku}
     */
    private function skuForAliasSync(string $skuCode, ?string $platformLabelCode = null): array
    {
        $tenant = Tenant::factory()->create();
        $stockItem = StockItem::factory()->for($tenant)->create();
        $sku = Sku::factory()->for($tenant)->for($stockItem)->create([
            'sku' => $skuCode,
            'name' => $skuCode.' name',
            'platform_label_code' => $platformLabelCode,
            'shop_id' => null,
        ]);

        return [$tenant, $sku];
    }

    private function amazonConnection(Shop $shop, array $attributes = []): AmazonSpapiConnection
    {
        return AmazonSpapiConnection::create(array_merge([
            'tenant_id' => $shop->tenant_id,
            'shop_id' => $shop->id,
            'seller_id' => 'SELLER123',
            'marketplace_id' => 'A1VC38T7YXB528',
            'region' => 'fe',
            'endpoint' => 'https://sellingpartnerapi-fe.amazon.com',
            'lwa_client_id' => 'client-id',
            'lwa_client_secret' => 'client-secret',
            'refresh_token' => 'refresh-token',
            'sync_enabled' => true,
            'status' => AmazonSpapiConnection::STATUS_CONNECTED,
        ], $attributes));
    }

    private function fakeAmazonImageImport(AmazonSpapiConnection $connection, string $imageUrl, UploadedFile|string $image): void
    {
        $bytes = $image instanceof UploadedFile
            ? (string) file_get_contents($image->getRealPath())
            : $image;

        Http::fake([
            'https://api.amazon.com/auth/o2/token' => Http::response(['access_token' => 'token', 'expires_in' => 3600], 200),
            rtrim($connection->endpoint, '/').'/catalog/2022-04-01/items/*' => Http::response([
                'images' => [[
                    'marketplaceId' => $connection->marketplace_id,
                    'images' => [[
                        'variant' => 'MAIN',
                        'link' => $imageUrl,
                        'width' => 1000,
                        'height' => 1000,
                    ]],
                ]],
            ], 200),
            $imageUrl => Http::response($bytes, 200, ['Content-Type' => 'image/jpeg']),
        ]);
    }

    private function mediaAsset(StockItem $stockItem, array $attributes = []): MediaAsset
    {
        return MediaAsset::create(array_merge([
            'tenant_id' => $stockItem->tenant_id,
            'model_type' => 'stock_item',
            'model_id' => $stockItem->id,
            'type' => 'main',
            'disk' => 'public',
            'path' => 'product-images/tenant-'.$stockItem->tenant_id.'/stock-items/'.$stockItem->id.'/test.jpg',
            'file_name' => 'test.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 10,
            'width' => 10,
            'height' => 10,
            'sort_order' => 1,
            'is_primary' => false,
            'uploaded_by_user_id' => null,
        ], $attributes));
    }
}
