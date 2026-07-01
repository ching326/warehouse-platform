<?php

namespace Tests\Feature;

use App\Livewire\StockAdjustmentImport;
use App\Models\BarcodeAlias;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\Sku;
use App\Models\StockAdjustmentImportMapping;
use App\Models\StockAdjustmentImportRun;
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

class StockAdjustmentImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_internal_user_can_open_stock_adjustment_import_page(): void
    {
        $this->actingAs($this->internalUser())
            ->get(route('stock-adjustments.import'))
            ->assertOk()
            ->assertSee(__('stock_adjustment_import.page_title'));
    }

    public function test_tenant_user_can_only_import_for_own_tenant(): void
    {
        [$tenant, $user] = $this->tenantUser();
        $otherTenant = Tenant::factory()->create();
        $warehouse = Warehouse::factory()->create(['status' => 'active']);
        StockItem::factory()->for($otherTenant)->create(['code' => 'OTHER-STOCK']);

        Livewire::actingAs($user)
            ->test(StockAdjustmentImport::class)
            ->assertSet('tenantId', (string) $tenant->id)
            ->set('tenantId', (string) $otherTenant->id)
            ->set('warehouseId', (string) $warehouse->id)
            ->set('action', 'add')
            ->set('reason', 'found_stock')
            ->set('file', $this->csv([['OTHER-STOCK', '1']]))
            ->call('readFile')
            ->assertHasErrors(['tenantId']);

        $this->assertSame(0, InventoryMovement::count());
    }

    public function test_tenant_user_cannot_manage_another_tenants_mapping_template(): void
    {
        [$tenant, $user] = $this->tenantUser();
        $otherTenant = Tenant::factory()->create();
        $template = StockAdjustmentImportMapping::create([
            'tenant_id' => $otherTenant->id,
            'name' => 'Other template',
            'mapping' => ['identifier' => 'Identifier', 'quantity' => 'Quantity'],
            'is_default' => false,
        ]);

        Livewire::actingAs($user)
            ->test(StockAdjustmentImport::class)
            ->assertSet('tenantId', (string) $tenant->id)
            ->set('fileHeaders', ['Identifier', 'Quantity'])
            ->set('mapping', ['identifier' => '', 'quantity' => '', 'line_note' => '', 'reference_no' => ''])
            ->call('loadTemplate', $template->id)
            ->assertSet('mapping.identifier', '')
            ->call('setDefaultTemplate', $template->id)
            ->call('deleteTemplate', $template->id);

        $this->assertDatabaseHas('stock_adjustment_import_mappings', [
            'id' => $template->id,
            'tenant_id' => $otherTenant->id,
            'is_default' => false,
        ]);

        Livewire::actingAs($user)
            ->test(StockAdjustmentImport::class)
            ->set('tenantId', (string) $otherTenant->id)
            ->set('templateName', 'Bad')
            ->call('saveTemplate')
            ->assertHasErrors(['tenantId']);
    }

    public function test_upload_step_rejects_required_inputs(): void
    {
        Livewire::actingAs($this->internalUser())
            ->test(StockAdjustmentImport::class)
            ->call('readFile')
            ->assertHasErrors(['tenantId', 'warehouseId', 'action', 'reason', 'file']);
    }

    public function test_action_and_reason_options_are_limited(): void
    {
        Livewire::actingAs($this->internalUser())
            ->test(StockAdjustmentImport::class)
            ->assertSee(__('stock_adjustment_import.action_add'))
            ->assertSee(__('stock_adjustment_import.action_deduct'))
            ->assertDontSee('Set actual qty')
            ->set('action', 'add')
            ->assertSee(__('stock_adjustments.reasons.found_stock'))
            ->assertDontSee(__('stock_adjustments.reasons.lost_missing'))
            ->set('action', 'deduct')
            ->assertSee(__('stock_adjustments.reasons.lost_missing'))
            ->assertDontSee(__('stock_adjustments.reasons.found_stock'));
    }

    public function test_default_template_auto_loads_after_upload_for_selected_tenant(): void
    {
        [$tenant, $warehouse] = $this->targetTenantWarehouse();

        StockAdjustmentImportMapping::create([
            'tenant_id' => $tenant->id,
            'name' => 'Default',
            'mapping' => ['identifier' => 'Code', 'quantity' => 'Qty'],
            'is_default' => true,
        ]);

        Storage::fake('local');

        Livewire::actingAs($this->internalUser())
            ->test(StockAdjustmentImport::class)
            ->set('tenantId', (string) $tenant->id)
            ->set('warehouseId', (string) $warehouse->id)
            ->set('action', 'add')
            ->set('reason', 'found_stock')
            ->set('file', $this->csv([['STK-A', '1']], ['Code', 'Qty']))
            ->call('readFile')
            ->assertSet('mapping.identifier', 'Code')
            ->assertSet('mapping.quantity', 'Qty');
    }

    public function test_identifier_resolution_sources_and_errors(): void
    {
        [$tenant, $warehouse] = $this->targetTenantWarehouse();
        $system = StockItem::factory()->for($tenant)->create(['code' => 'SYS-001']);
        $tenantCode = StockItem::factory()->for($tenant)->create(['code' => 'SYS-002', 'tenant_item_code' => 'TENANT-002']);
        $skuStock = StockItem::factory()->for($tenant)->create(['code' => 'SYS-003']);
        Sku::factory()->for($tenant)->for($skuStock)->create(['sku' => 'SKU-003']);
        $barcodeStock = StockItem::factory()->for($tenant)->create(['code' => 'SYS-004']);
        BarcodeAlias::create([
            'tenant_id' => $tenant->id,
            'model_type' => BarcodeAlias::MODEL_TYPE_STOCK_ITEM,
            'model_id' => $barcodeStock->id,
            'barcode' => '4900-0000',
            'normalized_barcode' => BarcodeAlias::normalize('4900-0000'),
            'barcode_type' => 'jan',
            'is_active' => true,
        ]);
        $otherTenant = Tenant::factory()->create();
        StockItem::factory()->for($otherTenant)->create(['code' => 'OTHER-ONLY']);

        $component = $this->preview($tenant, $warehouse, [
            [$system->code, '1'],
            ['TENANT-002', '1'],
            ['SKU-003', '1'],
            ['49000000', '1'],
            ['OTHER-ONLY', '1'],
        ]);

        $rows = $component->get('previewRows');

        $this->assertSame('SYS-001', $rows[0]['stock_item_code']);
        $this->assertSame('SYS-002', $rows[1]['stock_item_code']);
        $this->assertSame('SYS-003', $rows[2]['stock_item_code']);
        $this->assertSame('SYS-004', $rows[3]['stock_item_code']);
        $this->assertSame('error', $rows[4]['status']);
        $this->assertContains(__('stock_adjustment_import.error_identifier_not_found'), $rows[4]['errors']);
    }

    public function test_ambiguous_identifier_and_duplicate_stock_item_rows_are_blocked(): void
    {
        [$tenant, $warehouse] = $this->targetTenantWarehouse();
        $stockA = StockItem::factory()->for($tenant)->create(['code' => 'AMB-STOCK']);
        $stockB = StockItem::factory()->for($tenant)->create(['code' => 'OTHER-STOCK']);
        $stockC = StockItem::factory()->for($tenant)->create(['code' => 'DUP-STOCK']);
        Sku::factory()->for($tenant)->for($stockB)->create(['sku' => 'AMB-STOCK']);

        $component = $this->preview($tenant, $warehouse, [
            ['AMB-STOCK', '1'],
            [$stockC->code, '1'],
            [$stockC->code, '2'],
        ]);

        $rows = $component->get('previewRows');

        $this->assertSame('error', $rows[0]['status']);
        $this->assertContains(__('stock_adjustment_import.error_identifier_ambiguous'), $rows[0]['errors']);
        $this->assertSame('error', $rows[2]['status']);
        $this->assertContains(__('stock_adjustment_import.error_duplicate_stock_item'), $rows[2]['errors']);
    }

    public function test_add_import_creates_positive_adjustment_movement_and_run(): void
    {
        [$tenant, $warehouse] = $this->targetTenantWarehouse();
        $stockItem = StockItem::factory()->for($tenant)->create(['code' => 'ADD-STOCK']);
        InventoryBalance::factory()->create([
            'tenant_id' => $tenant->id,
            'warehouse_id' => $warehouse->id,
            'stock_item_id' => $stockItem->id,
            'on_hand_qty' => 10,
            'reserved_qty' => 0,
            'available_qty' => 10,
            'inbound_qty' => 0,
            'hold_qty' => 0,
            'damaged_qty' => 0,
        ]);

        $component = $this->preview($tenant, $warehouse, [[$stockItem->code, '5', 'Line note', 'REF-1']], action: 'add', reason: 'found_stock');
        $component->call('confirmImport')->assertSet('step', 'result');

        $this->assertDatabaseHas('inventory_balances', [
            'tenant_id' => $tenant->id,
            'warehouse_id' => $warehouse->id,
            'stock_item_id' => $stockItem->id,
            'on_hand_qty' => 15,
            'available_qty' => 15,
        ]);

        $run = StockAdjustmentImportRun::firstOrFail();
        $movement = InventoryMovement::where('stock_item_id', $stockItem->id)->firstOrFail();

        $this->assertSame('stock_adjustment_import', $movement->ref_type);
        $this->assertSame((string) $run->id, $movement->ref_id);
        $this->assertSame(5, $movement->on_hand_delta);
        $this->assertSame(5, $movement->available_delta);
        $this->assertStringContainsString('Found stock', (string) $movement->note);
        $this->assertStringContainsString('Line note', (string) $movement->note);
        $this->assertStringContainsString('REF-1', (string) $movement->note);
    }

    public function test_deduct_import_creates_negative_adjustment_and_blocks_negative_on_hand(): void
    {
        [$tenant, $warehouse] = $this->targetTenantWarehouse();
        $stockItem = StockItem::factory()->for($tenant)->create(['code' => 'DED-STOCK']);
        InventoryBalance::factory()->create([
            'tenant_id' => $tenant->id,
            'warehouse_id' => $warehouse->id,
            'stock_item_id' => $stockItem->id,
            'on_hand_qty' => 10,
            'reserved_qty' => 0,
            'available_qty' => 10,
            'inbound_qty' => 0,
            'hold_qty' => 0,
            'damaged_qty' => 0,
        ]);

        $component = $this->preview($tenant, $warehouse, [[$stockItem->code, '4']], action: 'deduct', reason: 'lost_missing');
        $component->call('confirmImport')->assertSet('step', 'result');

        $this->assertDatabaseHas('inventory_balances', [
            'tenant_id' => $tenant->id,
            'warehouse_id' => $warehouse->id,
            'stock_item_id' => $stockItem->id,
            'on_hand_qty' => 6,
            'available_qty' => 6,
        ]);

        $this->assertSame(-4, InventoryMovement::firstOrFail()->on_hand_delta);

        $blocked = $this->preview($tenant, $warehouse, [[$stockItem->code, '99']], action: 'deduct', reason: 'lost_missing');
        $blocked->assertSet('errorRowCount', 1);
        $this->assertContains(__('stock_adjustment_import.error_negative_on_hand'), $blocked->get('previewRows')[0]['errors']);
    }

    public function test_confirm_revalidates_if_stock_changed_after_preview(): void
    {
        [$tenant, $warehouse] = $this->targetTenantWarehouse();
        $stockItem = StockItem::factory()->for($tenant)->create(['code' => 'RECHECK-STOCK']);
        $balance = InventoryBalance::factory()->create([
            'tenant_id' => $tenant->id,
            'warehouse_id' => $warehouse->id,
            'stock_item_id' => $stockItem->id,
            'on_hand_qty' => 5,
            'reserved_qty' => 0,
            'available_qty' => 5,
            'inbound_qty' => 0,
            'hold_qty' => 0,
            'damaged_qty' => 0,
        ]);

        $component = $this->preview($tenant, $warehouse, [[$stockItem->code, '5']], action: 'deduct', reason: 'lost_missing');
        $component->assertSet('errorRowCount', 0);

        $balance->update(['on_hand_qty' => 3, 'available_qty' => 3]);

        $component->call('confirmImport')
            ->assertSet('step', 'preview')
            ->assertSet('errorRowCount', 1);

        $this->assertSame(0, InventoryMovement::count());
    }

    public function test_import_button_appears_on_stock_adjustment_create_page(): void
    {
        $this->actingAs($this->internalUser())
            ->get(route('stock-adjustments.create'))
            ->assertOk()
            ->assertSee(__('stock_adjustment_import.btn_import'))
            ->assertSee(route('stock-adjustments.import'), false);
    }

    private function preview(
        Tenant $tenant,
        Warehouse $warehouse,
        array $rows,
        string $action = 'add',
        string $reason = 'found_stock',
    ): Testable {
        Storage::fake('local');

        return Livewire::actingAs($this->internalUser())
            ->test(StockAdjustmentImport::class)
            ->set('tenantId', (string) $tenant->id)
            ->set('warehouseId', (string) $warehouse->id)
            ->set('action', $action)
            ->set('reason', $reason)
            ->set('file', $this->csv($rows))
            ->call('readFile')
            ->call('advanceToPreview');
    }

    private function csv(array $rows, array $headers = ['Identifier', 'Quantity', 'Line note', 'Reference no']): UploadedFile
    {
        $lines = [implode(',', $headers)];

        foreach ($rows as $row) {
            $lines[] = implode(',', array_map(fn ($value) => '"'.str_replace('"', '""', (string) $value).'"', $row));
        }

        return UploadedFile::fake()->createWithContent('adjustments.csv', implode("\n", $lines)."\n");
    }

    /**
     * @return array{0: Tenant, 1: Warehouse}
     */
    private function targetTenantWarehouse(): array
    {
        return [
            Tenant::factory()->create(['status' => 'active']),
            Warehouse::factory()->create(['status' => 'active']),
        ];
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
        $tenant = Tenant::factory()->create(['status' => 'active']);
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
