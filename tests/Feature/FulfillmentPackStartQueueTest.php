<?php

namespace Tests\Feature;

use App\Livewire\FulfillmentPackStart;
use App\Models\Carrier;
use App\Models\OutboundOrder;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\ShippingMethod;
use App\Models\Shop;
use App\Models\Sku;
use App\Models\StockItem;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Fulfillment\OutboundConsolidationService;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FulfillmentPackStartQueueTest extends TestCase
{
    use RefreshDatabase;

    public function test_queue_hidden_until_warehouse_and_shipping_method_are_selected(): void
    {
        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentPackStart::class)
            ->assertDontSee(__('fulfillment_pack.queue_waiting_groups'))
            ->assertDontSee(__('fulfillment_pack.queue_search_label'));
    }

    public function test_queue_shows_reserved_groups_for_selected_station(): void
    {
        [$tenant, $warehouse, $shop, $sku, $method] = $this->stationSku();
        $order = $this->readySalesOrder($tenant, $shop, $sku, $method, 2, 'SO-QUEUE-SHOW');
        $this->createGroup($tenant, $warehouse, $order->ship_together_key, [$order]);
        $group = OutboundOrder::firstOrFail();

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentPackStart::class)
            ->set('warehouseId', (string) $warehouse->id)
            ->set('shippingMethodId', (string) $method->id)
            ->assertSee($group->ref)
            ->assertSee('SO-QUEUE-SHOW')
            ->assertSee('0 / 2');
    }

    public function test_queue_does_not_show_shipped_or_cancelled_groups(): void
    {
        [$tenant, $warehouse, $shop, $sku, $method] = $this->stationSku();
        $reservedOrder = $this->readySalesOrder($tenant, $shop, $sku, $method, 1, 'SO-QUEUE-RESERVED');
        $shippedOrder = $this->readySalesOrder($tenant, $shop, $sku, $method, 1, 'SO-QUEUE-SHIPPED', '2 Shipped Street');
        $cancelledOrder = $this->readySalesOrder($tenant, $shop, $sku, $method, 1, 'SO-QUEUE-CANCELLED', '3 Cancelled Street');
        $this->createGroup($tenant, $warehouse, $reservedOrder->ship_together_key, [$reservedOrder]);
        $reserved = OutboundOrder::query()->latest('id')->firstOrFail();
        $this->createGroup($tenant, $warehouse, $shippedOrder->ship_together_key, [$shippedOrder]);
        $shipped = OutboundOrder::query()->latest('id')->firstOrFail();
        $shipped->update(['status' => OutboundOrder::STATUS_SHIPPED]);
        $this->createGroup($tenant, $warehouse, $cancelledOrder->ship_together_key, [$cancelledOrder]);
        $cancelled = OutboundOrder::query()->latest('id')->firstOrFail();
        $cancelled->update(['status' => OutboundOrder::STATUS_CANCELLED]);

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentPackStart::class)
            ->set('warehouseId', (string) $warehouse->id)
            ->set('shippingMethodId', (string) $method->id)
            ->assertSee($reserved->ref)
            ->assertDontSee($shipped->ref)
            ->assertDontSee($cancelled->ref);
    }

    public function test_queue_does_not_show_other_warehouse_or_shipping_method(): void
    {
        [$tenant, $warehouse, $shop, $sku, $method] = $this->stationSku();
        $otherWarehouse = Warehouse::factory()->create();
        app(InventoryService::class)->adjustStock($tenant->id, $otherWarehouse->id, $sku->stock_item_id, 10);
        $otherMethod = $this->shippingMethod('queue_other_method');
        $shownOrder = $this->readySalesOrder($tenant, $shop, $sku, $method, 1, 'SO-QUEUE-STATION');
        $otherWarehouseOrder = $this->readySalesOrder($tenant, $shop, $sku, $method, 1, 'SO-QUEUE-WAREHOUSE', '2 Other Warehouse');
        $otherMethodOrder = $this->readySalesOrder($tenant, $shop, $sku, $otherMethod, 1, 'SO-QUEUE-METHOD', '3 Other Method');
        $this->createGroup($tenant, $warehouse, $shownOrder->ship_together_key, [$shownOrder]);
        $shown = OutboundOrder::query()->latest('id')->firstOrFail();
        $this->createGroup($tenant, $otherWarehouse, $otherWarehouseOrder->ship_together_key, [$otherWarehouseOrder]);
        $this->createGroup($tenant, $warehouse, $otherMethodOrder->ship_together_key, [$otherMethodOrder]);

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentPackStart::class)
            ->set('warehouseId', (string) $warehouse->id)
            ->set('shippingMethodId', (string) $method->id)
            ->assertSee($shown->ref)
            ->assertSee('SO-QUEUE-STATION')
            ->assertDontSee('SO-QUEUE-WAREHOUSE')
            ->assertDontSee('SO-QUEUE-METHOD');
    }

    public function test_queue_search_matches_fulfillment_reference_order_id_and_tracking(): void
    {
        [$tenant, $warehouse, $shop, $sku, $method] = $this->stationSku();
        $firstOrder = $this->readySalesOrder($tenant, $shop, $sku, $method, 1, 'SO-QUEUE-SEARCH-1');
        $secondOrder = $this->readySalesOrder($tenant, $shop, $sku, $method, 1, 'SO-QUEUE-SEARCH-2', '2 Search Street');
        $thirdOrder = $this->readySalesOrder($tenant, $shop, $sku, $method, 1, 'SO-QUEUE-SEARCH-3', '3 Search Street');
        $this->createGroup($tenant, $warehouse, $firstOrder->ship_together_key, [$firstOrder]);
        $first = OutboundOrder::query()->latest('id')->firstOrFail();
        $this->createGroup($tenant, $warehouse, $secondOrder->ship_together_key, [$secondOrder]);
        $second = OutboundOrder::query()->latest('id')->firstOrFail();
        $this->createGroup($tenant, $warehouse, $thirdOrder->ship_together_key, [$thirdOrder]);
        $third = OutboundOrder::query()->latest('id')->firstOrFail();
        $third->update(['tracking_no' => 'TRACK-QUEUE-SEARCH']);
        $third->update(['tracking_no' => 'TRACK-QUEUE-SEARCH']);

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentPackStart::class)
            ->set('warehouseId', (string) $warehouse->id)
            ->set('shippingMethodId', (string) $method->id)
            ->set('queueSearch', $first->ref)
            ->assertSee($first->ref)
            ->assertDontSee($second->ref)
            ->set('queueSearch', 'SO-QUEUE-SEARCH-2')
            ->assertSee($second->ref)
            ->assertDontSee($first->ref)
            ->set('queueSearch', 'TRACK-QUEUE-SEARCH')
            ->assertSee($third->ref)
            ->assertDontSee($first->ref);
    }

    public function test_queue_pack_link_points_to_pack_screen(): void
    {
        [$tenant, $warehouse, $shop, $sku, $method] = $this->stationSku();
        $order = $this->readySalesOrder($tenant, $shop, $sku, $method, 1, 'SO-QUEUE-LINK');
        $this->createGroup($tenant, $warehouse, $order->ship_together_key, [$order]);
        $group = OutboundOrder::firstOrFail();

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentPackStart::class)
            ->set('warehouseId', (string) $warehouse->id)
            ->set('shippingMethodId', (string) $method->id)
            ->assertSee(__('fulfillment_pack.queue_pack'))
            ->assertSee(route('outbound.pack', $group), false);
    }

    public function test_tenant_user_cannot_access_pack_start_page(): void
    {
        [, $tenantUser] = $this->tenantUser();

        $this->actingAs($tenantUser)
            ->get(route('fulfillment.pack.start'))
            ->assertForbidden();
    }

    public function test_station_summary_shows_waiting_group_count(): void
    {
        [$tenant, $warehouse, $shop, $sku, $method] = $this->stationSku();
        $first = $this->readySalesOrder($tenant, $shop, $sku, $method, 1, 'SO-QUEUE-SUMMARY-1');
        $second = $this->readySalesOrder($tenant, $shop, $sku, $method, 1, 'SO-QUEUE-SUMMARY-2', '2 Summary Street');
        $this->createGroup($tenant, $warehouse, $first->ship_together_key, [$first]);
        $this->createGroup($tenant, $warehouse, $second->ship_together_key, [$second]);

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentPackStart::class)
            ->set('warehouseId', (string) $warehouse->id)
            ->set('shippingMethodId', (string) $method->id)
            ->assertSee(__('fulfillment_pack.queue_waiting_groups'))
            ->assertSee('2');
    }

    /**
     * @return array{0: Tenant, 1: Warehouse, 2: Shop, 3: Sku, 4: ShippingMethod}
     */
    private function stationSku(): array
    {
        $tenant = Tenant::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $shop = Shop::factory()->for($tenant)->create();
        $method = $this->shippingMethod('queue_method');
        $stockItem = StockItem::factory()->for($tenant)->create(['code' => $tenant->code.'-000001']);
        $sku = Sku::factory()->for($tenant)->for($shop)->for($stockItem)->create(['sku_type' => 'single']);

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
        string $addressLine1 = '1 Queue Street',
    ): SalesOrder {
        $order = SalesOrder::factory()->for($tenant)->for($shop)->create([
            'platform_order_id' => $platformOrderId,
            'order_status' => SalesOrder::ORDER_STATUS_PENDING,
            'fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_READY,
            'shipping_method_id' => $method->id,
            'recipient_name' => 'Queue Recipient',
            'recipient_phone' => '09012345678',
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
        $this->actingAs($this->internalUser());

        app(OutboundConsolidationService::class)->createGroup(
            tenantId: (int) $tenant->id,
            warehouseId: (int) $warehouse->id,
            salesOrderIds: collect($orders)->pluck('id')->map(fn ($id) => (int) $id)->all(),
        );
    }

    private function shippingMethod(string $code): ShippingMethod
    {
        $carrier = Carrier::query()->firstOrCreate(
            ['code' => 'queue_carrier'],
            ['name' => 'Queue Carrier', 'country_code' => 'JP', 'status' => 'active'],
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
}
