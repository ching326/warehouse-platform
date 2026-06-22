<?php

namespace Tests\Feature;

use App\Livewire\SalesOrderIndex;
use App\Models\FulfillmentGroup;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\Shop;
use App\Models\Sku;
use App\Models\StockItem;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SalesOrderIndexBulkTest extends TestCase
{
    use RefreshDatabase;

    public function test_bulk_hold_holds_eligible_pending_orders(): void
    {
        [, $shop, $sku] = $this->salesSku('BULK-HOLD');
        $unfulfilled = $this->orderWithLines($shop, $sku);
        $ready = $this->orderWithLines($shop, $sku, ['fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_READY]);

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderIndex::class)
            ->set('selectedIds', [(string) $unfulfilled->id, (string) $ready->id])
            ->call('bulkHold')
            ->assertSet('selectedIds', []);

        foreach ([$unfulfilled, $ready] as $order) {
            $order->refresh();
            $this->assertSame(SalesOrder::ORDER_STATUS_ON_HOLD, $order->order_status);
            $this->assertSame(SalesOrder::FULFILLMENT_STATUS_UNFULFILLED, $order->fulfillment_status);
        }
    }

    public function test_bulk_hold_skips_in_group_and_shipped_orders(): void
    {
        [, $shop, $sku] = $this->salesSku('BULK-HOLD-SKIP');
        $inGroup = $this->orderWithLines($shop, $sku, ['fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_IN_GROUP]);
        $shipped = $this->orderWithLines($shop, $sku, ['fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_SHIPPED]);

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderIndex::class)
            ->set('selectedIds', [(string) $inGroup->id, (string) $shipped->id])
            ->call('bulkHold');

        $this->assertSame(SalesOrder::ORDER_STATUS_PENDING, $inGroup->refresh()->order_status);
        $this->assertSame(SalesOrder::ORDER_STATUS_PENDING, $shipped->refresh()->order_status);
    }

    public function test_bulk_hold_skips_orders_in_an_active_fulfillment_group(): void
    {
        [$tenant, $shop, $sku] = $this->salesSku('BULK-HOLD-GROUPED');
        $warehouse = Warehouse::factory()->create(['status' => 'active']);
        $order = $this->orderWithLines($shop, $sku, ['fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_READY]);

        $group = FulfillmentGroup::factory()->for($tenant)->for($warehouse)->create([
            'status' => FulfillmentGroup::STATUS_RESERVED,
        ]);
        $group->orders()->attach($order->id);

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderIndex::class)
            ->set('selectedIds', [(string) $order->id])
            ->call('bulkHold');

        $order->refresh();
        $this->assertSame(SalesOrder::ORDER_STATUS_PENDING, $order->order_status);
        $this->assertSame(SalesOrder::FULFILLMENT_STATUS_READY, $order->fulfillment_status);
    }

    public function test_bulk_hold_skips_non_pending_orders(): void
    {
        [, $shop, $sku] = $this->salesSku('BULK-HOLD-NON-PENDING');
        $onHold = $this->orderWithLines($shop, $sku, ['order_status' => SalesOrder::ORDER_STATUS_ON_HOLD]);
        $backorder = $this->orderWithLines($shop, $sku, ['order_status' => SalesOrder::ORDER_STATUS_BACKORDER]);

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderIndex::class)
            ->set('selectedIds', [(string) $onHold->id, (string) $backorder->id])
            ->call('bulkHold');

        $this->assertSame(SalesOrder::ORDER_STATUS_ON_HOLD, $onHold->refresh()->order_status);
        $this->assertSame(SalesOrder::ORDER_STATUS_BACKORDER, $backorder->refresh()->order_status);
    }

    public function test_bulk_release_hold_returns_on_hold_to_pending(): void
    {
        [, $shop, $sku] = $this->salesSku('BULK-RELEASE');
        $onHold = $this->orderWithLines($shop, $sku, ['order_status' => SalesOrder::ORDER_STATUS_ON_HOLD]);
        $pending = $this->orderWithLines($shop, $sku);

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderIndex::class)
            ->set('selectedIds', [(string) $onHold->id, (string) $pending->id])
            ->call('bulkReleaseHold');

        $this->assertSame(SalesOrder::ORDER_STATUS_PENDING, $onHold->refresh()->order_status);
        $this->assertSame(SalesOrder::ORDER_STATUS_PENDING, $pending->refresh()->order_status);
    }

    public function test_bulk_cancel_cancels_orders_and_their_lines(): void
    {
        [, $shop, $sku] = $this->salesSku('BULK-CANCEL');
        $order = $this->orderWithLines($shop, $sku);
        $order->lines()->create([
            'sku_id' => $sku->id,
            'quantity' => 2,
            'line_status' => SalesOrderLine::STATUS_READY,
        ]);

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderIndex::class)
            ->set('selectedIds', [(string) $order->id])
            ->call('bulkCancel');

        $order->refresh();
        $this->assertSame(SalesOrder::ORDER_STATUS_CANCELLED, $order->order_status);
        $this->assertSame(SalesOrder::FULFILLMENT_STATUS_CANCELLED, $order->fulfillment_status);
        $this->assertSame([SalesOrderLine::STATUS_CANCELLED, SalesOrderLine::STATUS_CANCELLED], $order->lines()->pluck('line_status')->all());
    }

    public function test_bulk_cancel_skips_completed_and_cancelled_and_in_group(): void
    {
        [, $shop, $sku] = $this->salesSku('BULK-CANCEL-SKIP');
        $completed = $this->orderWithLines($shop, $sku, ['order_status' => SalesOrder::ORDER_STATUS_COMPLETED]);
        $cancelled = $this->orderWithLines($shop, $sku, [
            'order_status' => SalesOrder::ORDER_STATUS_CANCELLED,
            'fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_CANCELLED,
        ]);
        $inGroup = $this->orderWithLines($shop, $sku, ['fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_IN_GROUP]);

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderIndex::class)
            ->set('selectedIds', [(string) $completed->id, (string) $cancelled->id, (string) $inGroup->id])
            ->call('bulkCancel');

        foreach ([$completed, $cancelled, $inGroup] as $order) {
            $this->assertSame(SalesOrderLine::STATUS_READY, $order->lines()->firstOrFail()->line_status);
        }
    }

    public function test_bulk_actions_only_affect_allowed_tenant(): void
    {
        [$tenant, $user] = $this->tenantUser();
        $shop = Shop::factory()->for($tenant)->create(['status' => 'active']);
        $ownSku = $this->skuForShop($tenant, $shop, 'OWN-BULK-ACTION');
        $ownOrder = $this->orderWithLines($shop, $ownSku);
        [, $otherShop, $otherSku] = $this->salesSku('OTHER-BULK-ACTION');
        $otherOrder = $this->orderWithLines($otherShop, $otherSku);

        Livewire::actingAs($user)
            ->test(SalesOrderIndex::class)
            ->set('selectedIds', [(string) $ownOrder->id, (string) $otherOrder->id])
            ->call('bulkHold');

        $this->assertSame(SalesOrder::ORDER_STATUS_ON_HOLD, $ownOrder->refresh()->order_status);
        $this->assertSame(SalesOrder::ORDER_STATUS_PENDING, $otherOrder->refresh()->order_status);
    }

    public function test_bulk_mark_ready_marks_order_when_all_fulfillable_lines_are_ready(): void
    {
        [$tenant, $shop, $sku] = $this->salesSku('BULK-READY-ALL');
        $extraSku = $this->skuForShop($tenant, $shop, 'BULK-READY-ALL-EXTRA');
        $warehouse = $this->warehouseWithStock($tenant, [$sku, $extraSku]);
        $order = $this->orderWithLines($shop, $sku);
        $order->lines()->create([
            'sku_id' => $extraSku->id,
            'quantity' => 2,
            'line_status' => SalesOrderLine::STATUS_READY,
        ]);

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderIndex::class)
            ->set('selectedIds', [(string) $order->id])
            ->call('bulkMarkReady')
            ->assertSee(__('sales_orders.bulk_ready_result', ['updated' => 1, 'skipped' => 0]));

        $this->assertSame(SalesOrder::FULFILLMENT_STATUS_IN_GROUP, $order->refresh()->fulfillment_status);
        $this->assertDatabaseHas('fulfillment_group_orders', [
            'sales_order_id' => $order->id,
        ]);
        $this->assertNotNull($order->fulfillmentGroupOrders()->firstOrFail()->arranged_at);
        $this->assertDatabaseHas('inventory_balances', [
            'tenant_id' => $tenant->id,
            'warehouse_id' => $warehouse->id,
            'stock_item_id' => $sku->stock_item_id,
            'reserved_qty' => 1,
        ]);
    }

    public function test_bulk_mark_ready_skips_order_with_non_ready_fulfillable_line(): void
    {
        [$tenant, $shop, $sku] = $this->salesSku('BULK-READY-PARTIAL');
        $extraSku = $this->skuForShop($tenant, $shop, 'BULK-READY-PARTIAL-EXTRA');
        $order = $this->orderWithLines($shop, $sku);
        $order->lines()->create([
            'sku_id' => $extraSku->id,
            'quantity' => 1,
            'line_status' => 'pending',
        ]);

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderIndex::class)
            ->set('selectedIds', [(string) $order->id])
            ->call('bulkMarkReady')
            ->assertSee(__('sales_orders.bulk_ready_result', ['updated' => 0, 'skipped' => 1]));

        $this->assertSame(SalesOrder::FULFILLMENT_STATUS_UNFULFILLED, $order->refresh()->fulfillment_status);
    }

    public function test_bulk_mark_ready_prompts_and_combines_ready_candidates(): void
    {
        [$tenant, $shop, $sku] = $this->salesSku('BULK-COMBINE');
        $this->warehouseWithStock($tenant, [$sku]);
        $orderA = $this->orderWithLines($shop, $sku);
        $orderB = $this->orderWithLines($shop, $sku);

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderIndex::class)
            ->set('selectedIds', [(string) $orderA->id, (string) $orderB->id])
            ->call('bulkMarkReady')
            ->assertSet('showReadyCombinePrompt', true)
            ->assertSet('pendingReadyCombineCandidateCount', 2)
            ->call('confirmReadyCombine')
            ->assertSet('showReadyCombinePrompt', false);

        $this->assertSame(1, FulfillmentGroup::count());
        $this->assertSame(
            [SalesOrder::FULFILLMENT_STATUS_IN_GROUP, SalesOrder::FULFILLMENT_STATUS_IN_GROUP],
            SalesOrder::whereKey([$orderA->id, $orderB->id])->orderBy('id')->pluck('fulfillment_status')->all(),
        );
    }

    public function test_bulk_mark_ready_decline_creates_single_order_groups(): void
    {
        [$tenant, $shop, $sku] = $this->salesSku('BULK-DECLINE');
        $this->warehouseWithStock($tenant, [$sku]);
        $orderA = $this->orderWithLines($shop, $sku);
        $orderB = $this->orderWithLines($shop, $sku);

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderIndex::class)
            ->set('selectedIds', [(string) $orderA->id, (string) $orderB->id])
            ->call('bulkMarkReady')
            ->assertSet('showReadyCombinePrompt', true)
            ->call('declineReadyCombine')
            ->assertSet('showReadyCombinePrompt', false);

        $this->assertSame(2, FulfillmentGroup::count());
        $this->assertSame(2, SalesOrder::whereIn('id', [$orderA->id, $orderB->id])
            ->where('fulfillment_status', SalesOrder::FULFILLMENT_STATUS_IN_GROUP)
            ->count());
    }

    public function test_bulk_mark_ready_joins_existing_group_when_confirmed(): void
    {
        [$tenant, $shop, $sku] = $this->salesSku('BULK-JOIN');
        $warehouse = $this->warehouseWithStock($tenant, [$sku]);
        $grouped = $this->orderWithLines($shop, $sku, ['fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_READY]);
        $group = app(\App\Services\Fulfillment\GroupSalesOrdersService::class)
            ->singleOrderGroup($tenant->id, $warehouse->id, $grouped->id);
        $newOrder = $this->orderWithLines($shop, $sku);

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderIndex::class)
            ->set('selectedIds', [(string) $newOrder->id])
            ->call('bulkMarkReady')
            ->assertSet('showReadyCombinePrompt', true)
            ->assertSet('pendingReadyJoinableGroupCount', 1)
            ->call('confirmReadyCombine');

        $this->assertSame(1, FulfillmentGroup::count());
        $this->assertSame([$grouped->id, $newOrder->id], $group->refresh()->orders()->orderBy('sales_orders.id')->pluck('sales_orders.id')->all());
        $this->assertSame(SalesOrder::FULFILLMENT_STATUS_IN_GROUP, $newOrder->refresh()->fulfillment_status);
    }

    public function test_bulk_mark_ready_does_not_group_unfulfilled_suggestions(): void
    {
        [$tenant, $shop, $sku] = $this->salesSku('BULK-SUGGESTION');
        $this->warehouseWithStock($tenant, [$sku]);
        $readyNow = $this->orderWithLines($shop, $sku);
        $notSelected = $this->orderWithLines($shop, $sku);

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderIndex::class)
            ->set('selectedIds', [(string) $readyNow->id])
            ->call('bulkMarkReady')
            ->assertSet('showReadyCombinePrompt', false);

        $this->assertSame(SalesOrder::FULFILLMENT_STATUS_IN_GROUP, $readyNow->refresh()->fulfillment_status);
        $this->assertSame(SalesOrder::FULFILLMENT_STATUS_UNFULFILLED, $notSelected->refresh()->fulfillment_status);
        $this->assertSame(1, FulfillmentGroup::count());
    }

    public function test_bulk_mark_ready_requires_warehouse_selection_when_multiple_active_warehouses_exist(): void
    {
        [$tenant, $shop, $sku] = $this->salesSku('BULK-WAREHOUSE');
        $this->warehouseWithStock($tenant, [$sku]);
        Warehouse::factory()->create(['status' => 'active']);
        $order = $this->orderWithLines($shop, $sku);

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderIndex::class)
            ->set('selectedIds', [(string) $order->id])
            ->call('bulkMarkReady')
            ->assertSet('showReadyCombinePrompt', true)
            ->assertSet('readyWarehouseId', '');

        $this->assertSame(SalesOrder::FULFILLMENT_STATUS_UNFULFILLED, $order->refresh()->fulfillment_status);
    }

    public function test_bulk_mark_ready_respects_consolidation_modes(): void
    {
        [$tenant, $shopA, $skuA] = $this->salesSku('BULK-CONSOL-A');
        $shopB = Shop::factory()->for($tenant)->create([
            'status' => 'active',
            'consolidation_mode' => Shop::CONSOLIDATION_SAME_SHOP,
        ]);
        $skuB = $this->skuForShop($tenant, $shopB, 'BULK-CONSOL-B');
        $this->warehouseWithStock($tenant, [$skuA, $skuB]);
        $orderA = $this->orderWithLines($shopA, $skuA);
        $orderB = $this->orderWithLines($shopB, $skuB);

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderIndex::class)
            ->set('selectedIds', [(string) $orderA->id, (string) $orderB->id])
            ->call('bulkMarkReady');

        $this->assertSame(2, FulfillmentGroup::count());

        $shopA->update(['consolidation_mode' => Shop::CONSOLIDATION_CROSS_SHOP]);
        $shopB->update(['consolidation_mode' => Shop::CONSOLIDATION_CROSS_SHOP]);
        $crossOrderA = $this->orderWithLines($shopA, $skuA, ['platform_order_id' => 'BULK-CONSOL-CROSS-A']);
        $crossOrderB = $this->orderWithLines($shopB, $skuB, ['platform_order_id' => 'BULK-CONSOL-CROSS-B']);

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderIndex::class)
            ->set('selectedIds', [(string) $crossOrderA->id, (string) $crossOrderB->id])
            ->call('bulkMarkReady')
            ->assertSet('showReadyCombinePrompt', true)
            ->call('confirmReadyCombine');

        $this->assertSame(2, FulfillmentGroup::count());
        $this->assertSame(1, FulfillmentGroup::whereHas('orders', fn ($query) => $query
            ->whereIn('sales_orders.id', [$crossOrderA->id, $crossOrderB->id]))->count());
    }

    public function test_bulk_mark_ready_ignores_cancelled_lines(): void
    {
        [$tenant, $shop, $sku] = $this->salesSku('BULK-READY-CANCELLED');
        $this->warehouseWithStock($tenant, [$sku]);
        $cancelledSku = $this->skuForShop($tenant, $shop, 'BULK-READY-CANCELLED-EXTRA');
        $order = $this->orderWithLines($shop, $sku);
        $order->lines()->create([
            'sku_id' => $cancelledSku->id,
            'quantity' => 1,
            'line_status' => SalesOrderLine::STATUS_CANCELLED,
        ]);

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderIndex::class)
            ->set('selectedIds', [(string) $order->id])
            ->call('bulkMarkReady')
            ->assertSee(__('sales_orders.bulk_ready_result', ['updated' => 1, 'skipped' => 0]));

        $this->assertSame(SalesOrder::FULFILLMENT_STATUS_IN_GROUP, $order->refresh()->fulfillment_status);
    }

    public function test_bulk_actions_noop_on_empty_selection(): void
    {
        [, $shop, $sku] = $this->salesSku('BULK-NOOP');
        $order = $this->orderWithLines($shop, $sku);

        foreach (['bulkHold', 'bulkReleaseHold', 'bulkCancel'] as $method) {
            Livewire::actingAs($this->internalUser())
                ->test(SalesOrderIndex::class)
                ->call($method);
        }

        $this->assertSame(SalesOrder::ORDER_STATUS_PENDING, $order->refresh()->order_status);
        $this->assertSame(SalesOrderLine::STATUS_READY, $order->lines()->firstOrFail()->line_status);
    }

    public function test_bulk_result_reports_updated_and_skipped_counts(): void
    {
        [, $shop, $sku] = $this->salesSku('BULK-COUNTS');
        $eligible = $this->orderWithLines($shop, $sku);
        $ineligible = $this->orderWithLines($shop, $sku, ['fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_IN_GROUP]);

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderIndex::class)
            ->set('selectedIds', [(string) $eligible->id, (string) $ineligible->id])
            ->call('bulkHold')
            ->assertSee(__('sales_orders.bulk_hold_result', ['updated' => 1, 'skipped' => 1]));
    }

    /**
     * @return array{0: Tenant, 1: Shop, 2: Sku}
     */
    private function salesSku(string $skuCode): array
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        $shop = Shop::factory()->for($tenant)->create(['status' => 'active']);
        $sku = $this->skuForShop($tenant, $shop, $skuCode);

        return [$tenant, $shop, $sku];
    }

    private function skuForShop(Tenant $tenant, Shop $shop, string $skuCode): Sku
    {
        return Sku::factory()
            ->for($tenant)
            ->for($shop)
            ->for(StockItem::factory()->for($tenant)->create())
            ->create(['sku_type' => 'single', 'sku' => $skuCode, 'status' => 'active']);
    }

    private function orderWithLines(Shop $shop, Sku $sku, array $attributes = []): SalesOrder
    {
        $order = SalesOrder::factory()->for($shop->tenant)->for($shop)->create(array_merge([
            'order_status' => SalesOrder::ORDER_STATUS_PENDING,
            'fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_UNFULFILLED,
            'recipient_name' => 'Shared Recipient',
            'recipient_country_code' => 'JP',
            'recipient_postal_code' => '1000001',
            'recipient_state' => 'Tokyo',
            'recipient_city' => 'Tokyo',
            'recipient_address_line1' => '1 Shared Street',
            'recipient_address_line2' => '',
        ], $attributes));
        $order->recalculateShipTogetherKey();
        $order->save();

        $order->lines()->create([
            'sku_id' => $sku->id,
            'quantity' => 1,
            'line_status' => SalesOrderLine::STATUS_READY,
        ]);

        return $order;
    }

    private function warehouseWithStock(Tenant $tenant, array $skus): Warehouse
    {
        $warehouse = Warehouse::factory()->create(['status' => 'active']);

        foreach ($skus as $sku) {
            if ($sku->stock_item_id) {
                app(InventoryService::class)->adjustStock($tenant->id, $warehouse->id, $sku->stock_item_id, 100);
            }
        }

        return $warehouse;
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

        TenantUser::factory()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'status' => 'active',
        ]);

        return [$tenant, $user];
    }
}
