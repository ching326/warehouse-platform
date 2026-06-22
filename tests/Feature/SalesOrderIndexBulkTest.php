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
