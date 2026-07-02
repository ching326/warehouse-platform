<?php

namespace Tests\Feature;

use App\Livewire\StockCountCreate;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\StockCountLine;
use App\Models\StockCountRun;
use App\Models\StockItem;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StockCountTest extends TestCase
{
    use RefreshDatabase;

    public function test_internal_user_can_open_stock_count_pages(): void
    {
        [$tenant, $warehouse] = $this->targetModels();
        $run = StockCountRun::create([
            'tenant_id' => $tenant->id,
            'warehouse_id' => $warehouse->id,
            'source' => StockCountRun::SOURCE_MANUAL,
            'total_lines' => 0,
            'posted_at' => now(),
        ]);

        $this->actingAs($this->internalUser())
            ->get(route('stock-counts.index'))
            ->assertOk()
            ->assertSee(__('stock_counts.page_title'));

        $this->actingAs($this->internalUser())
            ->get(route('stock-counts.create'))
            ->assertOk()
            ->assertSee(__('stock_counts.create_title'));

        $this->actingAs($this->internalUser())
            ->get(route('stock-counts.show', $run))
            ->assertOk()
            ->assertSee('#'.$run->id);
    }

    public function test_tenant_user_cannot_open_stock_count_pages(): void
    {
        [$tenant, $user] = $this->tenantUser();
        $warehouse = Warehouse::factory()->create(['status' => 'active']);
        $run = StockCountRun::create([
            'tenant_id' => $tenant->id,
            'warehouse_id' => $warehouse->id,
            'source' => StockCountRun::SOURCE_MANUAL,
            'posted_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('stock-counts.index'))
            ->assertForbidden();

        $this->actingAs($user)
            ->get(route('stock-counts.show', $run))
            ->assertForbidden();
    }

    public function test_manual_stock_count_above_current_creates_positive_adjustment(): void
    {
        [$tenant, $warehouse, $stockItem] = $this->targetModels();
        $this->balance($tenant, $warehouse, $stockItem, 10);

        Livewire::actingAs($this->internalUser())
            ->test(StockCountCreate::class)
            ->set('tenantId', (string) $tenant->id)
            ->set('warehouseId', (string) $warehouse->id)
            ->set('stockItemId', (string) $stockItem->id)
            ->set('countedQty', '13')
            ->call('save')
            ->assertRedirect();

        $movement = InventoryMovement::firstOrFail();
        $line = StockCountLine::firstOrFail();

        $this->assertSame(3, $movement->on_hand_delta);
        $this->assertSame('stock_count', $movement->ref_type);
        $this->assertSame(3, $line->delta_qty);
        $this->assertSame(StockCountLine::STATUS_ADJUSTED, $line->status);
        $this->assertSame($movement->id, $line->movement_id);
    }

    public function test_manual_stock_count_below_current_creates_negative_adjustment(): void
    {
        [$tenant, $warehouse, $stockItem] = $this->targetModels();
        $this->balance($tenant, $warehouse, $stockItem, 10);

        Livewire::actingAs($this->internalUser())
            ->test(StockCountCreate::class)
            ->set('tenantId', (string) $tenant->id)
            ->set('warehouseId', (string) $warehouse->id)
            ->set('stockItemId', (string) $stockItem->id)
            ->set('countedQty', '7')
            ->call('save')
            ->assertRedirect();

        $this->assertSame(-3, InventoryMovement::firstOrFail()->on_hand_delta);
        $this->assertSame(-3, StockCountLine::firstOrFail()->delta_qty);
    }

    public function test_manual_stock_count_same_qty_records_no_change_without_movement(): void
    {
        [$tenant, $warehouse, $stockItem] = $this->targetModels();
        $this->balance($tenant, $warehouse, $stockItem, 10);

        Livewire::actingAs($this->internalUser())
            ->test(StockCountCreate::class)
            ->set('tenantId', (string) $tenant->id)
            ->set('warehouseId', (string) $warehouse->id)
            ->set('stockItemId', (string) $stockItem->id)
            ->set('countedQty', '10')
            ->call('save')
            ->assertRedirect();

        $this->assertSame(0, InventoryMovement::count());
        $this->assertSame(StockCountLine::STATUS_NO_CHANGE, StockCountLine::firstOrFail()->status);
    }

    public function test_manual_stock_count_blocks_qty_lower_than_reserved_hold_damaged(): void
    {
        [$tenant, $warehouse, $stockItem] = $this->targetModels();
        $this->balance($tenant, $warehouse, $stockItem, 10, reserved: 2, hold: 1, damaged: 1);

        Livewire::actingAs($this->internalUser())
            ->test(StockCountCreate::class)
            ->set('tenantId', (string) $tenant->id)
            ->set('warehouseId', (string) $warehouse->id)
            ->set('stockItemId', (string) $stockItem->id)
            ->set('countedQty', '3')
            ->call('save')
            ->assertHasErrors(['countedQty']);

        $this->assertSame(0, StockCountRun::count());
        $this->assertSame(0, InventoryMovement::count());
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

    /**
     * @return array{0: Tenant, 1: Warehouse, 2: StockItem}
     */
    private function targetModels(): array
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        $warehouse = Warehouse::factory()->create(['status' => 'active']);
        $stockItem = StockItem::factory()->for($tenant)->create(['code' => 'COUNT-STOCK']);

        return [$tenant, $warehouse, $stockItem];
    }

    private function balance(Tenant $tenant, Warehouse $warehouse, StockItem $stockItem, int $onHand, int $reserved = 0, int $hold = 0, int $damaged = 0): void
    {
        InventoryBalance::factory()->create([
            'tenant_id' => $tenant->id,
            'warehouse_id' => $warehouse->id,
            'stock_item_id' => $stockItem->id,
            'on_hand_qty' => $onHand,
            'reserved_qty' => $reserved,
            'hold_qty' => $hold,
            'damaged_qty' => $damaged,
            'available_qty' => $onHand - $reserved - $hold - $damaged,
            'inbound_qty' => 0,
        ]);
    }
}
