<?php

namespace Tests\Feature;

use App\Actions\BackfillNormalizedTrackingNumbers;
use App\Livewire\FulfillmentGroupCreate;
use App\Livewire\FulfillmentGroupIndex;
use App\Livewire\FulfillmentGroupPack;
use App\Livewire\FulfillmentPackStart;
use App\Livewire\OutboundOrderDetail;
use App\Livewire\OutboundOrderShip;
use App\Models\BarcodeAlias;
use App\Models\CourierExportBatch;
use App\Models\FulfillmentGroup;
use App\Models\FulfillmentPackScan;
use App\Models\InventoryBalance;
use App\Models\OutboundOrder;
use App\Models\OutboundOrderLine;
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
use App\Services\Courier\CourierExportService;
use App\Services\Courier\TrackingImport\TrackingImportService;
use App\Services\Fulfillment\FulfillmentPackService;
use App\Services\InventoryService;
use App\Support\CourierCarrier;
use App\Support\SalesOrderFilters;
use App\Support\TrackingNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class FulfillmentGroupTest extends TestCase
{
    use RefreshDatabase;

    public function test_tracking_number_normalizer_removes_common_separators(): void
    {
        $this->assertSame('123456789012', TrackingNumber::normalize('1234-5678-9012'));
        $this->assertSame('123456789012', TrackingNumber::normalize('1234 5678 9012'));
        $this->assertSame('AB123CD', TrackingNumber::normalize('  ab-123 cd  '));
        $this->assertSame('ABC123', TrackingNumber::normalize('a_b.c/1\\2:3;|'));
    }

    public function test_tracking_number_normalizer_returns_null_for_blank_or_separator_only_input(): void
    {
        $this->assertNull(TrackingNumber::normalize(null));
        $this->assertNull(TrackingNumber::normalize(''));
        $this->assertNull(TrackingNumber::normalize(' - _ . / \\ : ; | '));
    }

    public function test_tracking_backfill_normalizes_all_tracking_tables(): void
    {
        [$tenant, $warehouse, $shop, $sku] = $this->skuWithStock(20);
        $order = $this->readySalesOrder($tenant, $shop, $sku, 1, 'SO-BACKFILL');
        $this->createGroup($tenant, $warehouse, $order->ship_together_key, [$order]);
        $group = FulfillmentGroup::with('outboundOrder')->firstOrFail();

        DB::table('fulfillment_groups')->where('id', $group->id)->update(['tracking_no' => 'fg-123 456']);
        DB::table('fulfillment_group_orders')->where('fulfillment_group_id', $group->id)->update(['tracking_no' => 'fgo-123 456']);
        DB::table('sales_orders')->where('id', $order->id)->update(['tracking_no' => 'so-123 456']);
        DB::table('outbound_orders')->where('id', $group->outboundOrder->id)->update(['tracking_no' => 'ob-123 456']);
        $returnOrderId = DB::table('return_orders')->insertGetId([
            'tenant_id' => $tenant->id,
            'warehouse_id' => $warehouse->id,
            'return_no' => 'RTN-BACKFILL',
            'status' => 'announced',
            'return_type' => 'customer_return',
            'tracking_no' => 'rt-123 456',
            'payment_type' => 'unknown',
            'collect_currency' => 'JPY',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(BackfillNormalizedTrackingNumbers::class)->handle();

        $this->assertSame('FG123456', DB::table('fulfillment_groups')->where('id', $group->id)->value('tracking_no'));
        $this->assertSame('FGO123456', DB::table('fulfillment_group_orders')->where('fulfillment_group_id', $group->id)->value('tracking_no'));
        $this->assertSame('SO123456', DB::table('sales_orders')->where('id', $order->id)->value('tracking_no'));
        $this->assertSame('OB123456', DB::table('outbound_orders')->where('id', $group->outboundOrder->id)->value('tracking_no'));
        $this->assertSame('RT123456', DB::table('return_orders')->where('id', $returnOrderId)->value('tracking_no'));
    }

    public function test_tracking_backfill_converts_separator_only_values_to_null(): void
    {
        [$tenant, $warehouse, $shop, $sku] = $this->skuWithStock(20);
        $order = $this->readySalesOrder($tenant, $shop, $sku, 1, 'SO-BACKFILL-NULL');
        $this->createGroup($tenant, $warehouse, $order->ship_together_key, [$order]);
        $group = FulfillmentGroup::firstOrFail();

        DB::table('fulfillment_groups')->where('id', $group->id)->update(['tracking_no' => ' - _ . / \\ : ; | ']);

        app(BackfillNormalizedTrackingNumbers::class)->handle();

        $this->assertNull(DB::table('fulfillment_groups')->where('id', $group->id)->value('tracking_no'));
    }

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
        $this->assertSame(OutboundOrder::REASON_CUSTOMER_ORDER, $outbound->reason);
        $this->assertSame(OutboundOrder::SHIP_MODE_PARCEL, $outbound->ship_mode);
        $this->assertNull($outbound->shipping_method_id);
        $this->assertSame(OutboundOrder::STATUS_PENDING, $outbound->status);
        $this->assertSame(
            [$orderA->id, $orderB->id],
            $outbound->salesOrders()->orderBy('sales_orders.id')->pluck('sales_orders.id')->all(),
        );
        $this->assertTrue($outbound->salesOrders()->get()->every(fn ($order) => $order->pivot->arranged_at !== null));
        $this->assertSame(1, $outbound->lines->count());
        $this->assertSame(5, $outbound->lines->first()->qty);
        $this->assertSame(5, $balance->reserved_qty);
        $this->assertSame(15, $balance->available_qty);
        $this->assertSame(SalesOrder::FULFILLMENT_STATUS_ARRANGED, $orderA->refresh()->fulfillment_status);
        $this->assertSame(SalesOrder::FULFILLMENT_STATUS_ARRANGED, $orderB->refresh()->fulfillment_status);
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
        $tenantCode = str_pad(substr(preg_replace('/[^A-Z0-9]+/', '', strtoupper($tenant->code)) ?? '', 0, 3), 3, 'X');
        $this->assertMatchesRegularExpression('/^F'.preg_quote($tenantCode, '/').'\d{6}\d{5}$/', $references->first());
        $this->assertSame(15, strlen($references->first()));
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

    public function test_create_group_defaults_shipping_method_from_sales_order(): void
    {
        [$tenant, $warehouse, $shop, $sku] = $this->skuWithStock(20);
        $method = ShippingMethod::where('code', 'yamato_nekopos')->firstOrFail();
        $order = $this->readySalesOrder($tenant, $shop, $sku, 1, 'SO-SHIPMETHOD');
        $order->update(['shipping_method_id' => $method->id]);

        $this->createGroup($tenant, $warehouse, $order->ship_together_key, [$order]);

        $this->assertSame($method->id, FulfillmentGroup::firstOrFail()->shipping_method_id);
        $this->assertSame($method->id, FulfillmentGroup::firstOrFail()->outboundOrder()->value('shipping_method_id'));
    }

    public function test_create_group_picks_lowest_selection_priority_when_members_differ(): void
    {
        [$tenant, $warehouse, $shop, $sku] = $this->skuWithStock(20);
        $yamato = ShippingMethod::where('code', 'yamato_nekopos')->firstOrFail();
        $sagawa = ShippingMethod::where('code', 'sagawa_thb')->firstOrFail();
        $yamato->update(['selection_priority' => 1]);
        $sagawa->update(['selection_priority' => 2]);

        $orderA = $this->readySalesOrder($tenant, $shop, $sku, 1, 'SO-PRIO-A');
        $orderA->update(['shipping_method_id' => $yamato->id]);
        $orderB = $this->readySalesOrder($tenant, $shop, $sku, 1, 'SO-PRIO-B');
        $orderB->update(['shipping_method_id' => $sagawa->id]);

        $this->createGroup($tenant, $warehouse, $orderA->ship_together_key, [$orderA, $orderB]);

        $this->assertSame($yamato->id, FulfillmentGroup::firstOrFail()->shipping_method_id);
    }

    public function test_create_group_leaves_shipping_method_blank_on_priority_tie(): void
    {
        [$tenant, $warehouse, $shop, $sku] = $this->skuWithStock(20);
        $yamato = ShippingMethod::where('code', 'yamato_nekopos')->firstOrFail();
        $sagawa = ShippingMethod::where('code', 'sagawa_thb')->firstOrFail();
        $yamato->update(['selection_priority' => 1]);
        $sagawa->update(['selection_priority' => 1]);

        $orderA = $this->readySalesOrder($tenant, $shop, $sku, 1, 'SO-TIE-A');
        $orderA->update(['shipping_method_id' => $yamato->id]);
        $orderB = $this->readySalesOrder($tenant, $shop, $sku, 1, 'SO-TIE-B');
        $orderB->update(['shipping_method_id' => $sagawa->id]);

        $this->createGroup($tenant, $warehouse, $orderA->ship_together_key, [$orderA, $orderB]);

        $this->assertNull(FulfillmentGroup::firstOrFail()->shipping_method_id);
    }

    public function test_create_group_leaves_shipping_method_blank_when_a_member_has_no_method(): void
    {
        [$tenant, $warehouse, $shop, $sku] = $this->skuWithStock(20);
        $yamato = ShippingMethod::where('code', 'yamato_nekopos')->firstOrFail();

        $orderA = $this->readySalesOrder($tenant, $shop, $sku, 1, 'SO-NULL-A');
        $orderA->update(['shipping_method_id' => $yamato->id]);
        $orderB = $this->readySalesOrder($tenant, $shop, $sku, 1, 'SO-NULL-B');
        $orderB->update(['shipping_method_id' => null]);

        $this->createGroup($tenant, $warehouse, $orderA->ship_together_key, [$orderA, $orderB]);

        $this->assertNull(FulfillmentGroup::firstOrFail()->shipping_method_id);
    }

    public function test_fulfillment_index_updates_group_shipping_method_and_rejects_inactive(): void
    {
        [$tenant, $warehouse, $shop, $sku] = $this->skuWithStock(20);
        $order = $this->readySalesOrder($tenant, $shop, $sku, 1, 'SO-UPD-METHOD');
        $this->createGroup($tenant, $warehouse, $order->ship_together_key, [$order]);
        $group = FulfillmentGroup::firstOrFail();
        $outbound = $group->outboundOrder()->firstOrFail();

        $yamato = ShippingMethod::where('code', 'yamato_nekopos')->firstOrFail();

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentGroupIndex::class)
            ->call('updateShippingMethod', $outbound->id, (string) $yamato->id);

        $this->assertSame($yamato->id, $outbound->refresh()->shipping_method_id);
        $this->assertSame($yamato->id, $group->refresh()->shipping_method_id);

        $inactive = ShippingMethod::where('code', 'sagawa_thb')->firstOrFail();
        $inactive->update(['status' => 'inactive']);

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentGroupIndex::class)
            ->call('updateShippingMethod', $outbound->id, (string) $inactive->id);

        $this->assertSame($yamato->id, $outbound->refresh()->shipping_method_id);
        $this->assertSame($yamato->id, $group->refresh()->shipping_method_id);
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

    public function test_create_group_enforces_cross_shop_consolidation_mode(): void
    {
        [$tenant, $warehouse, $shopA, $skuA] = $this->skuWithStock(20);
        $shopB = Shop::factory()->for($tenant)->create([
            'status' => 'active',
            'consolidation_mode' => Shop::CONSOLIDATION_SAME_SHOP,
        ]);
        $skuB = Sku::factory()
            ->for($tenant)
            ->for($shopB)
            ->for(StockItem::factory()->for($tenant)->create())
            ->create(['sku_type' => 'single']);
        app(InventoryService::class)->adjustStock($tenant->id, $warehouse->id, $skuB->stock_item_id, 20);
        $orderA = $this->readySalesOrder($tenant, $shopA, $skuA, 1, 'SO-CONSOL-A');
        $orderB = $this->readySalesOrder($tenant, $shopB, $skuB, 1, 'SO-CONSOL-B');

        $this->createGroup($tenant, $warehouse, $orderA->ship_together_key, [$orderA, $orderB]);

        $this->assertSame(0, FulfillmentGroup::count());

        $shopA->update(['consolidation_mode' => Shop::CONSOLIDATION_CROSS_SHOP]);
        $shopB->update(['consolidation_mode' => Shop::CONSOLIDATION_CROSS_SHOP]);

        $this->createGroup($tenant, $warehouse, $orderA->ship_together_key, [$orderA, $orderB]);

        $this->assertSame(1, FulfillmentGroup::count());
    }

    public function test_create_group_rejects_none_shop_consolidation(): void
    {
        [$tenant, $warehouse, $shop, $sku] = $this->skuWithStock(20);
        $shop->update(['consolidation_mode' => Shop::CONSOLIDATION_NONE]);
        $orderA = $this->readySalesOrder($tenant, $shop, $sku, 1, 'SO-NONE-A');
        $orderB = $this->readySalesOrder($tenant, $shop, $sku, 1, 'SO-NONE-B');

        $this->createGroup($tenant, $warehouse, $orderA->ship_together_key, [$orderA, $orderB]);

        $this->assertSame(0, FulfillmentGroup::count());
    }

    public function test_join_group_refuses_exported_or_shipped_groups(): void
    {
        [$tenant, $warehouse, $shop, $sku] = $this->skuWithStock(20);
        $orderA = $this->readySalesOrder($tenant, $shop, $sku, 1, 'SO-JOIN-BLOCK-A');
        $this->createGroup($tenant, $warehouse, $orderA->ship_together_key, [$orderA]);
        $group = FulfillmentGroup::firstOrFail();
        $group->outboundOrder()->firstOrFail()->update(['courier_csv_exported_at' => now()]);
        $orderB = $this->readySalesOrder($tenant, $shop, $sku, 1, 'SO-JOIN-BLOCK-B');

        $this->expectException(\InvalidArgumentException::class);

        app(\App\Services\Fulfillment\GroupSalesOrdersService::class)->joinGroup($group, [$orderB->id]);
    }

    public function test_join_group_refuses_shipped_groups(): void
    {
        [$tenant, $warehouse, $shop, $sku] = $this->skuWithStock(20);
        $orderA = $this->readySalesOrder($tenant, $shop, $sku, 1, 'SO-JOIN-SHIPPED-A');
        $this->createGroup($tenant, $warehouse, $orderA->ship_together_key, [$orderA]);
        $group = FulfillmentGroup::firstOrFail();
        $group->update(['status' => FulfillmentGroup::STATUS_SHIPPED, 'shipped_at' => now()]);
        $orderB = $this->readySalesOrder($tenant, $shop, $sku, 1, 'SO-JOIN-SHIPPED-B');

        $this->expectException(\InvalidArgumentException::class);

        app(\App\Services\Fulfillment\GroupSalesOrdersService::class)->joinGroup($group, [$orderB->id]);
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
        $tenant = Tenant::factory()->create();
        $warehouse = Warehouse::factory()->create();
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
        $this->assertSame(SalesOrder::FULFILLMENT_STATUS_ARRANGED, $order->refresh()->fulfillment_status);
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
        OutboundOrder::factory()->create([
            'fulfillment_group_id' => $ownGroup->id,
            'tenant_id' => $ownTenant->id,
            'warehouse_id' => $ownWarehouse->id,
            'reason' => OutboundOrder::REASON_CUSTOMER_ORDER,
        ]);

        [$otherTenant, $otherWarehouse] = [Tenant::factory()->create(), Warehouse::factory()->create()];
        $otherGroup = FulfillmentGroup::factory()->for($otherTenant)->for($otherWarehouse)->create(['reference_no' => 'FG-HIDDEN']);
        OutboundOrder::factory()->create([
            'fulfillment_group_id' => $otherGroup->id,
            'tenant_id' => $otherTenant->id,
            'warehouse_id' => $otherWarehouse->id,
            'reason' => OutboundOrder::REASON_CUSTOMER_ORDER,
        ]);

        Livewire::actingAs($user)
            ->test(FulfillmentGroupIndex::class)
            ->assertSee($ownGroup->reference_no)
            ->assertDontSee('FG-HIDDEN');
    }

    public function test_fulfillment_index_searches_platform_order_id_and_lists_all_order_ids(): void
    {
        [$tenant, $warehouse, $shop, $sku] = $this->skuWithStock(20);
        $firstOrder = $this->readySalesOrder($tenant, $shop, $sku, 1, '503-2780983-9214241');
        $secondOrder = $this->readySalesOrder($tenant, $shop, $sku, 1, '503-2780983-9214242');
        $hiddenOrder = $this->readySalesOrder($tenant, $shop, $sku, 1, '503-2780983-9999999', '2 Hidden Street');
        $this->createGroup($tenant, $warehouse, $firstOrder->ship_together_key, [$firstOrder, $secondOrder]);
        $shownGroup = FulfillmentGroup::query()->latest('id')->firstOrFail();
        $this->createGroup($tenant, $warehouse, $hiddenOrder->ship_together_key, [$hiddenOrder]);
        $hiddenGroup = FulfillmentGroup::query()->latest('id')->firstOrFail();

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentGroupIndex::class)
            ->set('search', '503-2780983-9214241')
            ->assertSee($shownGroup->reference_no)
            ->assertSee('503-2780983-9214241')
            ->assertSee('503-2780983-9214242')
            ->assertDontSee('+1')
            ->assertDontSee($hiddenGroup->reference_no);
    }

    public function test_fulfillment_index_printed_filters_and_added_cell_use_outbound_flag(): void
    {
        [$tenant, $warehouse, $shop, $sku] = $this->skuWithStock(20);
        $printedOrder = $this->readySalesOrder($tenant, $shop, $sku, 1, 'SO-FG-PRINTED');
        $notPrintedOrder = $this->readySalesOrder($tenant, $shop, $sku, 1, 'SO-FG-NOT-PRINTED', '2 Not Printed Street');
        $this->createGroup($tenant, $warehouse, $printedOrder->ship_together_key, [$printedOrder]);
        $printedGroup = FulfillmentGroup::query()->latest('id')->firstOrFail();
        $printedGroup->outboundOrder()->firstOrFail()->update(['courier_csv_exported_at' => '2026-06-18 10:00:00']);
        $this->createGroup($tenant, $warehouse, $notPrintedOrder->ship_together_key, [$notPrintedOrder]);
        $notPrintedGroup = FulfillmentGroup::query()->latest('id')->firstOrFail();

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentGroupIndex::class)
            ->set('printWaiting', true)
            ->assertDontSee($printedGroup->reference_no)
            ->assertSee($notPrintedGroup->reference_no);

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentGroupIndex::class)
            ->set('othersFilter', [SalesOrderFilters::OTHER_PRINTED])
            ->assertSee($printedGroup->reference_no)
            ->assertSee(__('fulfillment_groups.printed_at', ['time' => '06-18 10:00']))
            ->assertDontSee($notPrintedGroup->reference_no);

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentGroupIndex::class)
            ->set('othersFilter', [SalesOrderFilters::OTHER_NOT_PRINTED])
            ->assertSee($notPrintedGroup->reference_no)
            ->assertSee(__('fulfillment_groups.not_printed'))
            ->assertDontSee($printedGroup->reference_no);
    }

    public function test_detailed_toggle_shows_sku_lines_and_full_address(): void
    {
        [$tenant, $warehouse, $shop, $sku] = $this->skuWithStock(20);
        $order = $this->readySalesOrder($tenant, $shop, $sku, 3, 'SO-DETAILED');
        $this->createGroup($tenant, $warehouse, $order->ship_together_key, [$order]);

        $component = Livewire::actingAs($this->internalUser())
            ->test(FulfillmentGroupIndex::class)
            ->assertDontSee($sku->sku)
            ->assertDontSee('1 Shared Street');

        $component->call('toggleDetailed')
            ->assertSet('detailed', true)
            ->assertSee($sku->sku)
            ->assertSee('1 Shared Street');
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
        $this->assertNotNull($group->shipped_at);
        $pivot = $group->groupOrders()->firstOrFail();
        $this->assertSame('Yamato', $pivot->courier);
        $this->assertSame('TRACK1', $pivot->tracking_no);
        $this->assertNotNull($pivot->shipped_at);
        $this->assertSame('TRACK1', $outbound->refresh()->tracking_no);
        $this->assertSame(SalesOrder::FULFILLMENT_STATUS_SHIPPED, $order->refresh()->fulfillment_status);
        $this->assertSame(SalesOrder::ORDER_STATUS_COMPLETED, $order->order_status);
        $this->assertSame('TRACK1', $order->tracking_no);
    }

    public function test_fulfillment_index_tracking_update_stores_normalized_value_on_member_orders(): void
    {
        [$tenant, $warehouse, $shop, $sku] = $this->skuWithStock(20);
        $order = $this->readySalesOrder($tenant, $shop, $sku, 1, 'SO-NORMALIZE-TRACK');
        $this->createGroup($tenant, $warehouse, $order->ship_together_key, [$order]);
        $group = FulfillmentGroup::firstOrFail();
        $outbound = $group->outboundOrder()->firstOrFail();

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentGroupIndex::class)
            ->call('updateTracking', $outbound->id, '  ab-123 cd  ');

        $this->assertSame('AB123CD', $outbound->refresh()->tracking_no);
        $this->assertSame('AB123CD', $group->groupOrders()->firstOrFail()->tracking_no);
        $this->assertSame('AB123CD', $order->refresh()->tracking_no);
    }

    public function test_fulfillment_index_mark_shipped_ships_reserved_group(): void
    {
        [$tenant, $warehouse, $shop, $sku] = $this->skuWithStock(20);
        $orderA = $this->readySalesOrder($tenant, $shop, $sku, 2, 'SO-BATCH-SHIP-A');
        $orderB = $this->readySalesOrder($tenant, $shop, $sku, 3, 'SO-BATCH-SHIP-B');
        $method = ShippingMethod::where('code', 'yamato_nekopos')->with('carrier')->firstOrFail();

        $this->createGroup($tenant, $warehouse, $orderA->ship_together_key, [$orderA, $orderB]);
        $group = FulfillmentGroup::with(['outboundOrder.leafLines', 'groupOrders'])->firstOrFail();
        $outbound = $group->outboundOrder()->firstOrFail();
        $outbound->update(['shipping_method_id' => $method->id, 'tracking_no' => 'FGTRACK1']);

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentGroupIndex::class)
            ->set('selectedIds', [(string) $outbound->id])
            ->call('markShipped')
            ->assertSet('selectedIds', []);

        $group->refresh();
        $outbound = $group->outboundOrder()->with('leafLines')->firstOrFail();
        $balance = $this->balance($tenant, $warehouse, $sku->stockItem);

        $this->assertSame(FulfillmentGroup::STATUS_SHIPPED, $group->status);
        $this->assertNotNull($group->shipped_at);
        $this->assertSame(OutboundOrder::STATUS_SHIPPED, $outbound->status);
        $this->assertSame($method->carrier->code, $outbound->courier);
        $this->assertSame('FGTRACK1', $outbound->tracking_no);
        $this->assertSame(15, $balance->on_hand_qty);
        $this->assertSame(0, $balance->reserved_qty);
        $this->assertSame(15, $balance->available_qty);
        $this->assertTrue($outbound->leafLines->every(fn ($line) => $line->inventory_movement_id !== null));

        foreach ([$orderA->refresh(), $orderB->refresh()] as $salesOrder) {
            $this->assertSame(SalesOrder::FULFILLMENT_STATUS_SHIPPED, $salesOrder->fulfillment_status);
            $this->assertSame(SalesOrder::ORDER_STATUS_COMPLETED, $salesOrder->order_status);
            $this->assertSame('FGTRACK1', $salesOrder->tracking_no);
            $this->assertNotNull($salesOrder->shipped_at);
        }

        foreach ($group->groupOrders()->get() as $pivot) {
            $this->assertSame('FGTRACK1', $pivot->tracking_no);
            $this->assertSame($method->carrier->code, $pivot->courier);
            $this->assertNotNull($pivot->shipped_at);
        }
    }

    public function test_fulfillment_index_mark_shipped_skips_non_reserved_group(): void
    {
        [$tenant, $warehouse, $shop, $sku] = $this->skuWithStock(20);
        $order = $this->readySalesOrder($tenant, $shop, $sku, 2, 'SO-BATCH-SKIP');

        $this->createGroup($tenant, $warehouse, $order->ship_together_key, [$order]);
        $group = FulfillmentGroup::firstOrFail();
        $outbound = $group->outboundOrder()->firstOrFail();
        $outbound->update(['status' => OutboundOrder::STATUS_SHIPPED]);
        $group->update(['status' => FulfillmentGroup::STATUS_SHIPPED]);

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentGroupIndex::class)
            ->set('selectedIds', [(string) $outbound->id])
            ->call('markShipped')
            ->assertSet('selectedIds', []);

        $this->assertSame(OutboundOrder::STATUS_SHIPPED, $outbound->refresh()->status);
        $this->assertSame(2, $this->balance($tenant, $warehouse, $sku->stockItem)->reserved_qty);
    }

    public function test_fulfillment_courier_export_emits_one_row_per_group_and_marks_member_orders_exported(): void
    {
        Storage::fake('local');
        [$tenant, $warehouse, $shop, $sku] = $this->skuWithStock(20);
        $orderA = $this->readySalesOrder($tenant, $shop, $sku, 1, 'SO-FG-EXPORT-A');
        $orderB = $this->readySalesOrder($tenant, $shop, $sku, 2, 'SO-FG-EXPORT-B');
        $method = ShippingMethod::where('code', 'yamato_nekopos')->firstOrFail();

        $this->createGroup($tenant, $warehouse, $orderA->ship_together_key, [$orderA, $orderB]);
        $group = FulfillmentGroup::firstOrFail();
        $group->update(['shipping_method_id' => $method->id]);
        $outbound = $group->outboundOrder()->firstOrFail();
        $outbound->update(['shipping_method_id' => $method->id]);

        $batch = app(CourierExportService::class)->exportOrders(
            outboundOrderIds: [$outbound->id],
            carrier: CourierCarrier::YAMATO,
            allowedTenantIds: [$tenant->id],
            user: $this->internalUser(),
        );

        $csv = mb_convert_encoding(Storage::disk($batch->disk)->get($batch->path), 'UTF-8', 'SJIS-win');
        $lines = array_values(array_filter(preg_split('/\r\n/', $csv) ?: []));

        $this->assertSame(2, count($lines));
        $this->assertStringContainsString($group->reference_no, $csv);
        $this->assertStringNotContainsString('SO-FG-EXPORT-A', $csv);
        $this->assertStringNotContainsString('SO-FG-EXPORT-B', $csv);
        $this->assertDatabaseHas('courier_export_batches', [
            'id' => $batch->id,
            'carrier' => CourierCarrier::YAMATO,
            'order_count' => 1,
        ]);
        $this->assertNotNull($orderA->refresh()->courier_csv_exported_at);
        $this->assertNotNull($orderB->refresh()->courier_csv_exported_at);
        $this->assertNotNull($outbound->refresh()->courier_csv_exported_at);
        $this->assertDatabaseHas('courier_export_batch_orders', [
            'courier_export_batch_id' => $batch->id,
            'sales_order_id' => $orderA->id,
            'outbound_order_id' => $outbound->id,
            'platform_order_id' => $orderA->platform_order_id,
        ]);
        $this->assertDatabaseHas('courier_export_batch_orders', [
            'courier_export_batch_id' => $batch->id,
            'sales_order_id' => $orderB->id,
            'outbound_order_id' => $outbound->id,
            'platform_order_id' => $orderB->platform_order_id,
        ]);
    }

    public function test_fulfillment_sagawa_export_writes_last_15_group_reference_to_customer_management_number(): void
    {
        Storage::fake('local');
        [$tenant, $warehouse, $shop, $sku] = $this->skuWithStock(20);
        $order = $this->readySalesOrder($tenant, $shop, $sku, 1, 'SO-FG-SAGAWA-LONG');
        $method = ShippingMethod::where('code', 'sagawa_thb')->firstOrFail();

        $this->createGroup($tenant, $warehouse, $order->ship_together_key, [$order]);
        $group = FulfillmentGroup::firstOrFail();
        $group->update([
            'reference_no' => 'FG-LONG-0613-0659033902',
            'shipping_method_id' => $method->id,
        ]);
        $outbound = $group->outboundOrder()->firstOrFail();
        $outbound->update(['shipping_method_id' => $method->id, 'ref' => 'FG-LONG-0613-0659033902']);

        $batch = app(CourierExportService::class)->exportOrders(
            outboundOrderIds: [$outbound->id],
            carrier: CourierCarrier::SAGAWA,
            allowedTenantIds: [$tenant->id],
            user: $this->internalUser(),
        );

        $lines = preg_split('/\r\n/', trim(mb_convert_encoding(Storage::disk($batch->disk)->get($batch->path), 'UTF-8', 'SJIS-win')));
        $dataRow = str_getcsv($lines[1] ?? '');

        $this->assertSame('0613-0659033902', $dataRow[9]);
        $this->assertNotContains('FG-LONG-0613-0659033902', $dataRow);
    }

    public function test_fulfillment_courier_export_blocks_wrong_carrier_group(): void
    {
        [$tenant, $warehouse, $shop, $sku] = $this->skuWithStock(20);
        $order = $this->readySalesOrder($tenant, $shop, $sku, 1, 'SO-FG-WRONG-CARRIER');
        $method = ShippingMethod::where('code', 'sagawa_thb')->firstOrFail();

        $this->createGroup($tenant, $warehouse, $order->ship_together_key, [$order]);
        $group = FulfillmentGroup::firstOrFail();
        $group->update(['shipping_method_id' => $method->id]);
        $outbound = $group->outboundOrder()->firstOrFail();
        $outbound->update(['shipping_method_id' => $method->id]);

        $result = app(CourierExportService::class)->validateOrderExport(
            outboundOrderIds: [$outbound->id],
            carrier: CourierCarrier::YAMATO,
            allowedTenantIds: [$tenant->id],
        );

        $this->assertFalse($result->ok);
        $this->assertSame([$outbound->id], $result->wrongCarrierOrderIds);
        $this->assertNull($order->refresh()->courier_csv_exported_at);
    }

    public function test_fulfillment_courier_export_validates_against_outbound_state(): void
    {
        [$tenant, $warehouse, $shop, $sku] = $this->skuWithStock(20);
        $order = $this->readySalesOrder($tenant, $shop, $sku, 1, 'SO-FG-OUTBOUND-GATES');
        $method = ShippingMethod::where('code', 'yamato_nekopos')->firstOrFail();

        $this->createGroup($tenant, $warehouse, $order->ship_together_key, [$order]);
        $group = FulfillmentGroup::firstOrFail();
        $group->update(['shipping_method_id' => $method->id]);
        $outbound = $group->outboundOrder()->firstOrFail();
        $outbound->update([
            'shipping_method_id' => $method->id,
            'courier_csv_exported_at' => now(),
        ]);

        $result = app(CourierExportService::class)->validateOrderExport(
            outboundOrderIds: [$outbound->id],
            carrier: CourierCarrier::YAMATO,
            allowedTenantIds: [$tenant->id],
        );

        $this->assertTrue($result->requiresConfirmation);
        $this->assertSame([$outbound->id], $result->alreadyExportedOrderIds);
        $this->assertNull($order->refresh()->courier_csv_exported_at);

        $outbound->update(['courier_csv_exported_at' => null]);
        $method->update(['supports_courier_csv' => false]);

        $result = app(CourierExportService::class)->validateOrderExport(
            outboundOrderIds: [$outbound->id],
            carrier: CourierCarrier::YAMATO,
            allowedTenantIds: [$tenant->id],
        );

        $this->assertSame([$outbound->id], $result->unsupportedCourierOrderIds);

        $method->update(['supports_courier_csv' => true]);
        $outbound->lines()->delete();

        $result = app(CourierExportService::class)->validateOrderExport(
            outboundOrderIds: [$outbound->id],
            carrier: CourierCarrier::YAMATO,
            allowedTenantIds: [$tenant->id],
        );

        $this->assertSame([$outbound->id], $result->noReadyLinesOrderIds);
    }

    public function test_fulfillment_index_courier_export_redirects_to_download(): void
    {
        Storage::fake('local');
        [$tenant, $warehouse, $shop, $sku] = $this->skuWithStock(20);
        $order = $this->readySalesOrder($tenant, $shop, $sku, 1, 'SO-FG-LW-EXPORT');
        $method = ShippingMethod::where('code', 'yamato_nekopos')->firstOrFail();

        $this->createGroup($tenant, $warehouse, $order->ship_together_key, [$order]);
        $group = FulfillmentGroup::firstOrFail();
        $group->update(['shipping_method_id' => $method->id]);
        $outbound = $group->outboundOrder()->firstOrFail();
        $outbound->update(['shipping_method_id' => $method->id]);

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentGroupIndex::class)
            ->set('selectedIds', [(string) $outbound->id])
            ->call('exportYamato')
            ->assertRedirect(route('courier-export-batches.download', CourierExportBatch::firstOrFail()));

        $this->assertNotNull($order->refresh()->courier_csv_exported_at);
    }

    public function test_manual_outbound_without_group_can_be_exported_end_to_end(): void
    {
        Storage::fake('local');
        $tenant = Tenant::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $stockItem = StockItem::factory()->for($tenant)->create(['code' => $tenant->code.'-MANUAL-001']);
        $sku = Sku::factory()->for($tenant)->for(Shop::factory()->for($tenant)->create())->for($stockItem)->create(['sku_type' => 'single']);
        $method = ShippingMethod::where('code', 'yamato_nekopos')->firstOrFail();

        $outbound = OutboundOrder::factory()->create([
            'tenant_id' => $tenant->id,
            'warehouse_id' => $warehouse->id,
            'fulfillment_group_id' => null,
            'ref' => 'OB-MANUAL-EXPORT-001',
            'reason' => OutboundOrder::REASON_REPLACEMENT,
            'ship_mode' => OutboundOrder::SHIP_MODE_PARCEL,
            'shipping_method_id' => $method->id,
            'status' => OutboundOrder::STATUS_PENDING,
            'recipient_name' => 'Manual Recipient',
            'recipient_phone' => '09012345678',
            'recipient_country_code' => 'JP',
            'recipient_postal_code' => '1000001',
            'recipient_state' => 'Tokyo',
            'recipient_city' => 'Chiyoda',
            'recipient_address_line1' => '1 Manual Street',
            'recipient_address_line2' => null,
        ]);

        OutboundOrderLine::factory()->for($outbound, 'order')->for($sku)->for($stockItem)->for($tenant)->create(['qty' => 2]);

        $result = app(CourierExportService::class)->validateOrderExport(
            outboundOrderIds: [$outbound->id],
            carrier: CourierCarrier::YAMATO,
            allowedTenantIds: [$tenant->id],
        );

        $this->assertTrue($result->ok);
        $this->assertSame([$outbound->id], $result->validOrderIds);

        $batch = app(CourierExportService::class)->exportOrders(
            outboundOrderIds: [$outbound->id],
            carrier: CourierCarrier::YAMATO,
            allowedTenantIds: [$tenant->id],
            user: $this->internalUser(),
        );

        $csv = mb_convert_encoding(Storage::disk($batch->disk)->get($batch->path), 'UTF-8', 'SJIS-win');
        $lines = array_values(array_filter(preg_split('/\r\n/', $csv) ?: []));

        $this->assertSame(2, count($lines));
        $this->assertStringContainsString('OB-MANUAL-EXPORT-001', $csv);
        $this->assertNotNull($outbound->refresh()->courier_csv_exported_at);
        $this->assertDatabaseHas('courier_export_batches', [
            'id' => $batch->id,
            'carrier' => CourierCarrier::YAMATO,
            'order_count' => 1,
        ]);
        $this->assertDatabaseHas('courier_export_batch_orders', [
            'courier_export_batch_id' => $batch->id,
            'sales_order_id' => null,
            'outbound_order_id' => $outbound->id,
            'platform_order_id' => 'OB-MANUAL-EXPORT-001',
        ]);
    }

    public function test_fulfillment_tracking_import_matches_group_reference_and_syncs_member_orders(): void
    {
        [$tenant, $warehouse, $shop, $sku] = $this->skuWithStock(20);
        $orderA = $this->readySalesOrder($tenant, $shop, $sku, 1, 'SO-FG-TRACK-A');
        $orderB = $this->readySalesOrder($tenant, $shop, $sku, 1, 'SO-FG-TRACK-B');

        $this->createGroup($tenant, $warehouse, $orderA->ship_together_key, [$orderA, $orderB]);
        $group = FulfillmentGroup::firstOrFail();
        $group->update(['reference_no' => 'FG-LEGACY-TRACK-REF']);
        $outbound = $group->outboundOrder()->firstOrFail();
        $outbound->update(['ref' => 'OB-TRACK-EXACT-REF']);
        $csv = "440-069 713300,{$outbound->ref}\r\n";

        $result = app(TrackingImportService::class)->importFulfillmentGroups(
            contents: $csv,
            sourceFileName: 'sagawa.csv',
            user: $this->internalUser(),
            allowedTenantIds: [$tenant->id],
        );

        $this->assertSame(['updated' => 1], $result);

        foreach ($group->groupOrders()->get() as $pivot) {
            $this->assertSame('440069713300', $pivot->tracking_no);
        }

        $this->assertSame('440069713300', $outbound->refresh()->tracking_no);
        $this->assertSame('440069713300', $orderA->refresh()->tracking_no);
        $this->assertSame('440069713300', $orderB->refresh()->tracking_no);
    }

    public function test_fulfillment_tracking_import_matches_outbound_reference_suffix(): void
    {
        [$tenant, $warehouse, $shop, $sku] = $this->skuWithStock(20);
        $order = $this->readySalesOrder($tenant, $shop, $sku, 1, 'SO-FG-TRACK-SUFFIX');

        $this->createGroup($tenant, $warehouse, $order->ship_together_key, [$order]);
        $group = FulfillmentGroup::firstOrFail();
        $group->update(['reference_no' => 'FG-DOES-NOT-MATCH-SUFFIX']);
        $outbound = $group->outboundOrder()->firstOrFail();
        $outbound->update(['ref' => 'OB-TRACK-SUFFIX-123456789012345']);

        $result = app(TrackingImportService::class)->importFulfillmentGroups(
            contents: "440069713311,123456789012345\r\n",
            sourceFileName: 'sagawa.csv',
            user: $this->internalUser(),
            allowedTenantIds: [$tenant->id],
        );

        $this->assertSame(['updated' => 1], $result);
        $this->assertSame('440069713311', $outbound->refresh()->tracking_no);
        $this->assertSame('440069713311', $order->refresh()->tracking_no);
        $this->assertSame('440069713311', $group->groupOrders()->firstOrFail()->tracking_no);
    }

    public function test_fulfillment_tracking_import_skips_ambiguous_no_match_and_unchanged_rows(): void
    {
        [$tenant, $warehouse, $shop, $sku] = $this->skuWithStock(20);
        $order = $this->readySalesOrder($tenant, $shop, $sku, 1, 'SO-FG-TRACK-UNCHANGED');

        $this->createGroup($tenant, $warehouse, $order->ship_together_key, [$order]);
        $group = FulfillmentGroup::firstOrFail();
        $outbound = $group->outboundOrder()->firstOrFail();
        $outbound->update([
            'ref' => 'OB-TRACK-UNCHANGED',
            'tracking_no' => 'UNCHANGED',
        ]);
        $order->update(['tracking_no' => 'UNCHANGED']);
        $group->groupOrders()->update(['tracking_no' => 'UNCHANGED']);

        OutboundOrder::factory()->for($tenant)->for($warehouse)->create([
            'ref' => 'OB-AMBIGUOUS-A-123456789012345',
            'status' => OutboundOrder::STATUS_PENDING,
        ]);
        OutboundOrder::factory()->for($tenant)->for($warehouse)->create([
            'ref' => 'OB-AMBIGUOUS-B-123456789012345',
            'status' => OutboundOrder::STATUS_PENDING,
        ]);

        $result = app(TrackingImportService::class)->importFulfillmentGroups(
            contents: "NEWTRACK,123456789012345\r\nUNCHANGED,{$outbound->ref}\r\nMISSING,NO-SUCH-REF\r\n",
            sourceFileName: 'sagawa.csv',
            user: $this->internalUser(),
            allowedTenantIds: [$tenant->id],
        );

        $this->assertSame(['updated' => 0], $result);
        $this->assertSame('UNCHANGED', $outbound->refresh()->tracking_no);
        $this->assertSame('UNCHANGED', $order->refresh()->tracking_no);
        $this->assertSame('UNCHANGED', $group->groupOrders()->firstOrFail()->tracking_no);
    }

    public function test_fulfillment_tracking_import_can_update_manual_outbound_parcel(): void
    {
        [$tenant, $warehouse] = $this->skuWithStock(20);
        $outbound = OutboundOrder::factory()->for($tenant)->for($warehouse)->create([
            'ref' => 'OB-MANUAL-TRACKING',
            'status' => OutboundOrder::STATUS_PENDING,
        ]);

        $result = app(TrackingImportService::class)->importFulfillmentGroups(
            contents: "{$outbound->ref},,,YMT-MANUAL\r\n",
            sourceFileName: 'yamato.csv',
            user: $this->internalUser(),
            allowedTenantIds: [$tenant->id],
        );

        $this->assertSame(['updated' => 1], $result);
        $this->assertSame('YMTMANUAL', $outbound->refresh()->tracking_no);
    }

    public function test_fulfillment_tracking_import_route_accepts_file_upload(): void
    {
        [$tenant, $warehouse, $shop, $sku] = $this->skuWithStock(20);
        $order = $this->readySalesOrder($tenant, $shop, $sku, 1, 'SO-FG-TRACK-ROUTE');

        $this->createGroup($tenant, $warehouse, $order->ship_together_key, [$order]);
        $group = FulfillmentGroup::firstOrFail();
        $outbound = $group->outboundOrder()->firstOrFail();
        $file = UploadedFile::fake()->createWithContent('tracking.csv', $outbound->ref.",,,YMT-TRACK\r\n");

        $this->actingAs($this->internalUser())
            ->post(route('fulfillment.tracking-import'), ['tracking_file' => $file])
            ->assertRedirect(route('fulfillment-groups.index'));

        $this->assertSame('YMTTRACK', $outbound->refresh()->tracking_no);
        $this->assertSame('YMTTRACK', $order->refresh()->tracking_no);
    }

    public function test_cancelling_linked_outbound_order_back_writes_group_and_sales_orders(): void
    {
        [$tenant, $warehouse, $shop, $sku] = $this->skuWithStock(20);
        $order = $this->readySalesOrder($tenant, $shop, $sku, 4, 'SO-CANCEL-GROUP');
        $this->createGroup($tenant, $warehouse, $order->ship_together_key, [$order]);
        $group = FulfillmentGroup::firstOrFail();
        $outbound = $group->outboundOrder()->firstOrFail();

        Livewire::actingAs($this->internalUser())
            ->test(OutboundOrderDetail::class, ['order' => $outbound])
            ->call('cancel');

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
        $this->assertSame(SalesOrder::FULFILLMENT_STATUS_ARRANGED, $order->refresh()->fulfillment_status);
    }

    public function test_detail_recipient_edit_updates_linked_outbound_order(): void
    {
        [$tenant, $warehouse, $shop, $sku] = $this->skuWithStock(20);
        $order = $this->readySalesOrder($tenant, $shop, $sku, 1, 'SO-RECIPIENT');
        $this->createGroup($tenant, $warehouse, $order->ship_together_key, [$order]);
        $group = FulfillmentGroup::firstOrFail();

        Livewire::actingAs($this->internalUser())
            ->test(OutboundOrderDetail::class, ['order' => $group->outboundOrder])
            ->call('editRecipient')
            ->set('recipientName', 'New Recipient')
            ->set('recipientCountryCode', 'jp')
            ->set('recipientCity', 'Tokyo')
            ->call('saveRecipient')
            ->assertHasNoErrors();

        $this->assertSame('New Recipient', $group->refresh()->recipient_name);
        $this->assertSame('New Recipient', $group->outboundOrder->refresh()->recipient_name);
        $this->assertSame('JP', $group->outboundOrder->recipient_country_code);
    }

    public function test_detail_shipping_edit_updates_outbound_and_group(): void
    {
        [$tenant, $warehouse, $shop, $sku] = $this->skuWithStock(20);
        $order = $this->readySalesOrder($tenant, $shop, $sku, 1, 'SO-SHIPPING');
        $this->createGroup($tenant, $warehouse, $order->ship_together_key, [$order]);
        $group = FulfillmentGroup::firstOrFail();

        Livewire::actingAs($this->internalUser())
            ->test(OutboundOrderDetail::class, ['order' => $group->outboundOrder])
            ->call('editShipping')
            ->set('courier', 'Sagawa')
            ->set('trackingNo', 'SG-1')
            ->set('note', 'Leave at front desk')
            ->call('saveShipping')
            ->assertHasNoErrors();

        $this->assertSame('Sagawa', $group->outboundOrder->refresh()->courier);
        $this->assertSame('SG1', $group->outboundOrder->tracking_no);
        $this->assertSame('Sagawa', $group->refresh()->courier);
    }

    public function test_fulfillment_group_routes_render(): void
    {
        [$tenant, $warehouse, $shop, $sku] = $this->skuWithStock(20);
        $order = $this->readySalesOrder($tenant, $shop, $sku, 1, 'SO-ROUTE');
        $this->createGroup($tenant, $warehouse, $order->ship_together_key, [$order]);
        $group = FulfillmentGroup::firstOrFail();

        $this->actingAs($this->internalUser())->get('/fulfillment-groups')->assertOk()->assertSee('Fulfillment Groups');
        $this->actingAs($this->internalUser())->get('/fulfillment-groups/create')->assertOk()->assertSee('Create Fulfillment Group');
        $this->actingAs($this->internalUser())->get(route('outbound.show', $group->outboundOrder))->assertOk()->assertSee($group->reference_no);
        $this->actingAs($this->internalUser())->get(route('fulfillment.pack.start'))->assertOk()->assertSee('Scan tracking no.');
        $this->actingAs($this->internalUser())->get(route('outbound.pack', $group->outboundOrder))->assertOk()->assertSee($group->reference_no);
    }

    public function test_pack_action_only_shows_for_reserved_groups(): void
    {
        [$tenant, $warehouse, $shop, $sku] = $this->skuWithStock(20);
        $reservedOrder = $this->readySalesOrder($tenant, $shop, $sku, 1, 'SO-PACK-ACTION-YES');
        $shippedOrder = $this->readySalesOrder($tenant, $shop, $sku, 1, 'SO-PACK-ACTION-NO', addressLine1: '2 Shared Street');
        $this->createGroup($tenant, $warehouse, $reservedOrder->ship_together_key, [$reservedOrder]);
        $this->createGroup($tenant, $warehouse, $shippedOrder->ship_together_key, [$shippedOrder]);
        $reserved = FulfillmentGroup::whereHas('orders', fn ($query) => $query->whereKey($reservedOrder->id))->firstOrFail();
        $shipped = FulfillmentGroup::whereHas('orders', fn ($query) => $query->whereKey($shippedOrder->id))->firstOrFail();
        $shipped->outboundOrder()->update(['status' => OutboundOrder::STATUS_SHIPPED]);
        $shipped->update(['status' => FulfillmentGroup::STATUS_SHIPPED]);

        $this->actingAs($this->internalUser())
            ->get(route('fulfillment-groups.index'))
            ->assertOk()
            ->assertSee(route('outbound.pack', $reserved->outboundOrder))
            ->assertDontSee(route('outbound.pack', $shipped->outboundOrder));

        $this->actingAs($this->internalUser())
            ->get(route('outbound.show', $reserved->outboundOrder))
            ->assertOk()
            ->assertSee(route('outbound.pack', $reserved->outboundOrder));

        $this->actingAs($this->internalUser())
            ->get(route('outbound.show', $shipped->outboundOrder))
            ->assertOk()
            ->assertDontSee(route('outbound.pack', $shipped->outboundOrder));
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

    public function test_guest_and_tenant_user_cannot_access_pack_check(): void
    {
        [$tenant, $tenantUser] = $this->tenantUser();

        $this->get(route('fulfillment.pack.start'))->assertForbidden();
        $this->actingAs($tenantUser)->get(route('fulfillment.pack.start'))->assertForbidden();

        $warehouse = Warehouse::factory()->create();
        $shop = Shop::factory()->for($tenant)->create();
        $stockItem = StockItem::factory()->for($tenant)->create();
        $sku = Sku::factory()->for($tenant)->for($shop)->for($stockItem)->create();
        app(InventoryService::class)->adjustStock($tenant->id, $warehouse->id, $stockItem->id, 5);
        $order = $this->readySalesOrder($tenant, $shop, $sku, 1, 'SO-TENANT-PACK');
        $this->createGroup($tenant, $warehouse, $order->ship_together_key, [$order], $this->internalUser());
        $group = FulfillmentGroup::firstOrFail();

        $this->actingAs($tenantUser)->get(route('outbound.pack', $group->outboundOrder))->assertForbidden();
    }

    public function test_pack_page_shows_create_issue_link_with_group_context(): void
    {
        [$tenant, $warehouse, $shop, $sku] = $this->skuWithStock(20);
        $order = $this->readySalesOrder($tenant, $shop, $sku, 1, 'SO-PACK-ISSUE-LINK');
        $this->createGroup($tenant, $warehouse, $order->ship_together_key, [$order]);
        $group = FulfillmentGroup::firstOrFail();

        $this->actingAs($this->internalUser())
            ->get(route('outbound.pack', $group->outboundOrder))
            ->assertOk()
            ->assertSee(__('issues.btn_create'))
            ->assertSee(route('fulfillment-groups.issues.create', $group), false);
    }

    public function test_pack_line_issue_link_includes_sku_stock_and_remaining_quantity_context(): void
    {
        [$tenant, $warehouse, $shop, $sku] = $this->skuWithStock(20);
        $order = $this->readySalesOrder($tenant, $shop, $sku, 3, 'SO-PACK-LINE-ISSUE-LINK');
        $this->createGroup($tenant, $warehouse, $order->ship_together_key, [$order]);
        $group = FulfillmentGroup::firstOrFail();

        $this->actingAs($this->internalUser())
            ->get(route('outbound.pack', $group->outboundOrder))
            ->assertOk()
            ->assertSee('sku_id='.$sku->id, false)
            ->assertSee('stock_item_id='.$sku->stock_item_id, false)
            ->assertSee('qty=3', false);
    }

    public function test_pack_start_requires_warehouse_and_shipping_method_before_scan(): void
    {
        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentPackStart::class)
            ->set('scan', '1234-5678')
            ->call('search')
            ->assertSee('Please select warehouse and shipping method first.');
    }

    public function test_pack_start_preselects_warehouse_when_only_one_active_warehouse_exists(): void
    {
        $warehouse = Warehouse::factory()->create(['status' => 'active']);
        Warehouse::factory()->create(['status' => 'inactive']);

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentPackStart::class)
            ->assertSet('warehouseId', (string) $warehouse->id);
    }

    public function test_pack_start_keeps_station_filters_in_url_state(): void
    {
        $warehouse = Warehouse::factory()->create(['status' => 'active']);
        $method = ShippingMethod::where('code', 'yamato_nekopos')->firstOrFail();

        Livewire::withQueryParams([
            'warehouse_id' => (string) $warehouse->id,
            'shipping_method_id' => (string) $method->id,
        ])
            ->actingAs($this->internalUser())
            ->test(FulfillmentPackStart::class)
            ->assertSet('warehouseId', (string) $warehouse->id)
            ->assertSet('shippingMethodId', (string) $method->id);
    }

    public function test_pack_start_refocuses_after_station_filters_and_failed_scan(): void
    {
        $warehouse = Warehouse::factory()->create(['status' => 'active']);
        $method = ShippingMethod::where('code', 'yamato_nekopos')->firstOrFail();

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentPackStart::class)
            ->set('shippingMethodId', (string) $method->id)
            ->assertDispatched('pack-scan-focus')
            ->set('warehouseId', (string) $warehouse->id)
            ->assertDispatched('pack-scan-focus')
            ->set('scan', 'NO-SUCH-LABEL')
            ->call('search')
            ->assertSet('lastScan', 'NO-SUCH-LABEL')
            ->assertSee('Last scan: NO-SUCH-LABEL')
            ->assertDispatched('pack-scan-focus');
    }

    public function test_pack_start_finds_group_by_group_tracking_number(): void
    {
        [$tenant, $warehouse, $shop, $sku] = $this->skuWithStock(20);
        $method = ShippingMethod::where('code', 'yamato_nekopos')->firstOrFail();
        $order = $this->readySalesOrder($tenant, $shop, $sku, 1, 'SO-PACK-GROUP-TRACK');
        $this->createGroup($tenant, $warehouse, $order->ship_together_key, [$order]);
        $group = FulfillmentGroup::firstOrFail();
        $group->update(['shipping_method_id' => $method->id, 'tracking_no' => '123456789012']);
        $group->outboundOrder->update(['shipping_method_id' => $method->id, 'tracking_no' => '123456789012']);

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentPackStart::class)
            ->set('warehouseId', (string) $warehouse->id)
            ->set('shippingMethodId', (string) $method->id)
            ->set('scan', '1234-5678-9012')
            ->call('search')
            ->assertRedirect(route('outbound.pack', $group->outboundOrder));
    }

    public function test_pack_start_finds_old_hyphenated_tracking_after_backfill(): void
    {
        [$tenant, $warehouse, $shop, $sku] = $this->skuWithStock(20);
        $method = ShippingMethod::where('code', 'yamato_nekopos')->firstOrFail();
        $order = $this->readySalesOrder($tenant, $shop, $sku, 1, 'SO-PACK-BACKFILL');
        $this->createGroup($tenant, $warehouse, $order->ship_together_key, [$order]);
        $group = FulfillmentGroup::firstOrFail();
        $group->update(['shipping_method_id' => $method->id]);
        DB::table('fulfillment_groups')->where('id', $group->id)->update(['tracking_no' => '1234-5678-9012']);
        $group->outboundOrder->update(['shipping_method_id' => $method->id, 'tracking_no' => '123456789012']);

        app(BackfillNormalizedTrackingNumbers::class)->handle();

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentPackStart::class)
            ->set('warehouseId', (string) $warehouse->id)
            ->set('shippingMethodId', (string) $method->id)
            ->set('scan', '123456789012')
            ->call('search')
            ->assertRedirect(route('outbound.pack', $group->outboundOrder));
    }

    public function test_pack_start_finds_group_by_group_order_tracking_number(): void
    {
        [$tenant, $warehouse, $shop, $sku] = $this->skuWithStock(20);
        $method = ShippingMethod::where('code', 'yamato_nekopos')->firstOrFail();
        $order = $this->readySalesOrder($tenant, $shop, $sku, 1, 'SO-PACK-PIVOT-TRACK');
        $this->createGroup($tenant, $warehouse, $order->ship_together_key, [$order]);
        $group = FulfillmentGroup::firstOrFail();
        $group->update(['shipping_method_id' => $method->id]);
        $group->outboundOrder->update(['shipping_method_id' => $method->id, 'tracking_no' => 'PIVOT123']);

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentPackStart::class)
            ->set('warehouseId', (string) $warehouse->id)
            ->set('shippingMethodId', (string) $method->id)
            ->set('scan', 'pivot-123')
            ->call('search')
            ->assertRedirect(route('outbound.pack', $group->outboundOrder));
    }

    public function test_pack_start_finds_group_by_sales_order_tracking_number(): void
    {
        [$tenant, $warehouse, $shop, $sku] = $this->skuWithStock(20);
        $method = ShippingMethod::where('code', 'yamato_nekopos')->firstOrFail();
        $order = $this->readySalesOrder($tenant, $shop, $sku, 1, 'SO-PACK-SALES-TRACK');
        $order->update(['tracking_no' => 'SALES123']);
        $this->createGroup($tenant, $warehouse, $order->ship_together_key, [$order]);
        $group = FulfillmentGroup::firstOrFail();
        $group->update(['shipping_method_id' => $method->id]);
        $group->outboundOrder->update(['shipping_method_id' => $method->id]);

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentPackStart::class)
            ->set('warehouseId', (string) $warehouse->id)
            ->set('shippingMethodId', (string) $method->id)
            ->set('scan', 'sales 123')
            ->call('search')
            ->assertRedirect(route('outbound.pack', $group->outboundOrder));
    }

    public function test_pack_start_finds_group_by_outbound_order_tracking_number(): void
    {
        [$tenant, $warehouse, $shop, $sku] = $this->skuWithStock(20);
        $method = ShippingMethod::where('code', 'yamato_nekopos')->firstOrFail();
        $order = $this->readySalesOrder($tenant, $shop, $sku, 1, 'SO-PACK-OUTBOUND-TRACK');
        $this->createGroup($tenant, $warehouse, $order->ship_together_key, [$order]);
        $group = FulfillmentGroup::firstOrFail();
        $group->update(['shipping_method_id' => $method->id]);
        $group->outboundOrder->update(['shipping_method_id' => $method->id, 'tracking_no' => 'OUTBOUND123']);

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentPackStart::class)
            ->set('warehouseId', (string) $warehouse->id)
            ->set('shippingMethodId', (string) $method->id)
            ->set('scan', 'outbound/123')
            ->call('search')
            ->assertRedirect(route('outbound.pack', $group->outboundOrder));
    }

    public function test_pack_start_does_not_match_wrong_station_or_blocked_status(): void
    {
        [$tenant, $warehouse, $shop, $sku] = $this->skuWithStock(20);
        $otherWarehouse = Warehouse::factory()->create();
        $method = ShippingMethod::where('code', 'yamato_nekopos')->firstOrFail();
        $otherMethod = ShippingMethod::where('code', 'sagawa_thb')->firstOrFail();
        $order = $this->readySalesOrder($tenant, $shop, $sku, 1, 'SO-PACK-MISMATCH');
        $this->createGroup($tenant, $warehouse, $order->ship_together_key, [$order]);
        $group = FulfillmentGroup::firstOrFail();
        $group->update(['shipping_method_id' => $method->id, 'tracking_no' => 'STATION123']);

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentPackStart::class)
            ->set('warehouseId', (string) $otherWarehouse->id)
            ->set('shippingMethodId', (string) $method->id)
            ->set('scan', 'STATION123')
            ->call('search')
            ->assertSee('No matching fulfillment group found for this warehouse and shipping method.');

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentPackStart::class)
            ->set('warehouseId', (string) $warehouse->id)
            ->set('shippingMethodId', (string) $otherMethod->id)
            ->set('scan', 'STATION123')
            ->call('search')
            ->assertSee('No matching fulfillment group found for this warehouse and shipping method.');

        $group->update(['status' => FulfillmentGroup::STATUS_SHIPPED]);

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentPackStart::class)
            ->set('warehouseId', (string) $warehouse->id)
            ->set('shippingMethodId', (string) $method->id)
            ->set('scan', 'STATION123')
            ->call('search')
            ->assertSee('No matching fulfillment group found for this warehouse and shipping method.');
    }

    public function test_pack_start_multiple_matches_blocks_and_tracking_lookup_stays_scoped_in_sql(): void
    {
        [$tenant, $warehouse, $shop, $sku] = $this->skuWithStock(20);
        $method = ShippingMethod::where('code', 'yamato_nekopos')->firstOrFail();
        $orderA = $this->readySalesOrder($tenant, $shop, $sku, 1, 'SO-MULTI-A');
        $orderB = $this->readySalesOrder($tenant, $shop, $sku, 1, 'SO-MULTI-B', addressLine1: '2 Shared Street');
        $this->createGroup($tenant, $warehouse, $orderA->ship_together_key, [$orderA]);
        $this->createGroup($tenant, $warehouse, $orderB->ship_together_key, [$orderB]);
        FulfillmentGroup::query()->update(['shipping_method_id' => $method->id]);
        OutboundOrder::query()->whereNotNull('fulfillment_group_id')->update(['shipping_method_id' => $method->id, 'tracking_no' => 'DUPTRACK']);

        DB::flushQueryLog();
        DB::enableQueryLog();

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentPackStart::class)
            ->set('warehouseId', (string) $warehouse->id)
            ->set('shippingMethodId', (string) $method->id)
            ->set('scan', 'DUP-TRACK')
            ->call('search')
            ->assertSee('Multiple matches found.');

        $outboundQueries = collect(DB::getQueryLog())
            ->pluck('query')
            ->filter(fn (string $query): bool => str_contains($query, 'from "outbound_orders"'))
            ->values();

        DB::disableQueryLog();

        $this->assertTrue($outboundQueries->contains(fn (string $query): bool => str_contains($query, '"warehouse_id"') && str_contains($query, '"shipping_method_id"') && str_contains($query, '"status"')));
        $this->assertFalse($outboundQueries->contains(fn (string $query): bool => preg_match('/from "outbound_orders" where "tenant_id" in \\([^)]*\\)$/', $query) === 1));
    }

    public function test_pack_page_scans_sku_and_stock_item_barcodes_and_persists_progress(): void
    {
        [$tenant, $warehouse, $shop, $sku] = $this->skuWithStock(20);
        $sku->update(['barcode' => 'SKU-BAR-001']);
        $sku->stockItem->update(['barcode' => 'STOCK-BAR-001']);
        $order = $this->readySalesOrder($tenant, $shop, $sku, 2, 'SO-PACK-SCAN');
        $this->createGroup($tenant, $warehouse, $order->ship_together_key, [$order]);
        $group = FulfillmentGroup::firstOrFail();

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentGroupPack::class, ['order' => $group->outboundOrder])
            ->set('packMode', 'strict')
            ->assertSee('2')
            ->set('barcode', 'SKU-BAR-001')
            ->call('scan')
            ->assertSee('Scanned '.$sku->sku)
            ->set('barcode', 'STOCK-BAR-001')
            ->call('scan')
            ->assertSee('Ready to mark shipped.');

        $this->assertSame(2, FulfillmentPackScan::where('result', FulfillmentPackScan::RESULT_ACCEPTED)->count());

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentGroupPack::class, ['order' => $group->outboundOrder])
            ->assertSee('Ready to mark shipped.')
            ->assertSee('2');
    }

    public function test_inactive_alias_does_not_match_pack_scan(): void
    {
        [$tenant, $warehouse, $shop, $sku] = $this->skuWithStock(20);
        $order = $this->readySalesOrder($tenant, $shop, $sku, 1, 'SO-PACK-INACTIVE-ALIAS');
        $this->createGroup($tenant, $warehouse, $order->ship_together_key, [$order]);
        $group = FulfillmentGroup::firstOrFail();
        $this->createBarcodeAlias($tenant, BarcodeAlias::MODEL_TYPE_SKU, $sku->id, 'INACTIVE-ALIAS', false);

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentGroupPack::class, ['order' => $group->outboundOrder])
            ->set('barcode', 'INACTIVE-ALIAS')
            ->call('scan')
            ->assertSee('Barcode does not match this shipment.');

        $this->assertSame(0, FulfillmentPackScan::where('result', FulfillmentPackScan::RESULT_ACCEPTED)->count());
    }

    public function test_sku_alias_matches_pack_scan(): void
    {
        [$tenant, $warehouse, $shop, $sku] = $this->skuWithStock(20);
        $order = $this->readySalesOrder($tenant, $shop, $sku, 1, 'SO-PACK-SKU-ALIAS');
        $this->createGroup($tenant, $warehouse, $order->ship_together_key, [$order]);
        $group = FulfillmentGroup::firstOrFail();
        $this->createBarcodeAlias($tenant, BarcodeAlias::MODEL_TYPE_SKU, $sku->id, 'SKU ALIAS-001');

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentGroupPack::class, ['order' => $group->outboundOrder])
            ->set('barcode', 'sku-alias001')
            ->call('scan')
            ->assertSee('Ready to mark shipped.');

        $this->assertDatabaseHas('fulfillment_pack_scans', [
            'fulfillment_group_id' => $group->id,
            'sku_id' => $sku->id,
            'stock_item_id' => $sku->stock_item_id,
            'barcode_scanned' => 'sku-alias001',
            'normalized_barcode' => 'SKUALIAS001',
            'result' => FulfillmentPackScan::RESULT_ACCEPTED,
        ]);
    }

    public function test_stock_item_alias_matches_pack_scan(): void
    {
        [$tenant, $warehouse, $shop, $sku] = $this->skuWithStock(20);
        $order = $this->readySalesOrder($tenant, $shop, $sku, 1, 'SO-PACK-STOCK-ALIAS');
        $this->createGroup($tenant, $warehouse, $order->ship_together_key, [$order]);
        $group = FulfillmentGroup::firstOrFail();
        $this->createBarcodeAlias($tenant, BarcodeAlias::MODEL_TYPE_STOCK_ITEM, $sku->stock_item_id, 'STOCK ALIAS-001');

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentGroupPack::class, ['order' => $group->outboundOrder])
            ->set('barcode', 'stock-alias001')
            ->call('scan')
            ->assertSee('Ready to mark shipped.');

        $this->assertSame(1, FulfillmentPackScan::where('result', FulfillmentPackScan::RESULT_ACCEPTED)->count());
    }

    public function test_virtual_bundle_component_stock_item_alias_matches_pack_scan(): void
    {
        $tenant = Tenant::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $shop = Shop::factory()->for($tenant)->create();
        $componentStock = StockItem::factory()->for($tenant)->create(['code' => $tenant->code.'-000002']);
        $bundleSku = Sku::factory()->virtualBundle()->for($tenant)->for($shop)->create(['sku' => 'SKU-BUNDLE-ALIAS']);
        SkuBundleComponent::factory()
            ->for($tenant)
            ->for($bundleSku, 'bundleSku')
            ->for($componentStock, 'componentStockItem')
            ->create(['quantity' => 1]);
        app(InventoryService::class)->adjustStock($tenant->id, $warehouse->id, $componentStock->id, 10);
        $order = $this->readySalesOrder($tenant, $shop, $bundleSku, 1, 'SO-PACK-COMPONENT-ALIAS');
        $this->createGroup($tenant, $warehouse, $order->ship_together_key, [$order]);
        $group = FulfillmentGroup::firstOrFail();
        $this->createBarcodeAlias($tenant, BarcodeAlias::MODEL_TYPE_STOCK_ITEM, $componentStock->id, 'COMPONENT ALIAS-001');

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentGroupPack::class, ['order' => $group->outboundOrder])
            ->set('barcode', 'component-alias001')
            ->call('scan')
            ->assertSee('Ready to mark shipped.');

        $this->assertDatabaseHas('fulfillment_pack_scans', [
            'fulfillment_group_id' => $group->id,
            'sku_id' => null,
            'stock_item_id' => $componentStock->id,
            'result' => FulfillmentPackScan::RESULT_ACCEPTED,
        ]);
    }

    public function test_existing_direct_sku_barcode_stock_barcode_and_sku_code_scans_still_work(): void
    {
        [$tenant, $warehouse, $shop, $sku] = $this->skuWithStock(20);
        $sku->update(['barcode' => 'DIRECT-SKU-BAR', 'sku' => 'DIRECT-SKU-CODE']);
        $sku->stockItem->update(['barcode' => 'DIRECT-STOCK-BAR']);
        $first = $this->readySalesOrder($tenant, $shop, $sku, 1, 'SO-PACK-DIRECT-1');
        $second = $this->readySalesOrder($tenant, $shop, $sku, 1, 'SO-PACK-DIRECT-2');
        $third = $this->readySalesOrder($tenant, $shop, $sku, 1, 'SO-PACK-DIRECT-3');
        $this->createGroup($tenant, $warehouse, $first->ship_together_key, [$first, $second, $third]);
        $group = FulfillmentGroup::firstOrFail();

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentGroupPack::class, ['order' => $group->outboundOrder])
            ->set('packMode', 'strict')
            ->set('barcode', 'direct-sku-bar')
            ->call('scan')
            ->set('barcode', 'direct-stock-bar')
            ->call('scan')
            ->set('barcode', 'direct-sku-code')
            ->call('scan')
            ->assertSee('Ready to mark shipped.');

        $this->assertSame(3, FulfillmentPackScan::where('result', FulfillmentPackScan::RESULT_ACCEPTED)->count());
    }

    public function test_pack_progress_sums_scan_quantity(): void
    {
        [$tenant, $warehouse, $shop, $sku] = $this->skuWithStock(20);
        $sku->update(['barcode' => 'SKU-BAR-QTY-SUM']);
        $order = $this->readySalesOrder($tenant, $shop, $sku, 3, 'SO-PACK-QTY-SUM');
        $this->createGroup($tenant, $warehouse, $order->ship_together_key, [$order]);
        $group = FulfillmentGroup::firstOrFail();

        FulfillmentPackScan::create([
            'tenant_id' => $tenant->id,
            'fulfillment_group_id' => $group->id,
            'outbound_order_id' => $group->outboundOrder->id,
            'sku_id' => $sku->id,
            'stock_item_id' => $sku->stock_item_id,
            'barcode_scanned' => 'SKU-BAR-QTY-SUM',
            'normalized_barcode' => 'SKU-BAR-QTY-SUM',
            'result' => FulfillmentPackScan::RESULT_ACCEPTED,
            'quantity' => 3,
            'message' => 'Scanned quantity',
            'scanned_by_user_id' => $this->internalUser()->id,
        ]);

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentGroupPack::class, ['order' => $group->outboundOrder])
            ->assertSee('Ready to mark shipped.')
            ->assertSee('3');
    }

    public function test_virtual_bundle_component_pack_progress_sums_quantity_by_stock_item(): void
    {
        $tenant = Tenant::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $shop = Shop::factory()->for($tenant)->create();
        $componentStock = StockItem::factory()->for($tenant)->create(['code' => $tenant->code.'-000003']);
        $bundleSku = Sku::factory()->virtualBundle()->for($tenant)->for($shop)->create(['sku' => 'SKU-BUNDLE-PROGRESS']);
        SkuBundleComponent::factory()
            ->for($tenant)
            ->for($bundleSku, 'bundleSku')
            ->for($componentStock, 'componentStockItem')
            ->create(['quantity' => 2]);
        app(InventoryService::class)->adjustStock($tenant->id, $warehouse->id, $componentStock->id, 10);
        $order = $this->readySalesOrder($tenant, $shop, $bundleSku, 2, 'SO-PACK-BUNDLE-PROGRESS');
        $this->createGroup($tenant, $warehouse, $order->ship_together_key, [$order]);
        $group = FulfillmentGroup::firstOrFail();

        FulfillmentPackScan::create([
            'tenant_id' => $tenant->id,
            'fulfillment_group_id' => $group->id,
            'outbound_order_id' => $group->outboundOrder->id,
            'sku_id' => null,
            'stock_item_id' => $componentStock->id,
            'barcode_scanned' => 'COMPONENT-QTY',
            'normalized_barcode' => 'COMPONENT-QTY',
            'result' => FulfillmentPackScan::RESULT_ACCEPTED,
            'quantity' => 3,
            'message' => 'Component quantity',
            'scanned_by_user_id' => $this->internalUser()->id,
        ]);

        $line = collect(app(FulfillmentPackService::class)->packLinesWithProgress($group->outboundOrder))->first();

        $this->assertSame(null, $line['sku_id']);
        $this->assertSame($componentStock->id, $line['stock_item_id']);
        $this->assertSame(4, $line['required_qty']);
        $this->assertSame(3, $line['scanned_qty']);
        $this->assertSame(1, $line['remaining_qty']);
        $this->assertSame('in_progress', $line['status']);
    }

    public function test_pack_lines_with_progress_uses_one_scan_quantity_query_for_multiple_lines(): void
    {
        [$tenant, $warehouse, $shop, $skuA] = $this->skuWithStock(20);
        $stockItemB = StockItem::factory()->for($tenant)->create(['code' => $tenant->code.'-000010']);
        $stockItemC = StockItem::factory()->for($tenant)->create(['code' => $tenant->code.'-000011']);
        $skuB = Sku::factory()->for($tenant)->for($shop)->for($stockItemB)->create(['sku' => 'SKU-PERF-B']);
        $skuC = Sku::factory()->for($tenant)->for($shop)->for($stockItemC)->create(['sku' => 'SKU-PERF-C']);
        app(InventoryService::class)->adjustStock($tenant->id, $warehouse->id, $stockItemB->id, 20);
        app(InventoryService::class)->adjustStock($tenant->id, $warehouse->id, $stockItemC->id, 20);
        $orderA = $this->readySalesOrder($tenant, $shop, $skuA, 2, 'SO-PACK-PERF-A');
        $orderB = $this->readySalesOrder($tenant, $shop, $skuB, 2, 'SO-PACK-PERF-B');
        $orderC = $this->readySalesOrder($tenant, $shop, $skuC, 2, 'SO-PACK-PERF-C');
        $this->createGroup($tenant, $warehouse, $orderA->ship_together_key, [$orderA, $orderB, $orderC]);
        $group = FulfillmentGroup::firstOrFail();

        foreach ([$skuA, $skuB, $skuC] as $sku) {
            FulfillmentPackScan::create([
                'tenant_id' => $tenant->id,
                'fulfillment_group_id' => $group->id,
                'outbound_order_id' => $group->outboundOrder->id,
                'sku_id' => $sku->id,
                'stock_item_id' => $sku->stock_item_id,
                'barcode_scanned' => $sku->sku,
                'normalized_barcode' => $sku->sku,
                'result' => FulfillmentPackScan::RESULT_ACCEPTED,
                'quantity' => 1,
                'message' => 'Accepted',
                'scanned_by_user_id' => $this->internalUser()->id,
            ]);
        }

        $queries = [];
        DB::listen(function (\Illuminate\Database\Events\QueryExecuted $query) use (&$queries): void {
            if (str_contains($query->sql, 'fulfillment_pack_scans')) {
                $queries[] = $query->sql;
            }
        });

        $lines = app(FulfillmentPackService::class)->packLinesWithProgress($group->outboundOrder);

        $this->assertCount(3, $lines);
        $this->assertSame([1, 1, 1], collect($lines)->pluck('scanned_qty')->all());
        $this->assertCount(1, $queries);
        $this->assertStringContainsString('group by', strtolower($queries[0]));
    }

    public function test_accepted_scan_sets_last_scanned_line_key_and_marker(): void
    {
        [$tenant, $warehouse, $shop, $sku] = $this->skuWithStock(20);
        $sku->update(['barcode' => 'SKU-BAR-LAST']);
        $order = $this->readySalesOrder($tenant, $shop, $sku, 1, 'SO-PACK-LAST');
        $this->createGroup($tenant, $warehouse, $order->ship_together_key, [$order]);
        $group = FulfillmentGroup::firstOrFail();
        $lineKey = 'sku:'.$sku->id.':stock:'.$sku->stock_item_id;

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentGroupPack::class, ['order' => $group->outboundOrder])
            ->set('barcode', 'SKU-BAR-LAST')
            ->call('scan')
            ->assertSet('lastScannedLineKey', $lineKey)
            ->assertSee('Last scan');
    }

    public function test_wrong_item_scan_does_not_mark_product_line_as_last_scanned(): void
    {
        [$tenant, $warehouse, $shop, $sku] = $this->skuWithStock(20);
        $order = $this->readySalesOrder($tenant, $shop, $sku, 1, 'SO-PACK-WRONG-LAST');
        $this->createGroup($tenant, $warehouse, $order->ship_together_key, [$order]);
        $group = FulfillmentGroup::firstOrFail();

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentGroupPack::class, ['order' => $group->outboundOrder])
            ->set('barcode', 'WRONG-LAST')
            ->call('scan')
            ->assertSet('lastScannedLineKey', null)
            ->assertDontSee('Last scan');
    }

    public function test_quantity_confirm_marks_line_as_last_scanned(): void
    {
        [$tenant, $warehouse, $shop, $sku] = $this->skuWithStock(20);
        $sku->update(['barcode' => 'SKU-BAR-QTY-LAST']);
        $order = $this->readySalesOrder($tenant, $shop, $sku, 3, 'SO-PACK-QTY-LAST');
        $this->createGroup($tenant, $warehouse, $order->ship_together_key, [$order]);
        $group = FulfillmentGroup::firstOrFail();
        $lineKey = 'sku:'.$sku->id.':stock:'.$sku->stock_item_id;

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentGroupPack::class, ['order' => $group->outboundOrder])
            ->set('barcode', 'SKU-BAR-QTY-LAST')
            ->call('scan')
            ->set('pendingQuantity', 2)
            ->call('confirmPendingQuantity')
            ->assertSet('lastScannedLineKey', $lineKey)
            ->assertSee('Last scan');
    }

    public function test_pack_progress_summary_counts_lines_quantities_and_group_exceptions(): void
    {
        [$tenant, $warehouse, $shop, $sku] = $this->skuWithStock(20);
        $order = $this->readySalesOrder($tenant, $shop, $sku, 3, 'SO-PACK-PROGRESS-SUMMARY');
        $this->createGroup($tenant, $warehouse, $order->ship_together_key, [$order]);
        $group = FulfillmentGroup::firstOrFail();
        FulfillmentPackScan::create([
            'tenant_id' => $tenant->id,
            'fulfillment_group_id' => $group->id,
            'outbound_order_id' => $group->outboundOrder->id,
            'sku_id' => $sku->id,
            'stock_item_id' => $sku->stock_item_id,
            'barcode_scanned' => 'SUMMARY-OK',
            'normalized_barcode' => 'SUMMARY-OK',
            'result' => FulfillmentPackScan::RESULT_ACCEPTED,
            'quantity' => 2,
            'message' => 'Summary accepted',
            'scanned_by_user_id' => $this->internalUser()->id,
        ]);
        FulfillmentPackScan::create([
            'tenant_id' => $tenant->id,
            'fulfillment_group_id' => $group->id,
            'outbound_order_id' => $group->outboundOrder->id,
            'barcode_scanned' => 'SUMMARY-WRONG',
            'normalized_barcode' => 'SUMMARY-WRONG',
            'result' => FulfillmentPackScan::RESULT_WRONG_ITEM,
            'quantity' => 1,
            'message' => 'Summary wrong',
            'scanned_by_user_id' => $this->internalUser()->id,
        ]);
        [$otherTenant, $otherWarehouse, $otherShop, $otherSku] = $this->skuWithStock(5);
        $otherOrder = $this->readySalesOrder($otherTenant, $otherShop, $otherSku, 1, 'SO-PACK-OTHER-EXCEPTION');
        $this->createGroup($otherTenant, $otherWarehouse, $otherOrder->ship_together_key, [$otherOrder]);
        $otherGroup = FulfillmentGroup::query()->whereKeyNot($group->id)->firstOrFail();
        FulfillmentPackScan::create([
            'tenant_id' => $otherTenant->id,
            'fulfillment_group_id' => $otherGroup->id,
            'outbound_order_id' => $otherGroup->outboundOrder->id,
            'barcode_scanned' => 'OTHER-WRONG',
            'normalized_barcode' => 'OTHER-WRONG',
            'result' => FulfillmentPackScan::RESULT_WRONG_ITEM,
            'quantity' => 1,
            'message' => 'Other wrong',
            'scanned_by_user_id' => $this->internalUser()->id,
        ]);

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentGroupPack::class, ['order' => $group->outboundOrder])
            ->assertSee('Lines complete')
            ->assertSee('0 / 1')
            ->assertSee('Qty scanned')
            ->assertSee('2 / 3')
            ->assertSee('Remaining')
            ->assertSee('1')
            ->assertSee('Exceptions')
            ->assertSee('1');
    }

    public function test_normal_mode_scan_with_remaining_quantity_shows_pending_prompt(): void
    {
        [$tenant, $warehouse, $shop, $sku] = $this->skuWithStock(20);
        $sku->update(['barcode' => 'SKU-BAR-QTY-PROMPT']);
        $order = $this->readySalesOrder($tenant, $shop, $sku, 3, 'SO-PACK-QTY-PROMPT');
        $this->createGroup($tenant, $warehouse, $order->ship_together_key, [$order]);
        $group = FulfillmentGroup::firstOrFail();

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentGroupPack::class, ['order' => $group->outboundOrder])
            ->set('barcode', 'SKU-BAR-QTY-PROMPT')
            ->call('scan')
            ->assertSet('pendingQuantity', 1)
            ->assertDispatched('pack-quantity-focus')
            ->assertSee('Quantity')
            ->assertSee('Add')
            ->assertSee('Remaining');

        $this->assertSame(0, FulfillmentPackScan::where('result', FulfillmentPackScan::RESULT_ACCEPTED)->count());
    }

    public function test_confirming_pending_quantity_adds_that_quantity(): void
    {
        [$tenant, $warehouse, $shop, $sku] = $this->skuWithStock(20);
        $sku->update(['barcode' => 'SKU-BAR-QTY-ADD']);
        $order = $this->readySalesOrder($tenant, $shop, $sku, 3, 'SO-PACK-QTY-ADD');
        $this->createGroup($tenant, $warehouse, $order->ship_together_key, [$order]);
        $group = FulfillmentGroup::firstOrFail();

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentGroupPack::class, ['order' => $group->outboundOrder])
            ->set('barcode', 'SKU-BAR-QTY-ADD')
            ->call('scan')
            ->set('pendingQuantity', 3)
            ->call('confirmPendingQuantity')
            ->assertSet('pendingQuantityScan', null)
            ->assertSee('Ready to mark shipped.')
            ->assertSee('Scanned '.$sku->sku.' x 3');

        $this->assertSame(3, (int) FulfillmentPackScan::where('result', FulfillmentPackScan::RESULT_ACCEPTED)->sum('quantity'));
        $this->assertSame(1, FulfillmentPackScan::where('result', FulfillmentPackScan::RESULT_ACCEPTED)->count());
    }

    public function test_pending_quantity_confirmation_is_blocked_if_group_becomes_unpackable(): void
    {
        foreach ([FulfillmentGroup::STATUS_SHIPPED, FulfillmentGroup::STATUS_CANCELLED] as $status) {
            [$tenant, $warehouse, $shop, $sku] = $this->skuWithStock(20);
            $sku->update(['barcode' => 'SKU-BAR-QTY-BLOCK-'.$status]);
            $order = $this->readySalesOrder($tenant, $shop, $sku, 3, 'SO-PACK-QTY-BLOCK-'.$status);
            $this->createGroup($tenant, $warehouse, $order->ship_together_key, [$order]);
            $group = FulfillmentGroup::query()->latest('id')->firstOrFail();

            $component = Livewire::actingAs($this->internalUser())
                ->test(FulfillmentGroupPack::class, ['order' => $group->outboundOrder])
                ->set('barcode', 'SKU-BAR-QTY-BLOCK-'.$status)
                ->call('scan')
                ->assertSet('pendingQuantity', 1);

            $group->update(['status' => $status]);
            $group->outboundOrder->update(['status' => $status]);

            $component
                ->set('pendingQuantity', 3)
                ->call('confirmPendingQuantity')
                ->assertSet('pendingQuantityScan', null)
                ->assertSee($status === FulfillmentGroup::STATUS_SHIPPED
                    ? 'This fulfillment group is already shipped.'
                    : 'This fulfillment group is cancelled.');

            $this->assertSame(0, FulfillmentPackScan::where('fulfillment_group_id', $group->id)
                ->where('result', FulfillmentPackScan::RESULT_ACCEPTED)
                ->count());
            $this->assertSame(1, FulfillmentPackScan::where('fulfillment_group_id', $group->id)
                ->where('result', FulfillmentPackScan::RESULT_BLOCKED_STATUS)
                ->count());
        }
    }

    public function test_pending_quantity_cannot_exceed_remaining_quantity(): void
    {
        [$tenant, $warehouse, $shop, $sku] = $this->skuWithStock(20);
        $sku->update(['barcode' => 'SKU-BAR-QTY-CLAMP']);
        $order = $this->readySalesOrder($tenant, $shop, $sku, 3, 'SO-PACK-QTY-CLAMP');
        $this->createGroup($tenant, $warehouse, $order->ship_together_key, [$order]);
        $group = FulfillmentGroup::firstOrFail();

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentGroupPack::class, ['order' => $group->outboundOrder])
            ->set('barcode', 'SKU-BAR-QTY-CLAMP')
            ->call('scan')
            ->set('pendingQuantity', 99)
            ->call('confirmPendingQuantity')
            ->assertSee('Ready to mark shipped.');

        $this->assertSame(3, (int) FulfillmentPackScan::where('result', FulfillmentPackScan::RESULT_ACCEPTED)->sum('quantity'));
    }

    public function test_remaining_quantity_one_accepts_immediately_without_prompt(): void
    {
        [$tenant, $warehouse, $shop, $sku] = $this->skuWithStock(20);
        $sku->update(['barcode' => 'SKU-BAR-QTY-ONE']);
        $order = $this->readySalesOrder($tenant, $shop, $sku, 1, 'SO-PACK-QTY-ONE');
        $this->createGroup($tenant, $warehouse, $order->ship_together_key, [$order]);
        $group = FulfillmentGroup::firstOrFail();

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentGroupPack::class, ['order' => $group->outboundOrder])
            ->set('barcode', 'SKU-BAR-QTY-ONE')
            ->call('scan')
            ->assertSet('pendingQuantityScan', null)
            ->assertSee('Ready to mark shipped.');

        $this->assertSame(1, (int) FulfillmentPackScan::where('result', FulfillmentPackScan::RESULT_ACCEPTED)->sum('quantity'));
    }

    public function test_strict_mode_never_shows_quantity_prompt_and_adds_only_one(): void
    {
        [$tenant, $warehouse, $shop, $sku] = $this->skuWithStock(20);
        $sku->update(['barcode' => 'SKU-BAR-STRICT']);
        $order = $this->readySalesOrder($tenant, $shop, $sku, 3, 'SO-PACK-STRICT');
        $this->createGroup($tenant, $warehouse, $order->ship_together_key, [$order]);
        $group = FulfillmentGroup::firstOrFail();

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentGroupPack::class, ['order' => $group->outboundOrder])
            ->set('packMode', 'strict')
            ->set('barcode', 'SKU-BAR-STRICT')
            ->call('scan')
            ->assertSet('pendingQuantityScan', null)
            ->assertDontSee('Add')
            ->assertDontSee('Ready to mark shipped.');

        $this->assertSame(1, (int) FulfillmentPackScan::where('result', FulfillmentPackScan::RESULT_ACCEPTED)->sum('quantity'));
    }

    public function test_high_risk_stock_item_in_normal_mode_requires_strict_scan(): void
    {
        [$tenant, $warehouse, $shop, $sku] = $this->skuWithStock(20);
        $sku->update(['barcode' => 'SKU-BAR-RISK']);
        $sku->stockItem->update(['is_dangerous_goods' => true]);
        $order = $this->readySalesOrder($tenant, $shop, $sku, 3, 'SO-PACK-RISK');
        $this->createGroup($tenant, $warehouse, $order->ship_together_key, [$order]);
        $group = FulfillmentGroup::firstOrFail();

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentGroupPack::class, ['order' => $group->outboundOrder])
            ->assertSee('Strict scan')
            ->set('barcode', 'SKU-BAR-RISK')
            ->call('scan')
            ->assertSet('pendingQuantityScan', null)
            ->assertDontSee('Add')
            ->assertDontSee('Ready to mark shipped.');

        $this->assertSame(1, (int) FulfillmentPackScan::where('result', FulfillmentPackScan::RESULT_ACCEPTED)->sum('quantity'));
    }

    public function test_pack_page_prefers_remaining_line_when_skus_share_stock_item_barcode(): void
    {
        [$tenant, $warehouse, $shop, $skuA] = $this->skuWithStock(20);
        $stockItem = $skuA->stockItem;
        $stockItem->update(['barcode' => 'SHARED-STOCK-BAR']);
        $skuB = Sku::factory()->for($tenant)->for($shop)->for($stockItem)->create([
            'sku' => 'SKU-SHARED-B',
            'sku_type' => 'single',
        ]);
        $orderA = $this->readySalesOrder($tenant, $shop, $skuA, 1, 'SO-SHARED-A');
        $orderB = $this->readySalesOrder($tenant, $shop, $skuB, 1, 'SO-SHARED-B');

        $this->createGroup($tenant, $warehouse, $orderA->ship_together_key, [$orderA, $orderB]);
        $group = FulfillmentGroup::firstOrFail();

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentGroupPack::class, ['order' => $group->outboundOrder])
            ->set('barcode', 'SHARED-STOCK-BAR')
            ->call('scan')
            ->set('barcode', 'SHARED-STOCK-BAR')
            ->call('scan')
            ->assertSee('Ready to mark shipped.');

        $this->assertSame(2, FulfillmentPackScan::where('result', FulfillmentPackScan::RESULT_ACCEPTED)->count());
        $this->assertSame(0, FulfillmentPackScan::where('result', FulfillmentPackScan::RESULT_OVER_SCAN)->count());
        $this->assertDatabaseHas('fulfillment_pack_scans', [
            'fulfillment_group_id' => $group->id,
            'sku_id' => $skuA->id,
            'stock_item_id' => $stockItem->id,
            'result' => FulfillmentPackScan::RESULT_ACCEPTED,
        ]);
        $this->assertDatabaseHas('fulfillment_pack_scans', [
            'fulfillment_group_id' => $group->id,
            'sku_id' => $skuB->id,
            'stock_item_id' => $stockItem->id,
            'result' => FulfillmentPackScan::RESULT_ACCEPTED,
        ]);
    }

    public function test_pack_page_rejects_wrong_barcode_and_over_scan_with_audit_rows(): void
    {
        [$tenant, $warehouse, $shop, $sku] = $this->skuWithStock(20);
        $sku->update(['barcode' => 'SKU-BAR-OVER']);
        $order = $this->readySalesOrder($tenant, $shop, $sku, 1, 'SO-PACK-REJECT');
        $this->createGroup($tenant, $warehouse, $order->ship_together_key, [$order]);
        $group = FulfillmentGroup::firstOrFail();

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentGroupPack::class, ['order' => $group->outboundOrder])
            ->set('barcode', 'WRONG')
            ->call('scan')
            ->assertSee('Barcode does not match this shipment.')
            ->set('barcode', 'SKU-BAR-OVER')
            ->call('scan')
            ->set('barcode', 'SKU-BAR-OVER')
            ->call('scan')
            ->assertSee('This item is already fully scanned.');

        $this->assertDatabaseHas('fulfillment_pack_scans', [
            'fulfillment_group_id' => $group->id,
            'barcode_scanned' => 'WRONG',
            'result' => FulfillmentPackScan::RESULT_WRONG_ITEM,
            'quantity' => 1,
        ]);
        $this->assertDatabaseHas('fulfillment_pack_scans', [
            'fulfillment_group_id' => $group->id,
            'barcode_scanned' => 'SKU-BAR-OVER',
            'result' => FulfillmentPackScan::RESULT_OVER_SCAN,
            'quantity' => 1,
        ]);
        $this->assertSame(1, FulfillmentPackScan::where('result', FulfillmentPackScan::RESULT_ACCEPTED)->count());
    }

    public function test_pack_mark_shipped_requires_completion_and_uses_outbound_shipping(): void
    {
        [$tenant, $warehouse, $shop, $sku] = $this->skuWithStock(20);
        $sku->update(['barcode' => 'SKU-BAR-SHIP']);
        $order = $this->readySalesOrder($tenant, $shop, $sku, 1, 'SO-PACK-SHIP');
        $this->createGroup($tenant, $warehouse, $order->ship_together_key, [$order]);
        $group = FulfillmentGroup::with('outboundOrder')->firstOrFail();

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentGroupPack::class, ['order' => $group->outboundOrder])
            ->call('markShipped')
            ->assertSee('Scan all required items before shipping.');

        $this->assertSame(FulfillmentGroup::STATUS_RESERVED, $group->refresh()->status);

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentGroupPack::class, ['order' => $group->outboundOrder])
            ->set('barcode', 'SKU-BAR-SHIP')
            ->call('scan')
            ->call('markShipped')
            ->assertRedirect(route('outbound.show', $group->outboundOrder));

        $this->assertSame(FulfillmentGroup::STATUS_SHIPPED, $group->refresh()->status);
        $this->assertSame(OutboundOrder::STATUS_SHIPPED, $group->outboundOrder->refresh()->status);
        $this->assertSame(SalesOrder::FULFILLMENT_STATUS_SHIPPED, $order->refresh()->fulfillment_status);
        $this->assertDatabaseHas('inventory_movements', [
            'ref_type' => 'outbound_order',
            'ref_id' => (string) $group->outboundOrder->id,
        ]);
    }

    public function test_cannot_mark_shipped_while_pending_quantity_exists(): void
    {
        [$tenant, $warehouse, $shop, $sku] = $this->skuWithStock(20);
        $sku->update(['barcode' => 'SKU-BAR-PENDING-SHIP']);
        $order = $this->readySalesOrder($tenant, $shop, $sku, 2, 'SO-PACK-PENDING-SHIP');
        $this->createGroup($tenant, $warehouse, $order->ship_together_key, [$order]);
        $group = FulfillmentGroup::with('outboundOrder')->firstOrFail();

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentGroupPack::class, ['order' => $group->outboundOrder])
            ->set('barcode', 'SKU-BAR-PENDING-SHIP')
            ->call('scan')
            ->call('markShipped')
            ->assertSee('Confirm or cancel the quantity before marking shipped.');

        $this->assertSame(FulfillmentGroup::STATUS_RESERVED, $group->refresh()->status);
        $this->assertSame(0, FulfillmentPackScan::where('result', FulfillmentPackScan::RESULT_ACCEPTED)->count());
    }

    public function test_can_mark_shipped_after_quantity_scan_completes_lines(): void
    {
        [$tenant, $warehouse, $shop, $sku] = $this->skuWithStock(20);
        $sku->update(['barcode' => 'SKU-BAR-QTY-SHIP']);
        $order = $this->readySalesOrder($tenant, $shop, $sku, 3, 'SO-PACK-QTY-SHIP');
        $this->createGroup($tenant, $warehouse, $order->ship_together_key, [$order]);
        $group = FulfillmentGroup::with('outboundOrder')->firstOrFail();

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentGroupPack::class, ['order' => $group->outboundOrder])
            ->set('barcode', 'SKU-BAR-QTY-SHIP')
            ->call('scan')
            ->set('pendingQuantity', 3)
            ->call('confirmPendingQuantity')
            ->call('markShipped')
            ->assertRedirect(route('outbound.show', $group->outboundOrder));

        $this->assertSame(FulfillmentGroup::STATUS_SHIPPED, $group->refresh()->status);
        $this->assertSame(3, (int) FulfillmentPackScan::where('result', FulfillmentPackScan::RESULT_ACCEPTED)->sum('quantity'));
    }

    public function test_shipped_and_cancelled_groups_cannot_accept_new_scans(): void
    {
        [$tenant, $warehouse, $shop, $sku] = $this->skuWithStock(20);
        $sku->update(['barcode' => 'SKU-BAR-BLOCK']);
        $order = $this->readySalesOrder($tenant, $shop, $sku, 1, 'SO-PACK-BLOCK');
        $this->createGroup($tenant, $warehouse, $order->ship_together_key, [$order]);
        $group = FulfillmentGroup::firstOrFail();

        $group->update(['status' => FulfillmentGroup::STATUS_CANCELLED]);
        $group->outboundOrder->update(['status' => OutboundOrder::STATUS_CANCELLED]);

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentGroupPack::class, ['order' => $group->outboundOrder])
            ->set('barcode', 'SKU-BAR-BLOCK')
            ->call('scan')
            ->assertSee('This fulfillment group is cancelled.');

        $group->update(['status' => FulfillmentGroup::STATUS_SHIPPED]);
        $group->outboundOrder->update(['status' => OutboundOrder::STATUS_SHIPPED]);

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentGroupPack::class, ['order' => $group->outboundOrder])
            ->set('barcode', 'SKU-BAR-BLOCK')
            ->call('scan')
            ->assertSee('This fulfillment group is already shipped.');

        $this->assertSame(2, FulfillmentPackScan::where('result', FulfillmentPackScan::RESULT_BLOCKED_STATUS)->count());
    }

    public function test_pack_lines_are_built_from_outbound_leaf_lines_for_single_skus(): void
    {
        [$tenant, $warehouse, $shop, $sku] = $this->skuWithStock(20);
        $orderA = $this->readySalesOrder($tenant, $shop, $sku, 2, 'SO-PACK-OUTBOUND-A');
        $orderB = $this->readySalesOrder($tenant, $shop, $sku, 3, 'SO-PACK-OUTBOUND-B');
        $this->createGroup($tenant, $warehouse, $orderA->ship_together_key, [$orderA, $orderB]);
        $group = FulfillmentGroup::firstOrFail();

        SalesOrderLine::query()->whereIn('sales_order_id', [$orderA->id, $orderB->id])->update([
            'line_status' => SalesOrderLine::STATUS_CANCELLED,
        ]);

        $lines = app(FulfillmentPackService::class)->packLines($group->outboundOrder);

        $this->assertCount(1, $lines);
        $this->assertSame('sku:'.$sku->id.':stock:'.$sku->stock_item_id, $lines[0]['key']);
        $this->assertSame($sku->id, $lines[0]['sku_id']);
        $this->assertSame($sku->stock_item_id, $lines[0]['stock_item_id']);
        $this->assertSame(5, $lines[0]['required_qty']);
    }

    public function test_pack_lines_preserve_virtual_bundle_component_identity_from_outbound_lines(): void
    {
        $tenant = Tenant::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $shop = Shop::factory()->for($tenant)->create();
        $component = StockItem::factory()->for($tenant)->create(['code' => $tenant->code.'-000004']);
        $bundle = Sku::factory()->virtualBundle()->for($tenant)->for($shop)->create([
            'sku' => 'BUNDLE-OUTBOUND',
            'barcode' => 'BUNDLE-BAR-OUTBOUND',
        ]);
        SkuBundleComponent::factory()
            ->for($tenant)
            ->for($bundle, 'bundleSku')
            ->for($component, 'componentStockItem')
            ->create(['quantity' => 2]);
        app(InventoryService::class)->adjustStock($tenant->id, $warehouse->id, $component->id, 10);
        $order = $this->readySalesOrder($tenant, $shop, $bundle, 3, 'SO-PACK-BUNDLE-OUTBOUND');
        $this->createGroup($tenant, $warehouse, $order->ship_together_key, [$order]);
        $group = FulfillmentGroup::firstOrFail();

        SalesOrderLine::query()->where('sales_order_id', $order->id)->update([
            'line_status' => SalesOrderLine::STATUS_CANCELLED,
        ]);

        $service = app(FulfillmentPackService::class);
        $lines = $service->packLines($group->outboundOrder);

        $this->assertCount(1, $lines);
        $this->assertSame('component:'.$component->id, $lines[0]['key']);
        $this->assertNull($lines[0]['sku_id']);
        $this->assertSame($component->id, $lines[0]['stock_item_id']);
        $this->assertSame(6, $lines[0]['required_qty']);
        $this->assertTrue($service->lineMatchesScan($lines[0], $service->normalizeProductBarcode('BUNDLE-BAR-OUTBOUND')));
    }

    public function test_virtual_bundle_pack_lines_scan_component_stock_items(): void
    {
        $tenant = Tenant::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $shop = Shop::factory()->for($tenant)->create();
        $component = StockItem::factory()->for($tenant)->create(['barcode' => 'COMPONENT-BAR']);
        $bundle = Sku::factory()->virtualBundle()->for($tenant)->for($shop)->create(['sku' => 'BUNDLE-1']);
        SkuBundleComponent::factory()->for($tenant)->for($bundle, 'bundleSku')->for($component, 'componentStockItem')->create(['quantity' => 2]);
        app(InventoryService::class)->adjustStock($tenant->id, $warehouse->id, $component->id, 10);
        $order = $this->readySalesOrder($tenant, $shop, $bundle, 1, 'SO-BUNDLE-PACK');

        $this->createGroup($tenant, $warehouse, $order->ship_together_key, [$order]);
        $group = FulfillmentGroup::firstOrFail();

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentGroupPack::class, ['order' => $group->outboundOrder])
            ->set('packMode', 'strict')
            ->assertSee('BUNDLE-1')
            ->assertSee('2')
            ->set('barcode', 'COMPONENT-BAR')
            ->call('scan')
            ->set('barcode', 'COMPONENT-BAR')
            ->call('scan')
            ->assertSee('Ready to mark shipped.');
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

    private function createBarcodeAlias(Tenant $tenant, string $modelType, int $modelId, string $barcode, bool $isActive = true): BarcodeAlias
    {
        return BarcodeAlias::create([
            'tenant_id' => $tenant->id,
            'model_type' => $modelType,
            'model_id' => $modelId,
            'barcode' => $barcode,
            'normalized_barcode' => BarcodeAlias::normalize($barcode),
            'barcode_type' => 'other',
            'is_active' => $isActive,
        ]);
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
