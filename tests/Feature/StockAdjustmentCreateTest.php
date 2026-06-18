<?php

namespace Tests\Feature;

use App\Livewire\StockAdjustmentCreate;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\StockItem;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StockAdjustmentCreateTest extends TestCase
{
    use RefreshDatabase;

    public function test_internal_user_can_create_manual_stock_adjustment(): void
    {
        [$tenant, $warehouse, $stockItem] = $this->targetModels();
        $user = $this->internalUser();

        InventoryBalance::factory()->create([
            'tenant_id' => $tenant->id,
            'warehouse_id' => $warehouse->id,
            'stock_item_id' => $stockItem->id,
            'on_hand_qty' => 10,
            'reserved_qty' => 2,
            'available_qty' => 8,
            'inbound_qty' => 0,
            'hold_qty' => 0,
            'damaged_qty' => 0,
        ]);

        Livewire::actingAs($user)
            ->test(StockAdjustmentCreate::class, [
                'tenant_id' => (string) $tenant->id,
                'warehouse_id' => (string) $warehouse->id,
                'stock_item_id' => (string) $stockItem->id,
            ])
            ->assertSet('tenantId', (string) $tenant->id)
            ->set('quantity', '5')
            ->set('note', 'Cycle count correction')
            ->set('refId', 'ADJ-001')
            ->call('save')
            ->assertRedirect(route('inventory.index'));

        $this->assertDatabaseHas('inventory_balances', [
            'tenant_id' => $tenant->id,
            'warehouse_id' => $warehouse->id,
            'stock_item_id' => $stockItem->id,
            'on_hand_qty' => 15,
            'reserved_qty' => 2,
            'available_qty' => 13,
        ]);

        $movement = InventoryMovement::where('stock_item_id', $stockItem->id)->firstOrFail();

        $this->assertSame(InventoryMovement::TYPE_ADJUST, $movement->movement_type);
        $this->assertSame(5, $movement->quantity_delta);
        $this->assertSame(5, $movement->on_hand_delta);
        $this->assertSame(5, $movement->available_delta);
        $this->assertSame(15, $movement->on_hand_after);
        $this->assertSame(13, $movement->available_after);
        $this->assertSame('manual_adjustment', $movement->ref_type);
        $this->assertSame('ADJ-001', $movement->ref_id);
        $this->assertSame($user->id, $movement->user_id);
    }

    public function test_negative_adjustment_cannot_make_inventory_negative(): void
    {
        [$tenant, $warehouse, $stockItem] = $this->targetModels();

        InventoryBalance::factory()->create([
            'tenant_id' => $tenant->id,
            'warehouse_id' => $warehouse->id,
            'stock_item_id' => $stockItem->id,
            'on_hand_qty' => 3,
            'reserved_qty' => 0,
            'available_qty' => 3,
            'inbound_qty' => 0,
            'hold_qty' => 0,
            'damaged_qty' => 0,
        ]);

        Livewire::actingAs($this->internalUser())
            ->test(StockAdjustmentCreate::class)
            ->set('tenantId', (string) $tenant->id)
            ->set('warehouseId', (string) $warehouse->id)
            ->set('stockItemId', (string) $stockItem->id)
            ->set('quantity', '-5')
            ->call('save')
            ->assertHasErrors(['quantity']);

        $this->assertDatabaseHas('inventory_balances', [
            'tenant_id' => $tenant->id,
            'warehouse_id' => $warehouse->id,
            'stock_item_id' => $stockItem->id,
            'on_hand_qty' => 3,
            'available_qty' => 3,
        ]);
        $this->assertSame(0, InventoryMovement::count());
    }

    public function test_tenant_user_is_prefilled_and_cannot_adjust_other_tenant_stock(): void
    {
        [$ownTenant, $user] = $this->tenantUser();
        $otherTenant = Tenant::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $otherStockItem = StockItem::factory()->for($otherTenant)->create();

        Livewire::actingAs($user)
            ->test(StockAdjustmentCreate::class)
            ->assertSet('tenantId', (string) $ownTenant->id)
            ->set('tenantId', (string) $otherTenant->id)
            ->set('warehouseId', (string) $warehouse->id)
            ->set('stockItemId', (string) $otherStockItem->id)
            ->set('quantity', '1')
            ->call('save')
            ->assertHasErrors(['tenantId']);

        $this->assertSame(0, InventoryMovement::count());
    }

    public function test_inventory_rows_link_to_prefilled_stock_adjustment_page(): void
    {
        [$tenant, $warehouse, $stockItem] = $this->targetModels();

        InventoryBalance::factory()->create([
            'tenant_id' => $tenant->id,
            'warehouse_id' => $warehouse->id,
            'stock_item_id' => $stockItem->id,
        ]);

        $this->actingAs($this->internalUser())
            ->get('/inventory')
            ->assertOk()
            ->assertSee('Adjust')
            ->assertSee(route('stock-adjustments.create', [
                'tenant_id' => $tenant->id,
                'warehouse_id' => $warehouse->id,
                'stock_item_id' => $stockItem->id,
            ]), false);
    }

    private function internalUser(): User
    {
        return User::factory()->create([
            'user_type' => 'internal',
            'is_active' => true,
        ]);
    }

    /**
     * @return array{0: Tenant, 1: Warehouse, 2: StockItem}
     */
    private function targetModels(): array
    {
        $tenant = Tenant::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $stockItem = StockItem::factory()->for($tenant)->create();

        return [$tenant, $warehouse, $stockItem];
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
