<?php

namespace Tests\Feature;

use App\Livewire\SalesOrderCreate;
use App\Livewire\SalesOrderDetail;
use App\Livewire\SalesOrderImport;
use App\Livewire\SalesOrderIndex;
use App\Livewire\SalesOrderPasteImport;
use App\Models\InventoryBalance;
use App\Models\OutboundOrder;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\SalesOrderPasteImportMapping;
use App\Models\ShippingMethod;
use App\Models\Shop;
use App\Models\Sku;
use App\Models\StockItem;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Fulfillment\OutboundConsolidationService;
use App\Services\InventoryService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Testing\File;
use Illuminate\Validation\ValidationException;
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
        $this->assertTrue($component->get('parsedRows')[0]['sku_not_found']);
        $component
            ->assertSee('SKU not found')
            ->assertSee('Add SKU')
            ->assertSee('/skus/create', false)
            ->assertSee('shop_id='.$shop->id, false)
            ->assertSee('sku=UNKNOWN', false)
            ->assertSee('target="_blank"', false);
    }

    public function test_parse_marks_duplicate_existing_order_id_as_already_imported(): void
    {
        [$tenant, $shop, $sku] = $this->salesSku('DUP-SKU');
        SalesOrder::factory()->for($tenant)->for($shop)->create(['platform_order_id' => 'SO-DUP']);

        $component = $this->parseOnly($shop, $this->csv([['SO-DUP', $sku->sku, '1']]));

        $this->assertFalse($component->get('hasErrors'));
        $this->assertTrue($component->get('parsedRows')[0]['is_duplicate']);
        $this->assertSame([], $component->get('parsedRows')[0]['errors']);
        $component
            ->assertSee('1 total order(s) in file, 1 already imported, 0 ready to import, 1 line(s) in file')
            ->assertSee('Already imported');
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

    public function test_import_uses_highest_priority_sku_default_shipping_method(): void
    {
        [$tenant, $shop, $skuA] = $this->salesSku('SHIP-DEFAULT-A');
        $skuB = $this->skuForShop($tenant, $shop, 'SHIP-DEFAULT-B');
        $low = ShippingMethod::where('code', 'yamato_nekopos')->firstOrFail();
        $high = ShippingMethod::where('code', 'yamato_tqb')->firstOrFail();
        $low->update(['selection_priority' => 20]);
        $high->update(['selection_priority' => 50]);
        $skuA->update(['default_shipping_method_id' => $low->id]);
        $skuB->update(['default_shipping_method_id' => $high->id]);

        $this->parseAndImport($shop, $this->csv([
            ['SO-SKU-DEFAULT', $skuA->sku, '1'],
            ['SO-SKU-DEFAULT', $skuB->sku, '1'],
        ]));

        $order = SalesOrder::where('platform_order_id', 'SO-SKU-DEFAULT')->firstOrFail();
        $this->assertSame($high->id, $order->shipping_method_id);
        $this->assertSame('yamato', $order->shipping_method);
    }

    public function test_import_tie_between_sku_defaults_leaves_shipping_blank(): void
    {
        [$tenant, $shop, $skuA] = $this->salesSku('SHIP-TIE-A');
        $skuB = $this->skuForShop($tenant, $shop, 'SHIP-TIE-B');
        $yamato = ShippingMethod::where('code', 'yamato_tqb')->firstOrFail();
        $sagawa = ShippingMethod::where('code', 'sagawa_thb')->firstOrFail();
        $yamato->update(['selection_priority' => 40]);
        $sagawa->update(['selection_priority' => 40]);
        $skuA->update(['default_shipping_method_id' => $yamato->id]);
        $skuB->update(['default_shipping_method_id' => $sagawa->id]);

        $this->parseAndImport($shop, $this->csv([
            ['SO-SKU-TIE', $skuA->sku, '1'],
            ['SO-SKU-TIE', $skuB->sku, '1'],
        ]));

        $order = SalesOrder::where('platform_order_id', 'SO-SKU-TIE')->firstOrFail();
        $this->assertNull($order->shipping_method_id);
        $this->assertNull($order->shipping_method);
    }

    public function test_generic_import_without_sku_defaults_leaves_shipping_blank(): void
    {
        [, $shop, $sku] = $this->salesSku('SHIP-NONE-GENERIC');

        $this->parseAndImport($shop, $this->csv([['SO-SHIP-NONE', $sku->sku, '1']]));

        $order = SalesOrder::where('platform_order_id', 'SO-SHIP-NONE')->firstOrFail();
        $this->assertNull($order->shipping_method_id);
        $this->assertNull($order->shipping_method);
    }

    public function test_manual_sales_order_create_does_not_auto_apply_sku_default_shipping_method(): void
    {
        [$tenant, $shop, $sku] = $this->salesSku('MANUAL-NO-DEFAULT');
        $method = ShippingMethod::where('code', 'yamato_tqb')->firstOrFail();
        $sku->update(['default_shipping_method_id' => $method->id]);

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderCreate::class)
            ->set('shopId', (string) $shop->id)
            ->set('platformOrderId', 'SO-MANUAL-NO-AUTO')
            ->set('recipientCountryCode', 'JP')
            ->set('lines.0.sku_id', (string) $sku->id)
            ->set('lines.0.quantity', '1')
            ->call('save')
            ->assertRedirect();

        $order = SalesOrder::where('platform_order_id', 'SO-MANUAL-NO-AUTO')->firstOrFail();
        $this->assertSame($tenant->id, $order->tenant_id);
        $this->assertNull($order->shipping_method_id);
        $this->assertNull($order->shipping_method);
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

    public function test_paste_import_route_renders_for_internal_user(): void
    {
        $this->actingAs($this->internalUser())
            ->get(route('sales.orders.import.paste'))
            ->assertOk()
            ->assertSee('Paste Sales Orders')
            ->assertSee('data-testid="paste-import-grid"', false);
    }

    public function test_paste_import_creates_orders_from_japanese_headers(): void
    {
        [, $shop, $skuA] = $this->salesSku('PASTE-A');
        $shop->update(['marketplace' => 'JP']);
        $skuB = $this->skuForShop($shop->tenant, $shop, 'PASTE-B');

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderPasteImport::class)
            ->set('shopId', (string) $shop->id)
            ->set('columnFieldMapping', [
                '1' => 'platform_order_id',
                '2' => 'platform_ordered_at',
                '3' => 'platform_product_name',
                '4' => 'sku',
                '5' => 'quantity',
                '6' => 'recipient_name',
                '8' => 'recipient_postal_code',
                '9' => 'recipient_address_line1',
                '10' => 'recipient_address_line2',
                '12' => 'recipient_phone',
            ])
            ->set('dataStartRow', 1)
            ->set('grid', $this->pasteGrid([
                ['', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', ''],
                ['No.', '*注文番号', '*注文日', '*商品名', '*商品番号', '*個数', '*送付先氏名1', '', '*送付先郵便番号1', '*送付先住所1', '送付先住所2', '', '*送付先電話番号1'],
                ['1', 'WX-ORDER-1', '2026/06/25', 'Pasted product A', $skuA->sku, '2', '山田 太郎', '', '100-0001', '東京都千代田区1-1', 'ビル101', '', '09011112222'],
                ['2', 'WX-ORDER-1', '2026/06/25', 'Pasted product B', $skuB->sku, '1', '山田 太郎', '', '100-0001', '東京都千代田区1-1', 'ビル101', '', '09011112222'],
            ]))
            ->call('preview')
            ->assertSet('hasErrors', false)
            ->call('import')
            ->assertRedirect(route('sales.orders.index'));

        $order = SalesOrder::query()->where('platform_order_id', 'WX-ORDER-1')->firstOrFail();

        $this->assertSame('山田 太郎', $order->recipient_name);
        $this->assertSame('東京都', $order->recipient_state);
        $this->assertSame('千代田区1-1', $order->recipient_address_line1);
        $this->assertSame('ビル101', $order->recipient_address_line2);
        $this->assertSame(2, $order->lines()->count());
        $this->assertDatabaseHas('sales_order_lines', [
            'sales_order_id' => $order->id,
            'sku_id' => $skuA->id,
            'quantity' => 2,
            'platform_product_name' => 'Pasted product A',
        ]);
    }

    public function test_paste_import_skips_existing_orders_and_imports_new_orders(): void
    {
        [$tenant, $shop, $sku] = $this->salesSku('PASTE-DUP');
        SalesOrder::factory()->for($tenant)->for($shop)->create(['platform_order_id' => 'WX-OLD']);

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderPasteImport::class)
            ->set('shopId', (string) $shop->id)
            ->set('columnFieldMapping', [
                '0' => 'platform_order_id',
                '1' => 'sku',
                '2' => 'quantity',
            ])
            ->set('dataStartRow', 1)
            ->set('grid', $this->pasteGrid([
                ['注文番号', '商品番号', '個数'],
                ['WX-OLD', $sku->sku, '1'],
                ['WX-NEW', $sku->sku, '2'],
            ]))
            ->call('preview')
            ->assertSet('hasErrors', false)
            ->call('import');

        $this->assertSame(1, SalesOrder::query()->where('platform_order_id', 'WX-OLD')->count());
        $this->assertDatabaseHas('sales_orders', ['platform_order_id' => 'WX-NEW']);
    }

    public function test_paste_import_carries_order_fields_down_for_merged_spreadsheet_rows(): void
    {
        [, $shop, $skuA] = $this->salesSku('PASTE-MERGE-A');
        $skuB = $this->skuForShop($shop->tenant, $shop, 'PASTE-MERGE-B');

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderPasteImport::class)
            ->set('shopId', (string) $shop->id)
            ->set('columnFieldMapping', [
                '0' => 'platform_order_id',
                '1' => 'sku',
                '2' => 'quantity',
                '3' => 'recipient_name',
                '4' => 'recipient_postal_code',
                '5' => 'recipient_address_line1',
            ])
            ->set('dataStartRow', 1)
            ->set('grid', $this->pasteGrid([
                ['注文番号', '商品番号', '個数', '送付先氏名1', '送付先郵便番号1', '送付先住所1'],
                ['WX-MERGED', $skuA->sku, '1', '佐藤 花子', '150-0001', '東京都渋谷区1-1'],
                ['', $skuB->sku, '2', '', '', ''],
            ]))
            ->call('preview')
            ->assertSet('hasErrors', false)
            ->call('import');

        $order = SalesOrder::query()->where('platform_order_id', 'WX-MERGED')->firstOrFail();

        $this->assertSame('佐藤 花子', $order->recipient_name);
        $this->assertSame(2, $order->lines()->count());
        $this->assertDatabaseHas('sales_order_lines', [
            'sales_order_id' => $order->id,
            'sku_id' => $skuB->id,
            'quantity' => 2,
        ]);
    }

    public function test_paste_import_repairs_extra_blank_cell_before_sku(): void
    {
        [, $shop, $sku] = $this->salesSku('PASTE-TAB-SKU');

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderPasteImport::class)
            ->set('shopId', (string) $shop->id)
            ->set('columnFieldMapping', [
                '0' => 'platform_order_id',
                '1' => 'platform_ordered_at',
                '2' => 'platform_product_name',
                '3' => 'sku',
                '4' => 'quantity',
                '5' => 'recipient_name',
            ])
            ->set('dataStartRow', 1)
            ->set('grid', $this->pasteGrid([
                ['注文番号', '注文日', '商品名', '商品番号', '個数', '送付先氏名1'],
                ['WX-TAB', '2026/06/25', 'Product name with trailing tab', '', $sku->sku, '2', '高橋 太郎'],
            ]))
            ->call('preview')
            ->assertSet('hasErrors', false)
            ->call('import');

        $order = SalesOrder::query()->where('platform_order_id', 'WX-TAB')->firstOrFail();

        $this->assertSame('高橋 太郎', $order->recipient_name);
        $this->assertDatabaseHas('sales_order_lines', [
            'sales_order_id' => $order->id,
            'sku_id' => $sku->id,
            'quantity' => 2,
            'platform_product_name' => 'Product name with trailing tab',
        ]);
    }

    public function test_paste_import_accepts_wechat_rows_without_header(): void
    {
        [, $shop, $skuA] = $this->salesSku('057-CWDWQ-BS');
        $shop->update(['marketplace' => 'JP']);
        $skuB = $this->skuForShop($shop->tenant, $shop, '195-C1YX-HS');
        $skuC = $this->skuForShop($shop->tenant, $shop, '031-F300-HSZ');

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderPasteImport::class)
            ->set('shopId', (string) $shop->id)
            ->set('columnFieldMapping', [
                '1' => 'platform_order_id',
                '2' => 'platform_ordered_at',
                '3' => 'platform_product_name',
                '4' => 'sku',
                '5' => 'quantity',
                '6' => 'recipient_name',
                '8' => 'recipient_postal_code',
                '9' => 'recipient_address_line1',
                '10' => 'recipient_address_line2',
                '12' => 'recipient_phone',
                '13' => 'order_note',
            ])
            ->set('grid', $this->pasteGrid([
                ['1', 'D503-9102385-0763840', '2026/6/25', 'F2 首輪型 ペット見守り機（白）', $skuA->sku, '1', '後藤 大史', '', '006-0041', '北海道札幌市手稲区金山1-2-3-17', '', '', '080-5202-0384', '', '4'],
                ['3', 'A503-3160604-7766225', '2026/6/25', '高精度DUALC1円形GPSトラッカー黒', '', $skuB->sku, '1', '小杉章仁', '', '522-0055', '滋賀県彦根市野瀬町47-1', 'カルミア 202', '', '08083263853', '', '4'],
                ['', '', '2026/6/25', '追加商品', '', $skuA->sku, '', '2', '', '', '', '', '', '', ''],
                ['', '', '', '22mm ブラック 3連ステンレスバンド', '', $skuC->sku, '', '1', '', '', '', '', '', '', ''],
            ]))
            ->call('preview')
            ->assertSet('hasErrors', false)
            ->call('import');

        $first = SalesOrder::query()->where('platform_order_id', 'D503-9102385-0763840')->firstOrFail();
        $second = SalesOrder::query()->where('platform_order_id', 'A503-3160604-7766225')->firstOrFail();

        $this->assertSame('後藤 大史', $first->recipient_name);
        $this->assertSame('北海道', $first->recipient_state);
        $this->assertSame('札幌市手稲区金山1-2-3-17', $first->recipient_address_line1);
        $this->assertSame('小杉章仁', $second->recipient_name);
        $this->assertSame(3, $second->lines()->count());
        $this->assertDatabaseHas('sales_order_lines', [
            'sales_order_id' => $second->id,
            'sku_id' => $skuA->id,
            'quantity' => 2,
            'platform_product_name' => '追加商品',
        ]);
        $this->assertDatabaseHas('sales_order_lines', [
            'sales_order_id' => $second->id,
            'sku_id' => $skuC->id,
            'quantity' => 1,
            'platform_product_name' => '22mm ブラック 3連ステンレスバンド',
        ]);
    }

    public function test_paste_import_flags_unknown_sku(): void
    {
        [, $shop] = $this->salesSku('PASTE-KNOWN');

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderPasteImport::class)
            ->set('shopId', (string) $shop->id)
            ->set('columnFieldMapping', [
                '0' => 'platform_order_id',
                '1' => 'sku',
                '2' => 'quantity',
            ])
            ->set('dataStartRow', 1)
            ->set('grid', $this->pasteGrid([
                ['注文番号', '商品番号', '個数'],
                ['WX-BAD', 'NO-SUCH-SKU', '1'],
            ]))
            ->call('preview')
            ->assertSet('hasErrors', true)
            ->assertSee('SKU not found')
            ->assertSee('/skus/create', false)
            ->assertSee('sku=NO-SUCH-SKU', false);
    }

    public function test_paste_import_uses_manual_column_mapping(): void
    {
        [, $shop, $sku] = $this->salesSku('PASTE-MAP');

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderPasteImport::class)
            ->set('shopId', (string) $shop->id)
            ->set('columnFieldMapping', [
                '0' => 'sku',
                '1' => 'quantity',
                '2' => 'platform_order_id',
                '3' => 'recipient_name',
            ])
            ->set('dataStartRow', 0)
            ->set('grid', $this->pasteGrid([
                [$sku->sku, '3', 'WX-MAPPED', 'Mapped Recipient'],
            ]))
            ->call('preview')
            ->assertSet('hasErrors', false)
            ->call('import');

        $order = SalesOrder::query()->where('platform_order_id', 'WX-MAPPED')->firstOrFail();

        $this->assertSame('Mapped Recipient', $order->recipient_name);
        $this->assertDatabaseHas('sales_order_lines', [
            'sales_order_id' => $order->id,
            'sku_id' => $sku->id,
            'quantity' => 3,
        ]);
    }

    public function test_paste_import_splits_japanese_prefecture_from_address_line_one(): void
    {
        [, $shop, $sku] = $this->salesSku('PASTE-ADDR-SPLIT');
        $shop->update(['marketplace' => 'JP']);

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderPasteImport::class)
            ->set('shopId', (string) $shop->id)
            ->set('columnFieldMapping', [
                '0' => 'platform_order_id',
                '1' => 'sku',
                '2' => 'quantity',
                '3' => 'recipient_address_line1',
            ])
            ->set('dataStartRow', 0)
            ->set('grid', $this->pasteGrid([
                ['WX-ADDR-SPLIT', $sku->sku, '1', '東京都千代田区丸の内1-1'],
            ]))
            ->call('preview')
            ->assertSet('hasErrors', false)
            ->call('import');

        $order = SalesOrder::query()->where('platform_order_id', 'WX-ADDR-SPLIT')->firstOrFail();

        $this->assertSame('東京都', $order->recipient_state);
        $this->assertSame('千代田区丸の内1-1', $order->recipient_address_line1);
    }

    public function test_paste_import_keeps_address_line_when_prefecture_is_mapped_separately(): void
    {
        [, $shop, $sku] = $this->salesSku('PASTE-ADDR-STATE');

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderPasteImport::class)
            ->set('shopId', (string) $shop->id)
            ->set('columnFieldMapping', [
                '0' => 'platform_order_id',
                '1' => 'sku',
                '2' => 'quantity',
                '3' => 'recipient_state',
                '4' => 'recipient_address_line1',
            ])
            ->set('dataStartRow', 0)
            ->set('grid', $this->pasteGrid([
                ['WX-ADDR-STATE', $sku->sku, '1', '東京都', '千代田区丸の内1-1'],
            ]))
            ->call('preview')
            ->assertSet('hasErrors', false)
            ->call('import');

        $order = SalesOrder::query()->where('platform_order_id', 'WX-ADDR-STATE')->firstOrFail();

        $this->assertSame('東京都', $order->recipient_state);
        $this->assertSame('千代田区丸の内1-1', $order->recipient_address_line1);
    }

    public function test_paste_import_does_not_split_japanese_prefecture_for_non_japan_marketplace(): void
    {
        [, $shop, $sku] = $this->salesSku('PASTE-ADDR-US');
        $shop->update(['marketplace' => 'US']);

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderPasteImport::class)
            ->set('shopId', (string) $shop->id)
            ->set('columnFieldMapping', [
                '0' => 'platform_order_id',
                '1' => 'sku',
                '2' => 'quantity',
                '3' => 'recipient_country_code',
                '4' => 'recipient_address_line1',
            ])
            ->set('dataStartRow', 0)
            ->set('grid', $this->pasteGrid([
                ['WX-ADDR-US', $sku->sku, '1', 'JP', '東京都千代田区丸の内1-1'],
            ]))
            ->call('preview')
            ->assertSet('hasErrors', false)
            ->call('import');

        $order = SalesOrder::query()->where('platform_order_id', 'WX-ADDR-US')->firstOrFail();

        $this->assertNull($order->recipient_state);
        $this->assertSame('東京都千代田区丸の内1-1', $order->recipient_address_line1);
    }

    public function test_paste_import_mapping_template_can_be_saved_and_loaded(): void
    {
        [, $shop] = $this->salesSku('PASTE-TEMPLATE');
        $mapping = [
            'platform_order_id' => '2',
            'sku' => '0',
            'quantity' => '1',
        ];

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderPasteImport::class)
            ->set('shopId', (string) $shop->id)
            ->set('columnMapping', $mapping)
            ->set('dataStartRow', 4)
            ->set('templateName', 'WeChat template')
            ->call('saveTemplate')
            ->assertHasNoErrors();

        $template = SalesOrderPasteImportMapping::query()
            ->where('tenant_id', $shop->tenant_id)
            ->where('name', 'WeChat template')
            ->firstOrFail();

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderPasteImport::class)
            ->set('shopId', (string) $shop->id)
            ->set('selectedTemplateId', (string) $template->id)
            ->call('loadTemplate')
            ->assertSet('columnMapping.platform_order_id', '2')
            ->assertSet('columnMapping.sku', '0')
            ->assertSet('columnMapping.quantity', '1')
            ->assertSet('columnFieldMapping.0', 'sku')
            ->assertSet('columnFieldMapping.1', 'quantity')
            ->assertSet('columnFieldMapping.2', 'platform_order_id')
            ->assertSet('dataStartRow', 4);
    }

    public function test_paste_import_template_can_be_saved_as_tenant_default(): void
    {
        [, $shop] = $this->salesSku('PASTE-DEFAULT');
        $oldDefault = SalesOrderPasteImportMapping::create([
            'tenant_id' => $shop->tenant_id,
            'name' => 'Old default',
            'mapping' => [
                'platform_order_id' => 0,
                'sku' => 1,
                'quantity' => 2,
            ],
            'data_start_row' => 0,
            'is_default' => true,
        ]);

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderPasteImport::class)
            ->set('shopId', (string) $shop->id)
            ->set('columnFieldMapping', [
                '2' => 'platform_order_id',
                '0' => 'sku',
                '1' => 'quantity',
            ])
            ->set('templateName', 'New default')
            ->set('saveTemplateAsDefault', true)
            ->call('saveTemplate')
            ->assertHasNoErrors();

        $this->assertFalse($oldDefault->refresh()->is_default);
        $this->assertDatabaseHas('sales_order_paste_import_mappings', [
            'tenant_id' => $shop->tenant_id,
            'name' => 'New default',
            'is_default' => true,
        ]);
    }

    public function test_paste_import_checkbox_sets_selected_template_as_default_immediately(): void
    {
        [, $shop] = $this->salesSku('PASTE-CHECK-DEFAULT');
        $oldDefault = SalesOrderPasteImportMapping::create([
            'tenant_id' => $shop->tenant_id,
            'name' => 'Old default',
            'mapping' => [
                'platform_order_id' => 0,
                'sku' => 1,
                'quantity' => 2,
            ],
            'data_start_row' => 0,
            'is_default' => true,
        ]);
        $newDefault = SalesOrderPasteImportMapping::create([
            'tenant_id' => $shop->tenant_id,
            'name' => 'New default',
            'mapping' => [
                'platform_order_id' => 2,
                'sku' => 0,
                'quantity' => 1,
            ],
            'data_start_row' => 3,
            'is_default' => false,
        ]);

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderPasteImport::class)
            ->set('shopId', (string) $shop->id)
            ->set('selectedTemplateId', (string) $newDefault->id)
            ->call('loadTemplate')
            ->set('saveTemplateAsDefault', true)
            ->assertSee(__('sales_orders.paste_import_template_default_saved'));

        $this->assertFalse($oldDefault->refresh()->is_default);
        $this->assertTrue($newDefault->refresh()->is_default);
    }

    public function test_paste_import_default_checkbox_requires_saved_template(): void
    {
        [, $shop] = $this->salesSku('PASTE-CHECK-NONE');

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderPasteImport::class)
            ->set('shopId', (string) $shop->id)
            ->set('saveTemplateAsDefault', true)
            ->assertSet('saveTemplateAsDefault', false)
            ->assertSee(__('sales_orders.paste_import_template_default_requires_saved'));
    }

    public function test_paste_import_loads_tenant_default_template_when_shop_is_selected(): void
    {
        [, $shop] = $this->salesSku('PASTE-AUTO-DEFAULT');
        SalesOrderPasteImportMapping::create([
            'tenant_id' => $shop->tenant_id,
            'name' => 'Auto default',
            'mapping' => [
                'platform_order_id' => 2,
                'sku' => 0,
                'quantity' => 1,
            ],
            'data_start_row' => 3,
            'is_default' => true,
        ]);

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderPasteImport::class)
            ->set('shopId', (string) $shop->id)
            ->assertSet('columnFieldMapping.0', 'sku')
            ->assertSet('columnFieldMapping.1', 'quantity')
            ->assertSet('columnFieldMapping.2', 'platform_order_id')
            ->assertSet('dataStartRow', 3);
    }

    public function test_paste_import_loads_tenant_default_template_from_shop_select_action(): void
    {
        [, $shop] = $this->salesSku('PASTE-AUTO-ACTION');
        SalesOrderPasteImportMapping::create([
            'tenant_id' => $shop->tenant_id,
            'name' => 'Action default',
            'mapping' => [
                'platform_order_id' => 2,
                'sku' => 0,
                'quantity' => 1,
            ],
            'data_start_row' => 3,
            'is_default' => true,
        ]);

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderPasteImport::class)
            ->call('selectShop', (string) $shop->id)
            ->assertSet('shopId', (string) $shop->id)
            ->assertSet('columnFieldMapping.0', 'sku')
            ->assertSet('columnFieldMapping.1', 'quantity')
            ->assertSet('columnFieldMapping.2', 'platform_order_id')
            ->assertSet('dataStartRow', 3);
    }

    public function test_paste_import_loads_only_template_when_no_default_is_marked(): void
    {
        [, $shop] = $this->salesSku('PASTE-AUTO-SINGLE');
        SalesOrderPasteImportMapping::create([
            'tenant_id' => $shop->tenant_id,
            'name' => 'Only template',
            'mapping' => [
                'platform_order_id' => 2,
                'sku' => 0,
                'quantity' => 1,
            ],
            'data_start_row' => 3,
            'is_default' => false,
        ]);

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderPasteImport::class)
            ->call('selectShop', (string) $shop->id)
            ->assertSet('columnFieldMapping.0', 'sku')
            ->assertSet('columnFieldMapping.1', 'quantity')
            ->assertSet('columnFieldMapping.2', 'platform_order_id')
            ->assertSet('dataStartRow', 3);
    }

    public function test_paste_import_loads_tenant_default_template_from_shop_query_param(): void
    {
        [, $shop] = $this->salesSku('PASTE-AUTO-QUERY');
        SalesOrderPasteImportMapping::create([
            'tenant_id' => $shop->tenant_id,
            'name' => 'Query default',
            'mapping' => [
                'platform_order_id' => 2,
                'sku' => 0,
                'quantity' => 1,
            ],
            'data_start_row' => 3,
            'is_default' => true,
        ]);

        Livewire::withQueryParams(['shop_id' => (string) $shop->id])
            ->actingAs($this->internalUser())
            ->test(SalesOrderPasteImport::class)
            ->assertSet('shopId', (string) $shop->id)
            ->assertSet('columnFieldMapping.0', 'sku')
            ->assertSet('columnFieldMapping.1', 'quantity')
            ->assertSet('columnFieldMapping.2', 'platform_order_id')
            ->assertSet('dataStartRow', 3);
    }

    public function test_paste_import_clears_mapping_when_selected_shop_has_no_default_template(): void
    {
        [, $shopWithDefault] = $this->salesSku('PASTE-CLEAR-DEFAULT');
        $otherTenant = Tenant::factory()->create(['status' => 'active']);
        $shopWithoutDefault = Shop::factory()->for($otherTenant)->create(['status' => 'active']);

        SalesOrderPasteImportMapping::create([
            'tenant_id' => $shopWithDefault->tenant_id,
            'name' => 'Default mapping',
            'mapping' => [
                'platform_order_id' => 2,
                'sku' => 0,
                'quantity' => 1,
            ],
            'data_start_row' => 3,
            'is_default' => true,
        ]);

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderPasteImport::class)
            ->set('shopId', (string) $shopWithDefault->id)
            ->assertSet('columnFieldMapping.0', 'sku')
            ->set('shopId', (string) $shopWithoutDefault->id)
            ->assertSet('selectedTemplateId', '')
            ->assertSet('columnMapping', [])
            ->assertSet('columnFieldMapping', [])
            ->assertSet('dataStartRow', 0);
    }

    public function test_tenant_user_cannot_load_another_tenants_paste_mapping_template(): void
    {
        [$tenant, $user] = $this->tenantUser();
        $ownShop = Shop::factory()->for($tenant)->create(['status' => 'active']);
        $otherTenant = Tenant::factory()->create(['status' => 'active']);
        $template = SalesOrderPasteImportMapping::create([
            'tenant_id' => $otherTenant->id,
            'name' => 'Other template',
            'mapping' => [
                'platform_order_id' => 2,
                'sku' => 0,
                'quantity' => 1,
            ],
            'data_start_row' => 3,
        ]);

        Livewire::actingAs($user)
            ->test(SalesOrderPasteImport::class)
            ->set('shopId', (string) $ownShop->id)
            ->set('selectedTemplateId', (string) $template->id)
            ->call('loadTemplate')
            ->assertSet('columnMapping', [])
            ->assertSet('dataStartRow', 0);
    }

    public function test_import_validate_button_waits_for_file_upload(): void
    {
        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderImport::class)
            ->assertSee('livewire-upload-start', false)
            ->assertSee('x-bind:disabled', false)
            ->assertSee('wire:loading.attr', false)
            ->assertSee('Uploading file', false);
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

    public function test_import_skips_duplicate_orders_and_imports_new_orders(): void
    {
        [$tenant, $shop, $sku] = $this->salesSku('SKIP-DUP-SKU');
        SalesOrder::factory()->for($tenant)->for($shop)->create(['platform_order_id' => 'SO-OLD']);

        $this->parseAndImport($shop, $this->csv([
            ['SO-OLD', $sku->sku, '1'],
            ['SO-NEW', $sku->sku, '2'],
        ]));

        $this->assertSame(2, SalesOrder::count());
        $this->assertDatabaseHas('sales_orders', ['platform_order_id' => 'SO-NEW']);
        $this->assertSame(1, SalesOrder::where('platform_order_id', 'SO-OLD')->count());
    }

    public function test_import_still_skips_preview_duplicate_even_if_order_is_deleted_before_confirm(): void
    {
        [$tenant, $shop, $sku] = $this->salesSku('SKIP-DELETED-DUP-SKU');
        $existing = SalesOrder::factory()->for($tenant)->for($shop)->create(['platform_order_id' => 'SO-DELETED-DUP']);

        $component = $this->parseOnly($shop, $this->csv([
            ['SO-DELETED-DUP', 'MISSING-SKU-STILL-SKIPPED', '1'],
            ['SO-NEW-AFTER-DELETED-DUP', $sku->sku, '2'],
        ]));

        $this->assertFalse($component->get('hasErrors'));
        $this->assertTrue($component->get('parsedRows')[0]['is_duplicate']);

        $existing->delete();
        $component->call('import');

        $this->assertDatabaseMissing('sales_orders', ['platform_order_id' => 'SO-DELETED-DUP']);
        $this->assertDatabaseHas('sales_orders', ['platform_order_id' => 'SO-NEW-AFTER-DELETED-DUP']);
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

    public function test_parse_row_number_counts_data_rows_only(): void
    {
        [, $shop, $sku] = $this->salesSku('ROW-SKU');
        $csv = $this->header()."\nSO-OK,{$sku->sku},1,,,,,,,,,,\n,,,,,,,,,,,,\nSO-BAD,NOPE,1,,,,,,,,,,\n";
        $component = $this->parseOnly($shop, $csv);
        $rows = $component->get('parsedRows');

        $this->assertSame(1, $rows[0]['row']);
        $this->assertSame(2, $rows[1]['row']);
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

        $component = $this->parseOnlyAmazon($shop, [$this->amazonRow([
            'sku' => 'AMZ-UNKNOWN',
            'product-name' => 'Unknown Amazon Product',
        ])]);

        $this->assertTrue($component->get('hasErrors'));
        $this->assertTrue($component->get('parsedRows')[0]['sku_not_found']);
        $component
            ->assertSee('SKU not found')
            ->assertSee('Add SKU')
            ->assertSee('name=Unknown%20Amazon%20Product', false)
            ->assertSee('platform_sku=AMZ-UNKNOWN', false);
        $component->call('import');
        $this->assertSame(0, SalesOrder::count());
    }

    public function test_amazon_report_marks_duplicate_existing_order_as_already_imported(): void
    {
        [$tenant, $shop, $sku] = $this->amazonSku('AMZ-DUP');
        SalesOrder::factory()->for($tenant)->for($shop)->create(['platform_order_id' => 'AMZ-DUP-ORDER']);

        $component = $this->parseOnlyAmazon($shop, [$this->amazonRow([
            'order-id' => 'AMZ-DUP-ORDER',
            'sku' => $sku->sku,
        ])]);

        $this->assertFalse($component->get('hasErrors'));
        $this->assertTrue($component->get('parsedRows')[0]['is_duplicate']);
        $this->assertSame([], $component->get('parsedRows')[0]['errors']);
    }

    public function test_amazon_report_rechecks_duplicate_during_confirm(): void
    {
        [$tenant, $shop, $sku] = $this->amazonSku('AMZ-RACE');
        $component = $this->parseOnlyAmazon($shop, [$this->amazonRow(['order-id' => 'AMZ-RACE-ORDER', 'sku' => $sku->sku])]);

        SalesOrder::factory()->for($tenant)->for($shop)->create(['platform_order_id' => 'AMZ-RACE-ORDER']);
        $component->call('import');

        $this->assertSame(1, SalesOrder::where('platform_order_id', 'AMZ-RACE-ORDER')->count());
    }

    public function test_amazon_report_skips_duplicate_orders_and_imports_new_orders(): void
    {
        [$tenant, $shop, $sku] = $this->amazonSku('AMZ-SKIP-DUP');
        SalesOrder::factory()->for($tenant)->for($shop)->create(['platform_order_id' => 'AMZ-OLD']);

        $this->parseAndImportAmazon($shop, [
            $this->amazonRow([
                'order-id' => 'AMZ-OLD',
                'sku' => 'OLD-SKU-NOT-IN-SYSTEM',
            ]),
            $this->amazonRow([
                'order-id' => 'AMZ-NEW',
                'order-item-id' => 'AMZ-NEW-LINE',
                'sku' => $sku->sku,
            ]),
        ]);

        $this->assertSame(2, SalesOrder::count());
        $this->assertDatabaseHas('sales_orders', ['platform_order_id' => 'AMZ-NEW']);
        $this->assertDatabaseMissing('sales_order_lines', ['platform_line_id' => 'AMZ-LINE-1']);
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

        $this->actingAs($this->internalUser());

        try {
            app(OutboundConsolidationService::class)->createGroup(
                tenantId: (int) $tenant->id,
                warehouseId: (int) $warehouse->id,
                salesOrderIds: [$order->id],
            );

            $this->fail('Cancel-requested order should not be accepted for fulfillment.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('selectedOrderIds', $exception->errors());
        }

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

        $this->actingAs($this->internalUser());
        app(OutboundConsolidationService::class)->createGroup(
            tenantId: (int) $tenant->id,
            warehouseId: (int) $warehouse->id,
            salesOrderIds: [$pending->id],
        );
        $this->assertSame(1, OutboundOrder::count());

        try {
            app(OutboundConsolidationService::class)->createGroup(
                tenantId: (int) $tenant->id,
                warehouseId: (int) $warehouse->id,
                salesOrderIds: [$onHold->id],
            );

            $this->fail('On-hold order should not be accepted for fulfillment.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('selectedOrderIds', $exception->errors());
        }
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

    public function test_amazon_report_imports_utf16le_bom_file(): void
    {
        [, $shop, $sku] = $this->amazonSku('AMZ-UTF16');
        $content = "\xFF\xFE".mb_convert_encoding($this->amazonTsv([
            $this->amazonRow(['sku' => $sku->sku]),
        ]), 'UTF-16LE', 'UTF-8');

        $this->parseAndImportAmazonContent($shop, $content);

        $this->assertSame(1, SalesOrder::count());
        $this->assertSame('AMZ-ORDER-1', SalesOrder::firstOrFail()->platform_order_id);
    }

    public function test_amazon_report_normalizes_dates_to_utc(): void
    {
        [, $shop, $sku] = $this->amazonSku('AMZ-DATE');

        $this->parseAndImportAmazon($shop, [$this->amazonRow([
            'sku' => $sku->sku,
            'purchase-date' => '2026-03-03T03:51:43+09:00',
            'latest-ship-date' => '2026-03-05T14:59:59+09:00',
        ])]);

        $order = SalesOrder::firstOrFail();

        $this->assertSame('2026-03-02 18:51:43', $order->platform_ordered_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-03-05 05:59:59', $order->latest_ship_at->format('Y-m-d H:i:s'));
    }

    public function test_amazon_report_preview_does_not_expose_internal_consistency_payload(): void
    {
        [, $shop, $sku] = $this->amazonSku('AMZ-PAYLOAD');

        $component = $this->parseOnlyAmazon($shop, [$this->amazonRow(['sku' => $sku->sku])]);

        $this->assertArrayNotHasKey('amazon_consistency', $component->get('parsedRows')[0]);
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

        $order = SalesOrder::firstOrFail();
        $this->assertNull($order->shipping_method);
        $this->assertNull($order->shipping_method_id);
    }

    public function test_amazon_report_sets_method_id_and_legacy_carrier_when_mapping_is_clear(): void
    {
        [, $shop, $sku] = $this->amazonSku('AMZ-NEKOPOS');

        $this->parseAndImportAmazon($shop, [$this->amazonRow([
            'sku' => $sku->sku,
            'ship-service-level' => 'Yamato Nekopos',
        ])]);

        $order = SalesOrder::firstOrFail();
        $this->assertSame(
            ShippingMethod::where('code', 'yamato_nekopos')->firstOrFail()->id,
            $order->shipping_method_id,
        );
        $this->assertSame('yamato', $order->shipping_method);
    }

    public function test_amazon_report_sku_default_overrides_platform_mapped_shipping_method(): void
    {
        [, $shop, $sku] = $this->amazonSku('AMZ-SKU-DEFAULT');
        $default = ShippingMethod::where('code', 'sagawa_thb')->firstOrFail();
        $default->update(['selection_priority' => 50]);
        $sku->update(['default_shipping_method_id' => $default->id]);

        $this->parseAndImportAmazon($shop, [$this->amazonRow([
            'sku' => $sku->sku,
            'ship-service-level' => 'Yamato Nekopos',
        ])]);

        $order = SalesOrder::firstOrFail();
        $this->assertSame($default->id, $order->shipping_method_id);
        $this->assertSame('sagawa', $order->shipping_method);
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

    private function pasteGrid(array $rows): array
    {
        $blankRow = array_fill(0, 18, '');

        return array_map(
            fn ($row) => array_slice(array_pad((array) $row, 18, ''), 0, 18),
            array_slice(array_pad($rows, 60, $blankRow), 0, 60),
        );
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
