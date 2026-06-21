<?php

namespace Tests\Feature;

use App\Livewire\InboundOrderCreate;
use App\Livewire\InboundOrderDetail;
use App\Livewire\InboundOrderIndex;
use App\Livewire\InboundOrderReceive;
use App\Models\InboundOrder;
use App\Models\InboundOrderLine;
use App\Models\InboundReceipt;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\Shop;
use App\Models\Sku;
use App\Models\StockItem;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class InboundOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_inbound_order_writes_records_without_stock_change(): void
    {
        [$tenant, $warehouse, $sku] = $this->receivableSku();

        Livewire::actingAs($this->internalUser())
            ->test(InboundOrderCreate::class)
            ->set('tenantId', (string) $tenant->id)
            ->set('warehouseId', (string) $warehouse->id)
            ->set('ref', 'PO-1001')
            ->set('expectedAt', '2026-06-20')
            ->set('lines.0.sku_id', (string) $sku->id)
            ->set('lines.0.expected_qty', '12')
            ->call('save')
            ->assertRedirect(route('inbound.index'));

        $order = InboundOrder::where('ref', 'PO-1001')->firstOrFail();
        $line = $order->lines()->firstOrFail();

        $this->assertSame(InboundOrder::STATUS_PENDING, $order->status);
        $this->assertSame($sku->id, $line->sku_id);
        $this->assertSame($sku->stock_item_id, $line->stock_item_id);
        $this->assertSame(12, $line->expected_qty);
        $this->assertSame(0, InventoryMovement::count());
        $this->assertSame(0, InventoryBalance::count());
    }

    public function test_create_rejects_duplicate_skus_and_virtual_bundle_skus(): void
    {
        [$tenant, $warehouse, $sku] = $this->receivableSku();
        $virtualSku = Sku::factory()->virtualBundle()->for($tenant)->create(['shop_id' => null]);

        Livewire::actingAs($this->internalUser())
            ->test(InboundOrderCreate::class)
            ->set('tenantId', (string) $tenant->id)
            ->set('warehouseId', (string) $warehouse->id)
            ->set('lines', [
                ['sku_id' => (string) $sku->id, 'expected_qty' => '1', 'note' => ''],
                ['sku_id' => (string) $sku->id, 'expected_qty' => '2', 'note' => ''],
            ])
            ->call('save')
            ->assertHasErrors(['lines']);

        Livewire::actingAs($this->internalUser())
            ->test(InboundOrderCreate::class)
            ->set('tenantId', (string) $tenant->id)
            ->set('warehouseId', (string) $warehouse->id)
            ->set('lines.0.sku_id', (string) $virtualSku->id)
            ->set('lines.0.expected_qty', '3')
            ->call('save')
            ->assertHasErrors(['lines.0.sku_id']);
    }

    public function test_create_inbound_order_can_select_sku_beyond_first_fifty(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        $warehouse = Warehouse::factory()->create();
        $shop = Shop::factory()->for($tenant)->create(['status' => 'active']);
        $targetSku = null;

        for ($i = 1; $i <= 60; $i++) {
            $stockItem = StockItem::factory()->for($tenant)->create();
            $sku = Sku::factory()->for($tenant)->for($shop)->for($stockItem)->create([
                'sku_type' => 'single',
                'sku' => sprintf('IB-BULK-%03d', $i),
            ]);

            if ($i === 60) {
                $targetSku = $sku;
            }
        }

        Livewire::actingAs($this->internalUser())
            ->test(InboundOrderCreate::class)
            ->set('tenantId', (string) $tenant->id)
            ->set('warehouseId', (string) $warehouse->id)
            ->set('lines.0.sku_id', (string) $targetSku->id)
            ->set('lines.0.expected_qty', '1')
            ->call('save')
            ->assertRedirect(route('inbound.index'));

        $this->assertDatabaseHas('inbound_order_lines', [
            'sku_id' => $targetSku->id,
            'expected_qty' => 1,
        ]);
    }

    public function test_mark_arrived_only_updates_status_and_metadata(): void
    {
        [$tenant, $warehouse, $sku] = $this->receivableSku();
        $order = $this->inboundOrder($tenant, $warehouse, $sku, expectedQty: 5);
        $user = $this->internalUser();

        Livewire::actingAs($user)
            ->test(InboundOrderIndex::class)
            ->call('markArrived', $order->id);

        $order->refresh();

        $this->assertSame(InboundOrder::STATUS_ARRIVED, $order->status);
        $this->assertNotNull($order->arrived_at);
        $this->assertSame($user->id, $order->arrived_by_user_id);
        $this->assertSame(0, InventoryMovement::count());
        $this->assertSame(0, InventoryBalance::count());
    }

    public function test_inbound_index_actions_link_to_detail_without_row_cancel(): void
    {
        [$tenant, $warehouse, $sku] = $this->receivableSku();
        $pending = $this->inboundOrder($tenant, $warehouse, $sku, status: InboundOrder::STATUS_PENDING, ref: 'IB-PENDING-LINK');
        $arrived = $this->inboundOrder($tenant, $warehouse, $sku, status: InboundOrder::STATUS_ARRIVED, ref: 'IB-ARRIVED-LINK');
        $partial = $this->inboundOrder($tenant, $warehouse, $sku, status: InboundOrder::STATUS_PARTIALLY_RECEIVED, ref: 'IB-PARTIAL-LINK');

        Livewire::actingAs($this->internalUser())
            ->test(InboundOrderIndex::class)
            ->assertSee('IB-PENDING-LINK')
            ->assertSee(route('inbound.show', $pending), false)
            ->assertSee(__('inbound.btn_mark_arrived'))
            ->assertSee('IB-ARRIVED-LINK')
            ->assertSee('IB-PARTIAL-LINK')
            ->assertSee(__('inbound.btn_receive'))
            ->assertDontSee(__('inbound.btn_cancel_order'));

        $this->assertSame(InboundOrder::STATUS_ARRIVED, $arrived->refresh()->status);
        $this->assertSame(InboundOrder::STATUS_PARTIALLY_RECEIVED, $partial->refresh()->status);
    }

    public function test_cancel_is_blocked_after_any_received_quantity(): void
    {
        [$tenant, $warehouse, $sku] = $this->receivableSku();
        $order = $this->inboundOrder($tenant, $warehouse, $sku, status: InboundOrder::STATUS_ARRIVED, expectedQty: 5);
        $order->lines()->firstOrFail()->update(['received_qty' => 1]);

        Livewire::actingAs($this->internalUser())
            ->test(InboundOrderDetail::class, ['order' => $order])
            ->call('cancel')
            ->assertSee(__('inbound.cannot_cancel_after_receiving'));

        $this->assertSame(InboundOrder::STATUS_ARRIVED, $order->refresh()->status);
        $this->assertSame(0, InventoryMovement::count());
    }

    public function test_inbound_detail_route_renders_and_is_tenant_scoped(): void
    {
        [$tenant, $warehouse, $sku] = $this->receivableSku();
        [$otherTenant, $user] = $this->tenantUser();
        $ownStock = StockItem::factory()->for($otherTenant)->create();
        $ownSku = Sku::factory()->for($otherTenant)->for($ownStock)->create(['shop_id' => null]);
        $internalOrder = $this->inboundOrder($tenant, $warehouse, $sku, ref: 'IB-INTERNAL-DETAIL');
        $ownOrder = $this->inboundOrder($otherTenant, $warehouse, $ownSku, ref: 'IB-OWN-DETAIL');

        $this->actingAs($this->internalUser())
            ->get(route('inbound.show', $internalOrder))
            ->assertOk()
            ->assertSee('IB-INTERNAL-DETAIL');

        $this->actingAs($user)
            ->get(route('inbound.show', $ownOrder))
            ->assertOk()
            ->assertSee('IB-OWN-DETAIL');

        $this->actingAs($user)
            ->get(route('inbound.show', $internalOrder))
            ->assertNotFound();
    }

    public function test_inbound_detail_buttons_follow_status_and_received_progress(): void
    {
        [$tenant, $warehouse, $sku] = $this->receivableSku();
        $pending = $this->inboundOrder($tenant, $warehouse, $sku, status: InboundOrder::STATUS_PENDING, ref: 'IB-PENDING-ACTIONS');
        $arrived = $this->inboundOrder($tenant, $warehouse, $sku, status: InboundOrder::STATUS_ARRIVED, ref: 'IB-ARRIVED-ACTIONS');
        $partial = $this->inboundOrder($tenant, $warehouse, $sku, status: InboundOrder::STATUS_PARTIALLY_RECEIVED, ref: 'IB-PARTIAL-ACTIONS');
        $partial->lines()->firstOrFail()->update(['received_qty' => 1]);
        $received = $this->inboundOrder($tenant, $warehouse, $sku, status: InboundOrder::STATUS_RECEIVED, ref: 'IB-RECEIVED-ACTIONS');
        $received->lines()->firstOrFail()->update(['received_qty' => 5]);
        $cancelled = $this->inboundOrder($tenant, $warehouse, $sku, status: InboundOrder::STATUS_CANCELLED, ref: 'IB-CANCELLED-ACTIONS');

        Livewire::actingAs($this->internalUser())
            ->test(InboundOrderDetail::class, ['order' => $pending])
            ->assertSee(__('inbound.btn_mark_arrived'))
            ->assertSee(__('inbound.btn_cancel_order'));

        Livewire::actingAs($this->internalUser())
            ->test(InboundOrderDetail::class, ['order' => $arrived])
            ->assertSee(__('inbound.btn_receive'))
            ->assertSee(__('inbound.btn_cancel_order'));

        Livewire::actingAs($this->internalUser())
            ->test(InboundOrderDetail::class, ['order' => $partial])
            ->assertSee(__('inbound.btn_receive'))
            ->assertDontSee(__('inbound.btn_cancel_order'));

        Livewire::actingAs($this->internalUser())
            ->test(InboundOrderDetail::class, ['order' => $received])
            ->assertDontSee(__('inbound.btn_cancel_order'));

        Livewire::actingAs($this->internalUser())
            ->test(InboundOrderDetail::class, ['order' => $cancelled])
            ->assertDontSee(__('inbound.btn_cancel_order'));
    }

    public function test_mark_arrived_from_detail_sets_metadata(): void
    {
        [$tenant, $warehouse, $sku] = $this->receivableSku();
        $order = $this->inboundOrder($tenant, $warehouse, $sku, expectedQty: 5);
        $user = $this->internalUser();

        Livewire::actingAs($user)
            ->test(InboundOrderDetail::class, ['order' => $order])
            ->call('markArrived')
            ->assertSee(__('inbound.order_arrived'));

        $order->refresh();

        $this->assertSame(InboundOrder::STATUS_ARRIVED, $order->status);
        $this->assertNotNull($order->arrived_at);
        $this->assertSame($user->id, $order->arrived_by_user_id);
    }

    public function test_cancel_from_detail_sets_status_and_does_not_change_inventory(): void
    {
        [$tenant, $warehouse, $sku] = $this->receivableSku();
        $order = $this->inboundOrder($tenant, $warehouse, $sku, status: InboundOrder::STATUS_ARRIVED, expectedQty: 5);

        Livewire::actingAs($this->internalUser())
            ->test(InboundOrderDetail::class, ['order' => $order])
            ->call('cancel')
            ->assertSee(__('inbound.order_cancelled_detail'));

        $this->assertSame(InboundOrder::STATUS_CANCELLED, $order->refresh()->status);
        $this->assertSame(0, InventoryMovement::count());
        $this->assertSame(0, InventoryBalance::count());
    }

    public function test_tenant_user_cannot_cancel_another_tenants_inbound_order(): void
    {
        [, $user] = $this->tenantUser();
        [$otherTenant, $warehouse, $sku] = $this->receivableSku();
        $order = $this->inboundOrder($otherTenant, $warehouse, $sku, status: InboundOrder::STATUS_PENDING);

        $this->actingAs($user)
            ->get(route('inbound.show', $order))
            ->assertNotFound();

        $this->assertSame(InboundOrder::STATUS_PENDING, $order->refresh()->status);
    }

    public function test_inbound_detail_shows_expected_lines_and_remaining_quantities(): void
    {
        [$tenant, $warehouse, $sku] = $this->receivableSku();
        $order = $this->inboundOrder($tenant, $warehouse, $sku, status: InboundOrder::STATUS_PARTIALLY_RECEIVED, expectedQty: 5);
        $order->lines()->firstOrFail()->update(['received_qty' => 2, 'note' => 'Fragile cartons']);

        Livewire::actingAs($this->internalUser())
            ->test(InboundOrderDetail::class, ['order' => $order])
            ->assertSee($sku->sku)
            ->assertSee($sku->stockItem->code)
            ->assertSee('5')
            ->assertSee('2')
            ->assertSee('3')
            ->assertSee('Fragile cartons');
    }

    public function test_receive_creates_receipt_inventory_movement_and_completed_status(): void
    {
        [$tenant, $warehouse, $sku] = $this->receivableSku();
        $location = WarehouseLocation::factory()->for($warehouse)->create(['code' => 'RCV-01']);
        $order = $this->inboundOrder($tenant, $warehouse, $sku, status: InboundOrder::STATUS_ARRIVED, expectedQty: 8);
        $line = $order->lines()->firstOrFail();
        $user = $this->internalUser();

        Livewire::actingAs($user)
            ->test(InboundOrderReceive::class, ['order' => $order])
            ->set("lineInputs.{$line->id}.actual_qty", '8')
            ->set("lineInputs.{$line->id}.location_id", (string) $location->id)
            ->call('save')
            ->assertRedirect(route('inbound.index'));

        $order->refresh();
        $line->refresh();

        $this->assertSame(InboundOrder::STATUS_RECEIVED, $order->status);
        $this->assertSame($user->id, $order->received_by_user_id);
        $this->assertSame(8, $line->received_qty);
        $this->assertDatabaseHas('inventory_balances', [
            'tenant_id' => $tenant->id,
            'warehouse_id' => $warehouse->id,
            'stock_item_id' => $sku->stock_item_id,
            'on_hand_qty' => 8,
            'available_qty' => 8,
        ]);

        $receipt = InboundReceipt::firstOrFail();
        $movement = InventoryMovement::firstOrFail();

        $this->assertSame($movement->id, $receipt->inventory_movement_id);
        $this->assertSame($location->id, $receipt->warehouse_location_id);
        $this->assertSame(8, $receipt->received_qty);
        $this->assertSame(InventoryMovement::TYPE_RECEIVE, $movement->movement_type);
        $this->assertSame('inbound_order', $movement->ref_type);
        $this->assertSame((string) $order->id, $movement->ref_id);
        $this->assertSame(8, $movement->on_hand_delta);
        $this->assertSame(8, $movement->available_delta);
    }

    public function test_receive_allows_partial_receipt_and_requires_location_for_positive_qty(): void
    {
        [$tenant, $warehouse, $sku] = $this->receivableSku();
        $location = WarehouseLocation::factory()->for($warehouse)->create();
        $order = $this->inboundOrder($tenant, $warehouse, $sku, status: InboundOrder::STATUS_ARRIVED, expectedQty: 10);
        $line = $order->lines()->firstOrFail();

        Livewire::actingAs($this->internalUser())
            ->test(InboundOrderReceive::class, ['order' => $order])
            ->set("lineInputs.{$line->id}.actual_qty", '3')
            ->set("lineInputs.{$line->id}.location_id", '')
            ->call('save')
            ->assertHasErrors(["lineInputs.{$line->id}.location_id"]);

        Livewire::actingAs($this->internalUser())
            ->test(InboundOrderReceive::class, ['order' => $order])
            ->set("lineInputs.{$line->id}.actual_qty", '3')
            ->set("lineInputs.{$line->id}.location_id", (string) $location->id)
            ->call('save')
            ->assertRedirect(route('inbound.index'));

        $this->assertSame(InboundOrder::STATUS_PARTIALLY_RECEIVED, $order->refresh()->status);
        $this->assertSame(3, $line->refresh()->received_qty);
        $this->assertNull($order->received_by_user_id);
    }

    public function test_tenant_user_only_sees_own_inbound_orders(): void
    {
        [$tenant, $user] = $this->tenantUser();
        [$otherTenant, $warehouse, $sku] = $this->receivableSku();
        $ownStock = StockItem::factory()->for($tenant)->create();
        $ownSku = Sku::factory()->for($tenant)->for($ownStock)->create(['shop_id' => null, 'sku' => 'OWN-INBOUND-SKU']);
        $this->inboundOrder($tenant, $warehouse, $ownSku, ref: 'OWN-INBOUND');
        $this->inboundOrder($otherTenant, $warehouse, $sku, ref: 'HIDDEN-INBOUND');

        Livewire::actingAs($user)
            ->test(InboundOrderIndex::class)
            ->assertSee('OWN-INBOUND')
            ->assertDontSee('HIDDEN-INBOUND');
    }

    public function test_inbound_routes_render(): void
    {
        [$tenant, $warehouse, $sku] = $this->receivableSku();
        $location = WarehouseLocation::factory()->for($warehouse)->create();
        $order = $this->inboundOrder($tenant, $warehouse, $sku, status: InboundOrder::STATUS_ARRIVED);
        $this->assertNotNull($location->id);

        $this->actingAs($this->internalUser())->get('/inbound')->assertOk()->assertSee('Inbound Orders');
        $this->actingAs($this->internalUser())->get('/inbound/create')->assertOk()->assertSee('Create Inbound Order');
        $this->actingAs($this->internalUser())->get(route('inbound.show', $order))->assertOk()->assertSee('Inbound Order');
        $this->actingAs($this->internalUser())->get(route('inbound.receive', $order))->assertOk()->assertSee('Receive Inbound Order');
    }

    private function internalUser(): User
    {
        return User::factory()->create([
            'user_type' => 'internal',
            'is_active' => true,
        ]);
    }

    /**
     * @return array{0: Tenant, 1: Warehouse, 2: Sku}
     */
    private function receivableSku(): array
    {
        $tenant = Tenant::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $stockItem = StockItem::factory()->for($tenant)->create();
        $shop = Shop::factory()->for($tenant)->create();
        $sku = Sku::factory()->for($tenant)->for($shop)->for($stockItem)->create([
            'sku_type' => 'single',
            'sku' => 'SKU-'.fake()->unique()->numberBetween(1000, 9999),
        ]);

        return [$tenant, $warehouse, $sku];
    }

    private function inboundOrder(
        Tenant $tenant,
        Warehouse $warehouse,
        Sku $sku,
        string $status = InboundOrder::STATUS_PENDING,
        int $expectedQty = 5,
        string $ref = 'IB-TEST',
    ): InboundOrder {
        $order = InboundOrder::factory()->for($tenant)->for($warehouse)->create([
            'ref' => $ref,
            'status' => $status,
            'created_by_user_id' => null,
        ]);

        InboundOrderLine::factory()->for($order)->for($sku)->for($sku->stockItem)->create([
            'tenant_id' => $tenant->id,
            'expected_qty' => $expectedQty,
            'received_qty' => 0,
        ]);

        return $order;
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
