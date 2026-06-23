<?php

namespace Tests\Feature;

use App\Livewire\FulfillmentGroupCreate;
use App\Livewire\FulfillmentPickSummary;
use App\Models\Carrier;
use App\Models\FulfillmentGroup;
use App\Models\InventoryBalance;
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
use App\Models\Warehouse;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FulfillmentPickSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access(): void
    {
        $this->get(route('fulfillment.pick-summary'))->assertForbidden();
    }

    public function test_tenant_user_cannot_access(): void
    {
        [, $user] = $this->tenantUser();

        $this->actingAs($user)->get(route('fulfillment.pick-summary'))->assertForbidden();
    }

    public function test_page_asks_for_warehouse_before_showing_results(): void
    {
        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentPickSummary::class)
            ->assertSee('Select a warehouse to view pick summary.')
            ->assertDontSee('Pick rows');
    }

    public function test_reserved_group_appears_and_normal_sku_qty_aggregates(): void
    {
        [$tenant, $warehouse, $shop, $sku, $method] = $this->stationSku('STK-PICK-001', 'SKU-PICK-001');
        $first = $this->readySalesOrder($tenant, $shop, $sku, $method, 2, 'SO-PICK-001');
        $second = $this->readySalesOrder($tenant, $shop, $sku, $method, 3, 'SO-PICK-002');
        $this->createGroup($tenant, $warehouse, $first->ship_together_key, [$first, $second]);
        $group = FulfillmentGroup::firstOrFail();

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentPickSummary::class)
            ->set('warehouseId', (string) $warehouse->id)
            ->assertSee('STK-PICK-001')
            ->assertSee('SKU-PICK-001')
            ->assertSee($group->reference_no)
            ->assertSee('5');
    }

    public function test_shipped_and_cancelled_groups_are_excluded(): void
    {
        [$tenant, $warehouse, $shop, $sku, $method] = $this->stationSku('STK-PICK-STATUS', 'SKU-PICK-STATUS');
        $reservedOrder = $this->readySalesOrder($tenant, $shop, $sku, $method, 1, 'SO-PICK-RESERVED');
        $shippedOrder = $this->readySalesOrder($tenant, $shop, $sku, $method, 1, 'SO-PICK-SHIPPED', '2 Status Street');
        $cancelledOrder = $this->readySalesOrder($tenant, $shop, $sku, $method, 1, 'SO-PICK-CANCELLED', '3 Status Street');
        $this->createGroup($tenant, $warehouse, $reservedOrder->ship_together_key, [$reservedOrder]);
        $reserved = FulfillmentGroup::query()->latest('id')->firstOrFail();
        $this->createGroup($tenant, $warehouse, $shippedOrder->ship_together_key, [$shippedOrder]);
        FulfillmentGroup::query()->latest('id')->firstOrFail()->update(['status' => FulfillmentGroup::STATUS_SHIPPED]);
        $this->createGroup($tenant, $warehouse, $cancelledOrder->ship_together_key, [$cancelledOrder]);
        FulfillmentGroup::query()->latest('id')->firstOrFail()->update(['status' => FulfillmentGroup::STATUS_CANCELLED]);

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentPickSummary::class)
            ->set('warehouseId', (string) $warehouse->id)
            ->assertSee($reserved->reference_no)
            ->assertDontSee('SO-PICK-SHIPPED')
            ->assertDontSee('SO-PICK-CANCELLED');
    }

    public function test_virtual_bundle_component_qty_aggregates(): void
    {
        $tenant = Tenant::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $shop = Shop::factory()->for($tenant)->create();
        $method = $this->shippingMethod('pick_bundle_method');
        $componentStock = StockItem::factory()->for($tenant)->create(['code' => 'STK-COMP-001', 'name' => 'Bundle component stock']);
        app(InventoryService::class)->adjustStock($tenant->id, $warehouse->id, $componentStock->id, 50);
        $bundleSku = Sku::factory()->virtualBundle()->for($tenant)->for($shop)->create(['sku' => 'SKU-BUNDLE-PICK']);
        SkuBundleComponent::factory()
            ->for($tenant)
            ->for($bundleSku, 'bundleSku')
            ->for($componentStock, 'componentStockItem')
            ->create(['quantity' => 2]);
        $order = $this->readySalesOrder($tenant, $shop, $bundleSku, $method, 3, 'SO-PICK-BUNDLE');
        $this->createGroup($tenant, $warehouse, $order->ship_together_key, [$order]);

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentPickSummary::class)
            ->set('warehouseId', (string) $warehouse->id)
            ->assertSee('STK-COMP-001')
            ->assertSee('Bundle component')
            ->assertSee('6');
    }

    public function test_reserved_stock_is_counted_as_pickable(): void
    {
        [$tenant, $warehouse, $shop, $sku, $method] = $this->stationSku('STK-RESERVED-FIVE', 'SKU-RESERVED-FIVE');
        $order = $this->readySalesOrder($tenant, $shop, $sku, $method, 5, 'SO-PICK-RESERVED-STOCK');
        $this->createGroup($tenant, $warehouse, $order->ship_together_key, [$order]);
        $this->setBalance($tenant, $warehouse, $sku->stockItem, onHand: 5, reserved: 5, available: 0);

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentPickSummary::class)
            ->set('warehouseId', (string) $warehouse->id)
            ->assertSee('Pickable')
            ->assertDontSee('Available qty')
            ->assertSeeInOrder(['Required qty', '5', 'Shortage rows', '0'])
            ->assertSeeInOrder(['STK-RESERVED-FIVE', 'SKU-RESERVED-FIVE', '5', '5', '0']);
    }

    public function test_hold_stock_is_not_pickable(): void
    {
        [$tenant, $warehouse, $shop, $sku, $method] = $this->stationSku('STK-HOLD-FIVE', 'SKU-HOLD-FIVE');
        $order = $this->readySalesOrder($tenant, $shop, $sku, $method, 5, 'SO-PICK-HOLD');
        $this->createGroup($tenant, $warehouse, $order->ship_together_key, [$order]);
        $this->setBalance($tenant, $warehouse, $sku->stockItem, onHand: 5, reserved: 5, hold: 2, available: 0);

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentPickSummary::class)
            ->set('warehouseId', (string) $warehouse->id)
            ->assertSeeInOrder(['Shortage rows', '1'])
            ->assertSeeInOrder(['STK-HOLD-FIVE', 'SKU-HOLD-FIVE', '5', '3', '-2']);
    }

    public function test_damaged_stock_is_not_pickable(): void
    {
        [$tenant, $warehouse, $shop, $sku, $method] = $this->stationSku('STK-DAMAGED-FIVE', 'SKU-DAMAGED-FIVE');
        $order = $this->readySalesOrder($tenant, $shop, $sku, $method, 5, 'SO-PICK-DAMAGED');
        $this->createGroup($tenant, $warehouse, $order->ship_together_key, [$order]);
        $this->setBalance($tenant, $warehouse, $sku->stockItem, onHand: 5, reserved: 5, damaged: 1, available: 0);

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentPickSummary::class)
            ->set('warehouseId', (string) $warehouse->id)
            ->assertSeeInOrder(['Shortage rows', '1'])
            ->assertSeeInOrder(['STK-DAMAGED-FIVE', 'SKU-DAMAGED-FIVE', '5', '4', '-1']);
    }

    public function test_search_matches_stock_item_name_and_sku_code(): void
    {
        [$tenant, $warehouse, $shop, $sku, $method] = $this->stationSku('STK-SEARCH-001', 'SKU-SEARCH-001');
        $sku->stockItem->update(['name' => 'Needle Product']);
        $order = $this->readySalesOrder($tenant, $shop, $sku, $method, 1, 'SO-PICK-SEARCH');
        $this->createGroup($tenant, $warehouse, $order->ship_together_key, [$order]);

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentPickSummary::class)
            ->set('warehouseId', (string) $warehouse->id)
            ->set('q', 'Needle')
            ->assertSee('STK-SEARCH-001')
            ->set('q', 'SKU-SEARCH-001')
            ->assertSee('STK-SEARCH-001');
    }

    public function test_warehouse_filter_prevents_other_warehouse_groups_from_appearing(): void
    {
        [$tenant, $warehouse, $shop, $sku, $method] = $this->stationSku('STK-PICK-WH', 'SKU-PICK-WH');
        $otherWarehouse = Warehouse::factory()->create();
        app(InventoryService::class)->adjustStock($tenant->id, $otherWarehouse->id, $sku->stock_item_id, 50);
        $shownOrder = $this->readySalesOrder($tenant, $shop, $sku, $method, 1, 'SO-PICK-WH-SHOWN');
        $hiddenOrder = $this->readySalesOrder($tenant, $shop, $sku, $method, 1, 'SO-PICK-WH-HIDDEN', '2 Warehouse Street');
        $this->createGroup($tenant, $warehouse, $shownOrder->ship_together_key, [$shownOrder]);
        $shown = FulfillmentGroup::query()->latest('id')->firstOrFail();
        $this->createGroup($tenant, $otherWarehouse, $hiddenOrder->ship_together_key, [$hiddenOrder]);

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentPickSummary::class)
            ->set('warehouseId', (string) $warehouse->id)
            ->assertSee($shown->reference_no)
            ->assertDontSee('SO-PICK-WH-HIDDEN');
    }

    public function test_summary_cards_reflect_filtered_rows(): void
    {
        [$tenant, $warehouse, $shop, $sku, $method] = $this->stationSku('STK-PICK-SUM', 'SKU-PICK-SUM');
        $order = $this->readySalesOrder($tenant, $shop, $sku, $method, 3, 'SO-PICK-SUM');
        $this->createGroup($tenant, $warehouse, $order->ship_together_key, [$order]);
        $this->setBalance($tenant, $warehouse, $sku->stockItem, onHand: 1, reserved: 0, available: 1);

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentPickSummary::class)
            ->set('warehouseId', (string) $warehouse->id)
            ->assertSee('Pick rows')
            ->assertSee('Required qty')
            ->assertSee('Shortage rows')
            ->assertSee('Groups included')
            ->assertSee('3');
    }

    /**
     * @return array{0: Tenant, 1: Warehouse, 2: Shop, 3: Sku, 4: ShippingMethod}
     */
    private function stationSku(string $stockCode, string $skuCode): array
    {
        $tenant = Tenant::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $shop = Shop::factory()->for($tenant)->create();
        $method = $this->shippingMethod('pick_method');
        $stockItem = StockItem::factory()->for($tenant)->create(['code' => $stockCode, 'name' => $stockCode.' name']);
        $sku = Sku::factory()->for($tenant)->for($shop)->for($stockItem)->create([
            'sku_type' => 'single',
            'sku' => $skuCode,
            'name' => $skuCode.' name',
        ]);
        app(InventoryService::class)->adjustStock($tenant->id, $warehouse->id, $stockItem->id, 50);

        return [$tenant, $warehouse, $shop, $sku, $method];
    }

    private function readySalesOrder(
        Tenant $tenant,
        Shop $shop,
        Sku $sku,
        ShippingMethod $method,
        int $quantity,
        string $platformOrderId,
        string $addressLine1 = '1 Pick Street',
    ): SalesOrder {
        $order = SalesOrder::factory()->for($tenant)->for($shop)->create([
            'platform_order_id' => $platformOrderId,
            'order_status' => SalesOrder::ORDER_STATUS_PENDING,
            'fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_READY,
            'shipping_method_id' => $method->id,
            'recipient_name' => 'Pick Recipient',
            'recipient_country_code' => 'JP',
            'recipient_postal_code' => '1000001',
            'recipient_state' => 'Tokyo',
            'recipient_city' => 'Chiyoda',
            'recipient_address_line1' => $addressLine1,
            'recipient_address_line2' => 'Unit 1',
        ]);
        $order->recalculateShipTogetherKey();
        $order->save();

        SalesOrderLine::factory()->for($order)->for($sku)->create([
            'quantity' => $quantity,
            'line_status' => SalesOrderLine::STATUS_READY,
        ]);

        return $order->refresh();
    }

    private function createGroup(Tenant $tenant, Warehouse $warehouse, string $shipKey, array $orders): void
    {
        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentGroupCreate::class)
            ->set('tenantId', (string) $tenant->id)
            ->set('warehouseId', (string) $warehouse->id)
            ->set('shipKey', $shipKey)
            ->set('selectedOrderIds', collect($orders)->pluck('id')->map(fn ($id) => (string) $id)->all())
            ->call('save');
    }

    private function setBalance(
        Tenant $tenant,
        Warehouse $warehouse,
        StockItem $stockItem,
        int $onHand,
        int $reserved = 0,
        int $hold = 0,
        int $damaged = 0,
        int $available = 0,
    ): void {
        InventoryBalance::query()
            ->where('tenant_id', $tenant->id)
            ->where('warehouse_id', $warehouse->id)
            ->where('stock_item_id', $stockItem->id)
            ->update([
                'on_hand_qty' => $onHand,
                'reserved_qty' => $reserved,
                'hold_qty' => $hold,
                'damaged_qty' => $damaged,
                'available_qty' => $available,
            ]);
    }

    private function shippingMethod(string $code): ShippingMethod
    {
        $carrier = Carrier::query()->firstOrCreate(
            ['code' => 'pick_carrier'],
            ['name' => 'Pick Carrier', 'country_code' => 'JP', 'status' => 'active'],
        );

        return ShippingMethod::query()->create([
            'carrier_id' => $carrier->id,
            'code' => $code,
            'name' => str($code)->replace('_', ' ')->title()->toString(),
            'service_type' => 'parcel',
            'status' => 'active',
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
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['user_type' => 'tenant', 'is_active' => true]);
        TenantUser::factory()->create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'status' => 'active']);

        return [$tenant, $user];
    }
}
