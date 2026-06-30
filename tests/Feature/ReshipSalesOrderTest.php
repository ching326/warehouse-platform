<?php

namespace Tests\Feature;

use App\Livewire\FulfillmentIndex;
use App\Livewire\SalesOrderDetail;
use App\Livewire\SalesOrderIndex;
use App\Models\InventoryBalance;
use App\Models\Issue;
use App\Models\OutboundOrder;
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
use App\Services\Outbound\ReshipSalesOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class ReshipSalesOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_reship_creates_reserved_outbound_issue_and_reserves_stock(): void
    {
        [$salesOrder, $originalOutbound, $line, $stockItem, $warehouse] = $this->shippedSalesOrderWithLine();
        $salesOrder->update(['note' => 'Copy this sales order note']);
        $originalOutbound->update(['ship_note' => 'Do not copy this outbound ship note']);
        app(InventoryService::class)->adjustStock($salesOrder->tenant_id, $warehouse->id, $stockItem->id, 10);

        $reship = app(ReshipSalesOrderService::class)->reship(
            salesOrder: $salesOrder,
            originalOutbound: $originalOutbound,
            warehouseId: null,
            issueType: Issue::TYPE_MISSING,
            lines: [['sales_order_line_id' => $line->id, 'qty' => 2]],
            note: (string) $salesOrder->note,
        );

        $this->assertSame(OutboundOrder::REASON_RE_SHIP, $reship->reason);
        $this->assertSame(OutboundOrder::STATUS_RESERVED, $reship->status);
        $this->assertSame($salesOrder->id, $reship->source_sales_order_id);
        $this->assertSame($originalOutbound->id, $reship->reship_of_outbound_id);
        $this->assertSame($warehouse->id, $reship->warehouse_id);
        $this->assertSame($originalOutbound->shipping_method_id, $reship->shipping_method_id);
        $this->assertNull($reship->ship_note);
        $this->assertSame('Copy this sales order note', $reship->note);
        $this->assertStringStartsWith('OB-', (string) $reship->ref);
        $this->assertStringNotContainsString('PENDING', (string) $reship->ref);

        $issue = Issue::query()->findOrFail($reship->issue_id);
        $this->assertSame(Issue::TYPE_MISSING, $issue->issue_type);
        $this->assertSame(Issue::STATUS_OPEN, $issue->status);
        $this->assertSame($salesOrder->id, $issue->sales_order_id);
        $this->assertSame($originalOutbound->id, $issue->outbound_order_id);
        $this->assertStringStartsWith('ISS-', (string) $issue->issue_no);
        $this->assertStringNotContainsString('PENDING', (string) $issue->issue_no);

        $this->assertDatabaseHas('outbound_order_sales_order', [
            'outbound_order_id' => $reship->id,
            'sales_order_id' => $salesOrder->id,
        ]);
        $this->assertDatabaseHas('outbound_order_lines', [
            'outbound_order_id' => $reship->id,
            'sku_id' => $line->sku_id,
            'stock_item_id' => $stockItem->id,
            'qty' => 2,
        ]);
        $this->assertSame(2, $this->balance($salesOrder->tenant_id, $warehouse->id, $stockItem->id)->reserved_qty);
        $this->assertSame(SalesOrder::ORDER_STATUS_COMPLETED, $salesOrder->refresh()->order_status);
        $this->assertSame(SalesOrder::FULFILLMENT_STATUS_SHIPPED, $salesOrder->fulfillment_status);
    }

    public function test_reship_can_add_a_sku_not_on_the_original_order(): void
    {
        [$salesOrder, $originalOutbound, $line, $stockItem, $warehouse] = $this->shippedSalesOrderWithLine();
        app(InventoryService::class)->adjustStock($salesOrder->tenant_id, $warehouse->id, $stockItem->id, 10);

        $extraStockItem = StockItem::factory()->for($salesOrder->tenant)->create();
        $extraSku = Sku::factory()->for($salesOrder->tenant)->for($salesOrder->shop)->for($extraStockItem)->create(['sku_type' => 'single']);
        app(InventoryService::class)->adjustStock($salesOrder->tenant_id, $warehouse->id, $extraStockItem->id, 10);

        $reship = app(ReshipSalesOrderService::class)->reship(
            salesOrder: $salesOrder,
            originalOutbound: $originalOutbound,
            warehouseId: null,
            issueType: Issue::TYPE_MISSING,
            lines: [['sales_order_line_id' => $line->id, 'qty' => 1]],
            note: null,
            recipient: [],
            extraLines: [['sku_id' => $extraSku->id, 'qty' => 5]],
        );

        // The added SKU is reshipped and reserved, with no order-line quantity cap.
        $this->assertDatabaseHas('outbound_order_lines', [
            'outbound_order_id' => $reship->id,
            'sku_id' => $extraSku->id,
            'stock_item_id' => $extraStockItem->id,
            'qty' => 5,
        ]);
        $this->assertSame(5, $this->balance($salesOrder->tenant_id, $warehouse->id, $extraStockItem->id)->reserved_qty);
    }

    public function test_reship_can_be_created_from_only_an_added_sku(): void
    {
        [$salesOrder, $originalOutbound, $line, $stockItem, $warehouse] = $this->shippedSalesOrderWithLine();

        $extraStockItem = StockItem::factory()->for($salesOrder->tenant)->create();
        $extraSku = Sku::factory()->for($salesOrder->tenant)->for($salesOrder->shop)->for($extraStockItem)->create(['sku_type' => 'single']);
        app(InventoryService::class)->adjustStock($salesOrder->tenant_id, $warehouse->id, $extraStockItem->id, 10);

        $reship = app(ReshipSalesOrderService::class)->reship(
            salesOrder: $salesOrder,
            originalOutbound: $originalOutbound,
            warehouseId: null,
            issueType: Issue::TYPE_MISSING,
            lines: [['sales_order_line_id' => $line->id, 'qty' => 0]],
            note: null,
            recipient: [],
            extraLines: [['sku_id' => $extraSku->id, 'qty' => 2]],
        );

        $this->assertDatabaseHas('outbound_order_lines', [
            'outbound_order_id' => $reship->id,
            'sku_id' => $extraSku->id,
            'qty' => 2,
        ]);
    }

    public function test_two_reships_create_distinct_issues(): void
    {
        [$salesOrder, $originalOutbound, $line, $stockItem, $warehouse] = $this->shippedSalesOrderWithLine();
        app(InventoryService::class)->adjustStock($salesOrder->tenant_id, $warehouse->id, $stockItem->id, 10);
        $service = app(ReshipSalesOrderService::class);

        $first = $service->reship($salesOrder, $originalOutbound, null, Issue::TYPE_MISSING, [['sales_order_line_id' => $line->id, 'qty' => 1]], null);
        $second = $service->reship($salesOrder, $originalOutbound, null, Issue::TYPE_DAMAGED, [['sales_order_line_id' => $line->id, 'qty' => 1]], null);

        $this->assertNotSame($first->issue_id, $second->issue_id);
        $this->assertDatabaseHas('issues', ['id' => $first->issue_id, 'issue_type' => Issue::TYPE_MISSING]);
        $this->assertDatabaseHas('issues', ['id' => $second->issue_id, 'issue_type' => Issue::TYPE_DAMAGED]);
    }

    public function test_reship_can_override_recipient_details_for_wrong_address(): void
    {
        [$salesOrder, $originalOutbound, $line, $stockItem, $warehouse] = $this->shippedSalesOrderWithLine();
        app(InventoryService::class)->adjustStock($salesOrder->tenant_id, $warehouse->id, $stockItem->id, 10);

        $reship = app(ReshipSalesOrderService::class)->reship(
            salesOrder: $salesOrder,
            originalOutbound: $originalOutbound,
            warehouseId: null,
            issueType: Issue::TYPE_WRONG_ADDRESS,
            lines: [['sales_order_line_id' => $line->id, 'qty' => 1]],
            note: 'Customer gave the correct address after shipment.',
            recipient: [
                'recipient_name' => 'Correct Recipient',
                'recipient_phone' => '09011112222',
                'recipient_country_code' => 'jp',
                'recipient_postal_code' => '150-0001',
                'recipient_state' => 'Tokyo',
                'recipient_city' => 'Shibuya',
                'recipient_address_line1' => '2 Correct Street',
                'recipient_address_line2' => 'Room 301',
            ],
        );

        $this->assertSame(Issue::TYPE_WRONG_ADDRESS, $reship->issue->issue_type);
        $this->assertSame('Correct Recipient', $reship->recipient_name);
        $this->assertSame('09011112222', $reship->recipient_phone);
        $this->assertSame('JP', $reship->recipient_country_code);
        $this->assertSame('150-0001', $reship->recipient_postal_code);
        $this->assertSame('Tokyo', $reship->recipient_state);
        $this->assertSame('Shibuya', $reship->recipient_city);
        $this->assertSame('2 Correct Street', $reship->recipient_address_line1);
        $this->assertSame('Room 301', $reship->recipient_address_line2);
    }

    public function test_reship_uses_sales_order_line_id_and_rejects_too_much_quantity(): void
    {
        [$salesOrder, $originalOutbound, $line, $stockItem, $warehouse, $sku] = $this->shippedSalesOrderWithLine(quantity: 2);
        $secondLine = SalesOrderLine::factory()->for($salesOrder)->for($sku)->create(['quantity' => 5]);
        app(InventoryService::class)->adjustStock($salesOrder->tenant_id, $warehouse->id, $stockItem->id, 20);

        $reship = app(ReshipSalesOrderService::class)->reship(
            salesOrder: $salesOrder,
            originalOutbound: $originalOutbound,
            warehouseId: null,
            issueType: Issue::TYPE_OTHER,
            lines: [['sales_order_line_id' => $secondLine->id, 'qty' => 3]],
            note: null,
        );

        $this->assertSame(3, (int) $reship->lines()->firstOrFail()->qty);

        $this->expectException(ValidationException::class);
        app(ReshipSalesOrderService::class)->reship(
            salesOrder: $salesOrder,
            originalOutbound: $originalOutbound,
            warehouseId: null,
            issueType: Issue::TYPE_OTHER,
            lines: [['sales_order_line_id' => $line->id, 'qty' => 3]],
            note: null,
        );
    }

    public function test_reship_rejects_duplicate_line_payload_when_total_exceeds_line_quantity(): void
    {
        [$salesOrder, $originalOutbound, $line, $stockItem, $warehouse] = $this->shippedSalesOrderWithLine(quantity: 3);
        app(InventoryService::class)->adjustStock($salesOrder->tenant_id, $warehouse->id, $stockItem->id, 20);

        $this->expectException(ValidationException::class);

        app(ReshipSalesOrderService::class)->reship(
            salesOrder: $salesOrder,
            originalOutbound: $originalOutbound,
            warehouseId: null,
            issueType: Issue::TYPE_MISSING,
            lines: [
                ['sales_order_line_id' => $line->id, 'qty' => 2],
                ['sales_order_line_id' => $line->id, 'qty' => 2],
            ],
            note: null,
        );
    }

    public function test_reship_virtual_bundle_expands_components_and_reserves_them(): void
    {
        [$tenant, $warehouse, $shop] = $this->tenantWarehouseShop();
        $bundle = Sku::factory()->virtualBundle()->for($tenant)->for($shop)->create(['sku' => 'BUNDLE-RS']);
        $componentA = StockItem::factory()->for($tenant)->create(['code' => 'CMP-RS-A']);
        $componentB = StockItem::factory()->for($tenant)->create(['code' => 'CMP-RS-B']);
        SkuBundleComponent::factory()->create(['tenant_id' => $tenant->id, 'bundle_sku_id' => $bundle->id, 'component_stock_item_id' => $componentA->id, 'quantity' => 1]);
        SkuBundleComponent::factory()->create(['tenant_id' => $tenant->id, 'bundle_sku_id' => $bundle->id, 'component_stock_item_id' => $componentB->id, 'quantity' => 2]);
        app(InventoryService::class)->adjustStock($tenant->id, $warehouse->id, $componentA->id, 10);
        app(InventoryService::class)->adjustStock($tenant->id, $warehouse->id, $componentB->id, 10);

        [$salesOrder, $originalOutbound] = $this->shippedSalesOrder($tenant, $warehouse, $shop);
        $line = SalesOrderLine::factory()->for($salesOrder)->for($bundle)->create(['quantity' => 2]);

        $reship = app(ReshipSalesOrderService::class)->reship(
            salesOrder: $salesOrder,
            originalOutbound: $originalOutbound,
            warehouseId: null,
            issueType: Issue::TYPE_MISSING,
            lines: [['sales_order_line_id' => $line->id, 'qty' => 2]],
            note: null,
        );

        $this->assertSame(3, $reship->lines()->count());
        $this->assertDatabaseHas('outbound_order_lines', ['outbound_order_id' => $reship->id, 'stock_item_id' => null, 'qty' => 2]);
        $this->assertSame(2, $this->balance($tenant->id, $warehouse->id, $componentA->id)->reserved_qty);
        $this->assertSame(4, $this->balance($tenant->id, $warehouse->id, $componentB->id)->reserved_qty);
    }

    public function test_reship_can_override_to_active_warehouse_and_rejects_inactive_warehouse(): void
    {
        [$salesOrder, $originalOutbound, $line, $stockItem, , $sku] = $this->shippedSalesOrderWithLine();
        $activeWarehouse = Warehouse::factory()->create(['status' => 'active']);
        $inactiveWarehouse = Warehouse::factory()->create(['status' => 'inactive']);
        app(InventoryService::class)->adjustStock($salesOrder->tenant_id, $activeWarehouse->id, $stockItem->id, 10);

        $reship = app(ReshipSalesOrderService::class)->reship(
            salesOrder: $salesOrder,
            originalOutbound: $originalOutbound,
            warehouseId: $activeWarehouse->id,
            issueType: Issue::TYPE_MISSING,
            lines: [['sales_order_line_id' => $line->id, 'qty' => 1]],
            note: null,
        );

        $this->assertSame($activeWarehouse->id, $reship->warehouse_id);

        $this->expectException(ValidationException::class);
        app(ReshipSalesOrderService::class)->reship(
            salesOrder: $salesOrder,
            originalOutbound: $originalOutbound,
            warehouseId: $inactiveWarehouse->id,
            issueType: Issue::TYPE_MISSING,
            lines: [['sales_order_line_id' => $line->id, 'qty' => 1]],
            note: $sku->sku,
        );
    }

    public function test_reship_appears_in_fulfillment_queue(): void
    {
        [$salesOrder, $originalOutbound, $line, $stockItem, $warehouse] = $this->shippedSalesOrderWithLine();
        app(InventoryService::class)->adjustStock($salesOrder->tenant_id, $warehouse->id, $stockItem->id, 10);
        $reship = app(ReshipSalesOrderService::class)->reship($salesOrder, $originalOutbound, null, Issue::TYPE_MISSING, [['sales_order_line_id' => $line->id, 'qty' => 1]], null);

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentIndex::class)
            ->assertSee($reship->ref);
    }

    public function test_sales_order_index_reship_action_only_shows_with_shipped_filter(): void
    {
        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderIndex::class)
            ->assertDontSee(__('outbound.reship'))
            ->set('fulfillmentStatusesFilter', [SalesOrder::FULFILLMENT_STATUS_SHIPPED])
            ->assertSee(__('outbound.reship'));
    }

    public function test_sales_order_index_reship_action_opens_detail_reship_modal_for_one_shipped_order(): void
    {
        [$salesOrder] = $this->shippedSalesOrderWithLine();

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderIndex::class)
            ->set('fulfillmentStatusesFilter', [SalesOrder::FULFILLMENT_STATUS_SHIPPED])
            ->set('selectedIds', [$salesOrder->id])
            ->call('reshipSelected')
            ->assertRedirect(route('sales.orders.show', ['order' => $salesOrder, 'reship' => 1]));

        Livewire::actingAs($this->internalUser())
            ->withQueryParams(['reship' => '1'])
            ->test(SalesOrderDetail::class, ['order' => $salesOrder])
            ->assertSet('showReshipModal', true)
            ->assertSet('reshipLines.0.qty', 3);
    }

    public function test_reship_modal_auto_selects_latest_shipped_outbound(): void
    {
        [$salesOrder, $originalOutbound] = $this->shippedSalesOrderWithLine();
        $latestWarehouse = Warehouse::factory()->create(['code' => 'LATE', 'status' => 'active']);
        $latestOutbound = OutboundOrder::factory()->for($salesOrder->tenant)->for($latestWarehouse)->create([
            'reason' => OutboundOrder::REASON_CUSTOMER_ORDER,
            'status' => OutboundOrder::STATUS_SHIPPED,
            'source_sales_order_id' => $salesOrder->id,
            'ref' => 'OB-LATEST',
            'recipient_name' => 'Latest Recipient',
            'recipient_country_code' => 'JP',
            'recipient_postal_code' => '150-0001',
            'recipient_state' => 'Tokyo',
            'recipient_city' => 'Shibuya',
            'recipient_address_line1' => '2 Latest Street',
            'shipped_at' => now()->addDay(),
        ]);
        $latestOutbound->salesOrders()->attach($salesOrder->id, ['arranged_at' => now()]);
        $originalOutbound->update(['shipped_at' => now()->subDay()]);

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderDetail::class, ['order' => $salesOrder])
            ->call('reship')
            ->assertSet('reshipSourceOutboundId', (string) $latestOutbound->id)
            ->assertSet('reshipSourceOutboundLabel', 'OB-LATEST / LATE')
            ->assertSet('reshipWarehouseId', (string) $latestWarehouse->id)
            ->assertSet('reshipRecipientName', 'Latest Recipient');
    }

    public function test_tenant_user_cannot_open_reship_modal(): void
    {
        [$salesOrder] = $this->shippedSalesOrderWithLine();
        $user = User::factory()->create(['user_type' => 'tenant', 'is_active' => true]);
        TenantUser::factory()->create(['tenant_id' => $salesOrder->tenant_id, 'user_id' => $user->id, 'status' => 'active']);

        Livewire::actingAs($user)
            ->test(SalesOrderDetail::class, ['order' => $salesOrder])
            ->call('reship')
            ->assertForbidden();
    }

    /**
     * @return array{0: SalesOrder, 1: OutboundOrder, 2: SalesOrderLine, 3: StockItem, 4: Warehouse, 5: Sku}
     */
    private function shippedSalesOrderWithLine(int $quantity = 3): array
    {
        [$tenant, $warehouse, $shop] = $this->tenantWarehouseShop();
        $stockItem = StockItem::factory()->for($tenant)->create();
        $sku = Sku::factory()->for($tenant)->for($shop)->for($stockItem)->create(['sku_type' => 'single']);
        [$salesOrder, $outbound] = $this->shippedSalesOrder($tenant, $warehouse, $shop);
        $line = SalesOrderLine::factory()->for($salesOrder)->for($sku)->create(['quantity' => $quantity]);

        return [$salesOrder, $outbound, $line, $stockItem, $warehouse, $sku];
    }

    /**
     * @return array{0: SalesOrder, 1: OutboundOrder}
     */
    private function shippedSalesOrder(Tenant $tenant, Warehouse $warehouse, Shop $shop): array
    {
        $method = ShippingMethod::query()->where('code', 'yamato_nekopos')->firstOrFail();
        $salesOrder = SalesOrder::factory()->for($tenant)->for($shop)->create([
            'order_status' => SalesOrder::ORDER_STATUS_COMPLETED,
            'fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_SHIPPED,
            'recipient_name' => 'Reship Recipient',
            'recipient_country_code' => 'JP',
            'recipient_postal_code' => '100-0001',
            'recipient_state' => 'Tokyo',
            'recipient_city' => 'Chiyoda',
            'recipient_address_line1' => '1 Test Street',
        ]);
        $outbound = OutboundOrder::factory()->for($tenant)->for($warehouse)->create([
            'reason' => OutboundOrder::REASON_CUSTOMER_ORDER,
            'status' => OutboundOrder::STATUS_SHIPPED,
            'source_sales_order_id' => $salesOrder->id,
            'shipping_method_id' => $method->id,
            'ref' => 'OB-ORIGINAL-'.$salesOrder->id,
            'recipient_name' => $salesOrder->recipient_name,
            'recipient_country_code' => $salesOrder->recipient_country_code,
            'recipient_postal_code' => $salesOrder->recipient_postal_code,
            'recipient_state' => $salesOrder->recipient_state,
            'recipient_city' => $salesOrder->recipient_city,
            'recipient_address_line1' => $salesOrder->recipient_address_line1,
        ]);
        $outbound->salesOrders()->attach($salesOrder->id, ['arranged_at' => now()]);

        return [$salesOrder, $outbound];
    }

    /**
     * @return array{0: Tenant, 1: Warehouse, 2: Shop}
     */
    private function tenantWarehouseShop(): array
    {
        $tenant = Tenant::factory()->create();
        $warehouse = Warehouse::factory()->create(['status' => 'active']);
        $shop = Shop::factory()->for($tenant)->create();

        return [$tenant, $warehouse, $shop];
    }

    private function balance(int $tenantId, int $warehouseId, int $stockItemId): InventoryBalance
    {
        return InventoryBalance::query()
            ->where('tenant_id', $tenantId)
            ->where('warehouse_id', $warehouseId)
            ->where('stock_item_id', $stockItemId)
            ->firstOrFail();
    }

    private function internalUser(): User
    {
        return User::factory()->create([
            'user_type' => 'internal',
            'is_active' => true,
        ]);
    }
}
