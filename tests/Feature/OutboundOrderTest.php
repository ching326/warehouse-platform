<?php

namespace Tests\Feature;

use App\Livewire\OutboundOrderCreate;
use App\Livewire\OutboundOrderDetail;
use App\Livewire\OutboundOrderIndex;
use App\Livewire\OutboundOrderShip;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\OutboundOrder;
use App\Models\OutboundOrderLine;
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
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class OutboundOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_single_sku_order_reserves_stock(): void
    {
        [$tenant, $warehouse, $sku] = $this->skuWithStock(20);

        $this->createOrder($tenant, $warehouse, $sku, qty: 5, ref: 'OB-SINGLE')
            ->assertRedirect(route('outbound.index'));

        $order = OutboundOrder::where('ref', 'OB-SINGLE')->firstOrFail();
        $line = $order->lines()->firstOrFail();
        $balance = $this->balance($tenant, $warehouse, $sku->stockItem);
        $reserveMovement = InventoryMovement::where('movement_type', InventoryMovement::TYPE_RESERVE)->firstOrFail();

        $this->assertSame(OutboundOrder::STATUS_PENDING, $order->status);
        $this->assertSame($sku->id, $line->sku_id);
        $this->assertSame($sku->stock_item_id, $line->stock_item_id);
        $this->assertSame(5, $line->qty);
        $this->assertSame(5, $balance->reserved_qty);
        $this->assertSame(15, $balance->available_qty);
        $this->assertSame(-5, $reserveMovement->available_delta);
        $this->assertSame(5, $reserveMovement->reserved_delta);
    }

    public function test_create_uses_shipping_method_dropdown_value(): void
    {
        [$tenant, $warehouse, $sku] = $this->skuWithStock(20);
        $method = ShippingMethod::query()->where('code', 'yamato_nekopos')->firstOrFail();

        Livewire::actingAs($this->internalUser())
            ->test(OutboundOrderCreate::class)
            ->set('tenantId', (string) $tenant->id)
            ->set('warehouseId', (string) $warehouse->id)
            ->set('shippingMethod', $method->code)
            ->set('lines.0.sku_id', (string) $sku->id)
            ->set('lines.0.qty', '1')
            ->call('save')
            ->assertRedirect(route('outbound.index'));

        $this->assertSame($method->code, OutboundOrder::firstOrFail()->shipping_method);
    }

    public function test_create_autofills_japanese_address_from_postal_code(): void
    {
        Livewire::actingAs($this->internalUser())
            ->test(OutboundOrderCreate::class)
            ->set('recipientPostalCode', '1030025')
            ->assertSet('recipientPostalCode', '103-0025')
            ->assertSet('recipientCountryCode', 'JP')
            ->assertSet('recipientState', 'Tokyo')
            ->assertSet('recipientCity', 'Chuo-ku')
            ->assertSet('recipientAddressLine1', 'Nihonbashi Kayabacho');
    }

    public function test_create_autofills_japanese_address_from_postal_api(): void
    {
        Http::fake([
            'zipcloud.ibsnet.co.jp/*' => Http::response([
                'status' => 200,
                'results' => [[
                    'zipcode' => '1600022',
                    'address1' => 'Tokyo',
                    'address2' => 'Shinjuku-ku',
                    'address3' => 'Shinjuku',
                ]],
            ]),
        ]);

        Livewire::actingAs($this->internalUser())
            ->test(OutboundOrderCreate::class)
            ->set('recipientPostalCode', '1600022')
            ->assertSet('recipientPostalCode', '160-0022')
            ->assertSet('recipientCountryCode', 'JP')
            ->assertSet('recipientState', 'Tokyo')
            ->assertSet('recipientCity', 'Shinjuku-ku')
            ->assertSet('recipientAddressLine1', 'Shinjuku');
    }

    public function test_create_sku_search_filters_dropdown_options(): void
    {
        $tenant = Tenant::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $shop = Shop::factory()->for($tenant)->create();
        $matchingItem = StockItem::factory()->for($tenant)->create(['code' => 'MATCH-STOCK']);
        $hiddenItem = StockItem::factory()->for($tenant)->create(['code' => 'HIDDEN-STOCK']);

        Sku::factory()->for($tenant)->for($shop)->for($matchingItem)->create([
            'sku_type' => 'single',
            'sku' => 'MATCH-SKU',
            'name' => 'Matching cable',
        ]);
        Sku::factory()->for($tenant)->for($shop)->for($hiddenItem)->create([
            'sku_type' => 'single',
            'sku' => 'HIDDEN-SKU',
            'name' => 'Hidden charger',
        ]);

        Livewire::actingAs($this->internalUser())
            ->test(OutboundOrderCreate::class)
            ->set('tenantId', (string) $tenant->id)
            ->set('warehouseId', (string) $warehouse->id)
            ->set('skuSearch', 'MATCH')
            ->assertSee('MATCH-SKU')
            ->assertDontSee('HIDDEN-SKU');
    }

    public function test_create_virtual_bundle_order_reserves_components(): void
    {
        [$tenant, $warehouse, $bundleSku, $componentA, $componentB] = $this->virtualBundleWithStock();

        $this->createOrder($tenant, $warehouse, $bundleSku, qty: 3, ref: 'OB-BUNDLE')
            ->assertRedirect(route('outbound.index'));

        $order = OutboundOrder::where('ref', 'OB-BUNDLE')->firstOrFail();
        $parent = $order->parentLines()->firstOrFail();
        $children = $parent->childLines()->orderBy('stock_item_id')->get();

        $this->assertNull($parent->stock_item_id);
        $this->assertSame(3, $parent->qty);
        $this->assertCount(2, $children);
        $this->assertSame([$componentA->id, $componentB->id], $children->pluck('stock_item_id')->all());
        $this->assertSame([3, 6], $children->pluck('qty')->all());
        $this->assertSame(3, $this->balance($tenant, $warehouse, $componentA)->reserved_qty);
        $this->assertSame(6, $this->balance($tenant, $warehouse, $componentB)->reserved_qty);
    }

    public function test_create_fails_for_bundle_with_no_components(): void
    {
        $tenant = Tenant::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $bundleSku = Sku::factory()->virtualBundle()->for($tenant)->create(['shop_id' => null]);

        Livewire::actingAs($this->internalUser())
            ->test(OutboundOrderCreate::class)
            ->set('tenantId', (string) $tenant->id)
            ->set('warehouseId', (string) $warehouse->id)
            ->set('lines.0.sku_id', (string) $bundleSku->id)
            ->set('lines.0.qty', '1')
            ->call('save')
            ->assertHasErrors(['lines.0.sku_id']);

        $this->assertSame(0, OutboundOrder::count());
    }

    public function test_create_fails_when_insufficient_stock(): void
    {
        [$tenant, $warehouse, $sku] = $this->skuWithStock(3);

        $this->createOrder($tenant, $warehouse, $sku, qty: 10, ref: 'OB-FAIL')
            ->assertHasErrors(['lines.0.qty']);

        $this->assertSame(0, OutboundOrder::count());
        $this->assertSame(0, $this->balance($tenant, $warehouse, $sku->stockItem)->reserved_qty);
    }

    public function test_create_rejects_duplicate_skus(): void
    {
        [$tenant, $warehouse, $sku] = $this->skuWithStock(20);

        Livewire::actingAs($this->internalUser())
            ->test(OutboundOrderCreate::class)
            ->set('tenantId', (string) $tenant->id)
            ->set('warehouseId', (string) $warehouse->id)
            ->set('lines', [
                ['sku_id' => (string) $sku->id, 'qty' => '1', 'note' => ''],
                ['sku_id' => (string) $sku->id, 'qty' => '2', 'note' => ''],
            ])
            ->call('save')
            ->assertHasErrors(['lines']);
    }

    public function test_create_rejects_inactive_warehouse(): void
    {
        $tenant = Tenant::factory()->create();
        $warehouse = Warehouse::factory()->create(['status' => 'inactive']);
        $stockItem = StockItem::factory()->for($tenant)->create();
        $shop = Shop::factory()->for($tenant)->create();
        $sku = Sku::factory()->for($tenant)->for($shop)->for($stockItem)->create([
            'sku_type' => 'single',
            'sku' => 'SKU-INACTIVE-WAREHOUSE',
        ]);

        app(InventoryService::class)->adjustStock($tenant->id, $warehouse->id, $stockItem->id, 10);

        Livewire::actingAs($this->internalUser())
            ->test(OutboundOrderCreate::class)
            ->set('tenantId', (string) $tenant->id)
            ->set('warehouseId', (string) $warehouse->id)
            ->set('ref', 'OB-INACTIVE-WAREHOUSE')
            ->set('lines.0.sku_id', (string) $sku->id)
            ->set('lines.0.qty', '1')
            ->call('save')
            ->assertHasErrors(['warehouse_id']);

        $this->assertSame(0, OutboundOrder::count());
    }

    public function test_ship_deducts_reserved_stock(): void
    {
        [$tenant, $warehouse, $sku] = $this->skuWithStock(10);
        $this->createOrder($tenant, $warehouse, $sku, qty: 4, ref: 'OB-SHIP');
        $order = OutboundOrder::where('ref', 'OB-SHIP')->firstOrFail();

        Livewire::actingAs($this->internalUser())
            ->test(OutboundOrderShip::class, ['order' => $order])
            ->set('courier', 'Yamato')
            ->set('trackingNo', 'YM123')
            ->call('save')
            ->assertRedirect(route('outbound.index'));

        $order->refresh();
        $line = $order->leafLines()->firstOrFail();
        $shipMovement = InventoryMovement::findOrFail($line->inventory_movement_id);
        $balance = $this->balance($tenant, $warehouse, $sku->stockItem);

        $this->assertSame(OutboundOrder::STATUS_SHIPPED, $order->status);
        $this->assertNotNull($order->shipped_at);
        $this->assertSame('Yamato', $order->courier);
        $this->assertSame('YM123', $order->tracking_no);
        $this->assertSame(6, $balance->on_hand_qty);
        $this->assertSame(0, $balance->reserved_qty);
        $this->assertSame(6, $balance->available_qty);
        $this->assertSame(InventoryMovement::TYPE_SHIP, $shipMovement->movement_type);
        $this->assertSame(-4, $shipMovement->on_hand_delta);
        $this->assertSame(-4, $shipMovement->reserved_delta);
    }

    public function test_ship_virtual_bundle_deducts_all_components(): void
    {
        [$tenant, $warehouse, $bundleSku, $componentA, $componentB] = $this->virtualBundleWithStock();
        $this->createOrder($tenant, $warehouse, $bundleSku, qty: 2, ref: 'OB-SHIP-BUNDLE');
        $order = OutboundOrder::where('ref', 'OB-SHIP-BUNDLE')->firstOrFail();

        Livewire::actingAs($this->internalUser())
            ->test(OutboundOrderShip::class, ['order' => $order])
            ->call('save')
            ->assertRedirect(route('outbound.index'));

        $balanceA = $this->balance($tenant, $warehouse, $componentA);
        $balanceB = $this->balance($tenant, $warehouse, $componentB);

        $this->assertSame(8, $balanceA->on_hand_qty);
        $this->assertSame(0, $balanceA->reserved_qty);
        $this->assertSame(8, $balanceA->available_qty);
        $this->assertSame(16, $balanceB->on_hand_qty);
        $this->assertSame(0, $balanceB->reserved_qty);
        $this->assertSame(16, $balanceB->available_qty);
    }

    public function test_outbound_index_links_to_detail_and_removes_row_cancel(): void
    {
        [$tenant, $warehouse, $sku] = $this->skuWithStock(10);
        $this->createOrder($tenant, $warehouse, $sku, qty: 1, ref: 'OB-INDEX-LINK');
        $order = OutboundOrder::where('ref', 'OB-INDEX-LINK')->firstOrFail();

        Livewire::actingAs($this->internalUser())
            ->test(OutboundOrderIndex::class)
            ->assertSee(route('outbound.show', $order), false)
            ->assertSee('#'.$order->id)
            ->assertSee(__('outbound.btn_ship'))
            ->assertDontSee(__('outbound.btn_cancel_order'));
    }

    public function test_outbound_detail_route_renders_for_internal_user(): void
    {
        [$tenant, $warehouse, $sku] = $this->skuWithStock(10);
        $this->createOrder($tenant, $warehouse, $sku, qty: 1, ref: 'OB-DETAIL-ROUTE');
        $order = OutboundOrder::where('ref', 'OB-DETAIL-ROUTE')->firstOrFail();

        $this->actingAs($this->internalUser())
            ->get(route('outbound.show', $order))
            ->assertOk()
            ->assertSee('OB-DETAIL-ROUTE')
            ->assertSee(__('outbound.section_actions'))
            ->assertSee(__('outbound.section_recipient'))
            ->assertSee(__('outbound.section_lines'));
    }

    public function test_outbound_detail_is_tenant_scoped(): void
    {
        [$ownTenant, $user] = $this->tenantUser();
        [$otherTenant, $warehouse, $otherSku] = $this->skuWithStock(10);
        $ownStockItem = StockItem::factory()->for($ownTenant)->create();
        $ownShop = Shop::factory()->for($ownTenant)->create();
        $ownSku = Sku::factory()->for($ownTenant)->for($ownShop)->for($ownStockItem)->create(['sku' => 'OWN-DETAIL-SKU']);

        app(InventoryService::class)->adjustStock($ownTenant->id, $warehouse->id, $ownStockItem->id, 10);
        $this->createOrder($ownTenant, $warehouse, $ownSku, qty: 1, ref: 'OWN-DETAIL', user: $user);
        $this->createOrder($otherTenant, $warehouse, $otherSku, qty: 1, ref: 'OTHER-DETAIL');

        $ownOrder = OutboundOrder::where('ref', 'OWN-DETAIL')->firstOrFail();
        $otherOrder = OutboundOrder::where('ref', 'OTHER-DETAIL')->firstOrFail();

        $this->actingAs($user)->get(route('outbound.show', $ownOrder))->assertOk()->assertSee('OWN-DETAIL');
        $this->actingAs($user)->get(route('outbound.show', $otherOrder))->assertNotFound();
    }

    public function test_outbound_detail_cancel_button_only_shows_for_pending_orders(): void
    {
        [$tenant, $warehouse, $sku] = $this->skuWithStock(30);
        $this->createOrder($tenant, $warehouse, $sku, qty: 1, ref: 'OB-PENDING-CANCEL');
        $this->createOrder($tenant, $warehouse, $sku, qty: 1, ref: 'OB-SHIPPED-NO-CANCEL');
        $this->createOrder($tenant, $warehouse, $sku, qty: 1, ref: 'OB-CANCELLED-NO-CANCEL');

        $pending = OutboundOrder::where('ref', 'OB-PENDING-CANCEL')->firstOrFail();
        $shipped = OutboundOrder::where('ref', 'OB-SHIPPED-NO-CANCEL')->firstOrFail();
        $cancelled = OutboundOrder::where('ref', 'OB-CANCELLED-NO-CANCEL')->firstOrFail();
        $shipped->update(['status' => OutboundOrder::STATUS_SHIPPED, 'shipped_at' => now()]);
        $cancelled->update(['status' => OutboundOrder::STATUS_CANCELLED, 'cancelled_at' => now()]);

        Livewire::actingAs($this->internalUser())
            ->test(OutboundOrderDetail::class, ['order' => $pending])
            ->assertSee(__('outbound.btn_cancel_order'));
        Livewire::actingAs($this->internalUser())
            ->test(OutboundOrderDetail::class, ['order' => $shipped])
            ->assertDontSee(__('outbound.btn_cancel_order'));
        Livewire::actingAs($this->internalUser())
            ->test(OutboundOrderDetail::class, ['order' => $cancelled])
            ->assertDontSee(__('outbound.btn_cancel_order'));
    }

    public function test_cannot_cancel_another_tenants_outbound_order(): void
    {
        [, $user] = $this->tenantUser();
        [$otherTenant, $warehouse, $sku] = $this->skuWithStock(10);
        $this->createOrder($otherTenant, $warehouse, $sku, qty: 1, ref: 'OTHER-CANCEL');
        $order = OutboundOrder::where('ref', 'OTHER-CANCEL')->firstOrFail();

        $this->actingAs($user)
            ->get(route('outbound.show', $order))
            ->assertNotFound();

        $this->assertSame(OutboundOrder::STATUS_PENDING, $order->refresh()->status);
    }

    public function test_outbound_detail_shows_virtual_bundle_child_lines(): void
    {
        [$tenant, $warehouse, $bundleSku, $componentA, $componentB] = $this->virtualBundleWithStock();
        $this->createOrder($tenant, $warehouse, $bundleSku, qty: 2, ref: 'OB-BUNDLE-DETAIL');
        $order = OutboundOrder::where('ref', 'OB-BUNDLE-DETAIL')->firstOrFail();

        Livewire::actingAs($this->internalUser())
            ->test(OutboundOrderDetail::class, ['order' => $order])
            ->assertSee($bundleSku->sku)
            ->assertSee($componentA->code)
            ->assertSee($componentB->code)
            ->assertSee((string) 2)
            ->assertSee((string) 4);
    }

    public function test_cancel_releases_reserved_stock(): void
    {
        [$tenant, $warehouse, $sku] = $this->skuWithStock(10);
        $this->createOrder($tenant, $warehouse, $sku, qty: 4, ref: 'OB-CANCEL');
        $order = OutboundOrder::where('ref', 'OB-CANCEL')->firstOrFail();
        $user = $this->internalUser();

        Livewire::actingAs($user)
            ->test(OutboundOrderDetail::class, ['order' => $order])
            ->call('cancel');

        $balance = $this->balance($tenant, $warehouse, $sku->stockItem);
        $releaseMovement = InventoryMovement::where('movement_type', InventoryMovement::TYPE_RELEASE_RESERVE)->firstOrFail();

        $order->refresh();
        $this->assertSame(OutboundOrder::STATUS_CANCELLED, $order->status);
        $this->assertNotNull($order->cancelled_at);
        $this->assertSame($user->id, $order->cancelled_by_user_id);
        $this->assertSame(0, $balance->reserved_qty);
        $this->assertSame(10, $balance->available_qty);
        $this->assertSame(-4, $releaseMovement->reserved_delta);
        $this->assertSame(4, $releaseMovement->available_delta);
    }

    public function test_cancel_is_blocked_for_shipped_order(): void
    {
        [$tenant, $warehouse, $sku] = $this->skuWithStock(10);
        $this->createOrder($tenant, $warehouse, $sku, qty: 4, ref: 'OB-NO-CANCEL');
        $order = OutboundOrder::where('ref', 'OB-NO-CANCEL')->firstOrFail();

        Livewire::actingAs($this->internalUser())
            ->test(OutboundOrderShip::class, ['order' => $order])
            ->call('save');

        Livewire::actingAs($this->internalUser())
            ->test(OutboundOrderDetail::class, ['order' => $order])
            ->call('cancel');

        $this->assertSame(OutboundOrder::STATUS_SHIPPED, $order->refresh()->status);
    }

    public function test_ship_page_is_blocked_for_non_pending_order(): void
    {
        [$tenant, $warehouse, $sku] = $this->skuWithStock(10);
        $this->createOrder($tenant, $warehouse, $sku, qty: 4, ref: 'OB-BLOCKED');
        $order = OutboundOrder::where('ref', 'OB-BLOCKED')->firstOrFail();

        Livewire::actingAs($this->internalUser())
            ->test(OutboundOrderDetail::class, ['order' => $order])
            ->call('cancel');

        $this->actingAs($this->internalUser())
            ->get(route('outbound.ship', $order->refresh()))
            ->assertRedirect(route('outbound.index'));
    }

    public function test_tenant_user_only_sees_own_outbound_orders(): void
    {
        [$ownTenant, $user] = $this->tenantUser();
        [$otherTenant, $warehouse, $otherSku] = $this->skuWithStock(10);
        $ownStockItem = StockItem::factory()->for($ownTenant)->create();
        $ownShop = Shop::factory()->for($ownTenant)->create();
        $ownSku = Sku::factory()->for($ownTenant)->for($ownShop)->for($ownStockItem)->create(['sku' => 'OWN-OUTBOUND-SKU']);

        app(InventoryService::class)->adjustStock($ownTenant->id, $warehouse->id, $ownStockItem->id, 10);
        $this->createOrder($ownTenant, $warehouse, $ownSku, qty: 1, ref: 'OWN-OUTBOUND', user: $user);
        $this->createOrder($otherTenant, $warehouse, $otherSku, qty: 1, ref: 'HIDDEN-OUTBOUND');

        Livewire::actingAs($user)
            ->test(OutboundOrderIndex::class)
            ->assertSee('OWN-OUTBOUND')
            ->assertDontSee('HIDDEN-OUTBOUND');
    }

    public function test_outbound_routes_render(): void
    {
        [$tenant, $warehouse, $sku] = $this->skuWithStock(10);
        $this->createOrder($tenant, $warehouse, $sku, qty: 1, ref: 'OB-ROUTE');
        $order = OutboundOrder::where('ref', 'OB-ROUTE')->firstOrFail();

        $this->actingAs($this->internalUser())->get('/outbound')->assertOk()->assertSee('Outbound Orders');
        $this->actingAs($this->internalUser())->get('/outbound/create')->assertOk()->assertSee('Create Outbound Order');
        $this->actingAs($this->internalUser())->get(route('outbound.ship', $order))->assertOk()->assertSee('Ship Order');
        $this->actingAs($this->internalUser())->get(route('outbound.show', $order))->assertOk()->assertSee('OB-ROUTE');
    }

    /**
     * @return array{0: Tenant, 1: Warehouse, 2: Sku}
     */
    private function skuWithStock(int $onHand): array
    {
        $tenant = Tenant::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $stockItem = StockItem::factory()->for($tenant)->create();
        $shop = Shop::factory()->for($tenant)->create();
        $sku = Sku::factory()->for($tenant)->for($shop)->for($stockItem)->create([
            'sku_type' => 'single',
            'sku' => 'SKU-'.fake()->unique()->numberBetween(1000, 9999),
        ]);

        app(InventoryService::class)->adjustStock($tenant->id, $warehouse->id, $stockItem->id, $onHand);

        return [$tenant, $warehouse, $sku];
    }

    /**
     * @return array{0: Tenant, 1: Warehouse, 2: Sku, 3: StockItem, 4: StockItem}
     */
    private function virtualBundleWithStock(): array
    {
        $tenant = Tenant::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $shop = Shop::factory()->for($tenant)->create();
        $bundleSku = Sku::factory()->virtualBundle()->for($tenant)->for($shop)->create([
            'sku' => 'BUNDLE-'.fake()->unique()->numberBetween(1000, 9999),
        ]);
        $componentA = StockItem::factory()->for($tenant)->create(['code' => 'CMP-A-'.fake()->unique()->numberBetween(1000, 9999)]);
        $componentB = StockItem::factory()->for($tenant)->create(['code' => 'CMP-B-'.fake()->unique()->numberBetween(1000, 9999)]);

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

        app(InventoryService::class)->adjustStock($tenant->id, $warehouse->id, $componentA->id, 10);
        app(InventoryService::class)->adjustStock($tenant->id, $warehouse->id, $componentB->id, 20);

        return [$tenant, $warehouse, $bundleSku, $componentA, $componentB];
    }

    private function createOrder(
        Tenant $tenant,
        Warehouse $warehouse,
        Sku $sku,
        int $qty,
        string $ref,
        ?User $user = null,
    ): \Livewire\Features\SupportTesting\Testable {
        return Livewire::actingAs($user ?? $this->internalUser())
            ->test(OutboundOrderCreate::class)
            ->set('tenantId', (string) $tenant->id)
            ->set('warehouseId', (string) $warehouse->id)
            ->set('ref', $ref)
            ->set('lines.0.sku_id', (string) $sku->id)
            ->set('lines.0.qty', (string) $qty)
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
