<?php

namespace Tests\Feature;

use App\Livewire\StockCountImport;
use App\Models\BarcodeAlias;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\Sku;
use App\Models\StockCountImportMapping;
use App\Models\StockCountLine;
use App\Models\StockCountRun;
use App\Models\StockItem;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class StockCountImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_page_follows_upload_map_preview_confirm_flow(): void
    {
        [$tenant, $warehouse, $stockItem] = $this->targetTenantWarehouse();
        $this->balance($tenant, $warehouse, $stockItem, 10);

        $component = $this->baseImport($tenant, $warehouse, [[$stockItem->code, '12']])
            ->assertSet('step', 'map')
            ->assertSet('mapping.identifier', 'Identifier')
            ->assertSet('mapping.counted_qty', 'Counted qty')
            ->call('advanceToPreview')
            ->assertSet('step', 'preview')
            ->assertSee('2')
            ->call('confirmImport')
            ->assertSet('step', 'result');

        $this->assertSame(1, StockCountRun::count());
        $this->assertSame(1, StockCountLine::count());
        $this->assertSame(2, InventoryMovement::firstOrFail()->on_hand_delta);
        $this->assertSame(1, $component->get('resultAdjusted'));
    }

    public function test_import_resolves_identifiers_by_supported_sources_and_blocks_cross_tenant(): void
    {
        [$tenant, $warehouse] = $this->targetTenantWarehouse();
        $system = StockItem::factory()->for($tenant)->create(['code' => 'SYS-COUNT']);
        $tenantCode = StockItem::factory()->for($tenant)->create(['code' => 'TEN-SYS', 'tenant_item_code' => 'TENANT-CODE']);
        $skuStock = StockItem::factory()->for($tenant)->create(['code' => 'SKU-STOCK']);
        Sku::factory()->for($tenant)->for($skuStock)->create(['sku' => 'SKU-CODE']);
        $barcodeStock = StockItem::factory()->for($tenant)->create(['code' => 'BAR-STOCK']);
        BarcodeAlias::create([
            'tenant_id' => $tenant->id,
            'model_type' => BarcodeAlias::MODEL_TYPE_STOCK_ITEM,
            'model_id' => $barcodeStock->id,
            'barcode' => '4900-1111',
            'normalized_barcode' => BarcodeAlias::normalize('4900-1111'),
            'barcode_type' => 'jan',
            'is_active' => true,
        ]);
        StockItem::factory()->for(Tenant::factory()->create())->create(['code' => 'OTHER-TENANT']);

        foreach ([$system, $tenantCode, $skuStock, $barcodeStock] as $item) {
            $this->balance($tenant, $warehouse, $item, 1);
        }

        $component = $this->preview($tenant, $warehouse, [
            [$system->code, '1'],
            ['TENANT-CODE', '1'],
            ['SKU-CODE', '1'],
            ['49001111', '1'],
            ['OTHER-TENANT', '1'],
        ]);

        $rows = $component->get('previewRows');

        $this->assertSame('SYS-COUNT', $rows[0]['stock_item_code']);
        $this->assertSame('TEN-SYS', $rows[1]['stock_item_code']);
        $this->assertSame('SKU-STOCK', $rows[2]['stock_item_code']);
        $this->assertSame('BAR-STOCK', $rows[3]['stock_item_code']);
        $this->assertSame('error', $rows[4]['status']);
        $this->assertContains(__('stock_counts.error_identifier_not_found'), $rows[4]['errors']);
    }

    public function test_import_blocks_ambiguous_identifier_and_duplicate_stock_item_rows(): void
    {
        [$tenant, $warehouse] = $this->targetTenantWarehouse();
        $stockA = StockItem::factory()->for($tenant)->create(['code' => 'AMB-COUNT']);
        $stockB = StockItem::factory()->for($tenant)->create(['code' => 'OTHER-COUNT']);
        $stockC = StockItem::factory()->for($tenant)->create(['code' => 'DUP-COUNT']);
        Sku::factory()->for($tenant)->for($stockB)->create(['sku' => 'AMB-COUNT']);
        $this->balance($tenant, $warehouse, $stockA, 1);
        $this->balance($tenant, $warehouse, $stockC, 1);

        $component = $this->preview($tenant, $warehouse, [
            ['AMB-COUNT', '1'],
            ['DUP-COUNT', '1'],
            ['DUP-COUNT', '2'],
        ]);

        $rows = $component->get('previewRows');
        $this->assertContains(__('stock_counts.error_identifier_ambiguous'), $rows[0]['errors']);
        $this->assertContains(__('stock_counts.error_duplicate_stock_item'), $rows[2]['errors']);
    }

    public function test_import_preview_shows_delta_and_confirm_revalidates_changed_balance(): void
    {
        [$tenant, $warehouse, $stockItem] = $this->targetTenantWarehouse();
        $balance = $this->balance($tenant, $warehouse, $stockItem, 10);

        $component = $this->preview($tenant, $warehouse, [[$stockItem->code, '7']]);
        $this->assertSame(-3, $component->get('previewRows')[0]['delta_qty']);

        $balance->update(['reserved_qty' => 8, 'available_qty' => 2]);

        $component->call('confirmImport')
            ->assertSet('step', 'preview')
            ->assertSet('errorRowCount', 1);

        $this->assertSame(0, InventoryMovement::count());
    }

    public function test_import_creates_run_lines_and_stock_count_movements(): void
    {
        [$tenant, $warehouse, $stockItem] = $this->targetTenantWarehouse();
        $this->balance($tenant, $warehouse, $stockItem, 10);

        $this->preview($tenant, $warehouse, [[$stockItem->code, '13', 'Count note', 'REF-COUNT']])
            ->call('confirmImport')
            ->assertSet('step', 'result');

        $run = StockCountRun::firstOrFail();
        $line = StockCountLine::firstOrFail();
        $movement = InventoryMovement::firstOrFail();

        $this->assertSame(1, $run->total_lines);
        $this->assertSame(1, $run->adjusted_lines);
        $this->assertSame(3, $line->delta_qty);
        $this->assertSame($movement->id, $line->movement_id);
        $this->assertSame('stock_count', $movement->ref_type);
        $this->assertSame((string) $run->id, $movement->ref_id);
    }

    public function test_import_mapping_template_is_tenant_scoped(): void
    {
        [$tenant, $warehouse] = $this->targetTenantWarehouse();
        [$ownTenant, $user] = $this->tenantUser();
        $otherTemplate = StockCountImportMapping::create([
            'tenant_id' => $tenant->id,
            'name' => 'Other',
            'mapping' => ['identifier' => 'Identifier', 'counted_qty' => 'Counted qty'],
        ]);

        Livewire::actingAs($user)
            ->test(StockCountImport::class)
            ->assertSet('tenantId', (string) $ownTenant->id)
            ->set('fileHeaders', ['Identifier', 'Counted qty'])
            ->set('mapping', ['identifier' => '', 'counted_qty' => '', 'line_note' => '', 'reference_no' => ''])
            ->call('loadTemplate', $otherTemplate->id)
            ->assertSet('mapping.identifier', '')
            ->call('setDefaultTemplate', $otherTemplate->id)
            ->call('deleteTemplate', $otherTemplate->id);

        $this->assertDatabaseHas('stock_count_import_mappings', ['id' => $otherTemplate->id]);

        Livewire::actingAs($this->internalUser())
            ->test(StockCountImport::class)
            ->set('tenantId', (string) $tenant->id)
            ->set('warehouseId', (string) $warehouse->id)
            ->set('step', 'map')
            ->set('doSaveTemplate', true)
            ->set('mapping', ['identifier' => 'Identifier', 'counted_qty' => 'Counted qty', 'line_note' => '', 'reference_no' => ''])
            ->assertDontSee('sku_import.map_btn_save')
            ->set('templateName', 'Default count')
            ->set('file', $this->csv([['STK-A', '1']]))
            ->call('readFile')
            ->set('mapping', ['identifier' => 'Identifier', 'counted_qty' => 'Counted qty', 'line_note' => '', 'reference_no' => ''])
            ->call('advanceToPreview')
            ->assertSet('doSaveTemplate', false)
            ->assertHasNoErrors();

        $template = StockCountImportMapping::where('name', 'Default count')->firstOrFail();
        $this->assertTrue($template->is_default);
    }

    public function test_stock_adjustment_import_does_not_show_set_actual_qty(): void
    {
        $this->actingAs($this->internalUser())
            ->get(route('stock-adjustments.import'))
            ->assertOk()
            ->assertDontSee('Set actual qty');
    }

    private function preview(Tenant $tenant, Warehouse $warehouse, array $rows): Testable
    {
        return $this->baseImport($tenant, $warehouse, $rows)->call('advanceToPreview');
    }

    private function baseImport(Tenant $tenant, Warehouse $warehouse, array $rows): Testable
    {
        Storage::fake('local');

        return Livewire::actingAs($this->internalUser())
            ->test(StockCountImport::class)
            ->set('tenantId', (string) $tenant->id)
            ->set('warehouseId', (string) $warehouse->id)
            ->set('file', $this->csv($rows))
            ->call('readFile');
    }

    private function csv(array $rows): UploadedFile
    {
        $lines = ['Identifier,Counted qty,Line note,Reference no'];

        foreach ($rows as $row) {
            $lines[] = implode(',', array_map(fn ($value) => '"'.str_replace('"', '""', (string) $value).'"', $row));
        }

        return UploadedFile::fake()->createWithContent('stock-count.csv', implode("\n", $lines)."\n");
    }

    /**
     * @return array{0: Tenant, 1: Warehouse, 2: StockItem}
     */
    private function targetTenantWarehouse(): array
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        $warehouse = Warehouse::factory()->create(['status' => 'active']);
        $stockItem = StockItem::factory()->for($tenant)->create(['code' => 'COUNT-IMPORT']);

        return [$tenant, $warehouse, $stockItem];
    }

    private function balance(Tenant $tenant, Warehouse $warehouse, StockItem $stockItem, int $onHand): InventoryBalance
    {
        return InventoryBalance::factory()->create([
            'tenant_id' => $tenant->id,
            'warehouse_id' => $warehouse->id,
            'stock_item_id' => $stockItem->id,
            'on_hand_qty' => $onHand,
            'reserved_qty' => 0,
            'hold_qty' => 0,
            'damaged_qty' => 0,
            'available_qty' => $onHand,
            'inbound_qty' => 0,
        ]);
    }

    private function internalUser(): User
    {
        return User::factory()->create(['user_type' => 'internal', 'is_active' => true]);
    }

    /**
     * @return array{0: Tenant, 1: User}
     */
    private function tenantUser(): array
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        $user = User::factory()->create(['user_type' => 'tenant', 'is_active' => true]);
        TenantUser::factory()->create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'status' => 'active']);

        return [$tenant, $user];
    }
}
