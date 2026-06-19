<?php

namespace Tests\Feature;

use App\Livewire\FulfillmentGroupCreate;
use App\Livewire\FulfillmentGroupDetail;
use App\Livewire\FulfillmentGroupIndex;
use App\Livewire\OutboundOrderIndex;
use App\Livewire\OutboundOrderShip;
use App\Models\FulfillmentGroup;
use App\Models\InventoryBalance;
use App\Models\OutboundOrder;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
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

class FulfillmentGroupTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_group_from_ready_sales_orders_reserves_stock_and_creates_outbound_order(): void
    {
        [$tenant, $warehouse, $shop, $sku] = $this->skuWithStock(20);
        $orderA = $this->readySalesOrder($tenant, $shop, $sku, 2, 'SO-GROUP-A');
        $orderB = $this->readySalesOrder($tenant, $shop, $sku, 3, 'SO-GROUP-B');

        $this->createGroup($tenant, $warehouse, $orderA->ship_together_key, [$orderA, $orderB])
            ->assertRedirect();

        $group = FulfillmentGroup::with(['orders', 'outboundOrder.lines'])->firstOrFail();
        $outbound = $group->outboundOrder;
        $balance = $this->balance($tenant, $warehouse, $sku->stockItem);

        $this->assertSame(FulfillmentGroup::STATUS_RESERVED, $group->status);
        $this->assertSame([$orderA->id, $orderB->id], $group->orders->pluck('id')->sort()->values()->all());
        $this->assertNotNull($outbound);
        $this->assertSame($group->id, $outbound->fulfillment_group_id);
        $this->assertSame(OutboundOrder::STATUS_PENDING, $outbound->status);
        $this->assertSame(1, $outbound->lines->count());
        $this->assertSame(5, $outbound->lines->first()->qty);
        $this->assertSame(5, $balance->reserved_qty);
        $this->assertSame(15, $balance->available_qty);
        $this->assertSame(SalesOrder::FULFILLMENT_STATUS_IN_GROUP, $orderA->refresh()->fulfillment_status);
        $this->assertSame(SalesOrder::FULFILLMENT_STATUS_IN_GROUP, $orderB->refresh()->fulfillment_status);
    }

    public function test_create_group_generates_unique_reference_no(): void
    {
        [$tenant, $warehouse, $shop, $sku] = $this->skuWithStock(20);
        $orderA = $this->readySalesOrder($tenant, $shop, $sku, 1, 'SO-REF-A');
        $orderB = $this->readySalesOrder($tenant, $shop, $sku, 1, 'SO-REF-B', addressLine1: '2 Shared Street');

        $this->createGroup($tenant, $warehouse, $orderA->ship_together_key, [$orderA]);
        $this->createGroup($tenant, $warehouse, $orderB->ship_together_key, [$orderB]);

        $references = FulfillmentGroup::query()->orderBy('id')->pluck('reference_no');

        $this->assertCount(2, $references->unique());
        $this->assertMatchesRegularExpression('/^FG-\d{8}-\d{4,}$/', $references->first());
    }

    public function test_create_group_snapshots_recipient_from_sales_order(): void
    {
        [$tenant, $warehouse, $shop, $sku] = $this->skuWithStock(20);
        $order = $this->readySalesOrder($tenant, $shop, $sku, 1, 'SO-SNAPSHOT');

        $this->createGroup($tenant, $warehouse, $order->ship_together_key, [$order]);

        $group = FulfillmentGroup::firstOrFail();

        $this->assertSame($order->recipient_name, $group->recipient_name);
        $this->assertSame($order->recipient_city, $group->recipient_city);
        $this->assertSame($order->recipient_address_line1, $group->recipient_address_line1);
    }

    public function test_create_group_rejects_empty_order_selection(): void
    {
        [$tenant, $warehouse, $shop, $sku] = $this->skuWithStock(20);
        $order = $this->readySalesOrder($tenant, $shop, $sku, 1, 'SO-EMPTY');

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentGroupCreate::class)
            ->set('tenantId', (string) $tenant->id)
            ->set('warehouseId', (string) $warehouse->id)
            ->set('shipKey', (string) $order->ship_together_key)
            ->set('selectedOrderIds', [])
            ->call('save')
            ->assertHasErrors(['selected_order_ids']);

        $this->assertSame(0, FulfillmentGroup::count());
    }

    public function test_create_group_rejects_orders_with_different_ship_together_keys(): void
    {
        [$tenant, $warehouse, $shop, $sku] = $this->skuWithStock(20);
        $orderA = $this->readySalesOrder($tenant, $shop, $sku, 1, 'SO-KEY-A');
        $orderB = $this->readySalesOrder($tenant, $shop, $sku, 1, 'SO-KEY-B', addressLine1: 'Different address');

        $this->createGroup($tenant, $warehouse, $orderA->ship_together_key, [$orderA, $orderB])
            ->assertHasErrors(['selectedOrderIds']);

        $this->assertSame(0, FulfillmentGroup::count());
        $this->assertSame(0, OutboundOrder::count());
    }

    public function test_create_group_rejects_order_that_is_not_ready(): void
    {
        [$tenant, $warehouse, $shop, $sku] = $this->skuWithStock(20);
        $order = $this->readySalesOrder($tenant, $shop, $sku, 1, 'SO-NOT-READY');
        $order->update(['fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_UNFULFILLED]);

        $this->createGroup($tenant, $warehouse, $order->ship_together_key, [$order])
            ->assertHasErrors(['selectedOrderIds']);

        $this->assertSame(0, FulfillmentGroup::count());
    }

    public function test_create_group_rejects_order_from_wrong_tenant(): void
    {
        [$tenant, $warehouse] = [Tenant::factory()->create(), Warehouse::factory()->create()];
        [$otherTenant, , $otherShop, $otherSku] = $this->skuWithStock(20);
        $order = $this->readySalesOrder($otherTenant, $otherShop, $otherSku, 1, 'SO-WRONG-TENANT');

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentGroupCreate::class)
            ->set('tenantId', (string) $tenant->id)
            ->set('warehouseId', (string) $warehouse->id)
            ->set('shipKey', (string) $order->ship_together_key)
            ->set('selectedOrderIds', [(string) $order->id])
            ->call('save')
            ->assertHasErrors(['selected_order_ids.0']);

        $this->assertSame(0, FulfillmentGroup::count());
    }

    public function test_group_creation_preserves_distinct_outbound_lines_for_skus_sharing_stock_item(): void
    {
        [$tenant, $warehouse, $shop, $skuA] = $this->skuWithStock(30);
        $skuB = Sku::factory()->for($tenant)->for($shop)->for($skuA->stockItem)->create(['sku' => 'SHARED-B']);
        $orderA = $this->readySalesOrder($tenant, $shop, $skuA, 2, 'SO-SHARED-A');
        $orderB = $this->readySalesOrder($tenant, $shop, $skuB, 4, 'SO-SHARED-B');

        $this->createGroup($tenant, $warehouse, $orderA->ship_together_key, [$orderA, $orderB]);

        $outbound = FulfillmentGroup::firstOrFail()->outboundOrder()->with('lines')->firstOrFail();

        $this->assertSame(2, $outbound->lines->count());
        $this->assertSame([$skuA->id, $skuB->id], $outbound->lines->pluck('sku_id')->sort()->values()->all());
        $this->assertSame([2, 4], $outbound->lines->sortBy('sku_id')->pluck('qty')->values()->all());
        $this->assertSame(6, $this->balance($tenant, $warehouse, $skuA->stockItem)->reserved_qty);
    }

    public function test_group_creation_expands_virtual_bundle_components(): void
    {
        [$tenant, $warehouse, $shop] = [Tenant::factory()->create(), Warehouse::factory()->create(), null];
        $shop = Shop::factory()->for($tenant)->create();
        $componentA = StockItem::factory()->for($tenant)->create(['code' => $tenant->code.'-BUNDLE-A']);
        $componentB = StockItem::factory()->for($tenant)->create(['code' => $tenant->code.'-BUNDLE-B']);
        $bundleSku = Sku::factory()->for($tenant)->for($shop)->create([
            'sku_type' => 'virtual_bundle',
            'stock_item_id' => null,
            'sku' => 'BUNDLE-SKU',
        ]);

        SkuBundleComponent::factory()->create([
            'tenant_id' => $tenant->id,
            'bundle_sku_id' => $bundleSku->id,
            'component_stock_item_id' => $componentA->id,
            'quantity' => 1,
        ]);
        SkuBundleComponent::factory()->create([
            'tenant_id' => $tenant->id,
            'bundle_sku_id' => $bundleSku->id,
            'component_stock_item_id' => $componentB->id,
            'quantity' => 2,
        ]);

        app(InventoryService::class)->adjustStock($tenant->id, $warehouse->id, $componentA->id, 20);
        app(InventoryService::class)->adjustStock($tenant->id, $warehouse->id, $componentB->id, 20);

        $order = $this->readySalesOrder($tenant, $shop, $bundleSku, 3, 'SO-BUNDLE-GROUP');

        $this->createGroup($tenant, $warehouse, $order->ship_together_key, [$order])
            ->assertRedirect();

        $outbound = FulfillmentGroup::firstOrFail()->outboundOrder()->with('lines')->firstOrFail();
        $parentLine = $outbound->lines->firstWhere('parent_line_id', null);
        $childLines = $outbound->lines->whereNotNull('parent_line_id')->sortBy('stock_item_id')->values();

        $this->assertNotNull($parentLine);
        $this->assertNull($parentLine->stock_item_id);
        $this->assertSame($bundleSku->id, $parentLine->sku_id);
        $this->assertSame(3, $parentLine->qty);
        $this->assertSame(2, $childLines->count());
        $this->assertSame([$componentA->id, $componentB->id], $childLines->pluck('stock_item_id')->all());
        $this->assertSame([3, 6], $childLines->pluck('qty')->all());
        $this->assertSame(3, $this->balance($tenant, $warehouse, $componentA)->reserved_qty);
        $this->assertSame(6, $this->balance($tenant, $warehouse, $componentB)->reserved_qty);
        $this->assertSame(SalesOrder::FULFILLMENT_STATUS_IN_GROUP, $order->refresh()->fulfillment_status);
    }

    public function test_tenant_user_can_only_create_group_for_own_tenant(): void
    {
        [$ownTenant, $user] = $this->tenantUser();
        [$otherTenant, $warehouse, $shop, $sku] = $this->skuWithStock(20);
        $order = $this->readySalesOrder($otherTenant, $shop, $sku, 1, 'SO-HIDDEN');

        Livewire::actingAs($user)
            ->test(FulfillmentGroupCreate::class)
            ->set('tenantId', (string) $otherTenant->id)
            ->set('warehouseId', (string) $warehouse->id)
            ->set('shipKey', (string) $order->ship_together_key)
            ->set('selectedOrderIds', [(string) $order->id])
            ->call('save')
            ->assertHasErrors(['tenant_id']);

        $this->assertSame(0, FulfillmentGroup::count());
        $this->assertTrue($ownTenant->isNot($otherTenant));
    }

    public function test_tenant_user_cannot_render_other_tenant_ready_orders_on_create_page(): void
    {
        [, $user] = $this->tenantUser();
        [$otherTenant, $warehouse, $shop, $sku] = $this->skuWithStock(20);
        $order = $this->readySalesOrder($otherTenant, $shop, $sku, 1, 'SO-HIDDEN-OPTION');

        Livewire::actingAs($user)
            ->test(FulfillmentGroupCreate::class)
            ->set('tenantId', (string) $otherTenant->id)
            ->set('warehouseId', (string) $warehouse->id)
            ->set('shipKey', (string) $order->ship_together_key)
            ->assertDontSee('SO-HIDDEN-OPTION')
            ->assertDontSee('Shared Recipient');
    }

    public function test_tenant_user_index_only_sees_own_fulfillment_groups(): void
    {
        [$ownTenant, $user] = $this->tenantUser();
        $ownWarehouse = Warehouse::factory()->create();
        $ownGroup = FulfillmentGroup::factory()->for($ownTenant)->for($ownWarehouse)->create(['reference_no' => 'FG-OWN']);

        [$otherTenant, $otherWarehouse] = [Tenant::factory()->create(), Warehouse::factory()->create()];
        FulfillmentGroup::factory()->for($otherTenant)->for($otherWarehouse)->create(['reference_no' => 'FG-HIDDEN']);

        Livewire::actingAs($user)
            ->test(FulfillmentGroupIndex::class)
            ->assertSee($ownGroup->reference_no)
            ->assertDontSee('FG-HIDDEN');
    }

    public function test_shipping_linked_outbound_order_back_writes_group_and_sales_orders(): void
    {
        [$tenant, $warehouse, $shop, $sku] = $this->skuWithStock(20);
        $order = $this->readySalesOrder($tenant, $shop, $sku, 4, 'SO-SHIP-GROUP');
        $this->createGroup($tenant, $warehouse, $order->ship_together_key, [$order]);
        $group = FulfillmentGroup::firstOrFail();
        $outbound = $group->outboundOrder()->firstOrFail();

        Livewire::actingAs($this->internalUser())
            ->test(OutboundOrderShip::class, ['order' => $outbound])
            ->set('courier', 'Yamato')
            ->set('trackingNo', 'TRACK-1')
            ->call('save')
            ->assertRedirect(route('outbound.index'));

        $this->assertSame(FulfillmentGroup::STATUS_SHIPPED, $group->refresh()->status);
        $this->assertSame('Yamato', $group->courier);
        $this->assertSame('TRACK-1', $group->tracking_no);
        $this->assertSame(SalesOrder::FULFILLMENT_STATUS_SHIPPED, $order->refresh()->fulfillment_status);
        $this->assertSame(SalesOrder::ORDER_STATUS_COMPLETED, $order->order_status);
    }

    public function test_cancelling_linked_outbound_order_back_writes_group_and_sales_orders(): void
    {
        [$tenant, $warehouse, $shop, $sku] = $this->skuWithStock(20);
        $order = $this->readySalesOrder($tenant, $shop, $sku, 4, 'SO-CANCEL-GROUP');
        $this->createGroup($tenant, $warehouse, $order->ship_together_key, [$order]);
        $group = FulfillmentGroup::firstOrFail();
        $outbound = $group->outboundOrder()->firstOrFail();

        Livewire::actingAs($this->internalUser())
            ->test(OutboundOrderIndex::class)
            ->call('cancel', $outbound->id);

        $this->assertSame(FulfillmentGroup::STATUS_CANCELLED, $group->refresh()->status);
        $this->assertSame(SalesOrder::FULFILLMENT_STATUS_READY, $order->refresh()->fulfillment_status);
        $this->assertSame(0, $this->balance($tenant, $warehouse, $sku->stockItem)->reserved_qty);
    }

    public function test_unlinked_outbound_ship_does_not_affect_groups(): void
    {
        [$tenant, $warehouse, $shop, $sku] = $this->skuWithStock(20);
        $order = $this->readySalesOrder($tenant, $shop, $sku, 1, 'SO-UNLINKED');
        $this->createGroup($tenant, $warehouse, $order->ship_together_key, [$order]);
        $group = FulfillmentGroup::firstOrFail();

        $unlinked = OutboundOrder::factory()->for($tenant)->for($warehouse)->create([
            'fulfillment_group_id' => null,
            'status' => OutboundOrder::STATUS_PENDING,
        ]);
        $unlinked->status = OutboundOrder::STATUS_SHIPPED;
        $unlinked->shipped_at = now();
        $unlinked->save();

        $this->assertSame(FulfillmentGroup::STATUS_RESERVED, $group->refresh()->status);
        $this->assertSame(SalesOrder::FULFILLMENT_STATUS_IN_GROUP, $order->refresh()->fulfillment_status);
    }

    public function test_detail_recipient_edit_updates_linked_outbound_order(): void
    {
        [$tenant, $warehouse, $shop, $sku] = $this->skuWithStock(20);
        $order = $this->readySalesOrder($tenant, $shop, $sku, 1, 'SO-RECIPIENT');
        $this->createGroup($tenant, $warehouse, $order->ship_together_key, [$order]);
        $group = FulfillmentGroup::firstOrFail();

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentGroupDetail::class, ['group' => $group])
            ->call('editRecipient')
            ->set('recipientName', 'New Recipient')
            ->set('recipientCountryCode', 'jp')
            ->set('recipientCity', 'Tokyo')
            ->call('saveRecipient')
            ->assertHasNoErrors();

        $this->assertSame('New Recipient', $group->refresh()->recipient_name);
        $this->assertSame('New Recipient', $group->outboundOrder->refresh()->recipient_name);
        $this->assertSame('JP', $group->recipient_country_code);
    }

    public function test_detail_shipping_edit_updates_group_only(): void
    {
        [$tenant, $warehouse, $shop, $sku] = $this->skuWithStock(20);
        $order = $this->readySalesOrder($tenant, $shop, $sku, 1, 'SO-SHIPPING');
        $this->createGroup($tenant, $warehouse, $order->ship_together_key, [$order]);
        $group = FulfillmentGroup::firstOrFail();

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentGroupDetail::class, ['group' => $group])
            ->call('editShipping')
            ->set('courier', 'Sagawa')
            ->set('trackingNo', 'SG-1')
            ->set('note', 'Leave at front desk')
            ->call('saveShipping')
            ->assertHasNoErrors();

        $this->assertSame('Sagawa', $group->refresh()->courier);
        $this->assertSame('SG-1', $group->tracking_no);
        $this->assertNull($group->outboundOrder->refresh()->courier);
    }

    public function test_fulfillment_group_routes_render(): void
    {
        [$tenant, $warehouse, $shop, $sku] = $this->skuWithStock(20);
        $order = $this->readySalesOrder($tenant, $shop, $sku, 1, 'SO-ROUTE');
        $this->createGroup($tenant, $warehouse, $order->ship_together_key, [$order]);
        $group = FulfillmentGroup::firstOrFail();

        $this->actingAs($this->internalUser())->get('/fulfillment-groups')->assertOk()->assertSee('Fulfillment Groups');
        $this->actingAs($this->internalUser())->get('/fulfillment-groups/create')->assertOk()->assertSee('Create Fulfillment Group');
        $this->actingAs($this->internalUser())->get(route('fulfillment-groups.show', $group))->assertOk()->assertSee($group->reference_no);
    }

    public function test_tenant_user_without_active_tenant_cannot_access_pages(): void
    {
        $user = User::factory()->create([
            'user_type' => 'tenant',
            'is_active' => true,
        ]);

        $this->actingAs($user)->get('/fulfillment-groups')->assertForbidden();
        $this->actingAs($user)->get('/fulfillment-groups/create')->assertForbidden();
    }

    /**
     * @return array{0: Tenant, 1: Warehouse, 2: Shop, 3: Sku}
     */
    private function skuWithStock(int $onHand): array
    {
        $tenant = Tenant::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $shop = Shop::factory()->for($tenant)->create();
        $stockItem = StockItem::factory()->for($tenant)->create(['code' => $tenant->code.'-000001']);
        $sku = Sku::factory()->for($tenant)->for($shop)->for($stockItem)->create([
            'sku_type' => 'single',
            'sku' => 'SKU-'.fake()->unique()->numberBetween(1000, 9999),
        ]);

        app(InventoryService::class)->adjustStock($tenant->id, $warehouse->id, $stockItem->id, $onHand);

        return [$tenant, $warehouse, $shop, $sku];
    }

    private function readySalesOrder(
        Tenant $tenant,
        Shop $shop,
        Sku $sku,
        int $quantity,
        string $platformOrderId,
        string $addressLine1 = '1 Shared Street',
    ): SalesOrder {
        $order = SalesOrder::factory()->for($tenant)->for($shop)->create([
            'platform_order_id' => $platformOrderId,
            'order_status' => SalesOrder::ORDER_STATUS_PENDING,
            'fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_READY,
            'recipient_name' => 'Shared Recipient',
            'recipient_country_code' => 'JP',
            'recipient_postal_code' => '1000001',
            'recipient_state' => 'Tokyo',
            'recipient_city' => 'Chiyoda',
            'recipient_address_line1' => $addressLine1,
            'recipient_address_line2' => 'Unit 1',
        ]);

        SalesOrderLine::factory()->for($order)->for($sku)->create([
            'quantity' => $quantity,
            'line_status' => SalesOrderLine::STATUS_READY,
        ]);

        return $order->refresh();
    }

    private function createGroup(
        Tenant $tenant,
        Warehouse $warehouse,
        string $shipKey,
        array $orders,
        ?User $user = null,
    ): \Livewire\Features\SupportTesting\Testable {
        return Livewire::actingAs($user ?? $this->internalUser())
            ->test(FulfillmentGroupCreate::class)
            ->set('tenantId', (string) $tenant->id)
            ->set('warehouseId', (string) $warehouse->id)
            ->set('shipKey', $shipKey)
            ->set('selectedOrderIds', collect($orders)->pluck('id')->map(fn ($id) => (string) $id)->all())
            ->call('save');
    }

    private function balance(Tenant $tenant, Warehouse $warehouse, StockItem $stockItem): InventoryBalance
    {
        return InventoryBalance::where('tenant_id', $tenant->id)
            ->where('warehouse_id', $warehouse->id)
            ->where('stock_item_id', $stockItem->id)
            ->firstOrFail();
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
