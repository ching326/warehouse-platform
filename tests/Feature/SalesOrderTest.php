<?php

namespace Tests\Feature;

use App\Actions\BackfillSalesOrderDate;
use App\Livewire\SalesOrderCreate;
use App\Livewire\SalesOrderDetail;
use App\Livewire\SalesOrderIndex;
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
use App\Services\Fulfillment\GroupSalesOrdersService;
use App\Services\InventoryService;
use App\Support\SalesOrderFilters;
use Carbon\Carbon;
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
        $this->assertSame(SalesOrder::FULFILLMENT_STATUS_UNFULFILLED, $order->fulfillment_status);
        $this->assertSame(SalesOrder::SOURCE_MANUAL, $order->source);
        $this->assertSame($sku->id, $line->sku_id);
        $this->assertSame(2, $line->quantity);
        $this->assertSame(SalesOrderLine::STATUS_READY, $line->line_status);
    }

    public function test_sales_order_index_includes_business_order_status_filters(): void
    {
        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderIndex::class)
            ->assertSee('Pending')
            ->assertSee('On hold')
            ->assertSee('Backorder')
            ->assertSee('Cancelled')
            ->assertSee('Completed');
    }

    public function test_backfill_sales_order_date_action_sets_order_date(): void
    {
        [, $shop, $sku] = $this->salesSku();
        $platformDate = Carbon::parse('2026-06-01 10:00:00');
        $createdDate = Carbon::parse('2026-06-02 11:00:00');
        $withPlatformDate = $this->createPersistedOrder($shop, $sku, [
            'platform_ordered_at' => $platformDate,
        ]);
        $withoutPlatformDate = $this->createPersistedOrder($shop, $sku, [
            'platform_ordered_at' => null,
            'created_at' => $createdDate,
            'updated_at' => $createdDate,
        ]);
        SalesOrder::whereKey([$withPlatformDate->id, $withoutPlatformDate->id])->update(['order_date' => null]);

        app(BackfillSalesOrderDate::class)();

        $this->assertTrue($withPlatformDate->refresh()->order_date->equalTo($platformDate));
        $this->assertTrue($withoutPlatformDate->refresh()->order_date->equalTo($createdDate));
    }

    public function test_sales_order_creating_hook_sets_order_date(): void
    {
        [, $shop, $sku] = $this->salesSku();
        $platformDate = Carbon::parse('2026-06-03 10:00:00');
        $createdDate = Carbon::parse('2026-06-04 11:00:00');
        $withPlatformDate = $this->createPersistedOrder($shop, $sku, ['platform_ordered_at' => $platformDate]);
        $withoutPlatformDate = $this->createPersistedOrder($shop, $sku, [
            'platform_ordered_at' => null,
            'created_at' => $createdDate,
            'updated_at' => $createdDate,
        ]);

        $this->assertTrue($withPlatformDate->order_date->equalTo($platformDate));
        $this->assertTrue($withoutPlatformDate->order_date->equalTo($createdDate));
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

    public function test_ship_together_key_no_longer_depends_on_shop_id(): void
    {
        [$tenant, $shopA, $skuA] = $this->salesSku();
        $shopB = Shop::factory()->for($tenant)->create(['status' => 'active']);
        $skuB = Sku::factory()
            ->for($tenant)
            ->for($shopB)
            ->for(StockItem::factory()->for($tenant)->create())
            ->create(['sku_type' => 'single']);

        $this->createOrder($shopA, $skuA, platformOrderId: 'SO-KEY-SHOP-A');
        $this->createOrder($shopB, $skuB, platformOrderId: 'SO-KEY-SHOP-B');

        $orders = SalesOrder::orderBy('id')->get();

        $this->assertNotSame($orders[0]->shop_id, $orders[1]->shop_id);
        $this->assertNotNull($orders[0]->ship_together_key);
        $this->assertSame($orders[0]->ship_together_key, $orders[1]->ship_together_key);
    }

    public function test_recompute_ship_together_key_migration_updates_active_orders_only(): void
    {
        [$tenant, $shopA, $skuA] = $this->salesSku();
        $shopB = Shop::factory()->for($tenant)->create(['status' => 'active']);
        $skuB = Sku::factory()
            ->for($tenant)
            ->for($shopB)
            ->for(StockItem::factory()->for($tenant)->create())
            ->create(['sku_type' => 'single']);

        $pendingA = $this->createPersistedOrder($shopA, $skuA, ['platform_order_id' => 'REKEY-PENDING-A']);
        $pendingB = $this->createPersistedOrder($shopB, $skuB, ['platform_order_id' => 'REKEY-PENDING-B']);
        $shipped = $this->createPersistedOrder($shopB, $skuB, [
            'platform_order_id' => 'REKEY-SHIPPED',
            'fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_SHIPPED,
        ]);
        $cancelled = $this->createPersistedOrder($shopB, $skuB, [
            'platform_order_id' => 'REKEY-CANCELLED',
            'order_status' => SalesOrder::ORDER_STATUS_CANCELLED,
            'fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_CANCELLED,
        ]);

        foreach ([$pendingA, $pendingB, $shipped, $cancelled] as $order) {
            SalesOrder::whereKey($order->id)->update([
                'ship_together_key' => $this->legacyShipTogetherKey($order),
            ]);
        }

        $shippedLegacyKey = $shipped->refresh()->ship_together_key;
        $cancelledLegacyKey = $cancelled->refresh()->ship_together_key;

        $migration = require database_path('migrations/2026_06_23_000003_recompute_sales_order_ship_together_keys.php');
        $migration->up();

        $this->assertSame($pendingA->refresh()->ship_together_key, $pendingB->refresh()->ship_together_key);
        $this->assertSame($shippedLegacyKey, $shipped->refresh()->ship_together_key);
        $this->assertSame($cancelledLegacyKey, $cancelled->refresh()->ship_together_key);
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

    public function test_cancel_sales_order_blocked_when_arranged(): void
    {
        [, $shop, $sku] = $this->salesSku();
        $order = $this->createPersistedOrder($shop, $sku, [
            'fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_ARRANGED,
        ]);

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderDetail::class, ['order' => $order])
            ->call('cancelOrder');

        $this->assertSame(SalesOrder::FULFILLMENT_STATUS_ARRANGED, $order->refresh()->fulfillment_status);
        $this->assertSame(SalesOrder::ORDER_STATUS_PENDING, $order->order_status);
    }

    public function test_delete_sales_order_succeeds(): void
    {
        [, $shop, $sku] = $this->salesSku();
        $order = $this->createPersistedOrder($shop, $sku, [
            'order_status' => SalesOrder::ORDER_STATUS_PENDING,
            'fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_READY,
        ]);
        $line = $order->lines()->create(['sku_id' => $sku->id, 'quantity' => 2, 'line_status' => SalesOrderLine::STATUS_READY]);

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderDetail::class, ['order' => $order])
            ->call('deleteOrder')
            ->assertRedirect(route('sales.orders.index'));

        $this->assertDatabaseMissing('sales_orders', ['id' => $order->id]);
        $this->assertDatabaseMissing('sales_order_lines', ['id' => $line->id]);
    }

    public function test_delete_sales_order_blocked_when_arranged(): void
    {
        [, $shop, $sku] = $this->salesSku();
        $order = $this->createPersistedOrder($shop, $sku, [
            'fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_ARRANGED,
        ]);

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderDetail::class, ['order' => $order])
            ->call('deleteOrder')
            ->assertSee(__('sales_orders.cannot_delete_order'));

        $this->assertDatabaseHas('sales_orders', ['id' => $order->id]);
    }

    public function test_sales_order_detail_updates_note(): void
    {
        [, $shop, $sku] = $this->salesSku();
        $order = $this->createPersistedOrder($shop, $sku, ['note' => 'Old note']);

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderDetail::class, ['order' => $order])
            ->call('updateNote', ' Updated detail note ');

        $this->assertSame('Updated detail note', $order->refresh()->note);
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

    public function test_tenant_user_linked_to_inactive_tenant_cannot_access_sales_order_pages(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'inactive']);
        $user = User::factory()->create([
            'user_type' => 'tenant',
            'is_active' => true,
        ]);

        TenantUser::factory()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'status' => 'active',
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

        $this->actingAs($this->internalUser())
            ->get('/sales-orders')
            ->assertOk()
            ->assertSee('ROUTE-SALES')
            ->assertDontSee('View and manage platform sales orders.');
        $this->actingAs($this->internalUser())->get('/sales-orders/create')->assertOk()->assertSee('Create Sales Order');
        $this->actingAs($this->internalUser())->get(route('sales.orders.show', $order))->assertOk()->assertSee('ROUTE-SALES');
    }

    public function test_mark_ready_succeeds(): void
    {
        [, $shop, $sku] = $this->salesSku();
        $order = $this->createPersistedOrder($shop, $sku);

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderDetail::class, ['order' => $order])
            ->call('markReady');

        $this->assertSame(SalesOrder::FULFILLMENT_STATUS_READY, $order->refresh()->fulfillment_status);
    }

    public function test_mark_ready_requires_address(): void
    {
        [, $shop, $sku] = $this->salesSku();
        $order = $this->createPersistedOrder($shop, $sku, ['recipient_address_line1' => '']);

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderDetail::class, ['order' => $order])
            ->call('markReady');

        $this->assertSame(SalesOrder::FULFILLMENT_STATUS_UNFULFILLED, $order->refresh()->fulfillment_status);
    }

    public function test_mark_ready_requires_shippable_line(): void
    {
        [, $shop, $sku] = $this->salesSku();
        $order = $this->createPersistedOrder($shop, $sku);
        $order->lines()->update(['line_status' => SalesOrderLine::STATUS_CANCELLED]);

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderDetail::class, ['order' => $order])
            ->call('markReady');

        $this->assertSame(SalesOrder::FULFILLMENT_STATUS_UNFULFILLED, $order->refresh()->fulfillment_status);
    }

    public function test_mark_ready_blocked_when_order_on_hold(): void
    {
        [, $shop, $sku] = $this->salesSku();
        $order = $this->createPersistedOrder($shop, $sku, ['order_status' => SalesOrder::ORDER_STATUS_ON_HOLD]);

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderDetail::class, ['order' => $order])
            ->call('markReady');

        $this->assertSame(SalesOrder::FULFILLMENT_STATUS_UNFULFILLED, $order->refresh()->fulfillment_status);
    }

    public function test_unmark_ready_succeeds(): void
    {
        [, $shop, $sku] = $this->salesSku();
        $order = $this->createPersistedOrder($shop, $sku, [
            'fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_READY,
        ]);

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderDetail::class, ['order' => $order])
            ->call('unmarkReady');

        $this->assertSame(SalesOrder::FULFILLMENT_STATUS_UNFULFILLED, $order->refresh()->fulfillment_status);
    }

    public function test_unmark_ready_blocked_when_arranged(): void
    {
        [, $shop, $sku] = $this->salesSku();
        $order = $this->createPersistedOrder($shop, $sku, [
            'fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_ARRANGED,
        ]);

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderDetail::class, ['order' => $order])
            ->call('unmarkReady');

        $this->assertSame(SalesOrder::FULFILLMENT_STATUS_ARRANGED, $order->refresh()->fulfillment_status);
    }

    public function test_hold_succeeds_and_resets_fulfillment_status(): void
    {
        [, $shop, $sku] = $this->salesSku();
        $order = $this->createPersistedOrder($shop, $sku, [
            'fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_READY,
        ]);

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderDetail::class, ['order' => $order])
            ->call('hold');

        $order->refresh();
        $this->assertSame(SalesOrder::ORDER_STATUS_ON_HOLD, $order->order_status);
        $this->assertSame(SalesOrder::FULFILLMENT_STATUS_UNFULFILLED, $order->fulfillment_status);
    }

    public function test_hold_blocked_when_arranged_without_reserved_group(): void
    {
        [, $shop, $sku] = $this->salesSku();
        $order = $this->createPersistedOrder($shop, $sku, [
            'fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_ARRANGED,
        ]);

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderDetail::class, ['order' => $order])
            ->call('hold');

        $this->assertSame(SalesOrder::ORDER_STATUS_PENDING, $order->refresh()->order_status);
    }

    public function test_release_hold_succeeds(): void
    {
        [, $shop, $sku] = $this->salesSku();
        $order = $this->createPersistedOrder($shop, $sku, ['order_status' => SalesOrder::ORDER_STATUS_ON_HOLD]);

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderDetail::class, ['order' => $order])
            ->call('releaseHold');

        $this->assertSame(SalesOrder::ORDER_STATUS_PENDING, $order->refresh()->order_status);
    }

    public function test_mark_backorder_succeeds(): void
    {
        [, $shop, $sku] = $this->salesSku();
        $order = $this->createPersistedOrder($shop, $sku, [
            'fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_READY,
        ]);

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderDetail::class, ['order' => $order])
            ->call('markBackorder');

        $order->refresh();
        $this->assertSame(SalesOrder::ORDER_STATUS_BACKORDER, $order->order_status);
        $this->assertSame(SalesOrder::FULFILLMENT_STATUS_UNFULFILLED, $order->fulfillment_status);
    }

    public function test_release_backorder_succeeds(): void
    {
        [, $shop, $sku] = $this->salesSku();
        $order = $this->createPersistedOrder($shop, $sku, ['order_status' => SalesOrder::ORDER_STATUS_BACKORDER]);

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderDetail::class, ['order' => $order])
            ->call('releaseBackorder');

        $this->assertSame(SalesOrder::ORDER_STATUS_PENDING, $order->refresh()->order_status);
    }

    public function test_edit_lines_replaces_ready_lines(): void
    {
        [$tenant, $shop, $sku] = $this->salesSku();
        $newSku = Sku::factory()->for($tenant)->for($shop)->for(StockItem::factory()->for($tenant)->create())->create([
            'sku_type' => 'single',
            'sku' => 'SO-EDIT-NEW',
        ]);
        $order = $this->createPersistedOrder($shop, $sku);

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderDetail::class, ['order' => $order])
            ->call('editLines')
            ->set('draftLines', [[
                'sku_id' => (string) $newSku->id,
                'quantity' => 3,
                'note' => 'replacement',
            ]])
            ->call('saveLines');

        $this->assertSame(1, $order->lines()->where('line_status', SalesOrderLine::STATUS_CANCELLED)->count());
        $this->assertDatabaseHas('sales_order_lines', [
            'sales_order_id' => $order->id,
            'sku_id' => $newSku->id,
            'quantity' => 3,
            'line_status' => SalesOrderLine::STATUS_READY,
            'note' => 'replacement',
        ]);
    }

    public function test_edit_lines_resets_fulfillment_if_was_ready(): void
    {
        [, $shop, $sku] = $this->salesSku();
        $order = $this->createPersistedOrder($shop, $sku, [
            'fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_READY,
        ]);

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderDetail::class, ['order' => $order])
            ->call('editLines')
            ->set('draftLines.0.quantity', 2)
            ->call('saveLines');

        $this->assertSame(SalesOrder::FULFILLMENT_STATUS_UNFULFILLED, $order->refresh()->fulfillment_status);
    }

    public function test_edit_lines_blocked_when_arranged(): void
    {
        [, $shop, $sku] = $this->salesSku();
        $order = $this->createPersistedOrder($shop, $sku, [
            'fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_ARRANGED,
        ]);

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderDetail::class, ['order' => $order])
            ->call('editLines')
            ->assertForbidden();
    }

    public function test_edit_lines_rejects_wrong_tenant_sku(): void
    {
        [, $shop, $sku] = $this->salesSku();
        [, , $wrongSku] = $this->salesSku();
        $order = $this->createPersistedOrder($shop, $sku);

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderDetail::class, ['order' => $order])
            ->call('editLines')
            ->set('draftLines.0.sku_id', (string) $wrongSku->id)
            ->call('saveLines')
            ->assertHasErrors(['lines.0.sku_id']);
    }

    public function test_bulk_mark_ready_updates_eligible_skips_ineligible(): void
    {
        [, $shop, $sku] = $this->salesSku();
        $warehouse = Warehouse::factory()->create(['status' => 'active']);
        app(InventoryService::class)->adjustStock($shop->tenant_id, $warehouse->id, $sku->stock_item_id, 20);
        $eligible = $this->createPersistedOrder($shop, $sku, ['platform_order_id' => 'BULK-READY']);
        $ineligible = $this->createPersistedOrder($shop, $sku, [
            'platform_order_id' => 'BULK-SKIP',
            'recipient_address_line1' => '',
        ]);

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderIndex::class)
            ->set('selectedIds', [(string) $eligible->id, (string) $ineligible->id])
            ->call('bulkMarkReady');

        $this->assertSame(SalesOrder::FULFILLMENT_STATUS_ARRANGED, $eligible->refresh()->fulfillment_status);
        $this->assertSame(SalesOrder::FULFILLMENT_STATUS_UNFULFILLED, $ineligible->refresh()->fulfillment_status);
    }

    public function test_bulk_mark_ready_ignores_other_tenant_orders(): void
    {
        [$ownTenant, $user] = $this->tenantUser();
        $ownShop = Shop::factory()->for($ownTenant)->create(['status' => 'active']);
        $ownSku = Sku::factory()->for($ownTenant)->for($ownShop)->for(StockItem::factory()->for($ownTenant)->create())->create(['sku_type' => 'single']);
        $warehouse = Warehouse::factory()->create(['status' => 'active']);
        app(InventoryService::class)->adjustStock($ownTenant->id, $warehouse->id, $ownSku->stock_item_id, 20);
        $ownOrder = $this->createPersistedOrder($ownShop, $ownSku, ['platform_order_id' => 'OWN-BULK']);
        [, $otherShop, $otherSku] = $this->salesSku();
        $otherOrder = $this->createPersistedOrder($otherShop, $otherSku, ['platform_order_id' => 'OTHER-BULK']);

        Livewire::actingAs($user)
            ->test(SalesOrderIndex::class)
            ->set('selectedIds', [(string) $ownOrder->id, (string) $otherOrder->id])
            ->call('bulkMarkReady');

        $this->assertSame(SalesOrder::FULFILLMENT_STATUS_ARRANGED, $ownOrder->refresh()->fulfillment_status);
        $this->assertSame(SalesOrder::FULFILLMENT_STATUS_UNFULFILLED, $otherOrder->refresh()->fulfillment_status);
    }

    public function test_bulk_mark_ready_rejects_virtual_bundle_without_components(): void
    {
        [$tenant, $shop] = $this->salesSku();
        $bundleSku = Sku::factory()->virtualBundle()->for($tenant)->for($shop)->create(['sku' => 'SO-BUNDLE-NO-COMP']);
        $order = $this->createPersistedOrder($shop, $bundleSku, ['platform_order_id' => 'BUNDLE-SKIP']);

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderIndex::class)
            ->set('selectedIds', [(string) $order->id])
            ->call('bulkMarkReady');

        $this->assertSame(SalesOrder::FULFILLMENT_STATUS_UNFULFILLED, $order->refresh()->fulfillment_status);
    }

    public function test_bulk_hold_resets_ungrouped_ready_order_to_unfulfilled(): void
    {
        [, $shop, $sku] = $this->salesSku();
        $order = $this->createPersistedOrder($shop, $sku, [
            'platform_order_id' => 'BULK-HOLD-READY',
            'fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_READY,
        ]);

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderIndex::class)
            ->set('selectedIds', [(string) $order->id])
            ->call('bulkHold');

        $order->refresh();
        $this->assertSame(SalesOrder::ORDER_STATUS_ON_HOLD, $order->order_status);
        $this->assertSame(SalesOrder::FULFILLMENT_STATUS_UNFULFILLED, $order->fulfillment_status);
    }

    public function test_bulk_delete_sales_orders_succeeds(): void
    {
        [, $shop, $sku] = $this->salesSku();
        $order = $this->createPersistedOrder($shop, $sku, ['platform_order_id' => 'BULK-DELETE']);
        $lineId = $order->lines()->firstOrFail()->id;

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderIndex::class)
            ->set('selectedIds', [(string) $order->id])
            ->call('bulkDelete')
            ->assertSet('selectedIds', []);

        $this->assertDatabaseMissing('sales_orders', ['id' => $order->id]);
        $this->assertDatabaseMissing('sales_order_lines', ['id' => $lineId]);
    }

    public function test_bulk_delete_skips_ineligible_orders(): void
    {
        [, $shop, $sku] = $this->salesSku();
        $eligible = $this->createPersistedOrder($shop, $sku, ['platform_order_id' => 'BULK-DELETE-YES']);
        $ineligible = $this->createPersistedOrder($shop, $sku, [
            'platform_order_id' => 'BULK-DELETE-NO',
            'fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_ARRANGED,
        ]);

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderIndex::class)
            ->set('selectedIds', [(string) $eligible->id, (string) $ineligible->id])
            ->call('bulkDelete')
            ->assertSet('selectedIds', []);

        $this->assertDatabaseMissing('sales_orders', ['id' => $eligible->id]);
        $this->assertDatabaseHas('sales_orders', ['id' => $ineligible->id]);
    }

    public function test_release_hold_blocked_when_fulfillment_status_terminal(): void
    {
        [, $shop, $sku] = $this->salesSku();
        $order = $this->createPersistedOrder($shop, $sku, [
            'order_status' => SalesOrder::ORDER_STATUS_ON_HOLD,
            'fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_SHIPPED,
        ]);

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderDetail::class, ['order' => $order])
            ->call('releaseHold');

        $this->assertSame(SalesOrder::ORDER_STATUS_ON_HOLD, $order->refresh()->order_status);
    }

    public function test_mark_ready_blocked_when_ready_line_has_unshippable_sku(): void
    {
        [$tenant, $shop, $sku] = $this->salesSku();
        $sku->update(['stock_item_id' => null, 'sku_type' => 'single']);
        $order = $this->createPersistedOrder($shop, $sku);

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderDetail::class, ['order' => $order])
            ->call('markReady');

        $this->assertSame(SalesOrder::FULFILLMENT_STATUS_UNFULFILLED, $order->refresh()->fulfillment_status);
        $this->assertSame($tenant->id, $order->tenant_id);
    }

    public function test_mark_ready_accepts_virtual_bundle_with_components(): void
    {
        [$tenant, $shop] = $this->salesSku();
        $bundleSku = Sku::factory()->virtualBundle()->for($tenant)->for($shop)->create(['sku' => 'SO-BUNDLE-READY']);
        SkuBundleComponent::factory()
            ->for($tenant)
            ->for($bundleSku, 'bundleSku')
            ->for(StockItem::factory()->for($tenant)->create(), 'componentStockItem')
            ->create(['quantity' => 1]);
        $order = $this->createPersistedOrder($shop, $bundleSku, ['platform_order_id' => 'BUNDLE-READY']);

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderDetail::class, ['order' => $order])
            ->call('markReady');

        $this->assertSame(SalesOrder::FULFILLMENT_STATUS_READY, $order->refresh()->fulfillment_status);
    }

    public function test_sales_order_index_hides_internal_id_under_platform_order_id(): void
    {
        [, $shop, $sku] = $this->salesSku();
        $order = $this->createPersistedOrder($shop, $sku, ['platform_order_id' => 'VISIBLE-ORDER-ID']);

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderIndex::class)
            ->assertSee('VISIBLE-ORDER-ID')
            ->assertDontSee('#'.$order->id);
    }

    public function test_sales_order_index_platform_order_id_links_to_detail(): void
    {
        [, $shop, $sku] = $this->salesSku();
        $order = $this->createPersistedOrder($shop, $sku, ['platform_order_id' => 'LINK-ORDER-ID']);

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderIndex::class)
            ->assertSee('LINK-ORDER-ID')
            ->assertSee(route('sales.orders.show', $order), false);
    }

    public function test_sales_order_index_shows_recipient_phone_and_address(): void
    {
        [, $shop, $sku] = $this->salesSku();
        $this->createPersistedOrder($shop, $sku, [
            'platform_order_id' => 'ADDRESS-ORDER',
            'recipient_name' => 'Aiko Tanaka',
            'recipient_phone' => '+81-90-1234-5678',
            'recipient_postal_code' => '150-0001',
            'recipient_state' => 'Tokyo',
            'recipient_city' => 'Shibuya',
            'recipient_address_line1' => '1-2-3 Jingumae',
            'recipient_address_line2' => 'Room 501',
        ]);

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderIndex::class)
            ->assertSee('Aiko Tanaka')
            ->assertSee('+81-90-1234-5678')
            ->assertSee('150-0001')
            ->assertSee('Tokyo Shibuya')
            ->assertSee('1-2-3 Jingumae')
            ->assertSee('Room 501');
    }

    public function test_sales_order_index_shows_line_quantities_and_skus(): void
    {
        [$tenant, $shop, $skuA] = $this->salesSku();
        $skuA->update(['sku' => 'INDEX-SKU-A']);
        $skuB = Sku::factory()->for($tenant)->for($shop)->for(StockItem::factory()->for($tenant)->create())->create([
            'sku_type' => 'single',
            'sku' => 'INDEX-SKU-B',
        ]);
        $cancelledSku = Sku::factory()->for($tenant)->for($shop)->for(StockItem::factory()->for($tenant)->create())->create([
            'sku_type' => 'single',
            'sku' => 'INDEX-CANCELLED-SKU',
        ]);
        $order = $this->createPersistedOrder($shop, $skuA, ['platform_order_id' => 'ITEMS-ORDER']);
        $order->lines()->create(['sku_id' => $skuB->id, 'quantity' => 3, 'line_status' => SalesOrderLine::STATUS_READY]);
        $order->lines()->create(['sku_id' => $cancelledSku->id, 'quantity' => 9, 'line_status' => SalesOrderLine::STATUS_CANCELLED]);

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderIndex::class)
            ->assertSeeInOrder(['1', 'x', 'INDEX-SKU-A'])
            ->assertSeeInOrder(['3', 'x', 'INDEX-SKU-B'])
            ->assertDontSee('INDEX-CANCELLED-SKU');
    }

    public function test_sales_order_index_groups_shop_under_order_id(): void
    {
        [$tenant, $shop, $sku] = $this->salesSku();
        $order = $this->createPersistedOrder($shop, $sku, ['platform_order_id' => 'GROUPED-ORDER']);

        $html = Livewire::actingAs($this->internalUser())
            ->test(SalesOrderIndex::class)
            ->html();

        $this->assertStringContainsString('GROUPED-ORDER', $html);
        $this->assertStringContainsString($shop->name, $html);
        $this->assertStringContainsString($tenant->code.' / '.$shop->platform, $html);
        $this->assertStringNotContainsString('<strong>#'.$order->id.'</strong>', $html);
        $this->assertDoesNotMatchRegularExpression('/<th[^>]*>\s*SHOP\s*<\/th>/i', $html);
    }

    public function test_sales_order_index_renames_platform_order_id_column_to_order_id(): void
    {
        [, $shop, $sku] = $this->salesSku();
        $this->createPersistedOrder($shop, $sku, ['platform_order_id' => 'RENAMED-ORDER']);

        $html = Livewire::actingAs($this->internalUser())
            ->test(SalesOrderIndex::class)
            ->html();

        $this->assertStringContainsString('Order ID', $html);
        $this->assertDoesNotMatchRegularExpression('/<th[^>]*>\s*PLATFORM ORDER ID\s*<\/th>/i', $html);
    }

    public function test_sales_order_index_renames_items_column_to_sku(): void
    {
        [, $shop, $sku] = $this->salesSku();
        $this->createPersistedOrder($shop, $sku, ['platform_order_id' => 'SKU-COLUMN-ORDER']);

        $html = Livewire::actingAs($this->internalUser())
            ->test(SalesOrderIndex::class)
            ->html();

        $this->assertStringContainsString('>SKU</div>', $html);
        $this->assertStringNotContainsString('>Items</div>', $html);
    }

    public function test_sales_order_index_address_postcode_is_not_bold(): void
    {
        [, $shop, $sku] = $this->salesSku();
        $this->createPersistedOrder($shop, $sku, [
            'platform_order_id' => 'POSTCODE-ORDER',
            'recipient_postal_code' => '150-0001',
        ]);

        $html = Livewire::actingAs($this->internalUser())
            ->test(SalesOrderIndex::class)
            ->html();

        $this->assertStringContainsString('150-0001', $html);
        $this->assertStringNotContainsString('<strong>150-0001</strong>', $html);
    }

    public function test_sales_order_index_sku_cell_prefers_stock_item_short_name_below_quantity_and_sku(): void
    {
        [$tenant, $shop, $skuA] = $this->salesSku();
        $skuA->stockItem->update(['short_name' => 'Short Cable']);
        $skuA->update(['sku' => 'SHORT-SKU', 'name' => 'Long Cable Name']);
        $stockWithoutShortName = StockItem::factory()->for($tenant)->create(['short_name' => null]);
        $skuB = Sku::factory()->for($tenant)->for($shop)->for($stockWithoutShortName)->create([
            'sku_type' => 'single',
            'sku' => 'NAME-FALLBACK-SKU',
            'name' => 'Fallback SKU Name',
        ]);
        $order = $this->createPersistedOrder($shop, $skuA, ['platform_order_id' => 'ITEM-LABELS']);
        $order->lines()->create(['sku_id' => $skuB->id, 'quantity' => 1, 'line_status' => SalesOrderLine::STATUS_READY]);

        $html = Livewire::actingAs($this->internalUser())
            ->test(SalesOrderIndex::class)
            ->html();

        $this->assertStringContainsString('class="so-sku-line"', $html);
        $this->assertStringContainsString('class="subtle so-sku-label"', $html);
        $this->assertStringContainsString('title="Short Cable"', $html);
        $this->assertStringContainsString('Short Cable', $html);
        $this->assertStringContainsString('Fallback SKU Name', $html);
        $this->assertStringNotContainsString('Long Cable Name', $html);
    }

    public function test_sales_order_index_combines_fulfillment_and_order_status_columns(): void
    {
        [, $shop, $sku] = $this->salesSku();
        $this->createPersistedOrder($shop, $sku, [
            'platform_order_id' => 'STATUS-ORDER',
            'fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_READY,
            'order_status' => SalesOrder::ORDER_STATUS_PENDING,
        ]);

        $html = Livewire::actingAs($this->internalUser())
            ->test(SalesOrderIndex::class)
            ->html();

        $this->assertDoesNotMatchRegularExpression('/<th[^>]*>\s*FULFILLMENT\s*<\/th>/i', $html);
        $this->assertStringContainsString('Status', $html);
        $this->assertStringContainsString('Ship Ready', $html);
        $this->assertStringContainsString('status-stack', $html);
    }

    public function test_sales_order_index_bulk_action_row_is_visible_with_no_selection(): void
    {
        [, $shop, $sku] = $this->salesSku();
        $this->createPersistedOrder($shop, $sku, ['platform_order_id' => 'BULK-ZERO']);

        $html = Livewire::actingAs($this->internalUser())
            ->test(SalesOrderIndex::class)
            ->html();

        $this->assertStringContainsString('x-text="selectedList().length"', $html);
        $this->assertStringContainsString('x-show="has()"', $html);
        $this->assertStringContainsString(__('sales_orders.btn_bulk_mark_ready'), $html);
        $this->assertStringContainsString(__('sales_orders.btn_bulk_hold'), $html);
        $this->assertStringContainsString(__('sales_orders.btn_bulk_cancel'), $html);
        $this->assertStringContainsString(__('sales_orders.btn_bulk_delete'), $html);
        $this->assertStringNotContainsString(__('sales_orders.courier_export_menu'), $html);
        $this->assertStringContainsString(__('sales_orders.shipping_notice_menu'), $html);
        $this->assertStringContainsString('x-show="has()"', $html);
        $this->assertStringContainsString('disabled', $html);
    }

    public function test_sales_order_index_toolbar_groups_actions_by_zone(): void
    {
        [, $shop, $sku] = $this->salesSku();
        $this->createPersistedOrder($shop, $sku, ['platform_order_id' => 'TOOLBAR-ZONES']);

        $html = Livewire::actingAs($this->internalUser())
            ->test(SalesOrderIndex::class)
            ->html();

        $this->assertStringContainsString('data-testid="sales-order-filter-row"', $html);
        $this->assertStringContainsString('data-testid="sales-order-page-actions"', $html);
        $this->assertStringContainsString('data-testid="sales-order-selection-actions"', $html);
        $this->assertStringContainsString('data-testid="sales-order-page-import-menu"', $html);
        $this->assertStringContainsString('data-testid="sales-order-orders-import-menu"', $html);
        $this->assertStringNotContainsString('data-testid="sales-order-courier-import-menu"', $html);
        $this->assertStringContainsString(__('sales_orders.import_btn'), $html);
        $this->assertStringContainsString(__('sales_orders.import_orders_menu'), $html);
        $this->assertStringContainsString(__('sales_orders.import_file_upload'), $html);
        $this->assertStringContainsString(__('sales_orders.import_amazon_api'), $html);
        $this->assertStringContainsString(__('sales_orders.import_manual_input'), $html);
        $this->assertStringNotContainsString(__('sales_orders.import_courier_menu'), $html);
        $this->assertStringNotContainsString(__('sales_orders.import_tracking_numbers'), $html);
        $this->assertStringContainsString(__('sales_orders.export_menu'), $html);
        $this->assertStringNotContainsString(__('sales_orders.btn_create_order'), $html);
    }

    public function test_sales_order_index_top_filters_use_category_labels_and_chip_row(): void
    {
        [, $shop, $sku] = $this->salesSku();
        $this->createPersistedOrder($shop, $sku, ['platform_order_id' => 'FILTER-TOOLBAR']);

        $html = Livewire::actingAs($this->internalUser())
            ->test(SalesOrderIndex::class)
            ->html();

        $this->assertStringContainsString(__('sales_orders.filter_order_date'), $html);
        $this->assertStringContainsString(__('sales_orders.filter_others'), $html);
        $this->assertStringContainsString(__('sales_orders.other_multi_item'), $html);
        $this->assertStringNotContainsString(__('sales_orders.other_printed'), $html);
        $this->assertStringNotContainsString(__('sales_orders.other_not_printed'), $html);
        $this->assertStringNotContainsString('<strong>'.__('sales_orders.all_platforms').'</strong>', $html);
        $this->assertStringNotContainsString('<strong>'.__('sales_orders.all_shops').'</strong>', $html);
        $this->assertStringNotContainsString('<strong>'.__('sales_orders.all_fulfillment_status').'</strong>', $html);
        $this->assertStringNotContainsString('<strong>'.__('sales_orders.all_order_status').'</strong>', $html);
    }

    public function test_sales_order_index_filter_chips_render_and_remove_individual_filters(): void
    {
        [, $shop, $sku] = $this->salesSku();
        $yamato = ShippingMethod::where('code', 'yamato_nekopos')->firstOrFail();
        $this->createPersistedOrder($shop, $sku, [
            'platform_order_id' => 'FILTER-CHIPS',
            'fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_READY,
            'shipping_method' => 'yamato',
            'shipping_method_id' => $yamato->id,
        ]);

        $component = Livewire::actingAs($this->internalUser())
            ->test(SalesOrderIndex::class)
            ->set('platforms', ['amazon'])
            ->set('shopIds', [(string) $shop->id])
            ->set('fulfillmentStatusesFilter', [SalesOrder::FULFILLMENT_STATUS_READY])
            ->set('orderStatusesFilter', [SalesOrder::ORDER_STATUS_PENDING])
            ->set('shippingMethodsFilter', [(string) $yamato->id])
            ->call('toggleOtherFilter', SalesOrderFilters::OTHER_NOT_PRINTED)
            ->set('dateRange', SalesOrderFilters::DATE_LAST_30_DAYS)
            ->set('search', 'Tanaka')
            ->assertSee('Platform: amazon')
            ->assertSee('Shop: '.$shop->tenant->code.' / '.$shop->name)
            ->assertSee('Ship status: Ship Ready')
            ->assertSee('Order Status: Pending')
            ->assertSee('Shipping: Yamato Nekopos')
            ->assertSee('Others: Not Printed')
            ->assertSee('Order Date: Last 30 days')
            ->assertSee('Search: Tanaka');

        $component
            ->call('removeFilterChip', 'platform', 'amazon')
            ->assertSet('platforms', [])
            ->assertSet('shopIds', [(string) $shop->id])
            ->call('removeFilterChip', 'date')
            ->assertSet('dateRange', SalesOrderFilters::DATE_ALL)
            ->assertSet('dateFrom', '')
            ->assertSet('dateTo', '')
            ->call('removeFilterChip', 'search')
            ->assertSet('search', '');
    }

    public function test_sales_order_index_clear_all_filters_resets_user_filters(): void
    {
        [, $shop, $sku] = $this->salesSku();
        $this->createPersistedOrder($shop, $sku, ['platform_order_id' => 'CLEAR-FILTERS']);

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderIndex::class)
            ->set('platforms', ['amazon'])
            ->set('shopIds', [(string) $shop->id])
            ->set('fulfillmentStatusesFilter', [SalesOrder::FULFILLMENT_STATUS_READY])
            ->set('orderStatusesFilter', [SalesOrder::ORDER_STATUS_PENDING])
            ->set('shippingMethodsFilter', ['yamato'])
            ->set('othersFilter', [SalesOrderFilters::OTHER_NOT_PRINTED])
            ->set('dateRange', SalesOrderFilters::DATE_LAST_30_DAYS)
            ->set('dateFrom', '2026-06-01')
            ->set('dateTo', '2026-06-20')
            ->set('search', 'Tanaka')
            ->call('clearAllFilters')
            ->assertSet('platforms', [])
            ->assertSet('shopIds', [])
            ->assertSet('fulfillmentStatusesFilter', [])
            ->assertSet('orderStatusesFilter', [])
            ->assertSet('shippingMethodsFilter', [])
            ->assertSet('othersFilter', [])
            ->assertSet('dateRange', SalesOrderFilters::DATE_ALL)
            ->assertSet('dateFrom', '')
            ->assertSet('dateTo', '')
            ->assertSet('search', '');
    }

    public function test_sales_order_index_filter_chips_show_clear_all_only_when_filters_are_active(): void
    {
        [, $shop, $sku] = $this->salesSku();
        $this->createPersistedOrder($shop, $sku, ['platform_order_id' => 'CLEAR-CHIP']);

        $component = Livewire::actingAs($this->internalUser())
            ->test(SalesOrderIndex::class);

        $this->assertStringNotContainsString('data-testid="sales-order-filter-chips"', $component->html());

        $html = $component
            ->set('search', 'Tanaka')
            ->html();

        $this->assertStringContainsString('data-testid="sales-order-filter-chips"', $html);
        $this->assertStringContainsString(__('sales_orders.clear_all_filters'), $html);
    }

    public function test_sales_order_index_export_menus_present(): void
    {
        [, $shop, $sku] = $this->salesSku();
        $order = $this->createPersistedOrder($shop, $sku, ['platform_order_id' => 'EXPORT-MENUS']);

        $html = Livewire::actingAs($this->internalUser())
            ->test(SalesOrderIndex::class)
            ->set('selectedIds', [(string) $order->id])
            ->html();

        $this->assertStringContainsString('data-testid="sales-order-page-export-menu"', $html);
        $this->assertStringNotContainsString('Export all', $html);
        $this->assertStringNotContainsString('data-testid="sales-order-selected-export-menu"', $html);
        $this->assertStringNotContainsString('Export selected', $html);
        $this->assertStringNotContainsString('data-testid="sales-order-courier-export-menu"', $html);
        $this->assertStringNotContainsString(__('sales_orders.btn_export_yamato_csv'), $html);
        $this->assertStringNotContainsString(__('sales_orders.btn_export_sagawa_csv'), $html);
        $this->assertStringContainsString('data-testid="sales-order-shipping-notice-export-menu"', $html);
        $this->assertStringContainsString(__('sales_orders.btn_export_amazon_ship_notice'), $html);
        $this->assertStringContainsString(__('sales_orders.btn_export_rakuten_ship_notice'), $html);
    }

    public function test_sales_order_index_shipping_filter_matches_order_shipping_dropdown_options(): void
    {
        [, $shop, $sku] = $this->salesSku();
        $this->createPersistedOrder($shop, $sku, ['platform_order_id' => 'SHIPPING-FILTER-OPTIONS']);
        $yamato = ShippingMethod::where('code', 'yamato_nekopos')->firstOrFail();
        $sagawa = ShippingMethod::where('code', 'sagawa_thb')->firstOrFail();

        $html = Livewire::actingAs($this->internalUser())
            ->test(SalesOrderIndex::class)
            ->html();

        $this->assertMatchesRegularExpression('/<input type="checkbox"[^>]*wire:model\.live="shippingMethodsFilter"[^>]*value="'.preg_quote((string) $yamato->id, '/').'"[^>]*>\s*Yamato Nekopos/', $html);
        $this->assertMatchesRegularExpression('/<input type="checkbox"[^>]*wire:model\.live="shippingMethodsFilter"[^>]*value="'.preg_quote((string) $sagawa->id, '/').'"[^>]*>\s*Sagawa THB/', $html);
        $this->assertMatchesRegularExpression('/<option value="'.preg_quote((string) $yamato->id, '/').'"\s*>\s*Yamato Nekopos\s*<\/option>/', $html);
        $this->assertMatchesRegularExpression('/<option value="'.preg_quote((string) $sagawa->id, '/').'"\s*>\s*Sagawa THB\s*<\/option>/', $html);
        $this->assertStringContainsString('value="'.SalesOrderFilters::EMPTY_SHIPPING.'"', $html);
        $this->assertStringContainsString(__('sales_orders.shipping_method_unset'), $html);
        $this->assertStringNotContainsString('Yamato Compact / Yamato Nekopos / Yamato TQB', $html);
    }

    public function test_sales_order_index_no_longer_renders_selected_order_export_links(): void
    {
        [, $shop, $sku] = $this->salesSku();
        $first = $this->createPersistedOrder($shop, $sku, ['platform_order_id' => 'SELECTED-EXPORT-1']);
        $second = $this->createPersistedOrder($shop, $sku, ['platform_order_id' => 'SELECTED-EXPORT-2']);

        $html = Livewire::actingAs($this->internalUser())
            ->test(SalesOrderIndex::class)
            ->set('selectedIds', [(string) $first->id, (string) $second->id])
            ->html();

        $this->assertStringNotContainsString('selectedExportHref', $html);
        $this->assertStringNotContainsString('Export selected', $html);
        $this->assertStringNotContainsString('selectedList().join', $html);
    }

    public function test_sales_order_index_select_all_selects_only_visible_page_orders(): void
    {
        [, $shop, $sku] = $this->salesSku();
        $orders = collect(range(1, 31))->map(fn (int $index) => $this->createPersistedOrder($shop, $sku, [
            'platform_order_id' => sprintf('SELECT-PAGE-%02d', $index),
        ]));

        $component = Livewire::actingAs($this->internalUser())
            ->test(SalesOrderIndex::class)
            ->call('toggleVisibleSelection');

        $selectedIds = array_map('intval', $component->get('selectedIds'));

        $this->assertCount(30, $selectedIds);
        $this->assertNotContains($orders->first()->id, $selectedIds);
        $this->assertSame($selectedIds, $component->get('selectedIds'));

        $component->call('toggleVisibleSelection');
        $this->assertSame([], $component->get('selectedIds'));
    }

    public function test_sales_order_index_checkbox_hitboxes_are_rendered_without_row_selection(): void
    {
        [, $shop, $sku] = $this->salesSku();
        $this->createPersistedOrder($shop, $sku, ['platform_order_id' => 'CHECKBOX-HITBOX']);

        $html = Livewire::actingAs($this->internalUser())
            ->test(SalesOrderIndex::class)
            ->html();

        $this->assertStringContainsString('so-checkbox-hitbox-header', $html);
        $this->assertStringContainsString('class="so-checkbox-hitbox"', $html);
        $this->assertStringContainsString("\$wire.entangle('selectedIds')", $html);
        $this->assertStringContainsString('x-on:change="toggleAll()"', $html);
        $this->assertStringContainsString('x-bind:indeterminate.prop="someVisibleSelected"', $html);
        $this->assertStringContainsString('x-on:change="toggleRow(', $html);
        $this->assertStringNotContainsString('wire:model.live="selectedIds"', $html);
        $this->assertStringNotContainsString('wire:click="toggleVisibleSelection"', $html);
        $this->assertStringNotContainsString('wire:click="toggleRowSelection"', $html);
    }

    public function test_sales_order_index_default_view_shows_active_backlog_all_dates(): void
    {
        [, $shop, $sku] = $this->salesSku();
        $oldActive = $this->createPersistedOrder($shop, $sku, [
            'platform_order_id' => 'OLD-ACTIVE',
            'order_date' => now()->subYears(2),
        ]);
        $this->createPersistedOrder($shop, $sku, [
            'platform_order_id' => 'OLD-SHIPPED',
            'fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_SHIPPED,
            'order_date' => now()->subYears(2),
        ]);
        $this->createPersistedOrder($shop, $sku, [
            'platform_order_id' => 'OLD-COMPLETED',
            'order_status' => SalesOrder::ORDER_STATUS_COMPLETED,
            'order_date' => now()->subYears(2),
        ]);
        $this->createPersistedOrder($shop, $sku, [
            'platform_order_id' => 'OLD-CANCELLED',
            'order_status' => SalesOrder::ORDER_STATUS_CANCELLED,
            'order_date' => now()->subYears(2),
        ]);

        $component = Livewire::actingAs($this->internalUser())
            ->test(SalesOrderIndex::class)
            ->assertSet('activeOnly', true)
            ->assertSee('OLD-ACTIVE')
            ->assertDontSee('OLD-SHIPPED')
            ->assertDontSee('OLD-COMPLETED')
            ->assertDontSee('OLD-CANCELLED');

        $html = $component->html();
        $this->assertStringNotContainsString('wire:model.live="activeOnly"', $html);
        $this->assertStringNotContainsString('active-only-toggle', $html);
        $this->assertStringContainsString('sales-order-search-icon', $html);
        $this->assertStringNotContainsString('print-ready-pill', $html);

        $this->assertNotNull($oldActive->refresh()->order_date);
    }

    public function test_sales_order_index_default_view_still_shows_operational_exceptions(): void
    {
        [, $shop, $sku] = $this->salesSku();
        $this->createPersistedOrder($shop, $sku, [
            'platform_order_id' => 'OPEN-HOLD',
            'order_status' => SalesOrder::ORDER_STATUS_ON_HOLD,
        ]);
        $this->createPersistedOrder($shop, $sku, [
            'platform_order_id' => 'OPEN-BACKORDER',
            'order_status' => SalesOrder::ORDER_STATUS_BACKORDER,
        ]);
        $this->createPersistedOrder($shop, $sku, [
            'platform_order_id' => 'OPEN-CANCEL-REQUEST',
            'order_status' => SalesOrder::ORDER_STATUS_CANCEL_REQUESTED,
        ]);
        $this->createPersistedOrder($shop, $sku, [
            'platform_order_id' => 'OPEN-SHIP-READY',
            'fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_READY,
        ]);

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderIndex::class)
            ->assertSee('OPEN-HOLD')
            ->assertSee('OPEN-BACKORDER')
            ->assertSee('OPEN-CANCEL-REQUEST')
            ->assertSee('OPEN-SHIP-READY');
    }

    public function test_sales_order_index_others_filters_multi_item_printed_and_not_printed(): void
    {
        [$tenant, $shop, $sku] = $this->salesSku();
        $extraSku = Sku::factory()->for($tenant)->for($shop)->for(StockItem::factory()->for($tenant)->create())->create(['sku_type' => 'single']);
        $single = $this->createPersistedOrder($shop, $sku, ['platform_order_id' => 'OTHER-SINGLE']);
        $multiLine = $this->createPersistedOrder($shop, $sku, ['platform_order_id' => 'OTHER-MULTI-LINE']);
        $multiLine->lines()->create(['sku_id' => $extraSku->id, 'quantity' => 1, 'line_status' => SalesOrderLine::STATUS_READY]);
        $multiQty = $this->createPersistedOrder($shop, $sku, ['platform_order_id' => 'OTHER-MULTI-QTY']);
        $multiQty->lines()->firstOrFail()->update(['quantity' => 2]);
        $cancelledExtra = $this->createPersistedOrder($shop, $sku, ['platform_order_id' => 'OTHER-CANCELLED-EXTRA']);
        $cancelledExtra->lines()->create(['sku_id' => $extraSku->id, 'quantity' => 5, 'line_status' => SalesOrderLine::STATUS_CANCELLED]);
        $printed = $this->createPersistedOrder($shop, $sku, [
            'platform_order_id' => 'OTHER-PRINTED',
            'courier_csv_exported_at' => now(),
        ]);

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderIndex::class)
            ->set('othersFilter', [SalesOrderFilters::OTHER_MULTI_ITEM])
            ->assertDontSee($single->platform_order_id)
            ->assertSee($multiLine->platform_order_id)
            ->assertSee($multiQty->platform_order_id)
            ->assertDontSee($cancelledExtra->platform_order_id);

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderIndex::class)
            ->set('othersFilter', [SalesOrderFilters::OTHER_PRINTED])
            ->assertSee($printed->platform_order_id)
            ->assertDontSee($single->platform_order_id);

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderIndex::class)
            ->set('othersFilter', [SalesOrderFilters::OTHER_NOT_PRINTED])
            ->assertSee($single->platform_order_id)
            ->assertDontSee($printed->platform_order_id);
    }

    public function test_sales_order_index_other_printed_toggle_is_mutually_exclusive(): void
    {
        [, $shop, $sku] = $this->salesSku();
        $this->createPersistedOrder($shop, $sku, [
            'platform_order_id' => 'PRINTED-TOGGLE',
            'fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_READY,
        ]);

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderIndex::class)
            ->call('toggleOtherFilter', SalesOrderFilters::OTHER_PRINTED)
            ->assertSet('othersFilter', [SalesOrderFilters::OTHER_PRINTED])
            ->call('toggleOtherFilter', SalesOrderFilters::OTHER_NOT_PRINTED)
            ->assertSet('othersFilter', [SalesOrderFilters::OTHER_NOT_PRINTED]);
    }

    public function test_sales_order_index_today_date_filter(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-20 10:00:00'));
        [, $shop, $sku] = $this->salesSku();
        $this->createPersistedOrder($shop, $sku, [
            'platform_order_id' => 'TODAY-ORDER',
            'order_date' => Carbon::parse('2026-06-20 09:00:00'),
        ]);
        $this->createPersistedOrder($shop, $sku, [
            'platform_order_id' => 'YESTERDAY-ORDER',
            'order_date' => Carbon::parse('2026-06-19 23:59:00'),
        ]);

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderIndex::class)
            ->set('dateRange', SalesOrderFilters::DATE_TODAY)
            ->assertSee('TODAY-ORDER')
            ->assertDontSee('YESTERDAY-ORDER')
            ->assertSee('Order Date: Today');

        Carbon::setTestNow();
    }

    public function test_sales_order_index_historical_status_defaults_to_last_30_days(): void
    {
        [, $shop, $sku] = $this->salesSku();
        $this->createPersistedOrder($shop, $sku, [
            'platform_order_id' => 'RECENT-SHIPPED',
            'fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_SHIPPED,
            'order_date' => now()->subDays(5),
        ]);
        $this->createPersistedOrder($shop, $sku, [
            'platform_order_id' => 'OLD-SHIPPED',
            'fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_SHIPPED,
            'order_date' => now()->subDays(60),
        ]);

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderIndex::class)
            ->set('fulfillmentStatusesFilter', [SalesOrder::FULFILLMENT_STATUS_SHIPPED])
            ->assertSet('dateRange', SalesOrderFilters::DATE_LAST_30_DAYS)
            ->assertSee('RECENT-SHIPPED')
            ->assertDontSee('OLD-SHIPPED');
    }

    public function test_sales_order_index_keeps_historical_status_bounded_when_date_range_is_reset_to_all(): void
    {
        [, $shop, $sku] = $this->salesSku();
        $this->createPersistedOrder($shop, $sku, [
            'platform_order_id' => 'RECENT-SHIPPED-RESET',
            'fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_SHIPPED,
            'order_date' => now()->subDays(5),
        ]);
        $this->createPersistedOrder($shop, $sku, [
            'platform_order_id' => 'OLD-SHIPPED-RESET',
            'fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_SHIPPED,
            'order_date' => now()->subDays(60),
        ]);

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderIndex::class)
            ->set('fulfillmentStatusesFilter', [SalesOrder::FULFILLMENT_STATUS_SHIPPED])
            ->set('dateRange', SalesOrderFilters::DATE_ALL)
            ->assertSet('dateRange', SalesOrderFilters::DATE_LAST_30_DAYS)
            ->assertSee('RECENT-SHIPPED-RESET')
            ->assertDontSee('OLD-SHIPPED-RESET');
    }

    public function test_sales_order_index_custom_date_range_cannot_exceed_365_days(): void
    {
        [, $shop, $sku] = $this->salesSku();
        $this->createPersistedOrder($shop, $sku, ['platform_order_id' => 'TOO-WIDE']);

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderIndex::class)
            ->set('dateRange', SalesOrderFilters::DATE_CUSTOM)
            ->set('dateFrom', '2024-01-01')
            ->set('dateTo', '2026-01-02')
            ->assertSee(__('sales_orders.date_range_too_wide'))
            ->assertDontSee('TOO-WIDE');
    }

    public function test_sales_order_index_shows_note_column(): void
    {
        [, $shop, $sku] = $this->salesSku();
        $this->createPersistedOrder($shop, $sku, [
            'platform_order_id' => 'NOTE-ORDER',
            'note' => 'Pack with invoice',
        ]);

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderIndex::class)
            ->assertSee(__('sales_orders.col_note'))
            ->assertSee('Pack with invoice');
    }

    public function test_sales_order_index_updates_note(): void
    {
        [, $shop, $sku] = $this->salesSku();
        $order = $this->createPersistedOrder($shop, $sku, ['note' => 'Old index note']);

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderIndex::class)
            ->call('updateNote', $order->id, ' Updated index note ');

        $this->assertSame('Updated index note', $order->refresh()->note);
    }

    public function test_sales_order_index_searches_address_phone_tracking_note_and_sku(): void
    {
        [, $shop, $sku] = $this->salesSku();
        $stockItem = $sku->stockItem;
        $stockItem->update(['short_name' => 'ShortFind', 'name' => 'Stock Long Find']);
        $sku->update(['sku' => 'SKU-FIND-ME', 'name' => 'Sku Name Find']);
        $target = $this->createPersistedOrder($shop, $sku, [
            'platform_order_id' => 'SEARCH-TARGET',
            'recipient_phone' => 'PHONE-FIND',
            'recipient_address_line1' => 'Address Find Street',
            'tracking_no' => 'TRACK-FIND',
            'note' => 'Order Note Find',
        ]);
        $target->lines()->firstOrFail()->update(['note' => 'Line Note Find']);
        $missSku = Sku::factory()->for($shop->tenant)->for($shop)->for(StockItem::factory()->for($shop->tenant)->create())->create(['sku_type' => 'single', 'sku' => 'MISS-SKU']);
        $this->createPersistedOrder($shop, $missSku, ['platform_order_id' => 'SEARCH-MISS']);

        foreach (['PHONE-FIND', 'Address Find', 'TRACK-FIND', 'Order Note Find', 'Line Note Find', 'SKU-FIND-ME', 'Sku Name Find', 'ShortFind'] as $term) {
            Livewire::actingAs($this->internalUser())
                ->test(SalesOrderIndex::class)
                ->set('search', $term)
                ->assertSee('SEARCH-TARGET')
                ->assertDontSee('SEARCH-MISS');
        }
    }

    public function test_sales_order_index_filters_by_multiple_platforms_shops_statuses_and_shipping_methods(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        $yamato = ShippingMethod::where('code', 'yamato_nekopos')->firstOrFail();
        $sagawa = ShippingMethod::where('code', 'sagawa_thb')->firstOrFail();
        $japanPost = ShippingMethod::where('code', 'japan_post_yupack')->firstOrFail();
        $amazon = Shop::factory()->for($tenant)->create(['status' => 'active', 'platform' => 'amazon', 'name' => 'Amazon Shop']);
        $rakuten = Shop::factory()->for($tenant)->create(['status' => 'active', 'platform' => 'rakuten', 'name' => 'Rakuten Shop']);
        $shopify = Shop::factory()->for($tenant)->create(['status' => 'active', 'platform' => 'shopify', 'name' => 'Shopify Shop']);
        $amazonSku = Sku::factory()->for($tenant)->for($amazon)->for(StockItem::factory()->for($tenant)->create())->create(['sku_type' => 'single']);
        $rakutenSku = Sku::factory()->for($tenant)->for($rakuten)->for(StockItem::factory()->for($tenant)->create())->create(['sku_type' => 'single']);
        $shopifySku = Sku::factory()->for($tenant)->for($shopify)->for(StockItem::factory()->for($tenant)->create())->create(['sku_type' => 'single']);
        $this->createPersistedOrder($amazon, $amazonSku, [
            'platform_order_id' => 'AMAZON-YAMATO',
            'shipping_method' => 'yamato',
            'shipping_method_id' => $yamato->id,
            'fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_READY,
            'order_status' => SalesOrder::ORDER_STATUS_PENDING,
        ]);
        $this->createPersistedOrder($rakuten, $rakutenSku, [
            'platform_order_id' => 'RAKUTEN-SAGAWA',
            'shipping_method' => 'sagawa',
            'shipping_method_id' => $sagawa->id,
            'fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_ARRANGED,
            'order_status' => SalesOrder::ORDER_STATUS_ON_HOLD,
        ]);
        $this->createPersistedOrder($shopify, $shopifySku, [
            'platform_order_id' => 'SHOPIFY-POST',
            'shipping_method' => 'japan_post',
            'shipping_method_id' => $japanPost->id,
            'fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_UNFULFILLED,
            'order_status' => SalesOrder::ORDER_STATUS_BACKORDER,
        ]);

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderIndex::class)
            ->set('platforms', ['amazon', 'rakuten'])
            ->set('shopIds', [(string) $amazon->id, (string) $rakuten->id])
            ->set('fulfillmentStatusesFilter', [SalesOrder::FULFILLMENT_STATUS_READY, SalesOrder::FULFILLMENT_STATUS_ARRANGED])
            ->set('orderStatusesFilter', [SalesOrder::ORDER_STATUS_PENDING, SalesOrder::ORDER_STATUS_ON_HOLD])
            ->set('shippingMethodsFilter', [(string) $yamato->id, (string) $sagawa->id])
            ->assertSee('AMAZON-YAMATO')
            ->assertSee('RAKUTEN-SAGAWA')
            ->assertDontSee('SHOPIFY-POST');
    }

    public function test_sales_order_index_filters_by_unset_shipping_method(): void
    {
        [, $shop, $sku] = $this->salesSku();
        $yamato = ShippingMethod::where('code', 'yamato_nekopos')->firstOrFail();
        $this->createPersistedOrder($shop, $sku, ['platform_order_id' => 'NO-SHIP', 'shipping_method' => null]);
        $this->createPersistedOrder($shop, $sku, [
            'platform_order_id' => 'HAS-SHIP',
            'shipping_method' => 'yamato',
            'shipping_method_id' => $yamato->id,
        ]);

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderIndex::class)
            ->set('shippingMethodsFilter', [SalesOrderFilters::EMPTY_SHIPPING])
            ->assertSee('NO-SHIP')
            ->assertDontSee('HAS-SHIP');
    }

    public function test_sales_order_index_filters_by_order_date_preset_and_custom_range(): void
    {
        [, $shop, $sku] = $this->salesSku();
        $this->createPersistedOrder($shop, $sku, ['platform_order_id' => 'RECENT-DATE', 'order_date' => now()->subDays(3)]);
        $this->createPersistedOrder($shop, $sku, ['platform_order_id' => 'OLD-DATE', 'order_date' => now()->subDays(20)]);

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderIndex::class)
            ->set('dateRange', SalesOrderFilters::DATE_LAST_7_DAYS)
            ->assertSee('RECENT-DATE')
            ->assertDontSee('OLD-DATE')
            ->set('dateRange', SalesOrderFilters::DATE_CUSTOM)
            ->set('dateFrom', now()->subDays(25)->toDateString())
            ->set('dateTo', now()->subDays(10)->toDateString())
            ->assertDontSee('RECENT-DATE')
            ->assertSee('OLD-DATE');
    }

    public function test_sales_order_index_filter_changes_clear_selection(): void
    {
        [, $shop, $sku] = $this->salesSku();
        $order = $this->createPersistedOrder($shop, $sku, ['platform_order_id' => 'CLEAR-SELECTION']);

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderIndex::class)
            ->set('selectedIds', [(string) $order->id])
            ->set('search', 'CLEAR')
            ->assertSet('selectedIds', []);
    }

    public function test_sales_order_index_filter_chips_include_new_filters_without_export_all_links(): void
    {
        [, $shop, $sku] = $this->salesSku();
        $yamato = ShippingMethod::where('code', 'yamato_nekopos')->firstOrFail();
        $this->createPersistedOrder($shop, $sku, ['platform_order_id' => 'EXPORT-LINK']);

        $html = Livewire::actingAs($this->internalUser())
            ->test(SalesOrderIndex::class)
            ->set('platforms', ['amazon'])
            ->set('shopIds', [(string) $shop->id])
            ->set('fulfillmentStatusesFilter', [SalesOrder::FULFILLMENT_STATUS_READY])
            ->set('orderStatusesFilter', [SalesOrder::ORDER_STATUS_PENDING])
            ->set('shippingMethodsFilter', [(string) $yamato->id])
            ->set('othersFilter', [SalesOrderFilters::OTHER_MULTI_ITEM])
            ->set('dateRange', SalesOrderFilters::DATE_LAST_7_DAYS)
            ->set('search', 'EXPORT')
            ->html();

        $this->assertStringContainsString('Platform: amazon', $html);
        $this->assertStringContainsString('Shop: '.$shop->tenant->code.' / '.$shop->name, $html);
        $this->assertStringContainsString('Ship status: Ship Ready', $html);
        $this->assertStringContainsString('Order Status: Pending', $html);
        $this->assertStringContainsString('Shipping: Yamato Nekopos', $html);
        $this->assertStringContainsString('Others: Multi-item order', $html);
        $this->assertStringContainsString('Order Date: Last 7 days', $html);
        $this->assertStringContainsString('Search: EXPORT', $html);
        $this->assertStringNotContainsString('Export all', $html);
    }

    public function test_sales_order_index_updates_shipping_method_with_tenant_scope(): void
    {
        [$ownTenant, $tenantUser] = $this->tenantUser();
        $ownShop = Shop::factory()->for($ownTenant)->create(['status' => 'active']);
        $ownSku = Sku::factory()->for($ownTenant)->for($ownShop)->for(StockItem::factory()->for($ownTenant)->create())->create(['sku_type' => 'single']);
        $ownOrder = $this->createPersistedOrder($ownShop, $ownSku, ['platform_order_id' => 'OWN-SHIP-METHOD']);
        [, $otherShop, $otherSku] = $this->salesSku();
        $otherOrder = $this->createPersistedOrder($otherShop, $otherSku, ['platform_order_id' => 'OTHER-SHIP-METHOD']);
        $yamato = ShippingMethod::where('code', 'yamato_nekopos')->firstOrFail();
        $sagawa = ShippingMethod::where('code', 'sagawa_thb')->firstOrFail();
        $japanPost = ShippingMethod::where('code', 'japan_post_yupack')->firstOrFail();

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderIndex::class)
            ->call('updateShippingMethod', $ownOrder->id, (string) $yamato->id);

        $this->assertSame($yamato->id, $ownOrder->refresh()->shipping_method_id);
        $this->assertSame('yamato', $ownOrder->refresh()->shipping_method);

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderIndex::class)
            ->call('updateShippingMethod', $ownOrder->id, 'unknown_method');

        $this->assertSame($yamato->id, $ownOrder->refresh()->shipping_method_id);
        $this->assertSame('yamato', $ownOrder->refresh()->shipping_method);

        Livewire::actingAs($tenantUser)
            ->test(SalesOrderIndex::class)
            ->call('updateShippingMethod', $ownOrder->id, (string) $sagawa->id);

        $this->assertSame($sagawa->id, $ownOrder->refresh()->shipping_method_id);
        $this->assertSame('sagawa', $ownOrder->refresh()->shipping_method);

        Livewire::actingAs($tenantUser)
            ->test(SalesOrderIndex::class)
            ->call('updateShippingMethod', $otherOrder->id, (string) $japanPost->id);

        $this->assertNull($otherOrder->refresh()->shipping_method);
        $this->assertNull($otherOrder->refresh()->shipping_method_id);
    }

    public function test_sales_order_index_tracking_no_is_read_only_synced_text(): void
    {
        [, $shop, $sku] = $this->salesSku();
        $this->createPersistedOrder($shop, $sku, [
            'platform_order_id' => 'READONLY-TRACKING',
            'tracking_no' => 'TRACK-READ-1',
        ]);

        $html = Livewire::actingAs($this->internalUser())
            ->test(SalesOrderIndex::class)
            ->html();

        $this->assertStringContainsString('TRACK-READ-1', $html);
        $this->assertStringContainsString('class="tracking-readonly"', $html);
        $this->assertStringNotContainsString('wire:model.live.debounce.800ms="trackingDrafts.', $html);
        $this->assertStringNotContainsString('wire:target="trackingDrafts.', $html);
        $this->assertStringNotContainsString('wire:click="openTrackingImportModal"', $html);
        $this->assertStringNotContainsString('route(\'sales.orders.tracking-import\')', $html);
    }

    public function test_sales_order_index_combines_shipping_and_tracking_and_shows_method_names(): void
    {
        [, $shop, $sku] = $this->salesSku();
        $this->createPersistedOrder($shop, $sku, ['platform_order_id' => 'SHIP-TRACK-CELL']);

        $html = Livewire::actingAs($this->internalUser())
            ->test(SalesOrderIndex::class)
            ->html();

        $this->assertStringContainsString(__('sales_orders.col_shipping_tracking'), $html);
        $this->assertStringNotContainsString('<div class="flex in-[.group\\/center-align]:justify-center in-[.group\\/end-align]:justify-end">'.__('sales_orders.col_tracking_no').'</div>', $html);
        $this->assertStringContainsString('Yamato Nekopos', $html);
        $this->assertMatchesRegularExpression('/<option value="\d+"\s*>\s*Yamato Nekopos\s*<\/option>/', $html);
        $this->assertDoesNotMatchRegularExpression('/<option value="\d+"\s*>\s*Yamato Nekopos \/ Yamato\s*<\/option>/', $html);
        $this->assertStringContainsString('class="shipping-tracking-stack"', $html);
        $this->assertStringContainsString('class="tracking-readonly"', $html);
    }

    public function test_sales_order_pages_show_packing_chip_when_active_outbound_is_printed(): void
    {
        [$tenant, $shop, $sku] = $this->salesSku();
        $warehouse = Warehouse::factory()->create(['status' => 'active']);
        app(InventoryService::class)->adjustStock($tenant->id, $warehouse->id, $sku->stock_item_id, 10);
        $printed = $this->createPersistedOrder($shop, $sku, [
            'platform_order_id' => 'PACKING-ORDER',
            'fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_READY,
        ]);
        $notGrouped = $this->createPersistedOrder($shop, $sku, ['platform_order_id' => 'NOT-PACKING-ORDER']);
        $outbound = app(GroupSalesOrdersService::class)->createGroup($tenant->id, $warehouse->id, [$printed->id]);
        $outbound->update(['courier_csv_exported_at' => '2026-06-18 10:00:00']);

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderIndex::class)
            ->assertSee('PACKING-ORDER')
            ->assertSee(__('sales_orders.label_packing'))
            ->assertSee('NOT-PACKING-ORDER');

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderDetail::class, ['order' => $printed])
            ->assertSee(__('sales_orders.label_packing'));

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderDetail::class, ['order' => $notGrouped])
            ->assertDontSee(__('sales_orders.label_packing'));
    }

    public function test_sales_order_index_has_no_view_action_column(): void
    {
        [, $shop, $sku] = $this->salesSku();
        $this->createPersistedOrder($shop, $sku, ['platform_order_id' => 'NO-VIEW-ACTION']);

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderIndex::class)
            ->assertSee('NO-VIEW-ACTION')
            ->assertDontSee(__('sales_orders.col_actions'))
            ->assertDontSee(__('sales_orders.btn_view_order'));
    }

    public function test_sales_order_index_empty_state_spans_all_columns(): void
    {
        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderIndex::class)
            ->set('search', 'no matching order')
            ->assertSee('colspan="9"', false)
            ->assertSee(__('sales_orders.empty_state'));
    }

    public function test_sales_order_detail_cancel_button_is_in_actions_header_area(): void
    {
        [, $shop, $sku] = $this->salesSku();
        $order = $this->createPersistedOrder($shop, $sku, [
            'platform_order_id' => 'DETAIL-ACTIONS',
            'fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_READY,
        ]);

        $html = Livewire::actingAs($this->internalUser())
            ->test(SalesOrderDetail::class, ['order' => $order])
            ->html();

        $this->assertStringContainsString('data-testid="sales-order-detail-actions"', $html);
        $this->assertStringContainsString('data-testid="sales-order-detail-actions-danger"', $html);
        $this->assertStringContainsString(__('sales_orders.btn_cancel_order'), $html);

        preg_match('/data-testid="sales-order-detail-bottom-actions"[\s\S]*?<\/div>/', $html, $bottomMatches);
        $this->assertNotEmpty($bottomMatches);
        $this->assertStringContainsString(__('sales_orders.btn_back_orders'), $bottomMatches[0]);
        $this->assertStringNotContainsString(__('sales_orders.btn_cancel_order'), $bottomMatches[0]);
    }

    public function test_sales_order_detail_main_actions_use_teal_style(): void
    {
        [, $shop, $sku] = $this->salesSku();
        $readyOrder = $this->createPersistedOrder($shop, $sku, [
            'platform_order_id' => 'DETAIL-STYLE',
            'fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_READY,
        ]);

        $html = Livewire::actingAs($this->internalUser())
            ->test(SalesOrderDetail::class, ['order' => $readyOrder])
            ->html();

        $this->assertStringContainsString('wire:click="unmarkReady"', $html);
        $this->assertStringContainsString('wire:click="hold"', $html);
        $this->assertStringContainsString('wire:click="markBackorder"', $html);
        $this->assertStringContainsString('wire:click="editLines"', $html);
        $this->assertSame(4, substr_count($html, 'data-action-variant="primary"'));
        $this->assertStringContainsString('data-action-variant="danger"', $html);
    }

    public function test_sales_order_detail_does_not_expose_mark_shipped_action(): void
    {
        [, $shop, $sku] = $this->salesSku();
        $ready = $this->createPersistedOrder($shop, $sku, [
            'platform_order_id' => 'DETAIL-SHIP-READY',
            'fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_READY,
        ]);

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderDetail::class, ['order' => $ready])
            ->assertDontSee(__('sales_orders.btn_mark_shipped'));

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderIndex::class)
            ->set('search', 'DETAIL-SHIP-READY')
            ->assertSee('DETAIL-SHIP-READY');
    }

    public function test_sales_order_detail_edit_recipient_button_sits_below_recipient_header(): void
    {
        [, $shop, $sku] = $this->salesSku();
        $order = $this->createPersistedOrder($shop, $sku, [
            'platform_order_id' => 'DETAIL-RECIPIENT',
        ]);

        $html = Livewire::actingAs($this->internalUser())
            ->test(SalesOrderDetail::class, ['order' => $order])
            ->html();

        $recipientTitlePosition = strpos($html, '<strong>'.__('sales_orders.field_recipient').'</strong>');
        $editRecipientPosition = strpos($html, 'data-testid="edit-recipient-button"');

        $this->assertNotFalse($recipientTitlePosition);
        $this->assertNotFalse($editRecipientPosition);
        $this->assertGreaterThan($recipientTitlePosition, $editRecipientPosition);
        $this->assertStringContainsString('wire:click="editRecipient"', $html);
    }

    public function test_sales_order_detail_line_ready_status_label_is_ship_ready(): void
    {
        [, $shop, $sku] = $this->salesSku();
        $order = $this->createPersistedOrder($shop, $sku, ['platform_order_id' => 'LINE-SHIP-READY']);

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderDetail::class, ['order' => $order])
            ->assertSee('Ship Ready')
            ->assertDontSee('>Ready<', false);
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
            'fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_UNFULFILLED,
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

    private function legacyShipTogetherKey(SalesOrder $order): ?string
    {
        if (empty(trim((string) $order->recipient_address_line1))) {
            return null;
        }

        return md5(implode('|', [
            $order->tenant_id,
            $order->shop_id,
            strtolower(trim((string) $order->recipient_name)),
            strtolower(trim((string) $order->recipient_country_code)),
            strtolower(trim((string) $order->recipient_postal_code)),
            strtolower(trim((string) $order->recipient_state)),
            strtolower(trim((string) $order->recipient_city)),
            strtolower(trim((string) $order->recipient_address_line1)),
            strtolower(trim((string) $order->recipient_address_line2)),
        ]));
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
