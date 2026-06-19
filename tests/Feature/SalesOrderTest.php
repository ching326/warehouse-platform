<?php

namespace Tests\Feature;

use App\Livewire\SalesOrderCreate;
use App\Livewire\SalesOrderDetail;
use App\Livewire\SalesOrderIndex;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\Shop;
use App\Models\Sku;
use App\Models\StockItem;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SalesOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_sales_order_succeeds(): void
    {
        [$tenant, $shop, $sku] = $this->salesSku();

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderCreate::class)
            ->set('shopId', (string) $shop->id)
            ->set('recipientName', 'Taro')
            ->set('recipientAddressLine1', '1-1 Namba')
            ->set('lines.0.sku_id', (string) $sku->id)
            ->set('lines.0.quantity', '2')
            ->call('save')
            ->assertRedirect();

        $order = SalesOrder::firstOrFail();
        $line = $order->lines()->firstOrFail();

        $this->assertSame($tenant->id, $order->tenant_id);
        $this->assertSame(SalesOrder::ORDER_STATUS_PENDING, $order->order_status);
        $this->assertSame(SalesOrder::FULFILLMENT_STATUS_READY, $order->fulfillment_status);
        $this->assertSame(SalesOrder::SOURCE_MANUAL, $order->source);
        $this->assertSame($sku->id, $line->sku_id);
        $this->assertSame(2, $line->quantity);
        $this->assertSame(SalesOrderLine::STATUS_READY, $line->line_status);
    }

    public function test_create_sales_order_computes_ship_together_key(): void
    {
        [, $shop, $sku] = $this->salesSku();

        $this->createOrder($shop, $sku, platformOrderId: 'SO-KEY-1');
        $this->createOrder($shop, $sku, platformOrderId: 'SO-KEY-2');

        $orders = SalesOrder::orderBy('id')->get();

        $this->assertNotNull($orders[0]->ship_together_key);
        $this->assertSame($orders[0]->ship_together_key, $orders[1]->ship_together_key);
    }

    public function test_create_sales_order_key_null_when_no_address(): void
    {
        [, $shop, $sku] = $this->salesSku();

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderCreate::class)
            ->set('shopId', (string) $shop->id)
            ->set('lines.0.sku_id', (string) $sku->id)
            ->set('lines.0.quantity', '1')
            ->call('save')
            ->assertRedirect();

        $this->assertNull(SalesOrder::firstOrFail()->ship_together_key);
    }

    public function test_create_sales_order_rejects_duplicate_platform_order_id(): void
    {
        [$tenant, $shop, $sku] = $this->salesSku();
        SalesOrder::factory()->create([
            'tenant_id' => $tenant->id,
            'shop_id' => $shop->id,
            'platform_order_id' => 'AMZ-123',
        ]);

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderCreate::class)
            ->set('shopId', (string) $shop->id)
            ->set('platformOrderId', 'AMZ-123')
            ->set('lines.0.sku_id', (string) $sku->id)
            ->set('lines.0.quantity', '1')
            ->call('save')
            ->assertHasErrors(['platform_order_id']);
    }

    public function test_create_sales_order_allows_same_platform_order_id_for_different_shop(): void
    {
        [$tenant, $shopA, $skuA] = $this->salesSku();
        $shopB = Shop::factory()->for($tenant)->create(['status' => 'active']);
        $stockItem = StockItem::factory()->for($tenant)->create();
        $skuB = Sku::factory()->for($tenant)->for($shopB)->for($stockItem)->create(['sku_type' => 'single']);

        $this->createOrder($shopA, $skuA, platformOrderId: 'AMZ-123');

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderCreate::class)
            ->set('shopId', (string) $shopB->id)
            ->set('platformOrderId', 'AMZ-123')
            ->set('lines.0.sku_id', (string) $skuB->id)
            ->set('lines.0.quantity', '1')
            ->call('save')
            ->assertRedirect();

        $this->assertSame(2, SalesOrder::where('platform_order_id', 'AMZ-123')->count());
    }

    public function test_create_sales_order_requires_sku_id(): void
    {
        [, $shop] = $this->salesSku();

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderCreate::class)
            ->set('shopId', (string) $shop->id)
            ->set('lines.0.quantity', '1')
            ->call('save')
            ->assertHasErrors(['lines.0.sku_id']);
    }

    public function test_create_sales_order_can_select_sku_beyond_first_fifty(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        $shop = Shop::factory()->for($tenant)->create(['status' => 'active']);
        $targetSku = null;

        for ($i = 1; $i <= 60; $i++) {
            $stockItem = StockItem::factory()->for($tenant)->create();
            $sku = Sku::factory()->for($tenant)->for($shop)->for($stockItem)->create([
                'sku_type' => 'single',
                'sku' => sprintf('SO-BULK-%03d', $i),
            ]);

            if ($i === 60) {
                $targetSku = $sku;
            }
        }

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderCreate::class)
            ->set('shopId', (string) $shop->id)
            ->set('lines.0.sku_id', (string) $targetSku->id)
            ->set('lines.0.quantity', '1')
            ->call('save')
            ->assertRedirect();

        $this->assertDatabaseHas('sales_order_lines', [
            'sku_id' => $targetSku->id,
            'quantity' => 1,
        ]);
    }

    public function test_create_sales_order_requires_quantity(): void
    {
        [, $shop, $sku] = $this->salesSku();

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderCreate::class)
            ->set('shopId', (string) $shop->id)
            ->set('lines.0.sku_id', (string) $sku->id)
            ->set('lines.0.quantity', '0')
            ->call('save')
            ->assertHasErrors(['lines.0.quantity']);
    }

    public function test_create_sales_order_rejects_sku_from_wrong_tenant(): void
    {
        [, $shop] = $this->salesSku();
        [, , $wrongSku] = $this->salesSku();

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderCreate::class)
            ->set('shopId', (string) $shop->id)
            ->set('lines.0.sku_id', (string) $wrongSku->id)
            ->set('lines.0.quantity', '1')
            ->call('save')
            ->assertHasErrors(['lines.0.sku_id']);
    }

    public function test_cancel_sales_order_succeeds(): void
    {
        [, $shop, $sku] = $this->salesSku();
        $order = $this->createPersistedOrder($shop, $sku, [
            'order_status' => SalesOrder::ORDER_STATUS_PENDING,
            'fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_READY,
        ]);
        $order->lines()->create(['sku_id' => $sku->id, 'quantity' => 2, 'line_status' => SalesOrderLine::STATUS_READY]);

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderDetail::class, ['order' => $order])
            ->call('cancelOrder');

        $order->refresh();

        $this->assertSame(SalesOrder::ORDER_STATUS_CANCELLED, $order->order_status);
        $this->assertSame(SalesOrder::FULFILLMENT_STATUS_CANCELLED, $order->fulfillment_status);
        $this->assertSame([SalesOrderLine::STATUS_CANCELLED, SalesOrderLine::STATUS_CANCELLED], $order->lines()->pluck('line_status')->all());
    }

    public function test_cancel_sales_order_blocked_when_in_group(): void
    {
        [, $shop, $sku] = $this->salesSku();
        $order = $this->createPersistedOrder($shop, $sku, [
            'fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_IN_GROUP,
        ]);

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderDetail::class, ['order' => $order])
            ->call('cancelOrder');

        $this->assertSame(SalesOrder::FULFILLMENT_STATUS_IN_GROUP, $order->refresh()->fulfillment_status);
        $this->assertSame(SalesOrder::ORDER_STATUS_PENDING, $order->order_status);
    }

    public function test_update_recipient_recalculates_ship_together_key(): void
    {
        [, $shop, $sku] = $this->salesSku();
        $order = $this->createPersistedOrder($shop, $sku, [
            'recipient_address_line1' => '1-1 Namba',
            'recipient_city' => 'Osaka',
        ]);
        $originalKey = $order->ship_together_key;

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderDetail::class, ['order' => $order])
            ->call('editRecipient')
            ->set('editRecipientAddressLine1', '2-2 Shibuya')
            ->set('editRecipientCity', 'Tokyo')
            ->call('saveRecipient');

        $this->assertNotSame($originalKey, $order->refresh()->ship_together_key);
    }

    public function test_related_orders_shown_on_detail(): void
    {
        [, $shop, $sku] = $this->salesSku();
        $orderA = $this->createPersistedOrder($shop, $sku, ['platform_order_id' => 'REL-A']);
        $orderB = $this->createPersistedOrder($shop, $sku, ['platform_order_id' => 'REL-B']);

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderDetail::class, ['order' => $orderA])
            ->assertViewHas('relatedOrders', fn ($orders) => $orders->contains('id', $orderB->id));
    }

    public function test_related_orders_excludes_cancelled(): void
    {
        [, $shop, $sku] = $this->salesSku();
        $orderA = $this->createPersistedOrder($shop, $sku, ['platform_order_id' => 'REL-A']);
        $this->createPersistedOrder($shop, $sku, [
            'platform_order_id' => 'REL-CANCEL',
            'fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_CANCELLED,
        ]);

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderDetail::class, ['order' => $orderA])
            ->assertViewHas('relatedOrders', fn ($orders) => $orders->isEmpty());
    }

    public function test_tenant_user_only_sees_own_sales_orders(): void
    {
        [$ownTenant, $user] = $this->tenantUser();
        $ownShop = Shop::factory()->for($ownTenant)->create(['status' => 'active']);
        $ownStock = StockItem::factory()->for($ownTenant)->create();
        $ownSku = Sku::factory()->for($ownTenant)->for($ownShop)->for($ownStock)->create(['sku' => 'OWN-SALES-SKU']);
        $ownOrder = $this->createPersistedOrder($ownShop, $ownSku, ['platform_order_id' => 'VISIBLE-SALES']);

        [, $otherShop, $otherSku] = $this->salesSku();
        $this->createPersistedOrder($otherShop, $otherSku, ['platform_order_id' => 'HIDDEN-SALES']);

        Livewire::actingAs($user)
            ->test(SalesOrderIndex::class)
            ->assertSee('VISIBLE-SALES')
            ->assertDontSee('HIDDEN-SALES');

        Livewire::actingAs($user)
            ->test(SalesOrderDetail::class, ['order' => $ownOrder])
            ->assertOk();
    }

    public function test_tenant_user_without_active_tenant_cannot_access_sales_order_pages(): void
    {
        $user = User::factory()->create([
            'user_type' => 'tenant',
            'is_active' => true,
        ]);

        $this->actingAs($user)->get('/sales-orders')->assertForbidden();
        $this->actingAs($user)->get('/sales-orders/create')->assertForbidden();

        Livewire::actingAs($user)
            ->test(SalesOrderIndex::class)
            ->assertForbidden();

        Livewire::actingAs($user)
            ->test(SalesOrderCreate::class)
            ->assertForbidden();
    }

    public function test_sales_order_routes_render(): void
    {
        [, $shop, $sku] = $this->salesSku();
        $order = $this->createPersistedOrder($shop, $sku, ['platform_order_id' => 'ROUTE-SALES']);

        $this->actingAs($this->internalUser())->get('/sales-orders')->assertOk()->assertSee('Sales Orders');
        $this->actingAs($this->internalUser())->get('/sales-orders/create')->assertOk()->assertSee('Create Sales Order');
        $this->actingAs($this->internalUser())->get(route('sales.orders.show', $order))->assertOk()->assertSee('ROUTE-SALES');
    }

    /**
     * @return array{0: Tenant, 1: Shop, 2: Sku}
     */
    private function salesSku(): array
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        $shop = Shop::factory()->for($tenant)->create(['status' => 'active']);
        $stockItem = StockItem::factory()->for($tenant)->create();
        $sku = Sku::factory()->for($tenant)->for($shop)->for($stockItem)->create([
            'sku_type' => 'single',
            'sku' => 'SO-SKU-'.fake()->unique()->numberBetween(1000, 9999),
        ]);

        return [$tenant, $shop, $sku];
    }

    private function createOrder(Shop $shop, Sku $sku, string $platformOrderId): void
    {
        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderCreate::class)
            ->set('shopId', (string) $shop->id)
            ->set('platformOrderId', $platformOrderId)
            ->set('recipientName', 'Taro')
            ->set('recipientCountryCode', 'jp')
            ->set('recipientPostalCode', '542-0076')
            ->set('recipientCity', 'Osaka')
            ->set('recipientAddressLine1', '1-1 Namba')
            ->set('lines.0.sku_id', (string) $sku->id)
            ->set('lines.0.quantity', '1')
            ->call('save')
            ->assertRedirect();
    }

    private function createPersistedOrder(Shop $shop, Sku $sku, array $attributes = []): SalesOrder
    {
        $order = SalesOrder::factory()->create(array_merge([
            'tenant_id' => $shop->tenant_id,
            'shop_id' => $shop->id,
            'source' => SalesOrder::SOURCE_MANUAL,
            'order_status' => SalesOrder::ORDER_STATUS_PENDING,
            'fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_READY,
            'recipient_name' => 'Taro',
            'recipient_country_code' => 'JP',
            'recipient_postal_code' => '542-0076',
            'recipient_state' => 'Osaka',
            'recipient_city' => 'Osaka',
            'recipient_address_line1' => '1-1 Namba',
            'recipient_address_line2' => '',
        ], $attributes));

        $order->lines()->create([
            'sku_id' => $sku->id,
            'quantity' => 1,
            'line_status' => SalesOrderLine::STATUS_READY,
        ]);

        return $order;
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
