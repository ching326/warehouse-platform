<?php

namespace Tests\Feature;

use App\Livewire\InventoryMovementsIndex;
use App\Models\InventoryMovement;
use App\Models\StockItem;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class InventoryMovementsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_inventory_movements_route_renders_flux_page(): void
    {
        $rows = $this->createMovementRows();

        $this->actingAs($this->internalUser())
            ->get('/inventory/movements')
            ->assertOk()
            ->assertSee('Inventory Movements')
            ->assertSee('Filtered Movements')
            ->assertSee('Net Available Impact')
            ->assertSee('Positive Available Impact')
            ->assertSee('Negative Available Impact')
            ->assertSee(now()->subDays(3)->format('Y-m-d'))
            ->assertDontSee(now()->subDays(3)->format('M j'))
            ->assertSee($rows['alphaStock']->code)
            ->assertSee('PO-ALPHA-1')
            ->assertSee('Receive');
    }

    public function test_inventory_movements_can_filter_by_stock_item_query_parameter(): void
    {
        $rows = $this->createMovementRows();

        $this->actingAs($this->internalUser())
            ->get('/inventory/movements?stock_item_id='.$rows['alphaStock']->id)
            ->assertOk()
            ->assertSee($rows['alphaStock']->code)
            ->assertSee('PO-ALPHA-1')
            ->assertDontSee('PO-BETA-1');
    }

    public function test_inventory_movements_supports_query_string_filters(): void
    {
        $rows = $this->createMovementRows();

        $this->actingAs($this->internalUser())
            ->get('/inventory/movements?tenant_id='.$rows['betaTenant']->id.'&warehouse_id='.$rows['betaWarehouse']->id.'&movement_type='.InventoryMovement::TYPE_RESERVE)
            ->assertOk()
            ->assertSee($rows['betaStock']->code)
            ->assertSee('PO-BETA-1')
            ->assertDontSee('PO-ALPHA-1');
    }

    public function test_tenant_users_only_see_their_own_movements(): void
    {
        $rows = $this->createMovementRows();
        $user = User::factory()->create(['user_type' => 'tenant']);

        TenantUser::create([
            'tenant_id' => $rows['alphaTenant']->id,
            'user_id' => $user->id,
            'role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        Livewire::actingAs($user)
            ->test(InventoryMovementsIndex::class)
            ->assertSee($rows['alphaStock']->code)
            ->assertSee('PO-ALPHA-1')
            ->assertDontSee($rows['betaStock']->code)
            ->assertDontSee('PO-BETA-1')
            ->assertDontSeeHtml('<div>Tenant</div>');
    }

    public function test_internal_user_can_search_and_filter_movements(): void
    {
        $rows = $this->createMovementRows();

        Livewire::actingAs($this->internalUser())
            ->test(InventoryMovementsIndex::class)
            ->set('search', 'PO-BETA-1')
            ->assertSee('PO-BETA-1')
            ->assertDontSee('PO-ALPHA-1')
            ->set('search', '')
            ->set('movementType', InventoryMovement::TYPE_RESERVE)
            ->assertSee('PO-BETA-1')
            ->assertDontSee('PO-ALPHA-1')
            ->set('movementType', '')
            ->set('warehouseId', (string) $rows['alphaWarehouse']->id)
            ->assertSee('PO-ALPHA-1')
            ->assertDontSee('PO-BETA-1');
    }

    public function test_internal_user_can_filter_by_stock_item_user_and_date_range(): void
    {
        $rows = $this->createMovementRows();

        Livewire::actingAs($this->internalUser())
            ->test(InventoryMovementsIndex::class)
            ->set('stockItemId', (string) $rows['alphaStock']->id)
            ->assertSee('PO-ALPHA-1')
            ->assertDontSee('PO-BETA-1')
            ->set('stockItemId', '')
            ->set('userId', (string) $rows['betaUser']->id)
            ->assertSee('PO-BETA-1')
            ->assertDontSee('PO-ALPHA-1')
            ->set('userId', '')
            ->set('dateFrom', now()->subDay()->toDateString())
            ->assertSee('PO-BETA-1')
            ->assertDontSee('PO-ALPHA-1')
            ->set('dateFrom', '')
            ->set('dateTo', now()->subDays(2)->toDateString())
            ->assertSee('PO-ALPHA-1')
            ->assertDontSee('PO-BETA-1');
    }

    public function test_inventory_movements_page_eager_loads_row_relationships(): void
    {
        $this->createMovementRows();

        Model::preventLazyLoading();

        try {
            $this->actingAs($this->internalUser())
                ->get('/inventory/movements')
                ->assertOk()
                ->assertSee('Inventory Movements');
        } finally {
            Model::preventLazyLoading(false);
        }
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
    private function createMovementRows(): array
    {
        $alphaTenant = Tenant::factory()->create(['code' => 'ALP', 'name' => 'Alpha Goods']);
        $betaTenant = Tenant::factory()->create(['code' => 'BET', 'name' => 'Beta Supplies']);

        $alphaWarehouse = Warehouse::factory()->create(['code' => 'JP-MOVE-A', 'name' => 'Tokyo Movement Warehouse']);
        $betaWarehouse = Warehouse::factory()->create(['code' => 'US-MOVE-B', 'name' => 'LA Movement Warehouse']);

        $alphaStock = StockItem::factory()->for($alphaTenant)->create([
            'code' => 'STK-MOVE-ALPHA',
            'name' => 'Alpha Movement Charger',
            'barcode' => '4911111111111',
        ]);

        $betaStock = StockItem::factory()->for($betaTenant)->create([
            'code' => 'STK-MOVE-BETA',
            'name' => 'Beta Movement Cream',
            'barcode' => '4922222222222',
        ]);

        $alphaUser = User::factory()->create(['name' => 'Alpha Operator']);
        $betaUser = User::factory()->create(['name' => 'Beta Operator']);

        InventoryMovement::factory()
            ->for($alphaTenant)
            ->for($alphaWarehouse)
            ->for($alphaStock)
            ->create([
                'movement_type' => InventoryMovement::TYPE_RECEIVE,
                'quantity_delta' => 100,
                'balance_after' => 100,
                'on_hand_delta' => 100,
                'reserved_delta' => 0,
                'available_delta' => 100,
                'inbound_delta' => 0,
                'hold_delta' => 0,
                'damaged_delta' => 0,
                'on_hand_after' => 100,
                'reserved_after' => 0,
                'available_after' => 100,
                'inbound_after' => 0,
                'hold_after' => 0,
                'damaged_after' => 0,
                'ref_type' => 'purchase_order',
                'ref_id' => 'PO-ALPHA-1',
                'user_id' => $alphaUser->id,
                'note' => 'Alpha inbound receipt',
                'created_at' => now()->subDays(3),
            ]);

        InventoryMovement::factory()
            ->for($betaTenant)
            ->for($betaWarehouse)
            ->for($betaStock)
            ->create([
                'movement_type' => InventoryMovement::TYPE_RESERVE,
                'quantity_delta' => -20,
                'balance_after' => 80,
                'on_hand_delta' => 0,
                'reserved_delta' => 20,
                'available_delta' => -20,
                'inbound_delta' => 0,
                'hold_delta' => 0,
                'damaged_delta' => 0,
                'on_hand_after' => 100,
                'reserved_after' => 20,
                'available_after' => 80,
                'inbound_after' => 0,
                'hold_after' => 0,
                'damaged_after' => 0,
                'ref_type' => 'order',
                'ref_id' => 'PO-BETA-1',
                'user_id' => $betaUser->id,
                'note' => 'Beta reservation',
                'created_at' => now(),
            ]);

        return compact(
            'alphaTenant',
            'betaTenant',
            'alphaWarehouse',
            'betaWarehouse',
            'alphaStock',
            'betaStock',
            'alphaUser',
            'betaUser',
        );
    }
}
