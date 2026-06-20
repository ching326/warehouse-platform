<?php

namespace Tests\Feature;

use App\Livewire\SalesOrderIndex;
use App\Models\CourierExportBatch;
use App\Models\CourierExportBatchOrder;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\Shop;
use App\Models\Sku;
use App\Models\StockItem;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\Courier\CourierExportService;
use App\Services\Courier\JapaneseAddressSplitter;
use App\Services\Courier\SagawaCsvBuilder;
use App\Support\CourierCarrier;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class CourierExportTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_yamato_export_generates_shift_jis_csv_and_marks_orders_exported(): void
    {
        Storage::fake('local');
        [$tenant, $shop, $sku] = $this->salesSku();
        $order = $this->order($shop, $sku, ['shipping_method' => CourierCarrier::YAMATO, 'platform_order_id' => 'YMT-1001']);

        $batch = app(CourierExportService::class)->export([$order->id], CourierCarrier::YAMATO, [$tenant->id], $this->internalUser());

        $this->assertDatabaseHas('courier_export_batches', ['id' => $batch->id, 'carrier' => CourierCarrier::YAMATO, 'order_count' => 1]);
        $this->assertNotNull($order->fresh()->courier_csv_exported_at);
        Storage::disk('local')->assertExists($batch->path);

        $csv = $this->decodedCsv($batch);
        $this->assertStringContainsString('YMT-1001', $csv);
        $this->assertStringContainsString('09012345678', $csv);
        $this->assertStringContainsString('150-0001', $csv);
        $this->assertStringContainsString('山田太郎', $csv);
    }

    public function test_sagawa_export_generates_shift_jis_csv_and_marks_orders_exported(): void
    {
        Storage::fake('local');
        [$tenant, $shop, $sku] = $this->salesSku();
        $order = $this->order($shop, $sku, ['shipping_method' => CourierCarrier::SAGAWA, 'platform_order_id' => 'SGW-1001']);

        $batch = app(CourierExportService::class)->export([$order->id], CourierCarrier::SAGAWA, [$tenant->id], $this->internalUser());

        $this->assertDatabaseHas('courier_export_batches', ['id' => $batch->id, 'carrier' => CourierCarrier::SAGAWA, 'order_count' => 1]);
        $this->assertNotNull($order->fresh()->courier_csv_exported_at);
        Storage::disk('local')->assertExists($batch->path);

        $csv = $this->decodedCsv($batch);
        $this->assertStringContainsString('SGW-1001', $csv);
        $this->assertStringContainsString('09012345678', $csv);
        $this->assertStringContainsString('150-0001', $csv);
        $this->assertStringContainsString('山田太郎', $csv);
    }

    public function test_yamato_export_blocks_sagawa_orders(): void
    {
        [$tenant, $shop, $sku] = $this->salesSku();
        $order = $this->order($shop, $sku, ['shipping_method' => CourierCarrier::SAGAWA]);

        $result = app(CourierExportService::class)->validateExport([$order->id], CourierCarrier::YAMATO, [$tenant->id]);

        $this->assertFalse($result->ok);
        $this->assertSame([$order->id], $result->wrongCarrierOrderIds);
        $this->assertDatabaseCount('courier_export_batches', 0);
        $this->assertNull($order->fresh()->courier_csv_exported_at);
    }

    public function test_sagawa_export_blocks_yamato_orders(): void
    {
        [$tenant, $shop, $sku] = $this->salesSku();
        $order = $this->order($shop, $sku, ['shipping_method' => CourierCarrier::YAMATO]);

        $result = app(CourierExportService::class)->validateExport([$order->id], CourierCarrier::SAGAWA, [$tenant->id]);

        $this->assertFalse($result->ok);
        $this->assertSame([$order->id], $result->wrongCarrierOrderIds);
        $this->assertDatabaseCount('courier_export_batches', 0);
        $this->assertNull($order->fresh()->courier_csv_exported_at);
    }

    public function test_export_requires_confirmation_for_already_exported_orders(): void
    {
        Storage::fake('local');
        [$tenant, $shop, $sku] = $this->salesSku();
        $order = $this->order($shop, $sku, [
            'shipping_method' => CourierCarrier::YAMATO,
            'courier_csv_exported_at' => now(),
        ]);

        $result = app(CourierExportService::class)->validateExport([$order->id], CourierCarrier::YAMATO, [$tenant->id]);

        $this->assertFalse($result->ok);
        $this->assertTrue($result->requiresConfirmation);
        $this->assertSame([$order->id], $result->alreadyExportedOrderIds);
        $this->assertDatabaseCount('courier_export_batches', 0);

        $this->expectException(RuntimeException::class);
        app(CourierExportService::class)->export([$order->id], CourierCarrier::YAMATO, [$tenant->id], $this->internalUser());
    }

    public function test_confirmed_re_export_creates_new_batch(): void
    {
        Storage::fake('local');
        [$tenant, $shop, $sku] = $this->salesSku();
        $order = $this->order($shop, $sku, [
            'shipping_method' => CourierCarrier::YAMATO,
            'courier_csv_exported_at' => now(),
        ]);

        $batch = app(CourierExportService::class)->export([$order->id], CourierCarrier::YAMATO, [$tenant->id], $this->internalUser(), true);

        $this->assertDatabaseHas('courier_export_batches', ['id' => $batch->id]);
        $this->assertDatabaseHas('courier_export_batch_orders', ['courier_export_batch_id' => $batch->id, 'sales_order_id' => $order->id]);
    }

    public function test_export_scopes_orders_by_tenant(): void
    {
        Storage::fake('local');
        [$ownTenant, $ownShop, $ownSku] = $this->salesSku();
        [$otherTenant, $otherShop, $otherSku] = $this->salesSku();
        $ownOrder = $this->order($ownShop, $ownSku, ['shipping_method' => CourierCarrier::YAMATO, 'platform_order_id' => 'OWN-EXPORT']);
        $otherOrder = $this->order($otherShop, $otherSku, ['shipping_method' => CourierCarrier::YAMATO, 'platform_order_id' => 'OTHER-EXPORT']);

        $user = $this->tenantUser($ownTenant);
        $result = app(CourierExportService::class)->validateExport([$ownOrder->id, $otherOrder->id], CourierCarrier::YAMATO, [$ownTenant->id]);

        $this->assertFalse($result->ok);
        $this->assertSame([$otherOrder->id], $result->missingOrderIds);

        $batch = app(CourierExportService::class)->export([$ownOrder->id], CourierCarrier::YAMATO, [$ownTenant->id], $user);
        $csv = $this->decodedCsv($batch);

        $this->assertStringContainsString('OWN-EXPORT', $csv);
        $this->assertStringNotContainsString('OTHER-EXPORT', $csv);
        $this->assertSame($otherTenant->id, $otherOrder->tenant_id);
    }

    public function test_download_scopes_batch_by_tenant(): void
    {
        Storage::fake('local');
        [$tenant, $shop, $sku] = $this->salesSku();
        [$otherTenant] = $this->salesSku();
        $order = $this->order($shop, $sku, ['shipping_method' => CourierCarrier::YAMATO]);
        $batch = app(CourierExportService::class)->export([$order->id], CourierCarrier::YAMATO, [$tenant->id], $this->internalUser());

        $this->actingAs($this->tenantUser($otherTenant))
            ->get(route('courier-export-batches.download', $batch))
            ->assertForbidden();
    }

    public function test_export_blocks_mixed_tenant_selection(): void
    {
        [$tenantA, $shopA, $skuA] = $this->salesSku();
        [$tenantB, $shopB, $skuB] = $this->salesSku();
        $orderA = $this->order($shopA, $skuA, ['shipping_method' => CourierCarrier::YAMATO]);
        $orderB = $this->order($shopB, $skuB, ['shipping_method' => CourierCarrier::YAMATO]);

        $result = app(CourierExportService::class)->validateExport([$orderA->id, $orderB->id], CourierCarrier::YAMATO, [$tenantA->id, $tenantB->id]);

        $this->assertFalse($result->ok);
        $this->assertEqualsCanonicalizing([$orderA->id, $orderB->id], $result->mixedTenantOrderIds);
        $this->assertDatabaseCount('courier_export_batches', 0);
    }

    public function test_export_blocks_orders_without_ready_lines(): void
    {
        [$tenant, $shop, $sku] = $this->salesSku();
        $order = $this->order($shop, $sku, ['shipping_method' => CourierCarrier::YAMATO], SalesOrderLine::STATUS_CANCELLED);

        $result = app(CourierExportService::class)->validateExport([$order->id], CourierCarrier::YAMATO, [$tenant->id]);

        $this->assertFalse($result->ok);
        $this->assertSame([$order->id], $result->noReadyLinesOrderIds);
    }

    public function test_export_blocks_orders_with_blocked_order_status(): void
    {
        [$tenant, $shop, $sku] = $this->salesSku();
        $orders = collect([
            SalesOrder::ORDER_STATUS_ON_HOLD,
            SalesOrder::ORDER_STATUS_CANCEL_REQUESTED,
            SalesOrder::ORDER_STATUS_CANCELLED,
        ])->map(fn (string $status) => $this->order($shop, $sku, [
            'shipping_method' => CourierCarrier::YAMATO,
            'order_status' => $status,
        ]));

        $result = app(CourierExportService::class)->validateExport($orders->pluck('id')->all(), CourierCarrier::YAMATO, [$tenant->id]);

        $this->assertFalse($result->ok);
        $this->assertFalse($result->requiresConfirmation);
        $this->assertEqualsCanonicalizing($orders->pluck('id')->all(), $result->blockedStatusOrderIds);
    }

    public function test_address_splitter_preserves_address_parts(): void
    {
        $split = app(JapaneseAddressSplitter::class)->split('東京都', '渋谷区', '神南1-2-3 とても長い建物名フロア', 'ABCビル 901');
        $joined = implode('', $split);

        $this->assertStringContainsString('東京都', $joined);
        $this->assertStringContainsString('渋谷区', $joined);
        $this->assertStringContainsString('神南1-2-3', $joined);
        $this->assertStringContainsString('ABCビル', $joined);
    }

    public function test_sales_order_index_shows_export_buttons_for_selected_orders(): void
    {
        [, $shop, $sku] = $this->salesSku();
        $order = $this->order($shop, $sku, ['shipping_method' => CourierCarrier::YAMATO]);

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderIndex::class)
            ->set('selectedIds', [$order->id])
            ->assertSee('Export Yamato CSV')
            ->assertSee('Export Sagawa CSV');
    }

    public function test_sales_order_index_courier_export_redirects_to_download(): void
    {
        Storage::fake('local');
        [, $shop, $sku] = $this->salesSku();
        $order = $this->order($shop, $sku, ['shipping_method' => CourierCarrier::YAMATO]);

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderIndex::class)
            ->set('selectedIds', [$order->id])
            ->call('validateCourierExport', CourierCarrier::YAMATO)
            ->assertRedirect(route('courier-export-batches.download', CourierExportBatch::firstOrFail()));

        $this->assertNotNull($order->fresh()->courier_csv_exported_at);
    }

    public function test_export_updates_activity_or_batch_history(): void
    {
        Storage::fake('local');
        [$tenant, $shop, $sku] = $this->salesSku();
        $order = $this->order($shop, $sku, ['shipping_method' => CourierCarrier::YAMATO, 'platform_order_id' => 'HISTORY-1']);

        $batch = app(CourierExportService::class)->export([$order->id], CourierCarrier::YAMATO, [$tenant->id], $this->internalUser());

        $this->assertDatabaseHas('courier_export_batch_orders', [
            'courier_export_batch_id' => $batch->id,
            'sales_order_id' => $order->id,
            'platform_order_id' => 'HISTORY-1',
            'carrier' => CourierCarrier::YAMATO,
        ]);
        $this->assertSame(1, CourierExportBatchOrder::count());
    }

    public function test_export_cleans_up_file_when_database_write_fails(): void
    {
        Storage::fake('local');
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-20 00:00:00', 'Asia/Tokyo'));
        [$tenant, $shop, $sku] = $this->salesSku();
        $order = $this->order($shop, $sku, ['shipping_method' => CourierCarrier::YAMATO]);
        $path = 'courier_exports/yamato/2026/06/yamato_20260620_0000.csv';

        CourierExportBatch::creating(function (): void {
            throw new RuntimeException('Simulated batch failure.');
        });

        try {
            app(CourierExportService::class)->export([$order->id], CourierCarrier::YAMATO, [$tenant->id], $this->internalUser());
            $this->fail('Expected courier export to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated batch failure.', $exception->getMessage());
        } finally {
            CourierExportBatch::flushEventListeners();
        }

        Storage::disk('local')->assertMissing($path);
        $this->assertSame([], Storage::disk('local')->allFiles('tmp/courier_exports'));
        $this->assertDatabaseCount('courier_export_batches', 0);
        $this->assertNull($order->fresh()->courier_csv_exported_at);
    }

    public function test_export_uses_japan_time_for_ship_date_and_filename(): void
    {
        Storage::fake('local');
        Carbon::setTestNow(Carbon::parse('2026-06-19 15:30:00', 'UTC'));
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-19 15:30:00', 'UTC'));
        [$tenant, $shop, $sku] = $this->salesSku();
        $yamatoOrder = $this->order($shop, $sku, ['shipping_method' => CourierCarrier::YAMATO, 'platform_order_id' => 'JST-YAMATO']);
        $sagawaOrder = $this->order($shop, $sku, ['shipping_method' => CourierCarrier::SAGAWA, 'platform_order_id' => 'JST-SAGAWA']);

        $yamato = app(CourierExportService::class)->export([$yamatoOrder->id], CourierCarrier::YAMATO, [$tenant->id], $this->internalUser());
        $sagawa = app(CourierExportService::class)->export([$sagawaOrder->id], CourierCarrier::SAGAWA, [$tenant->id], $this->internalUser());

        $this->assertSame('yamato_20260620_0030.csv', $yamato->file_name);
        $this->assertStringContainsString('2026/06/20', $this->decodedCsv($yamato));
        $this->assertSame('sagawa_20260620_0030.csv', $sagawa->file_name);
        $this->assertStringContainsString('20260620', $this->decodedCsv($sagawa));
    }

    public function test_csv_is_sjis_win_with_crlf_line_endings(): void
    {
        Storage::fake('local');
        [$tenant, $shop, $sku] = $this->salesSku();
        $order = $this->order($shop, $sku, ['shipping_method' => CourierCarrier::YAMATO]);

        $batch = app(CourierExportService::class)->export([$order->id], CourierCarrier::YAMATO, [$tenant->id], $this->internalUser());
        $content = Storage::disk('local')->get($batch->path);

        $this->assertStringContainsString("\r\n", $content);
        $this->assertFalse(mb_check_encoding($content, 'UTF-8'));
        $this->assertStringContainsString('山田太郎', mb_convert_encoding($content, 'UTF-8', 'SJIS-win'));
    }

    public function test_sagawa_header_shape_matches_reference(): void
    {
        $headers = SagawaCsvBuilder::HEADER;

        $this->assertCount(74, $headers);
        $this->assertSame('お届け先コード取得区分', $headers[0]);
        $this->assertSame('お届け先電話番号', $headers[2]);
        $this->assertSame('出荷日', $headers[60]);
        $this->assertSame('編集１０', $headers[73]);
    }

    private function decodedCsv(CourierExportBatch $batch): string
    {
        return mb_convert_encoding(Storage::disk($batch->disk)->get($batch->path), 'UTF-8', 'SJIS-win');
    }

    private function salesSku(): array
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        $shop = Shop::factory()->for($tenant)->create(['status' => 'active', 'name' => 'Demo Shop']);
        $stockItem = StockItem::factory()->for($tenant)->create(['name' => '商品サンプル', 'short_name' => '商品']);
        $sku = Sku::factory()->for($tenant)->for($shop)->for($stockItem)->create([
            'sku_type' => 'single',
            'sku' => 'COURIER-SKU-'.fake()->unique()->numberBetween(1000, 9999),
            'name' => '配送商品',
        ]);

        return [$tenant, $shop, $sku];
    }

    private function order(Shop $shop, Sku $sku, array $attributes = [], string $lineStatus = SalesOrderLine::STATUS_READY): SalesOrder
    {
        $order = SalesOrder::factory()->create(array_merge([
            'tenant_id' => $shop->tenant_id,
            'shop_id' => $shop->id,
            'source' => SalesOrder::SOURCE_MANUAL,
            'platform_order_id' => 'COURIER-'.fake()->unique()->numberBetween(1000, 9999),
            'order_status' => SalesOrder::ORDER_STATUS_PENDING,
            'fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_READY,
            'recipient_name' => '山田太郎',
            'recipient_phone' => '09012345678',
            'recipient_country_code' => 'JP',
            'recipient_postal_code' => '150-0001',
            'recipient_state' => '東京都',
            'recipient_city' => '渋谷区',
            'recipient_address_line1' => '神南1-2-3',
            'recipient_address_line2' => 'ABCビル 901',
        ], $attributes));

        $order->lines()->create([
            'sku_id' => $sku->id,
            'quantity' => 1,
            'line_status' => $lineStatus,
        ]);

        return $order;
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
