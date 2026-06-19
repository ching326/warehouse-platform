<?php

namespace Tests\Feature;

use App\Livewire\SalesOrderImport;
use App\Livewire\SalesOrderDetail;
use App\Livewire\SalesOrderIndex;
use App\Livewire\FulfillmentGroupCreate;
use App\Models\FulfillmentGroup;
use App\Models\InventoryBalance;
use App\Models\OutboundOrder;
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
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Testing\File;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class SalesOrderImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_creates_orders_grouped_by_platform_order_id(): void
    {
        [$tenant, $shop, $skuA] = $this->salesSku('IMP-A');
        $skuB = $this->skuForShop($tenant, $shop, 'IMP-B');
        $csv = $this->csv([
            ['SO-1', $skuA->sku, '2', 'first', 'Chan'],
            ['SO-1', $skuB->sku, '1', 'second', 'Chan'],
            ['SO-2', $skuA->sku, '3', '', 'Lee'],
        ]);

        $this->parseAndImport($shop, $csv)->assertRedirect(route('sales.orders.index'));

        $this->assertSame(2, SalesOrder::count());
        $this->assertSame(3, SalesOrder::query()->withCount('lines')->get()->sum('lines_count'));
        $this->assertSame(SalesOrder::SOURCE_CSV, SalesOrder::where('platform_order_id', 'SO-1')->firstOrFail()->source);
    }

    public function test_import_resolves_sku_by_code_scoped_to_shop(): void
    {
        [$tenant, $shop, $sku] = $this->salesSku('SHOP-SKU');
        $otherShop = Shop::factory()->for($tenant)->create(['status' => 'active']);
        $this->skuForShop($tenant, $otherShop, 'SHOP-SKU');

        $this->parseAndImport($shop, $this->csv([['SO-SCOPE', 'SHOP-SKU', '1']]));

        $line = SalesOrder::firstOrFail()->lines()->firstOrFail();

        $this->assertSame($sku->id, $line->sku_id);
    }

    public function test_import_recipient_taken_from_first_row_of_group(): void
    {
        [, $shop, $sku] = $this->salesSku('REC-SKU');

        $this->parseAndImport($shop, $this->csv([
            ['SO-REC', $sku->sku, '1', 'line one', 'First Recipient', '0900', 'jp', '100', 'Tokyo', 'Chiyoda', '1 Main', 'A', 'order note'],
            ['SO-REC', $sku->sku, '2', 'line two', 'First Recipient', '0900', 'jp', '100', 'Tokyo', 'Chiyoda', '1 Main', 'A', 'order note'],
        ]));

        $order = SalesOrder::firstOrFail();

        $this->assertSame('First Recipient', $order->recipient_name);
        $this->assertSame('JP', $order->recipient_country_code);
        $this->assertSame('order note', $order->note);
    }

    public function test_parse_flags_unknown_sku(): void
    {
        [, $shop] = $this->salesSku('KNOWN');
        $component = $this->parseOnly($shop, $this->csv([['SO-BAD', 'UNKNOWN', '1']]));

        $this->assertTrue($component->get('hasErrors'));
        $this->assertStringContainsString('UNKNOWN', implode(' ', $component->get('parsedRows')[0]['errors']));
    }

    public function test_parse_flags_duplicate_existing_order_id(): void
    {
        [$tenant, $shop, $sku] = $this->salesSku('DUP-SKU');
        SalesOrder::factory()->for($tenant)->for($shop)->create(['platform_order_id' => 'SO-DUP']);

        $component = $this->parseOnly($shop, $this->csv([['SO-DUP', $sku->sku, '1']]));

        $this->assertTrue($component->get('hasErrors'));
        $this->assertStringContainsString('already exists', implode(' ', $component->get('parsedRows')[0]['errors']));
    }

    public function test_parse_flags_bad_quantity(): void
    {
        [, $shop, $sku] = $this->salesSku('QTY-SKU');
        $component = $this->parseOnly($shop, $this->csv([
            ['SO-ZERO', $sku->sku, '0'],
            ['SO-NEG', $sku->sku, '-1'],
        ]));

        $this->assertTrue($component->get('hasErrors'));
        $this->assertCount(2, $component->get('parsedRows'));
    }

    public function test_parse_flags_bad_quantity_fractional(): void
    {
        [, $shop, $sku] = $this->salesSku('FRAC-SKU');
        $component = $this->parseOnly($shop, $this->csv([
            ['SO-FRAC1', $sku->sku, '1.5'],
            ['SO-FRAC2', $sku->sku, '1.0'],
        ]));

        $this->assertTrue($component->get('hasErrors'));
    }

    public function test_parse_flags_bad_country_code(): void
    {
        [, $shop, $sku] = $this->salesSku('COUNTRY-SKU');
        $component = $this->parseOnly($shop, $this->csv([['SO-COUNTRY', $sku->sku, '1', '', 'Taro', '', 'JPN']]));

        $this->assertTrue($component->get('hasErrors'));
        $this->assertStringContainsString('Country code', implode(' ', $component->get('parsedRows')[0]['errors']));
    }

    public function test_parse_flags_conflicting_order_fields(): void
    {
        [, $shop, $sku] = $this->salesSku('CONFLICT-SKU');
        $component = $this->parseOnly($shop, $this->csv([
            ['SO-CONFLICT', $sku->sku, '1', '', 'Chan'],
            ['SO-CONFLICT', $sku->sku, '1', '', 'Lee'],
        ]));

        $rows = $component->get('parsedRows');

        $this->assertTrue($component->get('hasErrors'));
        $this->assertNotEmpty($rows[0]['errors']);
        $this->assertNotEmpty($rows[1]['errors']);
    }

    public function test_import_blocked_when_any_row_has_errors(): void
    {
        [, $shop, $sku] = $this->salesSku('MIXED-SKU');
        $component = $this->parseOnly($shop, $this->csv([
            ['SO-GOOD', $sku->sku, '1'],
            ['SO-BAD', 'NOPE', '1'],
        ]));

        $component->call('import');

        $this->assertSame(0, SalesOrder::count());
    }

    public function test_import_sets_source_csv_and_pending_unfulfilled(): void
    {
        [, $shop, $sku] = $this->salesSku('STATUS-SKU');

        $this->parseAndImport($shop, $this->csv([['SO-STATUS', $sku->sku, '1']]));
        $order = SalesOrder::firstOrFail();

        $this->assertSame(SalesOrder::SOURCE_CSV, $order->source);
        $this->assertSame(SalesOrder::ORDER_STATUS_PENDING, $order->order_status);
        $this->assertSame(SalesOrder::FULFILLMENT_STATUS_UNFULFILLED, $order->fulfillment_status);
    }

    public function test_import_recomputes_ship_together_key(): void
    {
        [, $shop, $sku] = $this->salesSku('KEY-SKU');

        $this->parseAndImport($shop, $this->csv([['SO-KEY', $sku->sku, '1', '', 'Taro', '', 'JP', '', '', '', '1 Address']]));

        $this->assertNotNull(SalesOrder::firstOrFail()->ship_together_key);
    }

    public function test_import_rejects_sku_from_another_shop(): void
    {
        [$tenant, $shop] = $this->salesSku('OWN-SKU');
        $otherShop = Shop::factory()->for($tenant)->create(['status' => 'active']);
        $otherSku = $this->skuForShop($tenant, $otherShop, 'OTHER-SHOP-SKU');

        $component = $this->parseOnly($shop, $this->csv([['SO-OTHER-SHOP', $otherSku->sku, '1']]));

        $this->assertTrue($component->get('hasErrors'));
    }

    public function test_tenant_user_cannot_import_for_other_tenant_shop(): void
    {
        [, $user] = $this->tenantUser();
        [, $otherShop, $otherSku] = $this->salesSku('OTHER-TENANT-SKU');

        Livewire::actingAs($user)
            ->test(SalesOrderImport::class)
            ->set('shopId', (string) $otherShop->id)
            ->set('file', File::createWithContent('orders.csv', $this->csv([['SO-TAMPER', $otherSku->sku, '1']])))
            ->call('parse')
            ->assertHasErrors(['shopId']);

        $this->assertSame(0, SalesOrder::count());
    }

    public function test_tenant_user_without_active_tenant_cannot_access_import(): void
    {
        $user = User::factory()->create(['user_type' => 'tenant', 'is_active' => true]);

        $this->actingAs($user)->get('/sales-orders/import')->assertForbidden();
        Livewire::actingAs($user)->test(SalesOrderImport::class)->assertForbidden();
    }

    public function test_import_route_renders_for_internal_user(): void
    {
        $this->actingAs($this->internalUser())
            ->get('/sales-orders/import')
            ->assertOk()
            ->assertSee('Import Sales Orders');
    }

    public function test_import_skips_fully_blank_rows(): void
    {
        [, $shop, $sku] = $this->salesSku('BLANK-SKU');
        $csv = $this->header()."\nSO-BLANK,{$sku->sku},1,,,,,,,,,,\n,,,,,,,,,,,,\n";

        $component = $this->parseOnly($shop, $csv);

        $this->assertFalse($component->get('hasErrors'));
        $this->assertCount(1, $component->get('parsedRows'));
    }

    public function test_import_rejects_duplicate_order_id_inserted_by_concurrent_user(): void
    {
        [$tenant, $shop, $sku] = $this->salesSku('CONCURRENT-SKU');
        $component = $this->parseOnly($shop, $this->csv([['SO-RACE', $sku->sku, '1']]));

        SalesOrder::factory()->for($tenant)->for($shop)->create(['platform_order_id' => 'SO-RACE']);
        $component->call('import');

        $this->assertSame(1, SalesOrder::where('platform_order_id', 'SO-RACE')->count());
    }

    public function test_platform_order_id_is_unique_per_tenant_shop(): void
    {
        [$tenant, $shop] = $this->salesSku('UNIQUE-SKU');

        SalesOrder::factory()->for($tenant)->for($shop)->create(['platform_order_id' => 'SO-UNIQUE']);

        $this->expectException(QueryException::class);

        SalesOrder::factory()->for($tenant)->for($shop)->create(['platform_order_id' => 'SO-UNIQUE']);
    }

    public function test_parse_flags_missing_required_headers(): void
    {
        [, $shop] = $this->salesSku('HEADER-SKU');

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderImport::class)
            ->set('shopId', (string) $shop->id)
            ->set('file', File::createWithContent('orders.csv', "sku,quantity\nABC,1\n"))
            ->call('parse')
            ->assertHasErrors(['file']);
    }

    public function test_import_ignores_extra_columns(): void
    {
        [, $shop, $sku] = $this->salesSku('EXTRA-SKU');
        $csv = $this->header()."\nSO-EXTRA,{$sku->sku},1,,,,,,,,,,,IGNORED\n";

        $this->parseAndImport($shop, $csv);

        $this->assertSame(1, SalesOrder::count());
    }

    public function test_parse_row_number_matches_sheet_with_interleaved_blank(): void
    {
        [, $shop, $sku] = $this->salesSku('ROW-SKU');
        $csv = $this->header()."\nSO-OK,{$sku->sku},1,,,,,,,,,,\n,,,,,,,,,,,,\nSO-BAD,NOPE,1,,,,,,,,,,\n";
        $component = $this->parseOnly($shop, $csv);
        $rows = $component->get('parsedRows');

        $this->assertSame(2, $rows[0]['row']);
        $this->assertSame(4, $rows[1]['row']);
        $this->assertNotEmpty($rows[1]['errors']);
    }

    public function test_amazon_report_import_creates_orders(): void
    {
        [, $shop, $sku] = $this->amazonSku('AMZ-SKU-1');

        $this->parseAndImportAmazon($shop, [$this->amazonRow(['sku' => $sku->sku])])
            ->assertRedirect(route('sales.orders.index'));

        $order = SalesOrder::with('lines')->firstOrFail();

        $this->assertSame('AMZ-ORDER-1', $order->platform_order_id);
        $this->assertSame(SalesOrder::SOURCE_AMAZON_REPORT, $order->source);
        $this->assertSame(SalesOrder::ORDER_STATUS_PENDING, $order->order_status);
        $this->assertSame(SalesOrder::FULFILLMENT_STATUS_UNFULFILLED, $order->fulfillment_status);
        $this->assertCount(1, $order->lines);
    }

    public function test_amazon_report_groups_multiple_lines_with_same_order_id(): void
    {
        [$tenant, $shop, $skuA] = $this->amazonSku('AMZ-GROUP-A');
        $skuB = $this->skuForShop($tenant, $shop, 'AMZ-GROUP-B');

        $this->parseAndImportAmazon($shop, [
            $this->amazonRow(['order-id' => 'AMZ-GROUP', 'order-item-id' => 'LINE-1', 'sku' => $skuA->sku]),
            $this->amazonRow(['order-id' => 'AMZ-GROUP', 'order-item-id' => 'LINE-2', 'sku' => $skuB->sku]),
        ]);

        $this->assertSame(1, SalesOrder::count());
        $this->assertSame(2, SalesOrderLine::count());
    }

    public function test_amazon_report_resolves_sku_by_selected_amazon_shop(): void
    {
        [$tenant, $shop, $sku] = $this->amazonSku('AMZ-SCOPE');
        $otherShop = Shop::factory()->for($tenant)->create(['platform' => 'amazon', 'status' => 'active']);
        $this->skuForShop($tenant, $otherShop, 'AMZ-SCOPE');

        $this->parseAndImportAmazon($shop, [$this->amazonRow(['sku' => 'AMZ-SCOPE'])]);

        $this->assertSame($sku->id, SalesOrderLine::firstOrFail()->sku_id);
    }

    public function test_amazon_report_rejects_unknown_sku(): void
    {
        [, $shop] = $this->amazonSku('AMZ-KNOWN');

        $component = $this->parseOnlyAmazon($shop, [$this->amazonRow(['sku' => 'AMZ-UNKNOWN'])]);

        $this->assertTrue($component->get('hasErrors'));
        $component->call('import');
        $this->assertSame(0, SalesOrder::count());
    }

    public function test_amazon_report_rejects_duplicate_existing_order(): void
    {
        [$tenant, $shop, $sku] = $this->amazonSku('AMZ-DUP');
        SalesOrder::factory()->for($tenant)->for($shop)->create(['platform_order_id' => 'AMZ-DUP-ORDER']);

        $component = $this->parseOnlyAmazon($shop, [$this->amazonRow([
            'order-id' => 'AMZ-DUP-ORDER',
            'sku' => $sku->sku,
        ])]);

        $this->assertTrue($component->get('hasErrors'));
        $this->assertStringContainsString('already exists', implode(' ', $component->get('parsedRows')[0]['errors']));
    }

    public function test_amazon_report_rechecks_duplicate_during_confirm(): void
    {
        [$tenant, $shop, $sku] = $this->amazonSku('AMZ-RACE');
        $component = $this->parseOnlyAmazon($shop, [$this->amazonRow(['order-id' => 'AMZ-RACE-ORDER', 'sku' => $sku->sku])]);

        SalesOrder::factory()->for($tenant)->for($shop)->create(['platform_order_id' => 'AMZ-RACE-ORDER']);
        $component->call('import');

        $this->assertSame(1, SalesOrder::where('platform_order_id', 'AMZ-RACE-ORDER')->count());
    }

    public function test_amazon_report_cancel_requested_sets_cancel_requested_status(): void
    {
        [, $shop, $sku] = $this->amazonSku('AMZ-CANCEL');

        $this->parseAndImportAmazon($shop, [$this->amazonRow([
            'sku' => $sku->sku,
            'is-buyer-requested-cancellation' => 'true',
            'buyer-requested-cancel-reason' => 'Changed mind',
        ])]);

        $order = SalesOrder::firstOrFail();

        $this->assertSame(SalesOrder::ORDER_STATUS_CANCEL_REQUESTED, $order->order_status);
        $this->assertNull($order->note);
    }

    public function test_cancel_requested_order_cannot_be_marked_ready(): void
    {
        [, $shop, $sku] = $this->amazonSku('AMZ-CANCEL-READY');
        $order = $this->orderWithLine($shop->tenant, $shop, $sku, [
            'order_status' => SalesOrder::ORDER_STATUS_CANCEL_REQUESTED,
            'fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_UNFULFILLED,
        ]);

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderDetail::class, ['order' => $order])
            ->call('markReady');

        $this->assertSame(SalesOrder::FULFILLMENT_STATUS_UNFULFILLED, $order->refresh()->fulfillment_status);

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderIndex::class)
            ->set('selectedIds', [(string) $order->id])
            ->call('bulkMarkReady');

        $this->assertSame(SalesOrder::FULFILLMENT_STATUS_UNFULFILLED, $order->refresh()->fulfillment_status);
    }

    public function test_cancel_requested_order_is_not_available_for_fulfillment_group(): void
    {
        [$tenant, $shop, $sku] = $this->amazonSku('AMZ-FG-CANCEL');
        $warehouse = Warehouse::factory()->create(['status' => 'active']);
        app(InventoryService::class)->adjustStock($tenant->id, $warehouse->id, $sku->stock_item_id, 10);
        $order = $this->orderWithLine($tenant, $shop, $sku, [
            'order_status' => SalesOrder::ORDER_STATUS_CANCEL_REQUESTED,
            'fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_READY,
        ]);

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentGroupCreate::class)
            ->set('tenantId', (string) $tenant->id)
            ->set('warehouseId', (string) $warehouse->id)
            ->set('shipKey', (string) $order->ship_together_key)
            ->assertDontSee($order->platform_order_id)
            ->set('selectedOrderIds', [(string) $order->id])
            ->call('save')
            ->assertHasErrors(['selectedOrderIds']);

        $this->assertSame(0, FulfillmentGroup::count());
        $this->assertSame(0, OutboundOrder::count());
        $this->assertSame(0, $this->balance($tenant, $warehouse, $sku->stockItem)->reserved_qty);
    }

    public function test_fulfillment_group_only_accepts_pending_ready_orders(): void
    {
        [$tenant, $shop, $sku] = $this->amazonSku('AMZ-FG-PENDING');
        $warehouse = Warehouse::factory()->create(['status' => 'active']);
        app(InventoryService::class)->adjustStock($tenant->id, $warehouse->id, $sku->stock_item_id, 20);
        $pending = $this->orderWithLine($tenant, $shop, $sku, [
            'platform_order_id' => 'AMZ-FG-PENDING-ORDER',
            'order_status' => SalesOrder::ORDER_STATUS_PENDING,
            'fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_READY,
        ]);
        $onHold = $this->orderWithLine($tenant, $shop, $sku, [
            'platform_order_id' => 'AMZ-FG-HOLD-ORDER',
            'order_status' => SalesOrder::ORDER_STATUS_ON_HOLD,
            'fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_READY,
        ]);

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentGroupCreate::class)
            ->set('tenantId', (string) $tenant->id)
            ->set('warehouseId', (string) $warehouse->id)
            ->set('shipKey', (string) $pending->ship_together_key)
            ->assertSee('AMZ-FG-PENDING-ORDER')
            ->assertDontSee('AMZ-FG-HOLD-ORDER');

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentGroupCreate::class)
            ->set('tenantId', (string) $tenant->id)
            ->set('warehouseId', (string) $warehouse->id)
            ->set('shipKey', (string) $onHold->ship_together_key)
            ->set('selectedOrderIds', [(string) $onHold->id])
            ->call('save')
            ->assertHasErrors(['selectedOrderIds']);
    }

    public function test_amazon_report_imports_cp932_japanese_text(): void
    {
        [, $shop, $sku] = $this->amazonSku('AMZ-CP932');
        $row = $this->amazonRow([
            'sku' => $sku->sku,
            'product-name' => 'ムラタ 酸化銀電池',
            'recipient-name' => '山田 太郎',
            'ship-address-1' => '東京都千代田区丸の内1-1',
            'ship-state' => '東京都',
        ]);
        $content = mb_convert_encoding($this->amazonTsv([$row]), 'CP932', 'UTF-8');

        $this->parseAndImportAmazonContent($shop, $content);

        $order = SalesOrder::with('lines')->firstOrFail();

        $this->assertSame('山田 太郎', $order->recipient_name);
        $this->assertSame('東京都千代田区丸の内1-1', $order->recipient_address_line1);
        $this->assertSame('ムラタ 酸化銀電池', $order->lines->first()->platform_product_name);
    }

    public function test_amazon_report_rejects_missing_required_headers(): void
    {
        [, $shop] = $this->amazonSku('AMZ-MISSING-HEADER');

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderImport::class)
            ->set('importFormat', 'amazon_report')
            ->set('shopId', (string) $shop->id)
            ->set('file', File::createWithContent('amazon.txt', "sku\tquantity-purchased\nABC\t1\n"))
            ->call('parse')
            ->assertHasErrors(['file']);
    }

    public function test_amazon_report_rejects_conflicting_order_fields_for_same_order_id(): void
    {
        [, $shop, $sku] = $this->amazonSku('AMZ-CONFLICT');

        $component = $this->parseOnlyAmazon($shop, [
            $this->amazonRow(['order-id' => 'AMZ-CONFLICT-ORDER', 'sku' => $sku->sku, 'recipient-name' => 'A']),
            $this->amazonRow(['order-id' => 'AMZ-CONFLICT-ORDER', 'sku' => $sku->sku, 'recipient-name' => 'B', 'order-item-id' => 'LINE-B']),
        ]);

        $this->assertTrue($component->get('hasErrors'));
        $this->assertNotEmpty($component->get('parsedRows')[0]['errors']);
        $this->assertNotEmpty($component->get('parsedRows')[1]['errors']);
    }

    public function test_amazon_report_sets_platform_line_id_and_product_name(): void
    {
        [, $shop, $sku] = $this->amazonSku('AMZ-LINE-META');

        $this->parseAndImportAmazon($shop, [$this->amazonRow([
            'sku' => $sku->sku,
            'order-item-id' => 'AMZ-LINE-123',
            'product-name' => 'Amazon Product Name',
        ])]);

        $line = SalesOrderLine::firstOrFail();

        $this->assertSame('AMZ-LINE-123', $line->platform_line_id);
        $this->assertSame('Amazon Product Name', $line->platform_product_name);
    }

    public function test_amazon_report_sets_unit_price_and_currency(): void
    {
        [, $shop, $sku] = $this->amazonSku('AMZ-PRICE');

        $this->parseAndImportAmazon($shop, [$this->amazonRow([
            'sku' => $sku->sku,
            'quantity-purchased' => '2',
            'currency' => 'jpy',
            'item-price' => '500',
        ])]);

        $line = SalesOrderLine::firstOrFail();

        $this->assertSame('250.00', (string) $line->unit_price);
        $this->assertSame('JPY', $line->currency);
    }

    public function test_amazon_report_standard_shipping_imports_shipping_method_as_null(): void
    {
        [, $shop, $sku] = $this->amazonSku('AMZ-STANDARD');

        $this->parseAndImportAmazon($shop, [$this->amazonRow([
            'sku' => $sku->sku,
            'ship-service-level' => 'Standard',
        ])]);

        $this->assertNull(SalesOrder::firstOrFail()->shipping_method);
    }

    public function test_amazon_report_requires_amazon_shop(): void
    {
        [$tenant] = $this->salesSku('NON-AMZ-SKU');
        $shop = Shop::factory()->for($tenant)->create(['platform' => 'shopify', 'status' => 'active']);

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderImport::class)
            ->set('importFormat', 'amazon_report')
            ->set('shopId', (string) $shop->id)
            ->set('file', File::createWithContent('amazon.txt', $this->amazonTsv([$this->amazonRow()])))
            ->call('parse')
            ->assertHasErrors(['shopId']);
    }

    private function parseAndImport(Shop $shop, string $csv): Testable
    {
        $component = $this->parseOnly($shop, $csv);

        return $component->call('import');
    }

    private function parseOnly(Shop $shop, string $csv): Testable
    {
        return Livewire::actingAs($this->internalUser())
            ->test(SalesOrderImport::class)
            ->set('shopId', (string) $shop->id)
            ->set('file', File::createWithContent('orders.csv', $csv))
            ->call('parse');
    }

    private function parseAndImportAmazon(Shop $shop, array $rows): Testable
    {
        return $this->parseOnlyAmazon($shop, $rows)->call('import');
    }

    private function parseOnlyAmazon(Shop $shop, array $rows): Testable
    {
        return $this->parseAndImportAmazonContent($shop, $this->amazonTsv($rows), import: false);
    }

    private function parseAndImportAmazonContent(Shop $shop, string $content, bool $import = true): Testable
    {
        $component = Livewire::actingAs($this->internalUser())
            ->test(SalesOrderImport::class)
            ->set('importFormat', 'amazon_report')
            ->set('shopId', (string) $shop->id)
            ->set('file', File::createWithContent('amazon.txt', $content))
            ->call('parse');

        return $import ? $component->call('import') : $component;
    }

    private function amazonTsv(array $rows): string
    {
        $headers = $this->amazonHeaders();
        $lines = [implode("\t", $headers)];

        foreach ($rows as $row) {
            $lines[] = implode("\t", array_map(fn ($header) => (string) ($row[$header] ?? ''), $headers));
        }

        return implode("\n", $lines)."\n";
    }

    private function amazonHeaders(): array
    {
        return [
            'order-id',
            'order-item-id',
            'purchase-date',
            'payments-date',
            'buyer-phone-number',
            'sku',
            'product-name',
            'quantity-purchased',
            'currency',
            'item-price',
            'ship-service-level',
            'recipient-name',
            'ship-address-1',
            'ship-address-2',
            'ship-address-3',
            'ship-city',
            'ship-state',
            'ship-postal-code',
            'ship-country',
            'ship-phone-number',
            'latest-ship-date',
            'shipment-status',
            'is-buyer-requested-cancellation',
            'buyer-requested-cancel-reason',
        ];
    }

    private function amazonRow(array $overrides = []): array
    {
        return array_merge([
            'order-id' => 'AMZ-ORDER-1',
            'order-item-id' => 'AMZ-LINE-1',
            'purchase-date' => '2026-03-03T03:51:43+00:00',
            'payments-date' => '2026-03-03T03:51:43+00:00',
            'buyer-phone-number' => '09000000000',
            'sku' => 'AMZ-SKU',
            'product-name' => 'Amazon Sample Product',
            'quantity-purchased' => '1',
            'currency' => 'JPY',
            'item-price' => '195',
            'ship-service-level' => 'Standard',
            'recipient-name' => 'Amazon Recipient',
            'ship-address-1' => '1 Amazon Street',
            'ship-address-2' => '',
            'ship-address-3' => '',
            'ship-city' => 'Tokyo',
            'ship-state' => 'Tokyo',
            'ship-postal-code' => '100-0001',
            'ship-country' => 'JP',
            'ship-phone-number' => '08000000000',
            'latest-ship-date' => '2026-03-05T14:59:59+00:00',
            'shipment-status' => '',
            'is-buyer-requested-cancellation' => 'false',
            'buyer-requested-cancel-reason' => '',
        ], $overrides);
    }

    private function csv(array $rows): string
    {
        $lines = [$this->header()];

        foreach ($rows as $row) {
            $lines[] = implode(',', array_map(fn ($value) => str_contains((string) $value, ',')
                ? '"'.str_replace('"', '""', (string) $value).'"'
                : (string) $value, array_pad($row, 13, '')));
        }

        return implode("\n", $lines)."\n";
    }

    private function header(): string
    {
        return 'platform_order_id,sku,quantity,line_note,recipient_name,recipient_phone,recipient_country_code,recipient_postal_code,recipient_state,recipient_city,recipient_address_line1,recipient_address_line2,order_note';
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

    private function amazonSku(string $skuCode): array
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        $shop = Shop::factory()->for($tenant)->create([
            'platform' => 'amazon',
            'marketplace' => 'JP',
            'status' => 'active',
        ]);
        $sku = $this->skuForShop($tenant, $shop, $skuCode);

        return [$tenant, $shop, $sku];
    }

    private function skuForShop(Tenant $tenant, Shop $shop, string $skuCode): Sku
    {
        return Sku::factory()
            ->for($tenant)
            ->for($shop)
            ->for(StockItem::factory()->for($tenant)->create())
            ->create([
                'sku_type' => 'single',
                'sku' => $skuCode,
                'status' => 'active',
            ]);
    }

    private function orderWithLine(Tenant $tenant, Shop $shop, Sku $sku, array $attributes = []): SalesOrder
    {
        $order = SalesOrder::factory()->for($tenant)->for($shop)->create(array_merge([
            'platform_order_id' => 'SO-'.fake()->unique()->numberBetween(100000, 999999),
            'recipient_name' => 'Fulfillment Recipient',
            'recipient_country_code' => 'JP',
            'recipient_postal_code' => '100-0001',
            'recipient_state' => 'Tokyo',
            'recipient_city' => 'Chiyoda',
            'recipient_address_line1' => '1 Shared Street',
            'recipient_address_line2' => 'Unit 1',
        ], $attributes));

        SalesOrderLine::factory()->for($order)->for($sku)->create([
            'quantity' => 1,
            'line_status' => SalesOrderLine::STATUS_READY,
        ]);

        return $order->refresh();
    }

    private function balance(Tenant $tenant, Warehouse $warehouse, StockItem $stockItem): InventoryBalance
    {
        return InventoryBalance::query()
            ->where('tenant_id', $tenant->id)
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
