<?php

namespace Tests\Feature;

use App\Livewire\FulfillmentIndex;
use App\Livewire\FulfillmentPickSummary;
use App\Models\BarcodeAlias;
use App\Models\Carrier;
use App\Models\InboundReceipt;
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
use App\Models\WarehouseLocation;
use App\Services\Barcode\BarcodeImageService;
use App\Services\Fulfillment\OutboundConsolidationService;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class FulfillmentPickSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access(): void
    {
        $this->get(route('fulfillment.pick-summary'))->assertForbidden();
    }

    public function test_tenant_user_cannot_access(): void
    {
        [, $user] = $this->tenantUser();

        $this->actingAs($user)->get(route('fulfillment.pick-summary'))->assertForbidden();
    }

    public function test_internal_user_can_access_pick_summary_route(): void
    {
        $this->actingAs($this->internalUser())
            ->get(route('fulfillment.pick-summary'))
            ->assertOk()
            ->assertSee('Pick Summary')
            ->assertSee(__('fulfillment_pick.print_button'))
            ->assertSee('window.print()', false);
    }

    public function test_default_warehouse_filter_is_all_warehouses(): void
    {
        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentPickSummary::class)
            ->assertSet('warehouseId', '')
            ->assertSee(__('common.all_warehouses'))
            ->assertSee('Pick rows');
    }

    public function test_single_active_warehouse_still_defaults_to_all_warehouses(): void
    {
        Warehouse::factory()->create(['status' => 'active']);

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentPickSummary::class)
            ->assertSet('warehouseId', '')
            ->assertSee('Pick rows');
    }

    public function test_multiple_active_warehouses_are_not_auto_selected(): void
    {
        Warehouse::factory()->count(2)->create(['status' => 'active']);

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentPickSummary::class)
            ->assertSet('warehouseId', '')
            ->assertSee('Pick rows');
    }

    public function test_saved_default_pick_summary_warehouse_is_ignored_when_no_query_param(): void
    {
        $user = $this->internalUser();
        $defaultWarehouse = Warehouse::factory()->create(['status' => 'active']);
        Warehouse::factory()->create(['status' => 'active']);
        $user->setPreference('pick_summary_default_warehouse_id', (string) $defaultWarehouse->id);

        Livewire::actingAs($user)
            ->test(FulfillmentPickSummary::class)
            ->assertSet('warehouseId', '');
    }

    public function test_query_param_can_select_pick_summary_warehouse(): void
    {
        $user = $this->internalUser();
        $queryWarehouse = Warehouse::factory()->create(['status' => 'active']);

        Livewire::withQueryParams(['warehouse_id' => (string) $queryWarehouse->id])
            ->actingAs($user)
            ->test(FulfillmentPickSummary::class)
            ->assertSet('warehouseId', (string) $queryWarehouse->id);
    }

    public function test_date_filters_are_blank_by_default(): void
    {
        Carbon::setTestNow('2026-06-24 09:00:00');

        try {
            Livewire::actingAs($this->internalUser())
                ->test(FulfillmentPickSummary::class)
                ->assertSet('dateFrom', '')
                ->assertSet('dateTo', '');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_pick_summary_does_not_default_to_warehouse_timezone_date(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-24 00:30:00', 'Asia/Tokyo'));

        try {
            Warehouse::factory()->create([
                'country_code' => 'JP',
                'timezone' => 'Asia/Tokyo',
                'status' => 'active',
            ]);

            Livewire::actingAs($this->internalUser())
                ->test(FulfillmentPickSummary::class)
                ->assertSet('warehouseId', '')
                ->assertSet('dateFrom', '')
                ->assertSet('dateTo', '');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_warehouse_query_does_not_default_date_filters(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-24 00:30:00', 'Asia/Tokyo'));

        try {
            $warehouse = Warehouse::factory()->create([
                'country_code' => 'US',
                'timezone' => 'America/Los_Angeles',
                'status' => 'active',
            ]);

            Livewire::withQueryParams(['warehouse_id' => (string) $warehouse->id])
                ->actingAs($this->internalUser())
                ->test(FulfillmentPickSummary::class)
                ->assertSet('warehouseId', (string) $warehouse->id)
                ->assertSet('dateFrom', '')
                ->assertSet('dateTo', '');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_explicit_date_query_params_are_preserved(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-24 00:30:00', 'Asia/Tokyo'));

        try {
            $warehouse = Warehouse::factory()->create([
                'country_code' => 'US',
                'timezone' => 'America/Los_Angeles',
                'status' => 'active',
            ]);

            Livewire::withQueryParams([
                'warehouse_id' => (string) $warehouse->id,
                'date_from' => '2026-06-20',
                'date_to' => '2026-06-21',
            ])
                ->actingAs($this->internalUser())
                ->test(FulfillmentPickSummary::class)
                ->assertSet('warehouseId', (string) $warehouse->id)
                ->assertSet('dateFrom', '2026-06-20')
                ->assertSet('dateTo', '2026-06-21');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_reserved_group_appears_and_normal_sku_qty_aggregates(): void
    {
        [$tenant, $warehouse, $shop, $sku, $method] = $this->stationSku('STK-PICK-001', 'SKU-PICK-001');
        $first = $this->readySalesOrder($tenant, $shop, $sku, $method, 2, 'SO-PICK-001');
        $second = $this->readySalesOrder($tenant, $shop, $sku, $method, 3, 'SO-PICK-002');
        $this->createGroup($tenant, $warehouse, $first->ship_together_key, [$first, $second]);
        $group = OutboundOrder::firstOrFail();

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentPickSummary::class)
            ->set('warehouseId', (string) $warehouse->id)
            ->assertSee('STK-PICK-001')
            ->assertSee('SKU-PICK-001')
            ->assertSee($group->ref)
            ->assertSee('5');
    }

    public function test_shipped_and_cancelled_groups_are_excluded(): void
    {
        [$tenant, $warehouse, $shop, $sku, $method] = $this->stationSku('STK-PICK-STATUS', 'SKU-PICK-STATUS');
        $reservedOrder = $this->readySalesOrder($tenant, $shop, $sku, $method, 1, 'SO-PICK-RESERVED');
        $shippedOrder = $this->readySalesOrder($tenant, $shop, $sku, $method, 1, 'SO-PICK-SHIPPED', '2 Status Street');
        $cancelledOrder = $this->readySalesOrder($tenant, $shop, $sku, $method, 1, 'SO-PICK-CANCELLED', '3 Status Street');
        $this->createGroup($tenant, $warehouse, $reservedOrder->ship_together_key, [$reservedOrder]);
        $reserved = OutboundOrder::query()->latest('id')->firstOrFail();
        $this->createGroup($tenant, $warehouse, $shippedOrder->ship_together_key, [$shippedOrder]);
        OutboundOrder::query()->latest('id')->firstOrFail()->update(['status' => OutboundOrder::STATUS_SHIPPED]);
        $this->createGroup($tenant, $warehouse, $cancelledOrder->ship_together_key, [$cancelledOrder]);
        OutboundOrder::query()->latest('id')->firstOrFail()->update(['status' => OutboundOrder::STATUS_CANCELLED]);

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentPickSummary::class)
            ->set('warehouseId', (string) $warehouse->id)
            ->assertSee($reserved->ref)
            ->assertDontSee('SO-PICK-SHIPPED')
            ->assertDontSee('SO-PICK-CANCELLED');
    }

    public function test_printed_but_unshipped_group_stays_in_pick_summary(): void
    {
        [$tenant, $warehouse, $shop, $sku, $method] = $this->stationSku('STK-PICK-PRINTED', 'SKU-PICK-PRINTED');
        $order = $this->readySalesOrder($tenant, $shop, $sku, $method, 1, 'SO-PICK-PRINTED');
        $this->createGroup($tenant, $warehouse, $order->ship_together_key, [$order]);
        $group = OutboundOrder::firstOrFail();
        $group->update(['courier_csv_exported_at' => now()]);

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentPickSummary::class)
            ->set('warehouseId', (string) $warehouse->id)
            ->assertSee('STK-PICK-PRINTED')
            ->assertSee($group->ref);
    }

    public function test_virtual_bundle_component_qty_aggregates(): void
    {
        $tenant = Tenant::factory()->create();
        $warehouse = Warehouse::factory()->create([
            'country_code' => 'JP',
            'timezone' => 'Asia/Tokyo',
        ]);
        $shop = Shop::factory()->for($tenant)->create();
        $method = $this->shippingMethod('pick_bundle_method');
        $componentStock = StockItem::factory()->for($tenant)->create(['code' => 'STK-COMP-001', 'name' => 'Bundle component stock']);
        app(InventoryService::class)->adjustStock($tenant->id, $warehouse->id, $componentStock->id, 50);
        $bundleSku = Sku::factory()->virtualBundle()->for($tenant)->for($shop)->create(['sku' => 'SKU-BUNDLE-PICK']);
        SkuBundleComponent::factory()
            ->for($tenant)
            ->for($bundleSku, 'bundleSku')
            ->for($componentStock, 'componentStockItem')
            ->create(['quantity' => 2]);
        $order = $this->readySalesOrder($tenant, $shop, $bundleSku, $method, 3, 'SO-PICK-BUNDLE');
        $this->createGroup($tenant, $warehouse, $order->ship_together_key, [$order]);

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentPickSummary::class)
            ->set('warehouseId', (string) $warehouse->id)
            ->assertSee('STK-COMP-001')
            ->assertSee('Bundle component')
            ->assertSee('6');
    }

    public function test_reserved_stock_is_counted_as_pickable(): void
    {
        [$tenant, $warehouse, $shop, $sku, $method] = $this->stationSku('STK-RESERVED-FIVE', 'SKU-RESERVED-FIVE');
        $order = $this->readySalesOrder($tenant, $shop, $sku, $method, 5, 'SO-PICK-RESERVED-STOCK');
        $this->createGroup($tenant, $warehouse, $order->ship_together_key, [$order]);
        $this->setBalance($tenant, $warehouse, $sku->stockItem, onHand: 5, reserved: 5, available: 0);

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentPickSummary::class)
            ->set('warehouseId', (string) $warehouse->id)
            ->assertSee('Pickable')
            ->assertDontSee('Available qty')
            ->assertSeeInOrder(['Required qty', '5', 'Shortage rows', '0'])
            ->assertSeeInOrder(['STK-RESERVED-FIVE', 'SKU-RESERVED-FIVE', '5', '5', '0']);
    }

    public function test_pick_summary_shows_available_barcodes_instead_of_alias_count(): void
    {
        [$tenant, $warehouse, $shop, $sku, $method] = $this->stationSku('STK-BARCODE-001', 'SKU-BARCODE-001');
        BarcodeAlias::query()->create([
            'tenant_id' => $tenant->id,
            'model_type' => BarcodeAlias::MODEL_TYPE_STOCK_ITEM,
            'model_id' => $sku->stock_item_id,
            'barcode' => '4901234567890',
            'normalized_barcode' => '4901234567890',
            'barcode_type' => 'jan',
            'label' => 'Primary product barcode',
            'is_primary' => true,
            'is_active' => true,
            'source' => BarcodeAlias::SOURCE_MANUAL,
        ]);
        $order = $this->readySalesOrder($tenant, $shop, $sku, $method, 1, 'SO-PICK-BARCODE');
        $this->createGroup($tenant, $warehouse, $order->ship_together_key, [$order]);

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentPickSummary::class)
            ->set('warehouseId', (string) $warehouse->id)
            ->assertSee('4901234567890')
            ->assertDontSee('+1 aliases');
    }

    public function test_pick_summary_print_table_includes_svg_barcode_image_and_text(): void
    {
        [$tenant, $warehouse, $shop, $sku, $method] = $this->stationSku('STK-PRINT-BARCODE', 'SKU-PRINT-BARCODE');
        BarcodeAlias::query()->create([
            'tenant_id' => $tenant->id,
            'model_type' => BarcodeAlias::MODEL_TYPE_STOCK_ITEM,
            'model_id' => $sku->stock_item_id,
            'barcode' => 'PRINT-STOCK-001',
            'normalized_barcode' => 'PRINTSTOCK001',
            'barcode_type' => 'internal_label',
            'label' => 'Print stock barcode',
            'is_primary' => true,
            'is_active' => true,
            'source' => BarcodeAlias::SOURCE_MANUAL,
        ]);
        $order = $this->readySalesOrder($tenant, $shop, $sku, $method, 1, 'SO-PICK-PRINT-BARCODE');
        $this->createGroup($tenant, $warehouse, $order->ship_together_key, [$order]);

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentPickSummary::class)
            ->set('warehouseId', (string) $warehouse->id)
            ->assertSee('<svg', false)
            ->assertSee(app(BarcodeImageService::class)->code128Svg('PRINT-STOCK-001'), false)
            ->assertSee('PRINT-STOCK-001');
    }

    public function test_pick_summary_print_barcode_prefers_stock_item_alias_before_sku_alias(): void
    {
        [$tenant, $warehouse, $shop, $sku, $method] = $this->stationSku('STK-STOCK-WINS', 'SKU-STOCK-WINS');
        BarcodeAlias::query()->create([
            'tenant_id' => $tenant->id,
            'model_type' => BarcodeAlias::MODEL_TYPE_SKU,
            'model_id' => $sku->id,
            'barcode' => 'SKU-ALIAS-LOSES',
            'normalized_barcode' => 'SKUALIASLOSES',
            'barcode_type' => 'platform_label',
            'label' => 'SKU alias',
            'is_primary' => true,
            'is_active' => true,
            'source' => BarcodeAlias::SOURCE_MANUAL,
        ]);
        BarcodeAlias::query()->create([
            'tenant_id' => $tenant->id,
            'model_type' => BarcodeAlias::MODEL_TYPE_STOCK_ITEM,
            'model_id' => $sku->stock_item_id,
            'barcode' => 'STOCK-ALIAS-WINS',
            'normalized_barcode' => 'STOCKALIASWINS',
            'barcode_type' => 'internal_label',
            'label' => 'Stock alias',
            'is_primary' => false,
            'is_active' => true,
            'source' => BarcodeAlias::SOURCE_MANUAL,
        ]);
        $order = $this->readySalesOrder($tenant, $shop, $sku, $method, 1, 'SO-PICK-STOCK-WINS');
        $this->createGroup($tenant, $warehouse, $order->ship_together_key, [$order]);

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentPickSummary::class)
            ->set('warehouseId', (string) $warehouse->id)
            ->assertSee(app(BarcodeImageService::class)->code128Svg('STOCK-ALIAS-WINS'), false)
            ->assertDontSee(app(BarcodeImageService::class)->code128Svg('SKU-ALIAS-LOSES'), false)
            ->assertSee('SKU-ALIAS-LOSES');
    }

    public function test_pick_summary_print_barcode_uses_primary_stock_item_alias_first(): void
    {
        [$tenant, $warehouse, $shop, $sku, $method] = $this->stationSku('STK-PRIMARY-WINS', 'SKU-PRIMARY-WINS');

        foreach ([['NONPRIMARY-ALIAS', false], ['PRIMARY-ALIAS', true]] as [$barcode, $isPrimary]) {
            BarcodeAlias::query()->create([
                'tenant_id' => $tenant->id,
                'model_type' => BarcodeAlias::MODEL_TYPE_STOCK_ITEM,
                'model_id' => $sku->stock_item_id,
                'barcode' => $barcode,
                'normalized_barcode' => BarcodeAlias::normalize($barcode),
                'barcode_type' => 'internal_label',
                'label' => $barcode,
                'is_primary' => $isPrimary,
                'is_active' => true,
                'source' => BarcodeAlias::SOURCE_MANUAL,
            ]);
        }

        $order = $this->readySalesOrder($tenant, $shop, $sku, $method, 1, 'SO-PICK-PRIMARY-WINS');
        $this->createGroup($tenant, $warehouse, $order->ship_together_key, [$order]);

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentPickSummary::class)
            ->set('warehouseId', (string) $warehouse->id)
            ->assertSee(app(BarcodeImageService::class)->code128Svg('PRIMARY-ALIAS'), false)
            ->assertDontSee(app(BarcodeImageService::class)->code128Svg('NONPRIMARY-ALIAS'), false);
    }

    public function test_pick_summary_print_barcode_ignores_inactive_aliases_and_shows_dash_without_barcodes(): void
    {
        [$tenant, $warehouse, $shop, $sku, $method] = $this->stationSku('STK-INACTIVE-ALIAS', 'SKU-INACTIVE-ALIAS');
        BarcodeAlias::query()->create([
            'tenant_id' => $tenant->id,
            'model_type' => BarcodeAlias::MODEL_TYPE_STOCK_ITEM,
            'model_id' => $sku->stock_item_id,
            'barcode' => 'SLEEPING-BARCODE',
            'normalized_barcode' => 'SLEEPINGBARCODE',
            'barcode_type' => 'internal_label',
            'label' => 'Inactive alias',
            'is_primary' => true,
            'is_active' => false,
            'source' => BarcodeAlias::SOURCE_MANUAL,
        ]);
        $order = $this->readySalesOrder($tenant, $shop, $sku, $method, 1, 'SO-PICK-INACTIVE-ALIAS');
        $this->createGroup($tenant, $warehouse, $order->ship_together_key, [$order]);

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentPickSummary::class)
            ->set('warehouseId', (string) $warehouse->id)
            ->assertDontSee(app(BarcodeImageService::class)->code128Svg('SLEEPING-BARCODE'), false)
            ->assertDontSee('SLEEPING-BARCODE')
            ->assertSee('print-barcode-empty', false);
    }

    public function test_hold_stock_is_not_pickable(): void
    {
        [$tenant, $warehouse, $shop, $sku, $method] = $this->stationSku('STK-HOLD-FIVE', 'SKU-HOLD-FIVE');
        $order = $this->readySalesOrder($tenant, $shop, $sku, $method, 5, 'SO-PICK-HOLD');
        $this->createGroup($tenant, $warehouse, $order->ship_together_key, [$order]);
        $this->setBalance($tenant, $warehouse, $sku->stockItem, onHand: 5, reserved: 5, hold: 2, available: 0);

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentPickSummary::class)
            ->set('warehouseId', (string) $warehouse->id)
            ->assertSeeInOrder(['Shortage rows', '1'])
            ->assertSeeInOrder(['STK-HOLD-FIVE', 'SKU-HOLD-FIVE', '5', '3', '-2']);
    }

    public function test_damaged_stock_is_not_pickable(): void
    {
        [$tenant, $warehouse, $shop, $sku, $method] = $this->stationSku('STK-DAMAGED-FIVE', 'SKU-DAMAGED-FIVE');
        $order = $this->readySalesOrder($tenant, $shop, $sku, $method, 5, 'SO-PICK-DAMAGED');
        $this->createGroup($tenant, $warehouse, $order->ship_together_key, [$order]);
        $this->setBalance($tenant, $warehouse, $sku->stockItem, onHand: 5, reserved: 5, damaged: 1, available: 0);

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentPickSummary::class)
            ->set('warehouseId', (string) $warehouse->id)
            ->assertSeeInOrder(['Shortage rows', '1'])
            ->assertSeeInOrder(['STK-DAMAGED-FIVE', 'SKU-DAMAGED-FIVE', '5', '4', '-1']);
    }

    public function test_search_matches_stock_item_name_and_sku_code(): void
    {
        [$tenant, $warehouse, $shop, $sku, $method] = $this->stationSku('STK-SEARCH-001', 'SKU-SEARCH-001');
        $sku->stockItem->update(['name' => 'Needle Product']);
        $order = $this->readySalesOrder($tenant, $shop, $sku, $method, 1, 'SO-PICK-SEARCH');
        $this->createGroup($tenant, $warehouse, $order->ship_together_key, [$order]);

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentPickSummary::class)
            ->set('warehouseId', (string) $warehouse->id)
            ->set('q', 'Needle')
            ->assertSee('STK-SEARCH-001')
            ->set('q', 'SKU-SEARCH-001')
            ->assertSee('STK-SEARCH-001');
    }

    public function test_warehouse_filter_prevents_other_warehouse_groups_from_appearing(): void
    {
        [$tenant, $warehouse, $shop, $sku, $method] = $this->stationSku('STK-PICK-WH', 'SKU-PICK-WH');
        $otherWarehouse = Warehouse::factory()->create();
        app(InventoryService::class)->adjustStock($tenant->id, $otherWarehouse->id, $sku->stock_item_id, 50);
        $shownOrder = $this->readySalesOrder($tenant, $shop, $sku, $method, 1, 'SO-PICK-WH-SHOWN');
        $hiddenOrder = $this->readySalesOrder($tenant, $shop, $sku, $method, 1, 'SO-PICK-WH-HIDDEN', '2 Warehouse Street');
        $this->createGroup($tenant, $warehouse, $shownOrder->ship_together_key, [$shownOrder]);
        $shown = OutboundOrder::query()->latest('id')->firstOrFail();
        $this->createGroup($tenant, $otherWarehouse, $hiddenOrder->ship_together_key, [$hiddenOrder]);

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentPickSummary::class)
            ->set('warehouseId', (string) $warehouse->id)
            ->assertSee($shown->ref)
            ->assertDontSee('SO-PICK-WH-HIDDEN');
    }

    public function test_date_filters_can_scope_and_clear_visible_rows(): void
    {
        Carbon::setTestNow('2026-06-24 09:00:00');

        try {
            [$tenant, $warehouse, $shop, $sku, $method] = $this->stationSku('STK-DATE-TODAY', 'SKU-DATE-TODAY');
            $oldStockItem = StockItem::factory()->for($tenant)->create(['code' => 'STK-DATE-OLD', 'name' => 'Old date stock']);
            $oldSku = Sku::factory()->for($tenant)->for($shop)->for($oldStockItem)->create([
                'sku_type' => 'single',
                'sku' => 'SKU-DATE-OLD',
            ]);
            app(InventoryService::class)->adjustStock($tenant->id, $warehouse->id, $oldStockItem->id, 50);
            $todayOrder = $this->readySalesOrder($tenant, $shop, $sku, $method, 1, 'SO-PICK-TODAY');
            $oldOrder = $this->readySalesOrder($tenant, $shop, $oldSku, $method, 1, 'SO-PICK-OLD', '2 Date Street');
            $this->createGroup($tenant, $warehouse, $todayOrder->ship_together_key, [$todayOrder]);
            $todayGroup = OutboundOrder::query()->latest('id')->firstOrFail();
            DB::table('outbound_orders')->where('id', $todayGroup->id)->update(['ref' => 'FG-TODAY', 'created_at' => '2026-06-24 08:00:00']);
            $this->createGroup($tenant, $warehouse, $oldOrder->ship_together_key, [$oldOrder]);
            $oldGroup = OutboundOrder::query()->latest('id')->firstOrFail();
            DB::table('outbound_orders')->where('id', $oldGroup->id)->update(['ref' => 'FG-OLD', 'created_at' => '2026-06-23 08:00:00']);

            Livewire::actingAs($this->internalUser())
                ->test(FulfillmentPickSummary::class)
                ->assertSet('warehouseId', '')
                ->assertSee('STK-DATE-TODAY')
                ->assertSee('STK-DATE-OLD')
                ->set('dateFrom', '2026-06-23')
                ->set('dateTo', '2026-06-23')
                ->assertDontSee('STK-DATE-TODAY')
                ->assertSee('STK-DATE-OLD')
                ->call('clearDateFilters')
                ->assertSee('STK-DATE-TODAY')
                ->assertSee('STK-DATE-OLD');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_jst_date_filter_includes_after_midnight_and_excludes_previous_jst_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-24 00:30:00', 'Asia/Tokyo'));

        try {
            [$tenant, $warehouse, $shop, $sku, $method] = $this->stationSku('STK-JST-TODAY', 'SKU-JST-TODAY');
            $previousStockItem = StockItem::factory()->for($tenant)->create(['code' => 'STK-JST-PREV', 'name' => 'Previous JST stock']);
            $previousSku = Sku::factory()->for($tenant)->for($shop)->for($previousStockItem)->create([
                'sku_type' => 'single',
                'sku' => 'SKU-JST-PREV',
            ]);
            app(InventoryService::class)->adjustStock($tenant->id, $warehouse->id, $previousStockItem->id, 50);

            $todayOrder = $this->readySalesOrder($tenant, $shop, $sku, $method, 1, 'SO-JST-TODAY');
            $previousOrder = $this->readySalesOrder($tenant, $shop, $previousSku, $method, 1, 'SO-JST-PREV', '2 JST Street');

            $this->createGroup($tenant, $warehouse, $todayOrder->ship_together_key, [$todayOrder]);
            $todayGroup = OutboundOrder::query()->latest('id')->firstOrFail();
            DB::table('outbound_orders')->where('id', $todayGroup->id)->update([
                'ref' => 'FG-JST-TODAY',
                'created_at' => Carbon::parse('2026-06-24 00:30:00', 'Asia/Tokyo')->utc()->format('Y-m-d H:i:s'),
            ]);

            $this->createGroup($tenant, $warehouse, $previousOrder->ship_together_key, [$previousOrder]);
            $previousGroup = OutboundOrder::query()->latest('id')->firstOrFail();
            DB::table('outbound_orders')->where('id', $previousGroup->id)->update([
                'ref' => 'FG-JST-PREV',
                'created_at' => Carbon::parse('2026-06-23 23:30:00', 'Asia/Tokyo')->utc()->format('Y-m-d H:i:s'),
            ]);

            Livewire::actingAs($this->internalUser())
                ->test(FulfillmentPickSummary::class)
                ->set('dateFrom', '2026-06-24')
                ->set('dateTo', '2026-06-24')
                ->assertSee('STK-JST-TODAY')
                ->assertDontSee('STK-JST-PREV');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_us_warehouse_date_filter_uses_us_day_boundaries(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-24 00:30:00', 'Asia/Tokyo'));

        try {
            [$tenant, $warehouse, $shop, $sku, $method] = $this->stationSku('STK-US-TODAY', 'SKU-US-TODAY');
            $warehouse->update([
                'country_code' => 'US',
                'timezone' => 'America/Los_Angeles',
            ]);
            $nextStockItem = StockItem::factory()->for($tenant)->create(['code' => 'STK-US-NEXT', 'name' => 'Next US stock']);
            $nextSku = Sku::factory()->for($tenant)->for($shop)->for($nextStockItem)->create([
                'sku_type' => 'single',
                'sku' => 'SKU-US-NEXT',
            ]);
            app(InventoryService::class)->adjustStock($tenant->id, $warehouse->id, $nextStockItem->id, 50);

            $todayOrder = $this->readySalesOrder($tenant, $shop, $sku, $method, 1, 'SO-US-TODAY');
            $nextOrder = $this->readySalesOrder($tenant, $shop, $nextSku, $method, 1, 'SO-US-NEXT', '2 US Street');

            $this->createGroup($tenant, $warehouse, $todayOrder->ship_together_key, [$todayOrder]);
            $todayGroup = OutboundOrder::query()->latest('id')->firstOrFail();
            DB::table('outbound_orders')->where('id', $todayGroup->id)->update([
                'ref' => 'FG-US-TODAY',
                'created_at' => Carbon::parse('2026-06-23 23:30:00', 'America/Los_Angeles')->utc()->format('Y-m-d H:i:s'),
            ]);

            $this->createGroup($tenant, $warehouse, $nextOrder->ship_together_key, [$nextOrder]);
            $nextGroup = OutboundOrder::query()->latest('id')->firstOrFail();
            DB::table('outbound_orders')->where('id', $nextGroup->id)->update([
                'ref' => 'FG-US-NEXT',
                'created_at' => Carbon::parse('2026-06-24 00:30:00', 'America/Los_Angeles')->utc()->format('Y-m-d H:i:s'),
            ]);

            Livewire::withQueryParams([
                'warehouse_id' => (string) $warehouse->id,
                'date_from' => '2026-06-23',
                'date_to' => '2026-06-23',
            ])
                ->actingAs($this->internalUser())
                ->test(FulfillmentPickSummary::class)
                ->assertSee('STK-US-TODAY')
                ->assertDontSee('STK-US-NEXT');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_invalid_warehouse_timezone_falls_back_safely(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-24 00:30:00', 'Asia/Tokyo'));

        try {
            $warehouse = Warehouse::factory()->create([
                'timezone' => 'Not/A_Timezone',
                'status' => 'active',
            ]);

            Livewire::withQueryParams(['warehouse_id' => (string) $warehouse->id])
                ->actingAs($this->internalUser())
                ->test(FulfillmentPickSummary::class)
                ->assertSet('warehouseId', (string) $warehouse->id)
                ->assertSet('dateFrom', '')
                ->assertSet('dateTo', '')
                ->assertSee('Pick rows');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_fulfillment_group_index_does_not_show_pick_summary_link(): void
    {
        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentIndex::class)
            ->assertDontSee(route('fulfillment.pick-summary'), false);
    }

    public function test_print_view_contains_pick_sheet_context_without_printing_actions(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-24 09:00:00', 'Asia/Tokyo'));

        try {
            [$tenant, $warehouse, $shop, $sku, $method] = $this->stationSku('STK-PRINT-001', 'SKU-PRINT-001');
            $order = $this->readySalesOrder($tenant, $shop, $sku, $method, 1, 'SO-PICK-PRINT');
            $this->createGroup($tenant, $warehouse, $order->ship_together_key, [$order]);

            Livewire::actingAs($this->internalUser())
                ->test(FulfillmentPickSummary::class)
                ->assertSee('Warehouse: '.__('common.all_warehouses'))
                ->assertSee('Date: - - -')
                ->assertSee('Generated: 2026-06-24 09:00')
                ->assertSee('print-pick-table', false)
                ->assertSee('Pick qty')
                ->assertSee('Notes')
                ->assertSee('screen-pick-table', false);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_print_generated_time_uses_jst(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-24 00:30:00', 'Asia/Tokyo'));

        try {
            [$tenant, $warehouse, $shop, $sku, $method] = $this->stationSku('STK-PRINT-JST', 'SKU-PRINT-JST');
            $order = $this->readySalesOrder($tenant, $shop, $sku, $method, 1, 'SO-PRINT-JST');
            $this->createGroup($tenant, $warehouse, $order->ship_together_key, [$order]);

            Livewire::actingAs($this->internalUser())
                ->test(FulfillmentPickSummary::class)
                ->assertSee('Date: - - -')
                ->assertSee('Generated: 2026-06-24 00:30');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_rows_with_many_group_references_show_more_count(): void
    {
        [$tenant, $warehouse, $shop, $sku, $method] = $this->stationSku('STK-MANY-GROUPS', 'SKU-MANY-GROUPS');

        foreach (range(1, 4) as $index) {
            $order = $this->readySalesOrder($tenant, $shop, $sku, $method, 1, 'SO-MANY-'.$index, $index.' Many Street');
            $this->createGroup($tenant, $warehouse, $order->ship_together_key, [$order]);
        }

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentPickSummary::class)
            ->assertSee('STK-MANY-GROUPS')
            ->assertSee('+1 more')
            ->assertSee('Scan Pack')
            ->assertSee('View groups');
    }

    public function test_summary_cards_reflect_filtered_rows(): void
    {
        [$tenant, $warehouse, $shop, $sku, $method] = $this->stationSku('STK-PICK-SUM', 'SKU-PICK-SUM');
        $order = $this->readySalesOrder($tenant, $shop, $sku, $method, 3, 'SO-PICK-SUM');
        $this->createGroup($tenant, $warehouse, $order->ship_together_key, [$order]);
        $this->setBalance($tenant, $warehouse, $sku->stockItem, onHand: 1, reserved: 0, available: 1);

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentPickSummary::class)
            ->set('warehouseId', (string) $warehouse->id)
            ->assertSee('Pick rows')
            ->assertSee('Required qty')
            ->assertSee('Shortage rows')
            ->assertSee('Groups included')
            ->assertSee('3');
    }

    public function test_rows_sort_by_location_before_stock_item_code(): void
    {
        [$tenant, $warehouse, $shop, $bLocationSku, $method] = $this->stationSku('STK-001', 'SKU-B-LOCATION');
        $aLocationSku = $this->additionalSku($tenant, $warehouse, $shop, 'STK-999', 'SKU-A-LOCATION');
        $this->locateStock($warehouse, $bLocationSku, 'B-01');
        $this->locateStock($warehouse, $aLocationSku, 'A-01');

        foreach ([[$bLocationSku, 'SO-LOCATION-B'], [$aLocationSku, 'SO-LOCATION-A']] as [$sku, $orderNumber]) {
            $order = $this->readySalesOrder($tenant, $shop, $sku, $method, 1, $orderNumber, $orderNumber.' Street');
            $this->createGroup($tenant, $warehouse, $order->ship_together_key, [$order]);
        }

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentPickSummary::class)
            ->set('warehouseId', (string) $warehouse->id)
            ->assertSeeInOrder(['A-01', 'STK-999', 'B-01', 'STK-001']);
    }

    public function test_rows_without_location_appear_last(): void
    {
        [$tenant, $warehouse, $shop, $locatedSku, $method] = $this->stationSku('STK-WITH-LOC', 'SKU-WITH-LOC');
        $unlocatedSku = $this->additionalSku($tenant, $warehouse, $shop, 'STK-NO-LOC', 'SKU-NO-LOC');
        $this->locateStock($warehouse, $locatedSku, 'A-01');

        foreach ([[$unlocatedSku, 'SO-NO-LOC'], [$locatedSku, 'SO-WITH-LOC']] as [$sku, $orderNumber]) {
            $order = $this->readySalesOrder($tenant, $shop, $sku, $method, 1, $orderNumber, $orderNumber.' Street');
            $this->createGroup($tenant, $warehouse, $order->ship_together_key, [$order]);
        }

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentPickSummary::class)
            ->set('warehouseId', (string) $warehouse->id)
            ->assertSeeInOrder(['A-01', 'STK-WITH-LOC', 'No location', 'STK-NO-LOC']);
    }

    public function test_rows_inside_same_location_sort_by_stock_item_code(): void
    {
        [$tenant, $warehouse, $shop, $laterSku, $method] = $this->stationSku('STK-002', 'SKU-SAME-LATER');
        $earlierSku = $this->additionalSku($tenant, $warehouse, $shop, 'STK-001', 'SKU-SAME-EARLIER');
        $this->locateStock($warehouse, $laterSku, 'A-01');
        $this->locateStock($warehouse, $earlierSku, 'A-01');

        foreach ([[$laterSku, 'SO-SAME-LATER'], [$earlierSku, 'SO-SAME-EARLIER']] as [$sku, $orderNumber]) {
            $order = $this->readySalesOrder($tenant, $shop, $sku, $method, 1, $orderNumber, $orderNumber.' Street');
            $this->createGroup($tenant, $warehouse, $order->ship_together_key, [$order]);
        }

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentPickSummary::class)
            ->set('warehouseId', (string) $warehouse->id)
            ->assertSeeInOrder(['A-01', 'STK-001', 'STK-002']);
    }

    public function test_print_view_includes_location_group_labels(): void
    {
        [$tenant, $warehouse, $shop, $locatedSku, $method] = $this->stationSku('STK-PRINT-LOC', 'SKU-PRINT-LOC');
        $unlocatedSku = $this->additionalSku($tenant, $warehouse, $shop, 'STK-PRINT-NO-LOC', 'SKU-PRINT-NO-LOC');
        $this->locateStock($warehouse, $locatedSku, 'A-01');

        foreach ([[$locatedSku, 'SO-PRINT-LOC'], [$unlocatedSku, 'SO-PRINT-NO-LOC']] as [$sku, $orderNumber]) {
            $order = $this->readySalesOrder($tenant, $shop, $sku, $method, 1, $orderNumber, $orderNumber.' Street');
            $this->createGroup($tenant, $warehouse, $order->ship_together_key, [$order]);
        }

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentPickSummary::class)
            ->set('warehouseId', (string) $warehouse->id)
            ->assertSee('print-location-row', false)
            ->assertSee('Location A-01')
            ->assertSee('No location');
    }

    public function test_user_facing_column_says_location(): void
    {
        [$tenant, $warehouse, $shop, $sku, $method] = $this->stationSku('STK-LOCATION-LABEL', 'SKU-LOCATION-LABEL');
        $order = $this->readySalesOrder($tenant, $shop, $sku, $method, 1, 'SO-LOCATION-LABEL');
        $this->createGroup($tenant, $warehouse, $order->ship_together_key, [$order]);

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentPickSummary::class)
            ->set('warehouseId', (string) $warehouse->id)
            ->assertSee('Location')
            ->assertDontSee('Location hint');
    }

    /**
     * @return array{0: Tenant, 1: Warehouse, 2: Shop, 3: Sku, 4: ShippingMethod}
     */
    private function stationSku(string $stockCode, string $skuCode): array
    {
        $tenant = Tenant::factory()->create();
        $warehouse = Warehouse::factory()->create([
            'country_code' => 'JP',
            'timezone' => 'Asia/Tokyo',
        ]);
        $shop = Shop::factory()->for($tenant)->create();
        $method = $this->shippingMethod('pick_method');
        $stockItem = StockItem::factory()->for($tenant)->create(['code' => $stockCode, 'name' => $stockCode.' name']);
        $sku = Sku::factory()->for($tenant)->for($shop)->for($stockItem)->create([
            'sku_type' => 'single',
            'sku' => $skuCode,
        ]);
        app(InventoryService::class)->adjustStock($tenant->id, $warehouse->id, $stockItem->id, 50);

        return [$tenant, $warehouse, $shop, $sku, $method];
    }

    private function additionalSku(Tenant $tenant, Warehouse $warehouse, Shop $shop, string $stockCode, string $skuCode): Sku
    {
        $stockItem = StockItem::factory()->for($tenant)->create(['code' => $stockCode, 'name' => $stockCode.' name']);
        $sku = Sku::factory()->for($tenant)->for($shop)->for($stockItem)->create([
            'sku_type' => 'single',
            'sku' => $skuCode,
        ]);
        app(InventoryService::class)->adjustStock($tenant->id, $warehouse->id, $stockItem->id, 50);

        return $sku;
    }

    private function locateStock(Warehouse $warehouse, Sku $sku, string $locationCode): void
    {
        $location = WarehouseLocation::query()->firstOrCreate(
            ['warehouse_id' => $warehouse->id, 'code' => $locationCode],
            ['name' => $locationCode, 'type' => 'storage', 'status' => 'active'],
        );

        InboundReceipt::factory()->create([
            'tenant_id' => $sku->tenant_id,
            'warehouse_id' => $warehouse->id,
            'warehouse_location_id' => $location->id,
            'sku_id' => $sku->id,
            'stock_item_id' => $sku->stock_item_id,
            'received_at' => now(),
        ]);
    }

    private function readySalesOrder(
        Tenant $tenant,
        Shop $shop,
        Sku $sku,
        ShippingMethod $method,
        int $quantity,
        string $platformOrderId,
        string $addressLine1 = '1 Pick Street',
    ): SalesOrder {
        $order = SalesOrder::factory()->for($tenant)->for($shop)->create([
            'platform_order_id' => $platformOrderId,
            'order_status' => SalesOrder::ORDER_STATUS_PENDING,
            'fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_READY,
            'shipping_method_id' => $method->id,
            'recipient_name' => 'Pick Recipient',
            'recipient_country_code' => 'JP',
            'recipient_postal_code' => '1000001',
            'recipient_state' => 'Tokyo',
            'recipient_city' => 'Chiyoda',
            'recipient_address_line1' => $addressLine1,
            'recipient_address_line2' => 'Unit 1',
        ]);
        $order->recalculateShipTogetherKey();
        $order->save();

        SalesOrderLine::factory()->for($order)->for($sku)->create([
            'quantity' => $quantity,
            'line_status' => SalesOrderLine::STATUS_READY,
        ]);

        return $order->refresh();
    }

    private function createGroup(Tenant $tenant, Warehouse $warehouse, string $shipKey, array $orders): void
    {
        $this->actingAs($this->internalUser());

        app(OutboundConsolidationService::class)->createGroup(
            tenantId: (int) $tenant->id,
            warehouseId: (int) $warehouse->id,
            salesOrderIds: collect($orders)->pluck('id')->map(fn ($id) => (int) $id)->all(),
        );
    }

    private function setBalance(
        Tenant $tenant,
        Warehouse $warehouse,
        StockItem $stockItem,
        int $onHand,
        int $reserved = 0,
        int $hold = 0,
        int $damaged = 0,
        int $available = 0,
    ): void {
        InventoryBalance::query()
            ->where('tenant_id', $tenant->id)
            ->where('warehouse_id', $warehouse->id)
            ->where('stock_item_id', $stockItem->id)
            ->update([
                'on_hand_qty' => $onHand,
                'reserved_qty' => $reserved,
                'hold_qty' => $hold,
                'damaged_qty' => $damaged,
                'available_qty' => $available,
            ]);
    }

    private function shippingMethod(string $code): ShippingMethod
    {
        $carrier = Carrier::query()->firstOrCreate(
            ['code' => 'pick_carrier'],
            ['name' => 'Pick Carrier', 'country_code' => 'JP', 'status' => 'active'],
        );

        return ShippingMethod::query()->create([
            'carrier_id' => $carrier->id,
            'code' => $code,
            'name' => str($code)->replace('_', ' ')->title()->toString(),
            'service_type' => 'parcel',
            'status' => 'active',
        ]);
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
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['user_type' => 'tenant', 'is_active' => true]);
        TenantUser::factory()->create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'status' => 'active']);

        return [$tenant, $user];
    }
}
