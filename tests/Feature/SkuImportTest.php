<?php

namespace Tests\Feature;

use App\Livewire\SkuImport;
use App\Models\BarcodeAlias;
use App\Models\ProductType;
use App\Models\Shop;
use App\Models\Sku;
use App\Models\SkuImportMapping;
use App\Models\StockItem;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\SkuImport\SkuImportReader;
use App\Services\SkuImport\SkuWriter;
use App\Support\SkuImport\SkuImportFields;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Testing\File;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class SkuImportTest extends TestCase
{
    use RefreshDatabase;

    // ---- Reader tests ----

    public function test_reader_parses_csv_headers_and_rows(): void
    {
        $path = $this->csvFile("sku,name,brand\nSKU001,Product 1,Acme\nSKU002,Product 2,\n");
        $result = app(SkuImportReader::class)->read($path);

        $this->assertSame(['sku', 'name', 'brand'], $result['headers']);
        $this->assertSame(2, $result['total']);
        $this->assertCount(2, $result['rows']);
        $this->assertSame(['SKU001', 'Product 1', 'Acme'], $result['rows'][0]);
        $this->assertSame(['SKU002', 'Product 2', ''], $result['rows'][1]);

        unlink($path);
    }

    public function test_reader_skips_blank_rows(): void
    {
        $path = $this->csvFile("sku,name\nSKU001,Prod1\n\n   \nSKU002,Prod2\n");
        $result = app(SkuImportReader::class)->read($path);

        $this->assertSame(2, $result['total']);

        unlink($path);
    }

    public function test_reader_handles_utf8_bom(): void
    {
        $bom = "\xEF\xBB\xBF";
        $path = $this->csvFile($bom."sku,name\nBOM001,BOM Product\n");
        $result = app(SkuImportReader::class)->read($path);

        $this->assertSame(1, $result['total']);
        $this->assertSame('BOM001', $result['rows'][0][0]);

        unlink($path);
    }

    public function test_reader_handles_shift_jis_csv(): void
    {
        $utf8Content = "品番,商品名\nSKU001,テスト商品\n";
        $sjisContent = mb_convert_encoding($utf8Content, 'SJIS-WIN', 'UTF-8');
        $path = $this->csvFile($sjisContent);

        $result = app(SkuImportReader::class)->read($path);

        $this->assertSame(['品番', '商品名'], $result['headers']);
        $this->assertSame('SKU001', $result['rows'][0][0]);
        $this->assertSame('テスト商品', $result['rows'][0][1]);

        unlink($path);
    }

    public function test_reader_limits_returned_rows_but_counts_all(): void
    {
        $lines = "sku,name\n";
        for ($i = 1; $i <= 10; $i++) {
            $lines .= "SKU{$i},Product {$i}\n";
        }
        $path = $this->csvFile($lines);
        $result = app(SkuImportReader::class)->read($path, 3);

        $this->assertSame(10, $result['total']);
        $this->assertCount(3, $result['rows']);

        unlink($path);
    }

    // ---- Auto-guess tests ----

    public function test_auto_guess_maps_exact_field_key_headers(): void
    {
        $headers = ['sku', 'name', 'brand', 'color'];
        $mapping = SkuImportFields::autoGuess($headers);

        $this->assertSame('sku', $mapping['sku']);
        $this->assertSame('name', $mapping['name']);
        $this->assertSame('brand', $mapping['brand']);
        $this->assertSame('color', $mapping['color']);
    }

    public function test_auto_guess_maps_known_english_aliases(): void
    {
        $headers = ['product name', 'manufacturer', 'barcode'];
        $mapping = SkuImportFields::autoGuess($headers);

        $this->assertSame('product name', $mapping['name']);
        $this->assertSame('manufacturer', $mapping['brand']);
        $this->assertSame('barcode', $mapping['barcode']);
    }

    public function test_auto_guess_maps_depth_headers_to_length(): void
    {
        foreach (['depth', "\u{5965}\u{884C}\u{304D}", "\u{9577}\u{3055}(cm)"] as $header) {
            $mapping = SkuImportFields::autoGuess([$header]);

            $this->assertSame($header, $mapping['length_value']);
        }
    }

    public function test_auto_guess_maps_japanese_headers(): void
    {
        $headers = ['SKUコード', 'SKU名', 'ブランド'];
        $mapping = SkuImportFields::autoGuess($headers);

        $this->assertSame('SKUコード', $mapping['sku']);
        $this->assertSame('SKU名', $mapping['name_ja']);
        $this->assertSame('ブランド', $mapping['brand']);
    }

    public function test_auto_guess_leaves_unmatched_as_empty(): void
    {
        $headers = ['sku', 'unknown_column_xyz'];
        $mapping = SkuImportFields::autoGuess($headers);

        $this->assertSame('sku', $mapping['sku']);
        $this->assertSame('', $mapping['name']);
        $this->assertSame('', $mapping['brand']);
    }

    public function test_auto_guess_does_not_map_same_header_twice(): void
    {
        $headers = ['sku'];
        $mapping = SkuImportFields::autoGuess($headers);

        $skuCount = count(array_filter($mapping, fn ($v) => $v === 'sku'));
        $this->assertSame(1, $skuCount);
    }

    // ---- SkuWriter tests ----

    public function test_writer_creates_sku_and_stock_item(): void
    {
        $tenant = Tenant::factory()->create(['code' => 'WRT']);
        $productType = ProductType::firstOrCreate(['slug' => 'normal'], ['name' => 'Normal', 'sort_order' => 0]);

        DB::beginTransaction();

        $result = app(SkuWriter::class)->upsert(
            $tenant->id,
            null,
            ['sku' => 'WRT-001', 'name' => 'Write Test', 'status' => 'active'],
            ['product_type' => 'normal'],
            false,
        );

        DB::commit();

        $this->assertSame('created', $result->status);
        $this->assertDatabaseHas('skus', ['sku' => 'WRT-001', 'tenant_id' => $tenant->id]);
        $this->assertNotNull($result->sku->stock_item_id);
        $this->assertStringStartsWith('WRT-', $result->sku->stockItem->code);
    }

    public function test_writer_skips_existing_sku_in_insert_only_mode(): void
    {
        $tenant = Tenant::factory()->create(['code' => 'SKP']);
        $existing = Sku::factory()->for($tenant)->create(['sku' => 'SKP-001', 'shop_id' => null]);

        DB::beginTransaction();

        $result = app(SkuWriter::class)->upsert(
            $tenant->id,
            null,
            ['sku' => 'SKP-001', 'name' => 'Skip Me'],
            [],
            false,
        );

        DB::rollBack();

        $this->assertSame('skipped', $result->status);
    }

    public function test_writer_updates_existing_sku_in_upsert_mode(): void
    {
        $tenant = Tenant::factory()->create(['code' => 'UPS']);
        $existing = Sku::factory()->for($tenant)->create(['sku' => 'UPS-001', 'name' => 'Original Name', 'shop_id' => null]);

        DB::beginTransaction();

        $result = app(SkuWriter::class)->upsert(
            $tenant->id,
            null,
            ['sku' => 'UPS-001', 'name' => 'Updated Name', 'status' => 'active'],
            [],
            true,
        );

        DB::commit();

        $this->assertSame('updated', $result->status);
        $this->assertDatabaseHas('skus', ['sku' => 'UPS-001', 'name' => 'Updated Name']);
    }

    public function test_writer_links_existing_stock_item_by_code(): void
    {
        $tenant = Tenant::factory()->create(['code' => 'LNK']);
        $stockItem = StockItem::factory()->for($tenant)->create(['code' => 'LNK-000001']);

        DB::beginTransaction();

        $result = app(SkuWriter::class)->upsert(
            $tenant->id,
            null,
            ['sku' => 'LNK-001', 'name' => 'Linked SKU', 'status' => 'active'],
            ['stock_item_code' => 'LNK-000001'],
            false,
        );

        DB::commit();

        $this->assertSame('created', $result->status);
        $this->assertSame($stockItem->id, $result->sku->stock_item_id);
    }

    public function test_writer_maps_and_links_stock_item_by_tenant_item_code(): void
    {
        $tenant = Tenant::factory()->create(['code' => 'TIC']);
        $stockItem = StockItem::factory()->for($tenant)->create([
            'code' => 'TIC-000001',
            'tenant_item_code' => 'CUSTOM-LINK-001',
        ]);

        DB::beginTransaction();

        $result = app(SkuWriter::class)->upsert(
            $tenant->id,
            null,
            ['sku' => 'SKU-CUSTOM-LINK', 'name' => 'Linked by tenant code', 'status' => 'active'],
            ['tenant_item_code' => 'CUSTOM-LINK-001'],
            false,
        );

        DB::commit();

        $this->assertSame('created', $result->status);
        $this->assertSame($stockItem->id, $result->sku->stock_item_id);
    }

    public function test_writer_blocks_conflicting_stock_item_and_tenant_item_codes(): void
    {
        $tenant = Tenant::factory()->create(['code' => 'TCF']);
        StockItem::factory()->for($tenant)->create(['code' => 'TCF-000001', 'tenant_item_code' => 'CUSTOM-A']);
        StockItem::factory()->for($tenant)->create(['code' => 'TCF-000002', 'tenant_item_code' => 'CUSTOM-B']);

        DB::beginTransaction();

        try {
            $this->expectException(\RuntimeException::class);

            app(SkuWriter::class)->upsert(
                $tenant->id,
                null,
                ['sku' => 'SKU-CONFLICT', 'name' => 'Conflict', 'status' => 'active'],
                ['stock_item_code' => 'TCF-000001', 'tenant_item_code' => 'CUSTOM-B'],
                false,
            );
        } finally {
            DB::rollBack();
        }
    }

    public function test_writer_creates_new_stock_item_when_code_not_found(): void
    {
        $tenant = Tenant::factory()->create(['code' => 'NEW']);

        DB::beginTransaction();

        $result = app(SkuWriter::class)->upsert(
            $tenant->id,
            null,
            ['sku' => 'NEW-001', 'name' => 'New SKU', 'status' => 'active'],
            ['stock_item_code' => 'DOES-NOT-EXIST-9999'],
            false,
        );

        DB::commit();

        $this->assertSame('created', $result->status);
        $this->assertNotNull($result->sku->stock_item_id);
        $this->assertDatabaseHas('stock_items', ['tenant_id' => $tenant->id, 'code' => 'NEW-000001']);
    }

    // ---- Livewire wizard tests ----

    public function test_upload_step_reads_headers_and_advances_to_map(): void
    {
        [$tenant, $shop] = $this->tenantWithShop(['code' => 'WIZ']);
        $user = $this->internalUser();

        Livewire::actingAs($user)
            ->test(SkuImport::class)
            ->set('tenantId', (string) $tenant->id)
            ->set('file', File::createWithContent('import.csv', "sku,name,brand\nSKU001,Prod,Acme\n"))
            ->call('readFile')
            ->assertSet('step', 'map')
            ->assertSet('shopId', (string) $shop->id)
            ->assertSet('fileHeaders', ['sku', 'name', 'brand'])
            ->assertSet('totalDataRows', 1);
    }

    public function test_single_shop_is_auto_filled_when_tenant_is_selected(): void
    {
        [$tenant, $shop] = $this->tenantWithShop(['code' => 'SNG']);

        Livewire::actingAs($this->internalUser())
            ->test(SkuImport::class)
            ->set('tenantId', (string) $tenant->id)
            ->assertSet('shopId', (string) $shop->id);
    }

    public function test_upload_requires_shop_when_more_than_one_shop_exists(): void
    {
        [$tenant] = $this->tenantWithShop(['code' => 'MSH']);
        Shop::factory()->for($tenant)->create(['status' => 'active']);

        Livewire::actingAs($this->internalUser())
            ->test(SkuImport::class)
            ->set('tenantId', (string) $tenant->id)
            ->set('shopId', '')
            ->set('file', File::createWithContent('import.csv', "sku,name\nSKU001,Prod\n"))
            ->call('readFile')
            ->assertHasErrors(['shopId' => 'required']);
    }

    public function test_upload_rejects_empty_file(): void
    {
        [$tenant] = $this->tenantWithShop(['code' => 'EMP']);

        Livewire::actingAs($this->internalUser())
            ->test(SkuImport::class)
            ->set('tenantId', (string) $tenant->id)
            ->set('file', File::createWithContent('import.csv', "sku,name\n"))
            ->call('readFile')
            ->assertHasErrors(['file']);
    }

    public function test_upload_enforces_row_cap(): void
    {
        [$tenant] = $this->tenantWithShop(['code' => 'CAP']);
        $lines = "sku,name\n";
        for ($i = 1; $i <= 2001; $i++) {
            $lines .= "SKU{$i},Product {$i}\n";
        }

        Livewire::actingAs($this->internalUser())
            ->test(SkuImport::class)
            ->set('tenantId', (string) $tenant->id)
            ->set('file', File::createWithContent('big.csv', $lines))
            ->call('readFile')
            ->assertHasErrors(['file']);
    }

    public function test_map_step_requires_sku_and_name_fields(): void
    {
        [$tenant] = $this->tenantWithShop(['code' => 'REQ']);
        $component = Livewire::actingAs($this->internalUser())
            ->test(SkuImport::class)
            ->set('tenantId', (string) $tenant->id)
            ->set('file', File::createWithContent('import.csv', "col_a,col_b\nA1,B1\n"))
            ->call('readFile')
            ->assertSet('step', 'map');

        // mapping has no sku/name columns guessed -> advancing to preview should fail
        $component
            ->set('mapping.sku', '')
            ->set('mapping.name', '')
            ->call('advanceToPreview')
            ->assertSet('step', 'map')
            ->assertSee('required fields are not mapped');
    }

    public function test_map_step_requires_the_tenants_base_sku_name_field(): void
    {
        [$tenant] = $this->tenantWithShop(['code' => 'RJA', 'sku_name_locale' => 'ja']);

        Livewire::actingAs($this->internalUser())
            ->test(SkuImport::class)
            ->set('tenantId', (string) $tenant->id)
            ->set('file', File::createWithContent('import.csv', "sku,name\nRJA-001,English Name\n"))
            ->call('readFile')
            ->assertSet('step', 'map')
            ->call('advanceToPreview')
            ->assertSet('step', 'map')
            ->assertSee('SKU name (Japanese)')
            ->assertDontSee('SKU name (English), SKU name (Japanese)');
    }

    public function test_map_step_requires_default_barcode_type_when_barcode_has_no_type_column(): void
    {
        [$tenant] = $this->tenantWithShop(['code' => 'BTR']);

        Livewire::actingAs($this->internalUser())
            ->test(SkuImport::class)
            ->set('tenantId', (string) $tenant->id)
            ->set('file', File::createWithContent('import.csv', "sku,name,barcode\nBTR-001,Barcode Product,4901234567894\n"))
            ->call('readFile')
            ->assertSet('step', 'map')
            ->assertSee('Barcode type for imported barcodes')
            ->call('advanceToPreview')
            ->assertSet('step', 'map')
            ->assertSee('Barcode type for imported barcodes');
    }

    public function test_advance_to_preview_validates_all_rows(): void
    {
        [$tenant] = $this->tenantWithShop(['code' => 'PRV']);
        $csv = "sku,name\nSKU001,Good\n,Missing SKU\nSKU003,Also Good\n";

        $component = Livewire::actingAs($this->internalUser())
            ->test(SkuImport::class)
            ->set('tenantId', (string) $tenant->id)
            ->set('file', File::createWithContent('import.csv', $csv))
            ->call('readFile')
            ->assertSet('step', 'map');

        $component
            ->call('advanceToPreview')
            ->assertSet('step', 'preview')
            ->assertSet('validRowCount', 2)
            ->assertSet('errorRowCount', 1);
    }

    public function test_insert_only_counts_existing_sku_as_skipped(): void
    {
        [$tenant, $shop] = $this->tenantWithShop(['code' => 'INS']);
        Sku::factory()->for($tenant)->for($shop)->create(['sku' => 'INS-EXIST']);

        $csv = "sku,name\nINS-EXIST,Existing\nINS-NEW,New\n";
        $component = Livewire::actingAs($this->internalUser())
            ->test(SkuImport::class)
            ->set('tenantId', (string) $tenant->id)
            ->set('file', File::createWithContent('import.csv', $csv))
            ->call('readFile')
            ->call('advanceToPreview')
            ->assertSet('step', 'preview')
            ->assertSet('existsRowCount', 1)
            ->assertSet('validRowCount', 1);

        // insert-only: existing is skipped
        $component
            ->set('allowUpsert', '0')
            ->call('confirmImport')
            ->assertSet('step', 'result')
            ->assertSet('resultCreated', 1)
            ->assertSet('resultSkipped', 1);

        $this->assertDatabaseHas('skus', ['sku' => 'INS-NEW', 'tenant_id' => $tenant->id]);
    }

    public function test_upsert_updates_existing_sku(): void
    {
        [$tenant, $shop] = $this->tenantWithShop(['code' => 'UPT']);
        Sku::factory()->for($tenant)->for($shop)->create(['sku' => 'UPT-001', 'name' => 'Old Name']);

        $csv = "sku,name\nUPT-001,New Name\n";
        Livewire::actingAs($this->internalUser())
            ->test(SkuImport::class)
            ->set('tenantId', (string) $tenant->id)
            ->set('file', File::createWithContent('import.csv', $csv))
            ->call('readFile')
            ->call('advanceToPreview')
            ->set('allowUpsert', '1')
            ->call('confirmImport')
            ->assertSet('step', 'result')
            ->assertSet('resultUpdated', 1)
            ->assertSet('resultCreated', 0);

        $this->assertDatabaseHas('skus', ['sku' => 'UPT-001', 'name' => 'New Name']);
    }

    public function test_import_uses_tenant_base_sku_name_language(): void
    {
        [$tenant] = $this->tenantWithShop(['code' => 'JPN', 'sku_name_locale' => 'ja']);
        $csv = "sku,name,name_ja\nJPN-001,English Name,譌･譛ｬ隱槫錐\n";

        Livewire::actingAs($this->internalUser())
            ->test(SkuImport::class)
            ->set('tenantId', (string) $tenant->id)
            ->set('file', File::createWithContent('import.csv', $csv))
            ->call('readFile')
            ->call('advanceToPreview')
            ->call('confirmImport')
            ->assertSet('step', 'result')
            ->assertSet('resultCreated', 1)
            ->assertSet('resultFailed', 0);

        $this->assertDatabaseHas('skus', [
            'sku' => 'JPN-001',
            'tenant_id' => $tenant->id,
            'name' => '譌･譛ｬ隱槫錐',
            'name_en' => 'English Name',
            'name_ja' => '譌･譛ｬ隱槫錐',
        ]);
    }

    public function test_import_creates_managed_alias_from_platform_label_code(): void
    {
        [$tenant] = $this->tenantWithShop(['code' => 'FNI']);
        $csv = "sku,name,platform_label_code\nFNI-001,Imported FNSKU,x00-import 123\n";

        Livewire::actingAs($this->internalUser())
            ->test(SkuImport::class)
            ->set('tenantId', (string) $tenant->id)
            ->set('file', File::createWithContent('import.csv', $csv))
            ->call('readFile')
            ->call('advanceToPreview')
            ->call('confirmImport')
            ->assertSet('step', 'result')
            ->assertSet('resultCreated', 1)
            ->assertSet('resultFailed', 0);

        $sku = Sku::where('sku', 'FNI-001')->firstOrFail();

        $this->assertSame('x00-import 123', $sku->platform_label_code);
        $this->assertDatabaseHas('barcode_aliases', [
            'tenant_id' => $tenant->id,
            'model_type' => BarcodeAlias::MODEL_TYPE_SKU,
            'model_id' => $sku->id,
            'barcode' => 'x00-import 123',
            'normalized_barcode' => 'X00IMPORT123',
            'barcode_type' => 'platform_label',
            'source' => BarcodeAlias::SOURCE_IMPORT,
        ]);
    }

    public function test_import_creates_product_barcode_alias_without_writing_legacy_stock_item_barcode(): void
    {
        [$tenant] = $this->tenantWithShop(['code' => 'PBI']);
        $csv = "sku,name,barcode,barcode_type\nPBI-001,Imported Product,490-1234567894,jan\n";

        Livewire::actingAs($this->internalUser())
            ->test(SkuImport::class)
            ->set('tenantId', (string) $tenant->id)
            ->set('file', File::createWithContent('import.csv', $csv))
            ->call('readFile')
            ->call('advanceToPreview')
            ->call('confirmImport')
            ->assertSet('step', 'result')
            ->assertSet('resultCreated', 1)
            ->assertSet('resultFailed', 0);

        $sku = Sku::where('sku', 'PBI-001')->firstOrFail();

        $this->assertNull($sku->stockItem->barcode);
        $this->assertDatabaseHas('barcode_aliases', [
            'tenant_id' => $tenant->id,
            'model_type' => BarcodeAlias::MODEL_TYPE_STOCK_ITEM,
            'model_id' => $sku->stock_item_id,
            'barcode' => '490-1234567894',
            'normalized_barcode' => '4901234567894',
            'barcode_type' => 'jan',
            'source' => BarcodeAlias::SOURCE_IMPORT,
            'is_primary' => true,
        ]);
    }

    public function test_import_applies_selected_default_barcode_type_when_file_has_no_type_column(): void
    {
        [$tenant] = $this->tenantWithShop(['code' => 'DBT']);
        $csv = "sku,name,barcode\nDBT-001,Default Type Product,490-9876543210\n";

        Livewire::actingAs($this->internalUser())
            ->test(SkuImport::class)
            ->set('tenantId', (string) $tenant->id)
            ->set('file', File::createWithContent('import.csv', $csv))
            ->call('readFile')
            ->set('defaultBarcodeType', 'jan')
            ->call('advanceToPreview')
            ->call('confirmImport')
            ->assertSet('step', 'result')
            ->assertSet('resultCreated', 1)
            ->assertSet('resultFailed', 0);

        $sku = Sku::where('sku', 'DBT-001')->firstOrFail();

        $this->assertDatabaseHas('barcode_aliases', [
            'tenant_id' => $tenant->id,
            'model_type' => BarcodeAlias::MODEL_TYPE_STOCK_ITEM,
            'model_id' => $sku->stock_item_id,
            'barcode' => '490-9876543210',
            'normalized_barcode' => '4909876543210',
            'barcode_type' => 'jan',
            'source' => BarcodeAlias::SOURCE_IMPORT,
            'is_primary' => true,
        ]);
    }

    public function test_import_records_fnsku_collision_as_row_error_without_aborting_batch(): void
    {
        [$tenant, $shop] = $this->tenantWithShop(['code' => 'FNC']);
        $existing = Sku::factory()->for($tenant)->for($shop)->create(['sku' => 'FNC-EXISTING']);
        BarcodeAlias::create([
            'tenant_id' => $tenant->id,
            'model_type' => BarcodeAlias::MODEL_TYPE_SKU,
            'model_id' => $existing->id,
            'barcode' => 'DUP-FNSKU',
            'normalized_barcode' => 'DUPFNSKU',
            'barcode_type' => 'platform_label',
            'is_active' => true,
        ]);
        $csv = "sku,name,platform_label_code\nFNC-BAD,Bad Row,dup fnsku\nFNC-GOOD,Good Row,good fnsku\n";

        $component = Livewire::actingAs($this->internalUser())
            ->test(SkuImport::class)
            ->set('tenantId', (string) $tenant->id)
            ->set('file', File::createWithContent('import.csv', $csv))
            ->call('readFile')
            ->call('advanceToPreview')
            ->call('confirmImport')
            ->assertSet('step', 'result')
            ->assertSet('resultCreated', 1)
            ->assertSet('resultFailed', 1);

        $this->assertSame(__('skus.fnsku_alias_conflict'), $component->get('errorRows')[0]['errors']);

        $this->assertDatabaseMissing('skus', ['sku' => 'FNC-BAD']);
        $good = Sku::where('sku', 'FNC-GOOD')->firstOrFail();
        $this->assertDatabaseHas('barcode_aliases', [
            'model_id' => $good->id,
            'normalized_barcode' => 'GOODFNSKU',
            'source' => BarcodeAlias::SOURCE_IMPORT,
        ]);
    }

    public function test_duplicate_sku_in_file_is_flagged_as_error(): void
    {
        [$tenant] = $this->tenantWithShop(['code' => 'DUP']);

        $csv = "sku,name\nDUP-001,First\nDUP-001,Second\n";
        $component = Livewire::actingAs($this->internalUser())
            ->test(SkuImport::class)
            ->set('tenantId', (string) $tenant->id)
            ->set('file', File::createWithContent('import.csv', $csv))
            ->call('readFile')
            ->call('advanceToPreview')
            ->assertSet('step', 'preview');

        // Row 1: valid, Row 2: error (dup in file)
        $this->assertSame(1, $component->get('validRowCount'));
        $this->assertSame(1, $component->get('errorRowCount'));
    }

    public function test_invalid_rows_counted_as_failed_in_result(): void
    {
        [$tenant] = $this->tenantWithShop(['code' => 'INV']);

        $csv = "sku,name,status\nINV-001,Good,active\nINV-002,Bad Status,invalid_status_xyz\n";
        Livewire::actingAs($this->internalUser())
            ->test(SkuImport::class)
            ->set('tenantId', (string) $tenant->id)
            ->set('file', File::createWithContent('import.csv', $csv))
            ->call('readFile')
            ->call('advanceToPreview')
            ->call('confirmImport')
            ->assertSet('step', 'result')
            ->assertSet('resultCreated', 1)
            ->assertSet('resultFailed', 1);
    }

    public function test_tenant_user_cannot_import_for_another_tenant(): void
    {
        [$ownTenant, $user] = $this->tenantUser();
        $otherTenant = Tenant::factory()->create();

        Livewire::actingAs($user)
            ->test(SkuImport::class)
            ->assertSet('tenantId', (string) $ownTenant->id)
            ->set('tenantId', (string) $otherTenant->id)
            ->set('file', File::createWithContent('import.csv', "sku,name\nSKU001,P\n"))
            ->call('readFile')
            ->assertHasErrors(['tenantId']);
    }

    public function test_tenant_user_fixed_to_own_tenant_on_mount(): void
    {
        [$tenant, $user] = $this->tenantUser();

        Livewire::actingAs($user)
            ->test(SkuImport::class)
            ->assertSet('tenantId', (string) $tenant->id);
    }

    public function test_saved_template_can_be_created_and_loaded(): void
    {
        [$tenant] = $this->tenantWithShop(['code' => 'TPL']);
        $csv = "sku,name,brand\nT001,Product,Acme\n";

        // Step through upload and map, then confirm with template save
        $component = Livewire::actingAs($this->internalUser())
            ->test(SkuImport::class)
            ->set('tenantId', (string) $tenant->id)
            ->set('file', File::createWithContent('import.csv', $csv))
            ->call('readFile')
            ->call('advanceToPreview')
            ->set('doSaveTemplate', true)
            ->set('saveTemplateName', 'My Template')
            ->call('confirmImport')
            ->assertSet('step', 'result');

        $this->assertDatabaseHas('sku_import_mappings', [
            'tenant_id' => $tenant->id,
            'name' => 'My Template',
        ]);
    }

    public function test_saved_template_can_be_loaded_on_map_step(): void
    {
        [$tenant] = $this->tenantWithShop(['code' => 'TLD']);
        $template = SkuImportMapping::create([
            'tenant_id' => $tenant->id,
            'name' => 'Load Me',
            'mapping' => ['sku' => 'product_code', 'name' => 'title'],
        ]);

        $component = Livewire::actingAs($this->internalUser())
            ->test(SkuImport::class)
            ->set('tenantId', (string) $tenant->id)
            ->set('file', File::createWithContent('import.csv', "product_code,title\nX001,Prod\n"))
            ->call('readFile')
            ->assertSet('step', 'map');

        $component
            ->call('loadTemplate', $template->id)
            ->assertSet('mapping.sku', 'product_code')
            ->assertSet('mapping.name', 'title');
    }

    public function test_saved_template_name_duplicate_shows_error(): void
    {
        [$tenant] = $this->tenantWithShop(['code' => 'TDU']);
        SkuImportMapping::create([
            'tenant_id' => $tenant->id,
            'name' => 'Existing Template',
            'mapping' => [],
        ]);

        $csv = "sku,name\nTDU001,P\n";
        Livewire::actingAs($this->internalUser())
            ->test(SkuImport::class)
            ->set('tenantId', (string) $tenant->id)
            ->set('file', File::createWithContent('import.csv', $csv))
            ->call('readFile')
            ->call('advanceToPreview')
            ->set('doSaveTemplate', true)
            ->set('saveTemplateName', 'Existing Template')
            ->call('confirmImport')
            ->assertSet('step', 'preview')
            ->assertSee('already exists');

        // guard fired: no second template row created
        $this->assertSame(1, SkuImportMapping::where('tenant_id', $tenant->id)->count());
    }

    public function test_template_scoped_to_tenant(): void
    {
        [$tenantA, $userA] = $this->tenantUser();
        [$tenantB, $userB] = $this->tenantUser();

        SkuImportMapping::create([
            'tenant_id' => $tenantA->id,
            'name' => 'Tenant A Template',
            'mapping' => [],
        ]);

        $csv = "sku,name\nS001,P\n";
        Livewire::actingAs($userB)
            ->test(SkuImport::class)
            ->set('file', File::createWithContent('import.csv', $csv))
            ->call('readFile')
            ->assertSet('step', 'map')
            ->assertDontSee('Tenant A Template');
    }

    public function test_tenant_user_cannot_load_another_tenants_template_by_spoofing_tenant_id(): void
    {
        [$tenantA] = $this->tenantUser();
        [$tenantB, $userB] = $this->tenantUser();

        $template = SkuImportMapping::create([
            'tenant_id' => $tenantA->id,
            'name' => 'Tenant A Template',
            'mapping' => ['sku' => 'product_code', 'name' => 'title'],
        ]);

        Livewire::actingAs($userB)
            ->test(SkuImport::class)
            ->set('tenantId', (string) $tenantB->id)
            ->set('file', File::createWithContent('import.csv', "sku,name\nX001,Prod\n"))
            ->call('readFile')
            ->set('tenantId', (string) $tenantA->id)
            ->call('loadTemplate', $template->id)
            ->assertHasErrors(['tenantId'])
            ->assertSet('mapping.sku', 'sku')
            ->assertSet('mapping.name', 'name');
    }

    public function test_tenant_user_cannot_delete_another_tenants_template_by_spoofing_tenant_id(): void
    {
        [$tenantA] = $this->tenantUser();
        [$tenantB, $userB] = $this->tenantUser();

        $template = SkuImportMapping::create([
            'tenant_id' => $tenantA->id,
            'name' => 'Tenant A Template',
            'mapping' => ['sku' => 'product_code'],
        ]);

        Livewire::actingAs($userB)
            ->test(SkuImport::class)
            ->set('tenantId', (string) $tenantB->id)
            ->set('tenantId', (string) $tenantA->id)
            ->call('deleteTemplate', $template->id)
            ->assertHasErrors(['tenantId']);

        $this->assertDatabaseHas('sku_import_mappings', [
            'id' => $template->id,
            'tenant_id' => $tenantA->id,
        ]);
    }

    public function test_import_shows_clean_error_when_stored_file_is_missing_before_confirm(): void
    {
        [$tenant] = $this->tenantWithShop(['code' => 'EXP']);
        $csv = "sku,name\nEXP001,Product\n";

        $component = Livewire::actingAs($this->internalUser())
            ->test(SkuImport::class)
            ->set('tenantId', (string) $tenant->id)
            ->set('file', File::createWithContent('import.csv', $csv))
            ->call('readFile')
            ->call('advanceToPreview')
            ->assertSet('step', 'preview');

        $path = $component->get('filePath');
        $this->assertNotSame('', $path);
        Storage::disk('local')->delete($path);

        $component
            ->set('allowUpsert', '0')
            ->call('confirmImport')
            ->assertHasErrors(['file'])
            ->assertSet('step', 'upload');

        $this->assertDatabaseMissing('skus', [
            'tenant_id' => $tenant->id,
            'sku' => 'EXP001',
        ]);
    }

    // ---- Helpers ----

    private function csvFile(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'sku_import_test_').'.csv';
        file_put_contents($path, $content);

        return $path;
    }

    private function internalUser(): User
    {
        return User::factory()->create([
            'user_type' => 'internal',
            'is_active' => true,
        ]);
    }

    /** @return array{0: Tenant, 1: Shop} */
    private function tenantWithShop(array $attributes = []): array
    {
        $tenant = Tenant::factory()->create($attributes);
        $shop = Shop::factory()->for($tenant)->create(['status' => 'active']);

        return [$tenant, $shop];
    }

    /** @return array{0: Tenant, 1: User} */
    private function tenantUser(): array
    {
        $tenant = Tenant::factory()->create();
        Shop::factory()->for($tenant)->create(['status' => 'active']);
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
