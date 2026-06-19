<?php

namespace Tests\Feature;

use App\Exports\SalesOrdersExport;
use App\Livewire\SalesOrderImport;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\Shop;
use App\Models\Sku;
use App\Models\StockItem;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Support\SalesOrderFilters;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Testing\File;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

class SalesOrderExportTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_export_downloads_csv_with_expected_filename(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-19 19:45:30'));
        Excel::fake();

        $this->actingAs($this->internalUser())->get(route('sales.orders.export', ['format' => 'csv']))->assertOk();

        Excel::assertDownloaded('sales-orders-20260619-194530.csv');
    }

    public function test_export_downloads_xlsx_when_format_xlsx(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-19 19:45:30'));
        Excel::fake();

        $this->actingAs($this->internalUser())->get(route('sales.orders.export', ['format' => 'xlsx']))->assertOk();

        Excel::assertDownloaded('sales-orders-20260619-194530.xlsx');
    }

    public function test_export_defaults_to_csv_for_unknown_format(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-19 19:45:30'));
        Excel::fake();

        $this->actingAs($this->internalUser())->get(route('sales.orders.export', ['format' => 'bogus']))->assertOk();

        Excel::assertDownloaded('sales-orders-20260619-194530.csv');
    }

    public function test_export_has_one_row_per_line_with_order_fields_repeated(): void
    {
        [, $shop, $skuA] = $this->salesSku('EXP-A');
        $skuB = $this->skuForShop($shop->tenant, $shop, 'EXP-B');
        $order = $this->orderWithLines($shop, [
            [$skuA, 2, 'first'],
            [$skuB, 1, 'second'],
        ], ['platform_order_id' => 'EXP-1', 'recipient_name' => 'Chan']);

        $rows = $this->mappedRows($this->filters());

        $this->assertCount(2, $rows);
        $this->assertSame('EXP-1', $rows[0][0]);
        $this->assertSame('EXP-1', $rows[1][0]);
        $this->assertSame('Chan', $rows[0][4]);
        $this->assertSame('Chan', $rows[1][4]);
        $this->assertSame($order->id, SalesOrder::firstOrFail()->id);
    }

    public function test_export_respects_shop_filter(): void
    {
        [$tenant, $shopA, $skuA] = $this->salesSku('SHOP-A');
        $shopB = Shop::factory()->for($tenant)->create(['status' => 'active']);
        $skuB = $this->skuForShop($tenant, $shopB, 'SHOP-B');
        $this->orderWithLines($shopA, [[$skuA, 1]], ['platform_order_id' => 'ORDER-A']);
        $this->orderWithLines($shopB, [[$skuB, 1]], ['platform_order_id' => 'ORDER-B']);

        $rows = $this->mappedRows($this->filters(['shop_id' => (string) $shopA->id]));

        $this->assertSame(['ORDER-A'], array_column($rows, 0));
    }

    public function test_export_respects_fulfillment_status_filter(): void
    {
        [, $shop, $sku] = $this->salesSku('FULFILL-SKU');
        $this->orderWithLines($shop, [[$sku, 1]], [
            'platform_order_id' => 'READY',
            'fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_READY,
        ]);
        $this->orderWithLines($shop, [[$sku, 1]], ['platform_order_id' => 'UNFULFILLED']);

        $rows = $this->mappedRows($this->filters(['fulfillment' => SalesOrder::FULFILLMENT_STATUS_READY]));

        $this->assertSame(['READY'], array_column($rows, 0));
    }

    public function test_export_respects_order_status_filter(): void
    {
        [, $shop, $sku] = $this->salesSku('STATUS-SKU');
        $this->orderWithLines($shop, [[$sku, 1]], [
            'platform_order_id' => 'HOLD',
            'order_status' => SalesOrder::ORDER_STATUS_ON_HOLD,
        ]);
        $this->orderWithLines($shop, [[$sku, 1]], ['platform_order_id' => 'PENDING']);

        $rows = $this->mappedRows($this->filters(['order_status' => SalesOrder::ORDER_STATUS_ON_HOLD]));

        $this->assertSame(['HOLD'], array_column($rows, 0));
    }

    public function test_export_respects_search_filter(): void
    {
        [, $shop, $sku] = $this->salesSku('SEARCH-SKU');
        $this->orderWithLines($shop, [[$sku, 1]], ['platform_order_id' => 'AMZ-CHAN', 'recipient_name' => 'Someone']);
        $this->orderWithLines($shop, [[$sku, 1]], ['platform_order_id' => 'NOPE', 'recipient_name' => 'Chan Tai']);
        $this->orderWithLines($shop, [[$sku, 1]], ['platform_order_id' => 'MISS', 'recipient_name' => 'Lee']);

        $rows = $this->mappedRows($this->filters(['search' => 'Chan']));

        $this->assertSame(['NOPE', 'AMZ-CHAN'], array_column($rows, 0));
    }

    public function test_export_orders_newest_sales_orders_first(): void
    {
        [, $shop, $sku] = $this->salesSku('ORDER-SKU');
        $this->orderWithLines($shop, [[$sku, 1]], [
            'platform_order_id' => 'OLDER',
            'created_at' => Carbon::parse('2026-06-18 10:00:00'),
        ]);
        $this->orderWithLines($shop, [[$sku, 1]], [
            'platform_order_id' => 'NEWER',
            'created_at' => Carbon::parse('2026-06-19 10:00:00'),
        ]);

        $rows = $this->mappedRows($this->filters());

        $this->assertSame(['NEWER', 'OLDER'], array_column($rows, 0));
    }

    public function test_export_first_thirteen_headings_match_import_header(): void
    {
        $headings = (new SalesOrdersExport($this->filters()))->headings();

        $this->assertSame(SalesOrdersExport::IMPORT_HEADINGS, array_slice($headings, 0, 13));
    }

    public function test_export_sku_column_is_code_and_quantity_is_integer(): void
    {
        [, $shop, $sku] = $this->salesSku('CODE-SKU');
        $this->orderWithLines($shop, [[$sku, 5]], ['platform_order_id' => 'CODE-ORDER']);

        $row = $this->mappedRows($this->filters())[0];

        $this->assertSame('CODE-SKU', $row[1]);
        $this->assertIsInt($row[2]);
        $this->assertSame(5, $row[2]);
    }

    public function test_export_null_fields_render_as_empty_string(): void
    {
        [, $shop, $sku] = $this->salesSku('NULL-SKU');
        $this->orderWithLines($shop, [[$sku, 1]], [
            'platform_order_id' => 'NULL-ORDER',
            'recipient_phone' => null,
        ]);

        $row = $this->mappedRows($this->filters())[0];

        $this->assertSame('', $row[5]);
    }

    public function test_export_empty_result_returns_header_only_file(): void
    {
        $export = new SalesOrdersExport($this->filters(['search' => 'missing']));

        $this->assertSame(SalesOrdersExport::IMPORT_HEADINGS, array_slice($export->headings(), 0, 13));
        $this->assertCount(0, $export->query()->get());
    }

    public function test_tenant_user_only_exports_own_tenant_orders(): void
    {
        [$tenant, $user] = $this->tenantUser();
        $shop = Shop::factory()->for($tenant)->create(['status' => 'active']);
        $ownSku = $this->skuForShop($tenant, $shop, 'OWN-EXPORT');
        $this->orderWithLines($shop, [[$ownSku, 1]], ['platform_order_id' => 'OWN-ORDER']);
        [, $otherShop, $otherSku] = $this->salesSku('OTHER-EXPORT');
        $this->orderWithLines($otherShop, [[$otherSku, 1]], ['platform_order_id' => 'OTHER-ORDER']);

        $rows = $this->mappedRows($this->filters(['allowed_tenant_ids' => [$tenant->id]]));

        $this->assertSame(['OWN-ORDER'], array_column($rows, 0));
        $this->actingAs($user)->get(route('sales.orders.export'))->assertOk();
    }

    public function test_tenant_user_tampered_shop_exports_empty_result_not_leaked(): void
    {
        [$tenant, $user] = $this->tenantUser();
        $shop = Shop::factory()->for($tenant)->create(['status' => 'active']);
        $ownSku = $this->skuForShop($tenant, $shop, 'OWN-TAMPER');
        $this->orderWithLines($shop, [[$ownSku, 1]], ['platform_order_id' => 'OWN-TAMPER']);
        [, $otherShop] = $this->salesSku('OTHER-TAMPER');

        $rows = $this->mappedRows($this->filters([
            'allowed_tenant_ids' => [$tenant->id],
            'shop_id' => (string) $otherShop->id,
            'shop_filter_allowed' => false,
        ]));

        $this->assertSame([], $rows);
        $this->actingAs($user)->get(route('sales.orders.export', ['shop' => $otherShop->id]))->assertOk();
    }

    public function test_tenant_user_without_active_tenant_gets_403(): void
    {
        $user = User::factory()->create(['user_type' => 'tenant', 'is_active' => true]);

        $this->actingAs($user)->get(route('sales.orders.export'))->assertForbidden();
    }

    public function test_exported_file_round_trips_through_import(): void
    {
        [$sourceTenant, $sourceShop, $sourceSkuA] = $this->salesSku('ROUND-A');
        $sourceSkuB = $this->skuForShop($sourceTenant, $sourceShop, 'ROUND-B');
        $this->orderWithLines($sourceShop, [[$sourceSkuA, 2, 'first'], [$sourceSkuB, 1, 'second']], [
            'platform_order_id' => 'ROUND-ORDER',
            'recipient_name' => 'Round Trip',
        ]);

        $export = new SalesOrdersExport($this->filters());
        $rows = $export->query()->get()->map(fn ($line) => $export->map($line))->all();
        $csv = $this->csv([$export->headings(), ...$rows]);

        $targetTenant = Tenant::factory()->create(['status' => 'active']);
        $targetShop = Shop::factory()->for($targetTenant)->create(['status' => 'active']);
        $this->skuForShop($targetTenant, $targetShop, 'ROUND-A');
        $this->skuForShop($targetTenant, $targetShop, 'ROUND-B');

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderImport::class)
            ->set('shopId', (string) $targetShop->id)
            ->set('file', File::createWithContent('round-trip.csv', $csv))
            ->call('parse')
            ->assertSet('hasErrors', false)
            ->call('import')
            ->assertRedirect(route('sales.orders.index'));

        $imported = SalesOrder::where('shop_id', $targetShop->id)->firstOrFail();

        $this->assertSame('ROUND-ORDER', $imported->platform_order_id);
        $this->assertSame('Round Trip', $imported->recipient_name);
        $this->assertSame(2, $imported->lines()->count());
    }

    public function test_export_constrains_to_selected_ids(): void
    {
        [, $shop, $sku] = $this->salesSku('SELECTED-SKU');
        $first = $this->orderWithLines($shop, [[$sku, 1]], ['platform_order_id' => 'SELECTED-1']);
        $second = $this->orderWithLines($shop, [[$sku, 1]], ['platform_order_id' => 'SELECTED-2']);
        $this->orderWithLines($shop, [[$sku, 1]], ['platform_order_id' => 'NOT-SELECTED']);

        $rows = $this->mappedRows($this->filters([
            'has_order_id_filter' => true,
            'order_ids' => [$first->id, $second->id],
        ]));

        $this->assertSame(['SELECTED-2', 'SELECTED-1'], array_column($rows, 0));
    }

    public function test_export_ids_respects_tenant_scope(): void
    {
        [$tenant] = $this->tenantUser();
        $shop = Shop::factory()->for($tenant)->create(['status' => 'active']);
        $ownSku = $this->skuForShop($tenant, $shop, 'IDS-OWN');
        $ownOrder = $this->orderWithLines($shop, [[$ownSku, 1]], ['platform_order_id' => 'IDS-OWN']);
        [, $otherShop, $otherSku] = $this->salesSku('IDS-OTHER');
        $otherOrder = $this->orderWithLines($otherShop, [[$otherSku, 1]], ['platform_order_id' => 'IDS-OTHER']);

        $rows = $this->mappedRows($this->filters([
            'allowed_tenant_ids' => [$tenant->id],
            'has_order_id_filter' => true,
            'order_ids' => [$ownOrder->id, $otherOrder->id],
        ]));

        $this->assertSame(['IDS-OWN'], array_column($rows, 0));
    }

    public function test_export_empty_ids_param_behaves_like_full_export(): void
    {
        [, $shop, $sku] = $this->salesSku('EMPTY-IDS');
        $this->orderWithLines($shop, [[$sku, 1]], ['platform_order_id' => 'EMPTY-IDS-ORDER']);

        $rows = $this->mappedRows($this->filters([
            'has_order_id_filter' => false,
            'order_ids' => [],
        ]));

        $this->assertSame(['EMPTY-IDS-ORDER'], array_column($rows, 0));
    }

    public function test_export_invalid_ids_param_returns_empty_result_not_full_export(): void
    {
        [, $shop, $sku] = $this->salesSku('BAD-IDS');
        $this->orderWithLines($shop, [[$sku, 1]], ['platform_order_id' => 'BAD-IDS-ORDER']);

        $rows = $this->mappedRows($this->filters([
            'has_order_id_filter' => true,
            'order_ids' => [],
        ]));

        $this->assertSame([], $rows);
    }

    public function test_sales_order_export_respects_multi_select_filters(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        $amazon = Shop::factory()->for($tenant)->create(['status' => 'active', 'platform' => 'amazon']);
        $rakuten = Shop::factory()->for($tenant)->create(['status' => 'active', 'platform' => 'rakuten']);
        $shopify = Shop::factory()->for($tenant)->create(['status' => 'active', 'platform' => 'shopify']);
        $amazonSku = $this->skuForShop($tenant, $amazon, 'EXP-AMZ');
        $rakutenSku = $this->skuForShop($tenant, $rakuten, 'EXP-RAK');
        $shopifySku = $this->skuForShop($tenant, $shopify, 'EXP-SHO');
        $this->orderWithLines($amazon, [[$amazonSku, 1]], [
            'platform_order_id' => 'EXP-AMAZON',
            'shipping_method' => 'yamato',
            'fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_READY,
            'order_status' => SalesOrder::ORDER_STATUS_PENDING,
            'order_date' => now()->subDays(2),
        ]);
        $this->orderWithLines($rakuten, [[$rakutenSku, 1]], [
            'platform_order_id' => 'EXP-RAKUTEN',
            'shipping_method' => 'sagawa',
            'fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_IN_GROUP,
            'order_status' => SalesOrder::ORDER_STATUS_ON_HOLD,
            'order_date' => now()->subDays(3),
        ]);
        $this->orderWithLines($shopify, [[$shopifySku, 1]], [
            'platform_order_id' => 'EXP-SHOPIFY',
            'shipping_method' => 'japan_post',
            'fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_UNFULFILLED,
            'order_status' => SalesOrder::ORDER_STATUS_BACKORDER,
            'order_date' => now()->subDays(4),
        ]);

        $rows = $this->mappedRows($this->filters([
            'platforms' => ['amazon', 'rakuten'],
            'shops' => [(string) $amazon->id, (string) $rakuten->id],
            'fulfillment' => [SalesOrder::FULFILLMENT_STATUS_READY, SalesOrder::FULFILLMENT_STATUS_IN_GROUP],
            'order_status' => [SalesOrder::ORDER_STATUS_PENDING, SalesOrder::ORDER_STATUS_ON_HOLD],
            'shipping' => ['yamato', 'sagawa'],
            'date_range' => SalesOrderFilters::DATE_LAST_7_DAYS,
        ]));

        $this->assertSame(['EXP-AMAZON', 'EXP-RAKUTEN'], array_column($rows, 0));
    }

    public function test_sales_order_export_search_matches_new_search_fields(): void
    {
        [, $shop, $sku] = $this->salesSku('EXPORT-SEARCH');
        $sku->stockItem->update(['short_name' => 'ExportShort']);
        $target = $this->orderWithLines($shop, [[$sku, 1, 'Export Line Note']], [
            'platform_order_id' => 'EXPORT-TARGET',
            'tracking_no' => 'EXPORT-TRACK',
            'note' => 'Export Order Note',
        ]);
        $missSku = $this->skuForShop($shop->tenant, $shop, 'EXPORT-MISS-SKU');
        $this->orderWithLines($shop, [[$missSku, 1]], ['platform_order_id' => 'EXPORT-MISS']);

        foreach (['EXPORT-TRACK', 'Export Order Note', 'Export Line Note', 'ExportShort'] as $term) {
            $rows = $this->mappedRows($this->filters(['search' => $term]));

            $this->assertSame(['EXPORT-TARGET'], array_column($rows, 0));
        }

        $this->assertNotNull($target->id);
    }

    public function test_sales_order_export_blocks_unbounded_historical_export(): void
    {
        Excel::fake();

        $this->actingAs($this->internalUser())
            ->get(route('sales.orders.export', [
                'fulfillment' => [SalesOrder::FULFILLMENT_STATUS_SHIPPED],
                'date_range' => SalesOrderFilters::DATE_ALL,
            ]))
            ->assertStatus(422)
            ->assertSee(__('sales_orders.export_requires_date_range'));
    }

    public function test_sales_order_export_rejects_custom_range_over_365_days(): void
    {
        Excel::fake();

        $this->actingAs($this->internalUser())
            ->get(route('sales.orders.export', [
                'date_range' => SalesOrderFilters::DATE_CUSTOM,
                'date_from' => '2024-01-01',
                'date_to' => '2026-01-02',
            ]))
            ->assertStatus(422)
            ->assertSee(__('sales_orders.date_range_too_wide'));
    }

    private function mappedRows(array $filters): array
    {
        $export = new SalesOrdersExport($filters);

        return $export->query()
            ->get()
            ->map(fn ($line) => $export->map($line))
            ->values()
            ->all();
    }

    private function filters(array $overrides = []): array
    {
        return array_merge([
            'allowed_tenant_ids' => Tenant::query()->pluck('id')->all(),
            'has_order_id_filter' => false,
            'order_ids' => [],
            'shop_id' => '',
            'shops' => [],
            'shop_filter_allowed' => true,
            'fulfillment' => '',
            'order_status' => '',
            'shipping' => [],
            'platforms' => [],
            'date_range' => SalesOrderFilters::DATE_ALL,
            'active_only' => true,
            'date_from' => '',
            'date_to' => '',
            'search' => '',
        ], $overrides);
    }

    /**
     * @param array<int,array<int,mixed>> $rows
     */
    private function csv(array $rows): string
    {
        return collect($rows)
            ->map(fn ($row) => collect($row)
                ->map(fn ($value) => $this->escapeCsv((string) $value))
                ->implode(','))
            ->implode("\r\n")."\r\n";
    }

    private function escapeCsv(string $value): string
    {
        if (str_contains($value, ',') || str_contains($value, '"') || str_contains($value, "\n") || str_contains($value, "\r")) {
            return '"'.str_replace('"', '""', $value).'"';
        }

        return $value;
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
            ->create([
                'sku_type' => 'single',
                'sku' => $skuCode,
                'status' => 'active',
            ]);
    }

    /**
     * @param array<int,array{0: Sku, 1: int, 2?: string}> $lines
     */
    private function orderWithLines(Shop $shop, array $lines, array $attributes = []): SalesOrder
    {
        $order = SalesOrder::factory()->for($shop->tenant)->for($shop)->create(array_merge([
            'source' => SalesOrder::SOURCE_MANUAL,
            'platform_order_id' => 'SO-'.fake()->unique()->numberBetween(1000, 9999),
            'order_status' => SalesOrder::ORDER_STATUS_PENDING,
            'fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_UNFULFILLED,
            'recipient_name' => 'Taro',
            'recipient_phone' => '090',
            'recipient_country_code' => 'JP',
            'recipient_postal_code' => '1000001',
            'recipient_state' => 'Tokyo',
            'recipient_city' => 'Chiyoda',
            'recipient_address_line1' => '1 Main',
            'recipient_address_line2' => '',
            'note' => 'order note',
        ], $attributes));

        foreach ($lines as $line) {
            $order->lines()->create([
                'sku_id' => $line[0]->id,
                'quantity' => $line[1],
                'line_status' => SalesOrderLine::STATUS_READY,
                'note' => $line[2] ?? null,
            ]);
        }

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
