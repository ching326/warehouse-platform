<?php

namespace Tests\Feature;

use App\Livewire\SalesOrderIndex;
use App\Models\MarketplaceShippingNoticeBatch;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\ShippingMethod;
use App\Models\ShippingMethodMarketplaceMapping;
use App\Models\Shop;
use App\Models\Sku;
use App\Models\StockItem;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\MarketplaceShippingNotice\MarketplaceShippingNoticeExportService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class MarketplaceShippingNoticeExportTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_marketplace_notice_requires_selection(): void
    {
        $result = app(MarketplaceShippingNoticeExportService::class)->validateExport([], 'amazon', [1]);

        $this->assertFalse($result->ok);
        $this->assertSame(__('sales_orders.marketplace_notice_export_no_selection'), $result->message);
    }

    public function test_amazon_notice_blocks_non_amazon_orders_and_mixed_platforms(): void
    {
        [$tenant, $amazonShop, $sku] = $this->salesSku('amazon');
        [, $rakutenShop, $rakutenSku] = $this->salesSku('rakuten', $tenant);
        $amazon = $this->order($amazonShop, $sku);
        $rakuten = $this->order($rakutenShop, $rakutenSku);
        $this->mapping($amazon->shippingMethod, 'amazon');
        $this->mapping($rakuten->shippingMethod, 'rakuten');

        $wrong = app(MarketplaceShippingNoticeExportService::class)->validateExport([$rakuten->id], 'amazon', [$tenant->id]);
        $mixed = app(MarketplaceShippingNoticeExportService::class)->validateExport([$amazon->id, $rakuten->id], 'amazon', [$tenant->id]);

        $this->assertSame([$rakuten->id], $wrong->wrongPlatformOrderIds);
        $this->assertEqualsCanonicalizing([$amazon->id, $rakuten->id], $mixed->mixedPlatformOrderIds);
    }

    public function test_marketplace_notice_blocks_mixed_tenant_selection(): void
    {
        [$tenantA, $shopA, $skuA] = $this->salesSku('amazon');
        [$tenantB, $shopB, $skuB] = $this->salesSku('amazon');
        $orderA = $this->order($shopA, $skuA);
        $orderB = $this->order($shopB, $skuB);
        $this->mapping($orderA->shippingMethod, 'amazon');
        $this->mapping($orderB->shippingMethod, 'amazon');

        $result = app(MarketplaceShippingNoticeExportService::class)->validateExport([$orderA->id, $orderB->id], 'amazon', [$tenantA->id, $tenantB->id]);

        $this->assertEqualsCanonicalizing([$orderA->id, $orderB->id], $result->mixedTenantOrderIds);
    }

    public function test_marketplace_notice_blocks_statuses_missing_shipping_tracking_mapping_and_ready_lines(): void
    {
        [$tenant, $shop, $sku] = $this->salesSku('amazon');
        $statusOrders = collect([
            SalesOrder::ORDER_STATUS_ON_HOLD,
            SalesOrder::ORDER_STATUS_BACKORDER,
            SalesOrder::ORDER_STATUS_CANCEL_REQUESTED,
            SalesOrder::ORDER_STATUS_CANCELLED,
        ])->map(fn (string $status) => $this->order($shop, $sku, ['order_status' => $status]));
        $unmappedMethod = ShippingMethod::where('code', 'sagawa_thb')->firstOrFail();
        $missingMethod = $this->order($shop, $sku, ['shipping_method_id' => null]);
        $missingTracking = $this->order($shop, $sku, ['tracking_no' => '']);
        $missingMapping = $this->order($shop, $sku, ['shipping_method_id' => $unmappedMethod->id]);
        $noReadyLine = $this->order($shop, $sku, [], SalesOrderLine::STATUS_CANCELLED);

        $this->mapping($statusOrders->first()->shippingMethod, 'amazon');

        $result = app(MarketplaceShippingNoticeExportService::class)->validateExport(
            $statusOrders->pluck('id')->merge([$missingMethod->id, $missingTracking->id, $missingMapping->id, $noReadyLine->id])->all(),
            'amazon',
            [$tenant->id],
        );

        $this->assertEqualsCanonicalizing($statusOrders->pluck('id')->all(), $result->blockedStatusOrderIds);
        $this->assertSame([$missingMethod->id], $result->missingShippingMethodOrderIds);
        $this->assertContains($missingTracking->id, $result->missingTrackingOrderIds);
        $this->assertContains($missingMapping->id, $result->missingMappingOrderIds);
        $this->assertSame([$noReadyLine->id], $result->noReadyLinesOrderIds);
    }

    public function test_marketplace_notice_allows_blank_tracking_for_non_trackable_methods(): void
    {
        [$tenant, $shop, $sku] = $this->salesSku('rakuten');
        $method = ShippingMethod::where('code', 'other')->firstOrFail();
        $method->update(['is_trackable' => false]);
        $this->mapping($method, 'rakuten', carrierCode: '99');
        $order = $this->order($shop, $sku, [
            'shipping_method_id' => $method->id,
            'tracking_no' => '',
        ]);

        $result = app(MarketplaceShippingNoticeExportService::class)->validateExport([$order->id], 'rakuten', [$tenant->id]);

        $this->assertTrue($result->ok);
        $this->assertSame([], $result->missingTrackingOrderIds);
    }

    public function test_marketplace_notice_requires_mapping_carrier_code_and_uses_empty_marketplace_fallback(): void
    {
        [$tenant, $shop, $sku] = $this->salesSku('amazon');
        $order = $this->order($shop, $sku);
        $this->mapping($order->shippingMethod, 'amazon', marketplace: '', carrierCode: '');

        $missingCode = app(MarketplaceShippingNoticeExportService::class)->validateExport([$order->id], 'amazon', [$tenant->id]);
        $this->assertSame([$order->id], $missingCode->missingCarrierCodeOrderIds);

        $order->shippingMethod->marketplaceMappings()->delete();
        $this->mapping($order->shippingMethod, 'amazon', marketplace: '', carrierCode: 'Yamato');

        $fallback = app(MarketplaceShippingNoticeExportService::class)->validateExport([$order->id], 'amazon', [$tenant->id]);
        $this->assertTrue($fallback->ok);
    }

    public function test_marketplace_notice_requires_reexport_confirmation(): void
    {
        [$tenant, $shop, $sku] = $this->salesSku('amazon');
        $order = $this->order($shop, $sku, ['marketplace_shipping_notice_exported_at' => now()]);
        $this->mapping($order->shippingMethod, 'amazon');

        $result = app(MarketplaceShippingNoticeExportService::class)->validateExport([$order->id], 'amazon', [$tenant->id]);

        $this->assertFalse($result->ok);
        $this->assertTrue($result->requiresConfirmation);
        $this->assertSame([$order->id], $result->alreadyExportedOrderIds);
    }

    public function test_amazon_shipping_notice_exports_sjis_tsv_one_row_per_ready_line_with_japan_time(): void
    {
        Storage::fake('local');
        Carbon::setTestNow(Carbon::parse('2026-06-20 06:30:00', 'UTC'));
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-20 06:30:00', 'UTC'));
        [$tenant, $shop, $sku] = $this->salesSku('amazon');
        $order = $this->order($shop, $sku, ['platform_order_id' => 'AMZ-ORDER-1']);
        $order->lines()->create(['sku_id' => $sku->id, 'quantity' => 2, 'platform_line_id' => 'ITEM-2', 'line_status' => SalesOrderLine::STATUS_READY]);
        $this->mapping($order->shippingMethod, 'amazon', carrierCode: 'Yamato', carrierName: 'ヤマト', serviceName: 'ネコポス');

        $batch = app(MarketplaceShippingNoticeExportService::class)->export([$order->id], 'amazon', [$tenant->id], $this->internalUser());
        $content = Storage::disk('local')->get($batch->path);
        $decoded = mb_convert_encoding($content, 'UTF-8', 'SJIS-win');

        $this->assertSame('amazon-shipping-notice-20260620-153000.txt', $batch->file_name);
        $this->assertSame(2, $batch->line_count);
        $this->assertStringContainsString("TemplateType=OrderFulfillment\tVersion=2011.1102\tAmazon shipment confirmation feed", $decoded);
        $this->assertStringContainsString("order-id\torder-item-id\tquantity\tship-date\tcarrier-code\tcarrier-name\ttracking-number\tship-method", $decoded);
        $this->assertStringContainsString("AMZ-ORDER-1\tITEM-1\t1\t2026-06-20T15:30:00+09:00\tYamato\tヤマト\tTRACK-100\tネコポス", $decoded);
        $this->assertStringContainsString("AMZ-ORDER-1\tITEM-2\t2\t2026-06-20T15:30:00+09:00\tYamato\tヤマト\tTRACK-100\tネコポス", $decoded);
        $this->assertStringContainsString("\r\n", $content);
        $this->assertFalse(mb_check_encoding($content, 'UTF-8'));
    }

    public function test_rakuten_shipping_notice_exports_sjis_csv_one_row_per_order_with_japan_date(): void
    {
        Storage::fake('local');
        Carbon::setTestNow(Carbon::parse('2026-06-19 15:30:00', 'UTC'));
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-19 15:30:00', 'UTC'));
        [$tenant, $shop, $sku] = $this->salesSku('rakuten');
        $order = $this->order($shop, $sku, ['platform_order_id' => 'RKT-ORDER-1']);
        $this->mapping($order->shippingMethod, 'rakuten', carrierCode: '1001');

        $batch = app(MarketplaceShippingNoticeExportService::class)->export([$order->id], 'rakuten', [$tenant->id], $this->internalUser());
        $content = Storage::disk('local')->get($batch->path);
        $decoded = mb_convert_encoding($content, 'UTF-8', 'SJIS-win');

        $this->assertSame('rakuten-shipping-notice-20260620-003000.csv', $batch->file_name);
        $this->assertSame(1, $batch->line_count);
        $this->assertStringContainsString('豕ｨ譁・分蜿ｷ', $decoded);
        $this->assertStringContainsString('RKT-ORDER-1,,,TRACK-100,1001,2026-06-20', $decoded);
        $this->assertStringContainsString("\r\n", $content);
        $this->assertFalse(mb_check_encoding($content, 'UTF-8'));
    }

    public function test_marketplace_notice_export_creates_batch_marks_order_and_does_not_mark_shipped(): void
    {
        Storage::fake('local');
        [$tenant, $shop, $sku] = $this->salesSku('amazon');
        $order = $this->order($shop, $sku);
        $this->mapping($order->shippingMethod, 'amazon');

        $batch = app(MarketplaceShippingNoticeExportService::class)->export([$order->id], 'amazon', [$tenant->id], $this->internalUser());

        $this->assertDatabaseHas('marketplace_shipping_notice_batches', [
            'id' => $batch->id,
            'tenant_id' => $tenant->id,
            'platform' => 'amazon',
            'marketplace' => 'JP',
            'order_count' => 1,
        ]);
        $this->assertDatabaseHas('marketplace_shipping_notice_batch_orders', [
            'marketplace_shipping_notice_batch_id' => $batch->id,
            'sales_order_id' => $order->id,
            'platform_order_id' => $order->platform_order_id,
            'tracking_no' => 'TRACK-100',
        ]);
        Storage::disk('local')->assertExists($batch->path);
        $order->refresh();
        $this->assertNotNull($order->marketplace_shipping_notice_exported_at);
        $this->assertSame(SalesOrder::FULFILLMENT_STATUS_READY, $order->fulfillment_status);
        $this->assertNull($order->shipped_at);
    }

    public function test_tenant_user_cannot_export_or_download_other_tenant_batch(): void
    {
        Storage::fake('local');
        [$tenant, $shop, $sku] = $this->salesSku('amazon');
        [$otherTenant, $otherShop, $otherSku] = $this->salesSku('amazon');
        $order = $this->order($shop, $sku, ['platform_order_id' => 'OWN-NOTICE']);
        $otherOrder = $this->order($otherShop, $otherSku, ['platform_order_id' => 'OTHER-NOTICE']);
        $this->mapping($order->shippingMethod, 'amazon');
        $this->mapping($otherOrder->shippingMethod, 'amazon');

        $result = app(MarketplaceShippingNoticeExportService::class)->validateExport([$otherOrder->id], 'amazon', [$tenant->id]);
        $this->assertSame([$otherOrder->id], $result->missingOrderIds);

        $batch = app(MarketplaceShippingNoticeExportService::class)->export([$order->id], 'amazon', [$tenant->id], $this->tenantUser($tenant));

        $this->actingAs($this->tenantUser($otherTenant))
            ->get(route('marketplace-shipping-notice-batches.download', $batch))
            ->assertForbidden();
        $this->actingAs($this->internalUser())
            ->get(route('marketplace-shipping-notice-batches.download', $batch))
            ->assertOk();
    }

    public function test_sales_order_index_marketplace_notice_flow_and_confirmation(): void
    {
        Storage::fake('local');
        [$tenant, $shop, $sku] = $this->salesSku('amazon');
        $order = $this->order($shop, $sku, ['marketplace_shipping_notice_exported_at' => now()]);
        $this->mapping($order->shippingMethod, 'amazon');

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderIndex::class)
            ->assertSee(__('sales_orders.btn_export_amazon_ship_notice'))
            ->assertSee(__('sales_orders.btn_export_rakuten_ship_notice'))
            ->set('selectedIds', [$order->id])
            ->call('validateMarketplaceShippingNoticeExport', 'amazon')
            ->assertSet('pendingMarketplaceNoticePlatform', 'amazon')
            ->assertSet('pendingMarketplaceNoticeOrderIds', [$order->id])
            ->call('confirmMarketplaceShippingNoticeExport')
            ->assertRedirect(route('marketplace-shipping-notice-batches.download', MarketplaceShippingNoticeBatch::firstOrFail()));

        $this->assertNotNull($order->fresh()->marketplace_shipping_notice_exported_at);
        $this->assertSame($tenant->id, MarketplaceShippingNoticeBatch::firstOrFail()->tenant_id);
    }

    public function test_marketplace_notice_export_cleans_up_file_when_database_write_fails(): void
    {
        Storage::fake('local');
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-20 00:00:00', 'Asia/Tokyo'));
        [$tenant, $shop, $sku] = $this->salesSku('amazon');
        $order = $this->order($shop, $sku);
        $this->mapping($order->shippingMethod, 'amazon');
        $path = 'marketplace_shipping_notices/amazon/2026/06/amazon-shipping-notice-20260620-000000.txt';

        MarketplaceShippingNoticeBatch::creating(function (): void {
            throw new RuntimeException('Simulated notice batch failure.');
        });

        try {
            app(MarketplaceShippingNoticeExportService::class)->export([$order->id], 'amazon', [$tenant->id], $this->internalUser());
            $this->fail('Expected marketplace notice export to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated notice batch failure.', $exception->getMessage());
        } finally {
            MarketplaceShippingNoticeBatch::flushEventListeners();
        }

        Storage::disk('local')->assertMissing($path);
        $this->assertSame([], Storage::disk('local')->allFiles('tmp/marketplace_shipping_notices'));
        $this->assertDatabaseCount('marketplace_shipping_notice_batches', 0);
        $this->assertNull($order->fresh()->marketplace_shipping_notice_exported_at);
    }

    private function salesSku(string $platform, ?Tenant $tenant = null): array
    {
        $tenant ??= Tenant::factory()->create(['status' => 'active']);
        $shop = Shop::factory()->for($tenant)->create([
            'status' => 'active',
            'platform' => $platform,
            'marketplace' => 'JP',
            'name' => ucfirst($platform).' Shop',
        ]);
        $stockItem = StockItem::factory()->for($tenant)->create();
        $sku = Sku::factory()->for($tenant)->for($shop)->for($stockItem)->create([
            'sku_type' => 'single',
            'sku' => strtoupper($platform).'-SKU-'.fake()->unique()->numberBetween(1000, 9999),
        ]);

        return [$tenant, $shop, $sku];
    }

    private function order(Shop $shop, Sku $sku, array $attributes = [], string $lineStatus = SalesOrderLine::STATUS_READY): SalesOrder
    {
        $method = ShippingMethod::where('code', 'yamato_nekopos')->firstOrFail();
        $order = SalesOrder::factory()->create(array_merge([
            'tenant_id' => $shop->tenant_id,
            'shop_id' => $shop->id,
            'source' => SalesOrder::SOURCE_MANUAL,
            'platform_order_id' => 'NOTICE-'.fake()->unique()->numberBetween(1000, 9999),
            'order_status' => SalesOrder::ORDER_STATUS_PENDING,
            'fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_READY,
            'shipping_method_id' => $method->id,
            'shipping_method' => null,
            'tracking_no' => 'TRACK-100',
        ], $attributes));

        $order->lines()->create([
            'sku_id' => $sku->id,
            'quantity' => 1,
            'platform_line_id' => 'ITEM-1',
            'line_status' => $lineStatus,
        ]);

        return $order->load('shippingMethod.marketplaceMappings');
    }

    private function mapping(
        ShippingMethod $method,
        string $platform,
        string $marketplace = 'JP',
        string $carrierCode = 'Yamato',
        string $carrierName = 'Yamato',
        string $serviceName = 'Nekopos',
    ): ShippingMethodMarketplaceMapping {
        return ShippingMethodMarketplaceMapping::updateOrCreate(
            [
                'shipping_method_id' => $method->id,
                'platform' => $platform,
                'marketplace' => $marketplace,
            ],
            [
                'carrier_code' => $carrierCode,
                'carrier_name' => $carrierName,
                'service_name' => $serviceName,
            ],
        );
    }

    private function internalUser(): User
    {
        return User::factory()->create([
            'user_type' => 'internal',
            'is_active' => true,
        ]);
    }

    private function tenantUser(Tenant $tenant): User
    {
        $user = User::factory()->create([
            'user_type' => 'tenant',
            'is_active' => true,
        ]);

        TenantUser::factory()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'status' => 'active',
        ]);

        return $user;
    }
}
