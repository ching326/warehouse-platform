<?php

namespace Tests\Feature;

use App\Livewire\SalesOrderCreate;
use App\Livewire\SalesOrderDetail;
use App\Livewire\SalesOrderIndex;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\Shop;
use App\Models\Sku;
use App\Models\SkuBundleComponent;
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

    public function test_unmark_ready_blocked_when_in_group(): void
    {
        [, $shop, $sku] = $this->salesSku();
        $order = $this->createPersistedOrder($shop, $sku, [
            'fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_IN_GROUP,
        ]);

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderDetail::class, ['order' => $order])
            ->call('unmarkReady');

        $this->assertSame(SalesOrder::FULFILLMENT_STATUS_IN_GROUP, $order->refresh()->fulfillment_status);
    }

    public function test_hold_succeeds_and_resets_fulfillment_to_unfulfilled(): void
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

    public function test_hold_blocked_when_in_group(): void
    {
        [, $shop, $sku] = $this->salesSku();
        $order = $this->createPersistedOrder($shop, $sku, [
            'fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_IN_GROUP,
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

    public function test_edit_lines_blocked_when_in_group(): void
    {
        [, $shop, $sku] = $this->salesSku();
        $order = $this->createPersistedOrder($shop, $sku, [
            'fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_IN_GROUP,
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
        $eligible = $this->createPersistedOrder($shop, $sku, ['platform_order_id' => 'BULK-READY']);
        $ineligible = $this->createPersistedOrder($shop, $sku, [
            'platform_order_id' => 'BULK-SKIP',
            'recipient_address_line1' => '',
        ]);

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderIndex::class)
            ->set('selectedIds', [(string) $eligible->id, (string) $ineligible->id])
            ->call('bulkMarkReady');

        $this->assertSame(SalesOrder::FULFILLMENT_STATUS_READY, $eligible->refresh()->fulfillment_status);
        $this->assertSame(SalesOrder::FULFILLMENT_STATUS_UNFULFILLED, $ineligible->refresh()->fulfillment_status);
    }

    public function test_bulk_mark_ready_ignores_other_tenant_orders(): void
    {
        [$ownTenant, $user] = $this->tenantUser();
        $ownShop = Shop::factory()->for($ownTenant)->create(['status' => 'active']);
        $ownSku = Sku::factory()->for($ownTenant)->for($ownShop)->for(StockItem::factory()->for($ownTenant)->create())->create(['sku_type' => 'single']);
        $ownOrder = $this->createPersistedOrder($ownShop, $ownSku, ['platform_order_id' => 'OWN-BULK']);
        [, $otherShop, $otherSku] = $this->salesSku();
        $otherOrder = $this->createPersistedOrder($otherShop, $otherSku, ['platform_order_id' => 'OTHER-BULK']);

        Livewire::actingAs($user)
            ->test(SalesOrderIndex::class)
            ->set('selectedIds', [(string) $ownOrder->id, (string) $otherOrder->id])
            ->call('bulkMarkReady');

        $this->assertSame(SalesOrder::FULFILLMENT_STATUS_READY, $ownOrder->refresh()->fulfillment_status);
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

    public function test_sales_order_index_sku_cell_shows_name_below_quantity_and_sku(): void
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
        $this->assertStringContainsString('Long Cable Name', $html);
        $this->assertStringContainsString('Fallback SKU Name', $html);
        $this->assertStringNotContainsString('- Long Cable Name', $html);
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
        $this->assertStringContainsString('Pending', $html);
        $this->assertStringContainsString('status-stack', $html);
    }

    public function test_sales_order_index_bulk_action_row_is_visible_with_no_selection(): void
    {
        [, $shop, $sku] = $this->salesSku();
        $this->createPersistedOrder($shop, $sku, ['platform_order_id' => 'BULK-ZERO']);

        $html = Livewire::actingAs($this->internalUser())
            ->test(SalesOrderIndex::class)
            ->html();

        $this->assertStringContainsString('0 orders selected', $html);
        $this->assertStringContainsString(__('sales_orders.btn_bulk_mark_ready'), $html);
        $this->assertStringContainsString(__('sales_orders.btn_bulk_hold'), $html);
        $this->assertStringContainsString(__('sales_orders.btn_bulk_cancel'), $html);
        $this->assertStringContainsString(__('sales_orders.btn_bulk_export_csv'), $html);
        $this->assertStringContainsString('disabled', $html);
        $this->assertStringNotContainsString('ids=', $html);
    }

    public function test_sales_order_index_updates_shipping_method_with_tenant_scope(): void
    {
        [$ownTenant, $tenantUser] = $this->tenantUser();
        $ownShop = Shop::factory()->for($ownTenant)->create(['status' => 'active']);
        $ownSku = Sku::factory()->for($ownTenant)->for($ownShop)->for(StockItem::factory()->for($ownTenant)->create())->create(['sku_type' => 'single']);
        $ownOrder = $this->createPersistedOrder($ownShop, $ownSku, ['platform_order_id' => 'OWN-SHIP-METHOD']);
        [, $otherShop, $otherSku] = $this->salesSku();
        $otherOrder = $this->createPersistedOrder($otherShop, $otherSku, ['platform_order_id' => 'OTHER-SHIP-METHOD']);

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderIndex::class)
            ->call('updateShippingMethod', $ownOrder->id, 'yamato');

        $this->assertSame('yamato', $ownOrder->refresh()->shipping_method);

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderIndex::class)
            ->call('updateShippingMethod', $ownOrder->id, 'unknown_method');

        $this->assertSame('yamato', $ownOrder->refresh()->shipping_method);

        Livewire::actingAs($tenantUser)
            ->test(SalesOrderIndex::class)
            ->call('updateShippingMethod', $ownOrder->id, 'sagawa');

        $this->assertSame('sagawa', $ownOrder->refresh()->shipping_method);

        Livewire::actingAs($tenantUser)
            ->test(SalesOrderIndex::class)
            ->call('updateShippingMethod', $otherOrder->id, 'japan_post');

        $this->assertNull($otherOrder->refresh()->shipping_method);
    }

    public function test_sales_order_index_updates_tracking_no_with_tenant_scope(): void
    {
        [$ownTenant, $tenantUser] = $this->tenantUser();
        $ownShop = Shop::factory()->for($ownTenant)->create(['status' => 'active']);
        $ownSku = Sku::factory()->for($ownTenant)->for($ownShop)->for(StockItem::factory()->for($ownTenant)->create())->create(['sku_type' => 'single']);
        $ownOrder = $this->createPersistedOrder($ownShop, $ownSku, ['platform_order_id' => 'OWN-TRACKING']);
        [, $otherShop, $otherSku] = $this->salesSku();
        $otherOrder = $this->createPersistedOrder($otherShop, $otherSku, ['platform_order_id' => 'OTHER-TRACKING']);

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderIndex::class)
            ->call('updateTrackingNo', $ownOrder->id, ' 1234567890 ');

        $this->assertSame('1234567890', $ownOrder->refresh()->tracking_no);

        Livewire::actingAs($tenantUser)
            ->test(SalesOrderIndex::class)
            ->call('updateTrackingNo', $ownOrder->id, 'SELLER-TRACK-1');

        $this->assertSame('SELLER-TRACK-1', $ownOrder->refresh()->tracking_no);

        Livewire::actingAs($tenantUser)
            ->test(SalesOrderIndex::class)
            ->call('updateTrackingNo', $otherOrder->id, 'SHOULD-NOT-SAVE');

        $this->assertNull($otherOrder->refresh()->tracking_no);
    }

    public function test_sales_order_index_saves_tracking_draft(): void
    {
        [, $shop, $sku] = $this->salesSku();
        $order = $this->createPersistedOrder($shop, $sku, ['platform_order_id' => 'DRAFT-TRACKING']);

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderIndex::class)
            ->set("trackingDrafts.{$order->id}", ' DRAFT-123 ')
            ->call('saveTrackingDraft', $order->id)
            ->assertSet("trackingDrafts.{$order->id}", 'DRAFT-123')
            ->assertSet("trackingSavedDrafts.{$order->id}", 'DRAFT-123');

        $this->assertSame('DRAFT-123', $order->refresh()->tracking_no);
    }

    public function test_sales_order_index_does_not_mark_tracking_draft_saved_when_order_is_out_of_scope(): void
    {
        [$ownTenant, $tenantUser] = $this->tenantUser();
        Shop::factory()->for($ownTenant)->create(['status' => 'active']);
        [, $otherShop, $otherSku] = $this->salesSku();
        $otherOrder = $this->createPersistedOrder($otherShop, $otherSku, ['platform_order_id' => 'OUT-OF-SCOPE-DRAFT']);

        Livewire::actingAs($tenantUser)
            ->test(SalesOrderIndex::class)
            ->set("trackingDrafts.{$otherOrder->id}", 'SHOULD-NOT-SAVE')
            ->call('saveTrackingDraft', $otherOrder->id)
            ->assertSet("trackingDrafts.{$otherOrder->id}", 'SHOULD-NOT-SAVE')
            ->assertNotSet("trackingSavedDrafts.{$otherOrder->id}", 'SHOULD-NOT-SAVE');

        $this->assertNull($otherOrder->refresh()->tracking_no);
    }

    public function test_sales_order_index_tracking_unsaved_indicator_uses_wire_dirty_label(): void
    {
        [, $shop, $sku] = $this->salesSku();
        $this->createPersistedOrder($shop, $sku, ['platform_order_id' => 'DIRTY-TRACKING']);

        $html = Livewire::actingAs($this->internalUser())
            ->test(SalesOrderIndex::class)
            ->html();

        $this->assertStringContainsString('class="tracking-field"', $html);
        $this->assertStringContainsString('class="tracking-unsaved"', $html);
        $this->assertStringContainsString('wire:dirty', $html);
        $this->assertStringContainsString('wire:target="trackingDrafts.', $html);
        $this->assertStringNotContainsString('trackingServerDirty', $html);
        $this->assertStringNotContainsString('class="so-unsaved"', $html);
    }

    public function test_sales_order_index_tracking_unsaved_indicator_has_wrapper(): void
    {
        [, $shop, $sku] = $this->salesSku();
        $order = $this->createPersistedOrder($shop, $sku, ['platform_order_id' => 'WRAPPED-TRACKING']);

        $html = Livewire::actingAs($this->internalUser())
            ->test(SalesOrderIndex::class)
            ->html();

        $trackingFieldPosition = strpos($html, 'class="tracking-field"');
        $trackingInputPosition = strpos($html, 'wire:model.live.debounce.800ms="trackingDrafts.'.$order->id.'"');
        $unsavedPosition = strpos($html, 'class="tracking-unsaved"');

        $this->assertNotFalse($trackingFieldPosition);
        $this->assertNotFalse($trackingInputPosition);
        $this->assertNotFalse($unsavedPosition);
        $this->assertGreaterThan($trackingFieldPosition, $trackingInputPosition);
        $this->assertGreaterThan($trackingInputPosition, $unsavedPosition);
    }

    public function test_sales_order_index_shows_printed_date_when_courier_csv_exported(): void
    {
        [, $shop, $sku] = $this->salesSku();
        $this->createPersistedOrder($shop, $sku, [
            'platform_order_id' => 'PRINTED-ORDER',
            'courier_csv_exported_at' => '2026-06-18 10:00:00',
        ]);

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderIndex::class)
            ->assertSee('Printed: 2026-06-18');
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
