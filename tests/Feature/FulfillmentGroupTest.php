<?php

namespace Tests\Feature;

use App\Livewire\FulfillmentGroupCreate;
use App\Livewire\FulfillmentGroupDetail;
use App\Livewire\FulfillmentGroupIndex;
use App\Livewire\FulfillmentGroupPack;
use App\Livewire\FulfillmentPackStart;
use App\Livewire\OutboundOrderDetail;
use App\Livewire\OutboundOrderShip;
use App\Models\CourierExportBatch;
use App\Models\FulfillmentGroup;
use App\Models\FulfillmentPackScan;
use App\Models\InventoryBalance;
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
use App\Services\Courier\CourierExportService;
use App\Services\Courier\TrackingImport\TrackingImportService;
use App\Services\InventoryService;
use App\Support\CourierCarrier;
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

        $yamato = ShippingMethod::where('code', 'yamato_nekopos')->firstOrFail();

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentGroupIndex::class)
            ->call('updateShippingMethod', $group->id, (string) $yamato->id);

        $this->assertSame($yamato->id, $group->refresh()->shipping_method_id);

        $inactive = ShippingMethod::where('code', 'sagawa_thb')->firstOrFail();
        $inactive->update(['status' => 'inactive']);

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentGroupIndex::class)
            ->call('updateShippingMethod', $group->id, (string) $inactive->id);

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

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentGroupIndex::class)
            ->call('updateTracking', $group->id, '  ab-123 cd  ');

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
        $group->update(['shipping_method_id' => $method->id]);
        $group->groupOrders()->update(['tracking_no' => 'FGTRACK1']);

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentGroupIndex::class)
            ->set('selectedIds', [(string) $group->id])
            ->call('markShipped')
            ->assertSet('selectedIds', []);

        $group->refresh();
        $outbound = $group->outboundOrder()->with('leafLines')->firstOrFail();
        $balance = $this->balance($tenant, $warehouse, $sku->stockItem);

        $this->assertSame(FulfillmentGroup::STATUS_SHIPPED, $group->status);
        $this->assertNotNull($group->shipped_at);
        $this->assertSame(OutboundOrder::STATUS_SHIPPED, $outbound->status);
        $this->assertSame($method->carrier->code, $outbound->courier);
        $this->assertSame($method->name, $outbound->shipping_method);
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
        $group->update(['status' => FulfillmentGroup::STATUS_SHIPPED]);

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentGroupIndex::class)
            ->set('selectedIds', [(string) $group->id])
            ->call('markShipped')
            ->assertSet('selectedIds', []);

        $this->assertSame(OutboundOrder::STATUS_PENDING, $outbound->refresh()->status);
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

        $batch = app(CourierExportService::class)->exportGroups(
            fulfillmentGroupIds: [$group->id],
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

        $batch = app(CourierExportService::class)->exportGroups(
            fulfillmentGroupIds: [$group->id],
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

        $result = app(CourierExportService::class)->validateGroupExport(
            fulfillmentGroupIds: [$group->id],
            carrier: CourierCarrier::YAMATO,
            allowedTenantIds: [$tenant->id],
        );

        $this->assertFalse($result->ok);
        $this->assertSame([$group->id], $result->wrongCarrierOrderIds);
        $this->assertNull($order->refresh()->courier_csv_exported_at);
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

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentGroupIndex::class)
            ->set('selectedIds', [(string) $group->id])
            ->call('exportYamato')
            ->assertRedirect(route('courier-export-batches.download', CourierExportBatch::firstOrFail()));

        $this->assertNotNull($order->refresh()->courier_csv_exported_at);
    }

    public function test_fulfillment_tracking_import_matches_group_reference_and_syncs_member_orders(): void
    {
        [$tenant, $warehouse, $shop, $sku] = $this->skuWithStock(20);
        $orderA = $this->readySalesOrder($tenant, $shop, $sku, 1, 'SO-FG-TRACK-A');
        $orderB = $this->readySalesOrder($tenant, $shop, $sku, 1, 'SO-FG-TRACK-B');

        $this->createGroup($tenant, $warehouse, $orderA->ship_together_key, [$orderA, $orderB]);
        $group = FulfillmentGroup::firstOrFail();
        $csv = "440-069 713300,{$group->reference_no}\r\n";

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

        $this->assertSame('440069713300', $orderA->refresh()->tracking_no);
        $this->assertSame('440069713300', $orderB->refresh()->tracking_no);
    }

    public function test_fulfillment_tracking_import_route_accepts_file_upload(): void
    {
        [$tenant, $warehouse, $shop, $sku] = $this->skuWithStock(20);
        $order = $this->readySalesOrder($tenant, $shop, $sku, 1, 'SO-FG-TRACK-ROUTE');

        $this->createGroup($tenant, $warehouse, $order->ship_together_key, [$order]);
        $group = FulfillmentGroup::firstOrFail();
        $file = UploadedFile::fake()->createWithContent('tracking.csv', $group->reference_no.",,,YMT-TRACK\r\n");

        $this->actingAs($this->internalUser())
            ->post(route('fulfillment.tracking-import'), ['tracking_file' => $file])
            ->assertRedirect(route('fulfillment-groups.index'));

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
        $this->assertSame('SG1', $group->tracking_no);
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
        $this->actingAs($this->internalUser())->get(route('fulfillment.pack.start'))->assertOk()->assertSee('Scan tracking no.');
        $this->actingAs($this->internalUser())->get(route('fulfillment-groups.pack', $group))->assertOk()->assertSee($group->reference_no);
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

        $this->actingAs($tenantUser)->get(route('fulfillment-groups.pack', $group))->assertForbidden();
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

    public function test_pack_start_finds_group_by_group_tracking_number(): void
    {
        [$tenant, $warehouse, $shop, $sku] = $this->skuWithStock(20);
        $method = ShippingMethod::where('code', 'yamato_nekopos')->firstOrFail();
        $order = $this->readySalesOrder($tenant, $shop, $sku, 1, 'SO-PACK-GROUP-TRACK');
        $this->createGroup($tenant, $warehouse, $order->ship_together_key, [$order]);
        $group = FulfillmentGroup::firstOrFail();
        $group->update(['shipping_method_id' => $method->id, 'tracking_no' => '123456789012']);

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentPackStart::class)
            ->set('warehouseId', (string) $warehouse->id)
            ->set('shippingMethodId', (string) $method->id)
            ->set('scan', '1234-5678-9012')
            ->call('search')
            ->assertRedirect(route('fulfillment-groups.pack', $group));
    }

    public function test_pack_start_finds_group_by_group_order_tracking_number(): void
    {
        [$tenant, $warehouse, $shop, $sku] = $this->skuWithStock(20);
        $method = ShippingMethod::where('code', 'yamato_nekopos')->firstOrFail();
        $order = $this->readySalesOrder($tenant, $shop, $sku, 1, 'SO-PACK-PIVOT-TRACK');
        $this->createGroup($tenant, $warehouse, $order->ship_together_key, [$order]);
        $group = FulfillmentGroup::firstOrFail();
        $group->update(['shipping_method_id' => $method->id]);
        $group->groupOrders()->update(['tracking_no' => 'PIVOT123']);

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentPackStart::class)
            ->set('warehouseId', (string) $warehouse->id)
            ->set('shippingMethodId', (string) $method->id)
            ->set('scan', 'pivot-123')
            ->call('search')
            ->assertRedirect(route('fulfillment-groups.pack', $group));
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

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentPackStart::class)
            ->set('warehouseId', (string) $warehouse->id)
            ->set('shippingMethodId', (string) $method->id)
            ->set('scan', 'sales 123')
            ->call('search')
            ->assertRedirect(route('fulfillment-groups.pack', $group));
    }

    public function test_pack_start_finds_group_by_outbound_order_tracking_number(): void
    {
        [$tenant, $warehouse, $shop, $sku] = $this->skuWithStock(20);
        $method = ShippingMethod::where('code', 'yamato_nekopos')->firstOrFail();
        $order = $this->readySalesOrder($tenant, $shop, $sku, 1, 'SO-PACK-OUTBOUND-TRACK');
        $this->createGroup($tenant, $warehouse, $order->ship_together_key, [$order]);
        $group = FulfillmentGroup::firstOrFail();
        $group->update(['shipping_method_id' => $method->id]);
        $group->outboundOrder->update(['tracking_no' => 'OUTBOUND123']);

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentPackStart::class)
            ->set('warehouseId', (string) $warehouse->id)
            ->set('shippingMethodId', (string) $method->id)
            ->set('scan', 'outbound/123')
            ->call('search')
            ->assertRedirect(route('fulfillment-groups.pack', $group));
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
        FulfillmentGroup::query()->each(fn (FulfillmentGroup $group) => $group->groupOrders()->update(['tracking_no' => 'DUPTRACK']));

        DB::flushQueryLog();
        DB::enableQueryLog();

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentPackStart::class)
            ->set('warehouseId', (string) $warehouse->id)
            ->set('shippingMethodId', (string) $method->id)
            ->set('scan', 'DUP-TRACK')
            ->call('search')
            ->assertSee('Multiple matches found.');

        $groupQueries = collect(DB::getQueryLog())
            ->pluck('query')
            ->filter(fn (string $query): bool => str_contains($query, 'from "fulfillment_groups"'))
            ->values();

        DB::disableQueryLog();

        $this->assertTrue($groupQueries->contains(fn (string $query): bool => str_contains($query, '"warehouse_id"') && str_contains($query, '"shipping_method_id"') && str_contains($query, '"status"')));
        $this->assertFalse($groupQueries->contains(fn (string $query): bool => preg_match('/from "fulfillment_groups" where "tenant_id" in \\([^)]*\\)$/', $query) === 1));
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
            ->test(FulfillmentGroupPack::class, ['group' => $group])
            ->assertSee('2')
            ->set('barcode', 'SKU-BAR-001')
            ->call('scan')
            ->assertSee('Scanned '.$sku->sku)
            ->set('barcode', 'STOCK-BAR-001')
            ->call('scan')
            ->assertSee('Ready to ship');

        $this->assertSame(2, FulfillmentPackScan::where('result', FulfillmentPackScan::RESULT_ACCEPTED)->count());

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentGroupPack::class, ['group' => $group])
            ->assertSee('Ready to ship')
            ->assertSee('2');
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
            ->test(FulfillmentGroupPack::class, ['group' => $group])
            ->set('barcode', 'SHARED-STOCK-BAR')
            ->call('scan')
            ->set('barcode', 'SHARED-STOCK-BAR')
            ->call('scan')
            ->assertSee('Ready to ship');

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
            ->test(FulfillmentGroupPack::class, ['group' => $group])
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
        ]);
        $this->assertDatabaseHas('fulfillment_pack_scans', [
            'fulfillment_group_id' => $group->id,
            'barcode_scanned' => 'SKU-BAR-OVER',
            'result' => FulfillmentPackScan::RESULT_OVER_SCAN,
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
            ->test(FulfillmentGroupPack::class, ['group' => $group])
            ->call('markShipped')
            ->assertSee('Scan all required items before shipping.');

        $this->assertSame(FulfillmentGroup::STATUS_RESERVED, $group->refresh()->status);

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentGroupPack::class, ['group' => $group])
            ->set('barcode', 'SKU-BAR-SHIP')
            ->call('scan')
            ->call('markShipped')
            ->assertRedirect(route('fulfillment-groups.show', $group));

        $this->assertSame(FulfillmentGroup::STATUS_SHIPPED, $group->refresh()->status);
        $this->assertSame(OutboundOrder::STATUS_SHIPPED, $group->outboundOrder->refresh()->status);
        $this->assertSame(SalesOrder::FULFILLMENT_STATUS_SHIPPED, $order->refresh()->fulfillment_status);
        $this->assertDatabaseHas('inventory_movements', [
            'ref_type' => 'outbound_order',
            'ref_id' => (string) $group->outboundOrder->id,
        ]);
    }

    public function test_shipped_and_cancelled_groups_cannot_accept_new_scans(): void
    {
        [$tenant, $warehouse, $shop, $sku] = $this->skuWithStock(20);
        $sku->update(['barcode' => 'SKU-BAR-BLOCK']);
        $order = $this->readySalesOrder($tenant, $shop, $sku, 1, 'SO-PACK-BLOCK');
        $this->createGroup($tenant, $warehouse, $order->ship_together_key, [$order]);
        $group = FulfillmentGroup::firstOrFail();

        $group->update(['status' => FulfillmentGroup::STATUS_CANCELLED]);

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentGroupPack::class, ['group' => $group])
            ->set('barcode', 'SKU-BAR-BLOCK')
            ->call('scan')
            ->assertSee('This fulfillment group is cancelled.');

        $group->update(['status' => FulfillmentGroup::STATUS_SHIPPED]);

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentGroupPack::class, ['group' => $group])
            ->set('barcode', 'SKU-BAR-BLOCK')
            ->call('scan')
            ->assertSee('This fulfillment group is already shipped.');

        $this->assertSame(2, FulfillmentPackScan::where('result', FulfillmentPackScan::RESULT_BLOCKED_STATUS)->count());
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
            ->test(FulfillmentGroupPack::class, ['group' => $group])
            ->assertSee('BUNDLE-1')
            ->assertSee('2')
            ->set('barcode', 'COMPONENT-BAR')
            ->call('scan')
            ->set('barcode', 'COMPONENT-BAR')
            ->call('scan')
            ->assertSee('Ready to ship');
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
