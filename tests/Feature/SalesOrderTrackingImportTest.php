<?php

namespace Tests\Feature;

use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\Shop;
use App\Models\Sku;
use App\Models\StockItem;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Testing\File;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class SalesOrderTrackingImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_yamato_tracking_import_updates_by_exact_and_suffix_platform_order_id(): void
    {
        [, $shop, $sku] = $this->salesSku();
        $exact = $this->createOrder($shop, $sku, ['platform_order_id' => 'A000-20260619-1000']);
        $suffix = $this->createOrder($shop, $sku, ['platform_order_id' => 'B000-0613-0659033902']);
        $csv = "A000-20260619-1000,,,123456789012\n"
            ."0613-0659033902,,,987654321098\n";

        $this->importTracking($this->internalUser(), 'yamato.csv', $csv)
            ->assertRedirect(route('sales.orders.index'))
            ->assertSessionHas('status', __('sales_orders.tracking_import_succeeded'));

        $this->assertSame('123456789012', $exact->refresh()->tracking_no);
        $this->assertSame('987654321098', $suffix->refresh()->tracking_no);
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'sales_order',
            'event' => 'tracking_imported',
            'subject_id' => $exact->id,
            'subject_type' => SalesOrder::class,
        ]);
    }

    public function test_sagawa_tracking_import_updates_order(): void
    {
        [, $shop, $sku] = $this->salesSku();
        $order = $this->createOrder($shop, $sku, ['platform_order_id' => 'SG-ORDER-1']);
        $csv = "456789012345,SG-ORDER-1\n";

        $this->importTracking($this->internalUser(), 'sagawa.csv', $csv)
            ->assertRedirect(route('sales.orders.index'))
            ->assertSessionHas('status', __('sales_orders.tracking_import_succeeded'));

        $this->assertSame('456789012345', $order->refresh()->tracking_no);
    }

    public function test_sagawa_tracking_import_detects_japanese_header_file(): void
    {
        [, $shop, $sku] = $this->salesSku();
        $order = $this->createOrder($shop, $sku, ['platform_order_id' => '0613-0659033902']);
        $csv = "\"お問い合せ送り状No.\",\"お客様管理番号\",\"お届け先名称１\",\"お届け先電話番号\"\n"
            ."\"440069713300\",\"0613-0659033902\",\"喜納　幸人\",\"090-2398-8761\"\n";

        $this->importTracking($this->internalUser(), 'shukka_rireki.csv', $csv)
            ->assertRedirect(route('sales.orders.index'))
            ->assertSessionHas('status', __('sales_orders.tracking_import_succeeded'));

        $this->assertSame('440069713300', $order->refresh()->tracking_no);
    }

    public function test_tracking_import_respects_tenant_scope(): void
    {
        [$tenant, $user] = $this->tenantUser();
        $shop = Shop::factory()->for($tenant)->create(['status' => 'active']);
        $sku = $this->skuFor($tenant, $shop);
        $ownOrder = $this->createOrder($shop, $sku, ['platform_order_id' => 'OWN-ORDER']);

        [$otherTenant, $otherShop, $otherSku] = $this->salesSku();
        $otherOrder = $this->createOrder($otherShop, $otherSku, ['platform_order_id' => 'OTHER-ORDER']);
        $csv = "OWN-ORDER,,,111111111111\n"
            ."OTHER-ORDER,,,222222222222\n";

        $this->importTracking($user, 'tenant-yamato.csv', $csv)
            ->assertRedirect(route('sales.orders.index'))
            ->assertSessionHas('status', __('sales_orders.tracking_import_succeeded'));

        $this->assertSame('111111111111', $ownOrder->refresh()->tracking_no);
        $this->assertNull($otherOrder->refresh()->tracking_no);
        $this->assertNotSame($tenant->id, $otherTenant->id);
    }

    public function test_tracking_import_does_not_match_internal_sales_order_id(): void
    {
        [, $shop, $sku] = $this->salesSku();
        $order = $this->createOrder($shop, $sku, ['platform_order_id' => 'PLATFORM-ONLY']);
        $csv = "NO-MATCH,,,999999999999\n"
            .$order->id.",,,123123123123\n";

        $this->importTracking($this->internalUser(), 'internal-id.csv', $csv)
            ->assertRedirect(route('sales.orders.index'))
            ->assertSessionHas('status', __('sales_orders.tracking_import_succeeded'));

        $this->assertNull($order->refresh()->tracking_no);
    }

    public function test_tracking_import_skips_duplicate_platform_order_id_matches(): void
    {
        [$tenant, $shopA, $skuA] = $this->salesSku();
        $shopB = Shop::factory()->for($tenant)->create(['status' => 'active']);
        $skuB = $this->skuFor($tenant, $shopB);
        $first = $this->createOrder($shopA, $skuA, ['platform_order_id' => 'DUP-ORDER']);
        $second = $this->createOrder($shopB, $skuB, ['platform_order_id' => 'DUP-ORDER']);
        $csv = "DUP-ORDER,,,123456789012\n";

        $this->importTracking($this->internalUser(), 'ambiguous.csv', $csv)
            ->assertRedirect(route('sales.orders.index'))
            ->assertSessionHas('status', __('sales_orders.tracking_import_succeeded'));

        $this->assertNull($first->refresh()->tracking_no);
        $this->assertNull($second->refresh()->tracking_no);
    }

    public function test_tracking_import_does_not_suffix_match_short_order_values(): void
    {
        [, $shop, $sku] = $this->salesSku();
        $first = $this->createOrder($shop, $sku, ['platform_order_id' => 'ORDER-100']);
        $second = $this->createOrder($shop, $sku, ['platform_order_id' => 'ORDER-200']);
        $csv = "440069713300,00-TEST\n";

        $this->importTracking($this->internalUser(), 'short-sagawa.csv', $csv)
            ->assertRedirect(route('sales.orders.index'))
            ->assertSessionHas('status', __('sales_orders.tracking_import_succeeded'));

        $this->assertNull($first->refresh()->tracking_no);
        $this->assertNull($second->refresh()->tracking_no);
    }

    public function test_tracking_import_allows_already_same_rows_without_rewriting(): void
    {
        [, $shop, $sku] = $this->salesSku();
        $order = $this->createOrder($shop, $sku, [
            'platform_order_id' => 'SAME-ORDER',
            'tracking_no' => '123456789012',
        ]);
        $csv = "SAME-ORDER,,,123456789012\n";

        $this->importTracking($this->internalUser(), 'same.csv', $csv)
            ->assertRedirect(route('sales.orders.index'))
            ->assertSessionHas('status', __('sales_orders.tracking_import_succeeded'));

        $this->assertSame('123456789012', $order->refresh()->tracking_no);
    }

    public function test_tracking_import_accepts_unmatched_and_missing_value_rows_without_error(): void
    {
        $this->importTracking($this->internalUser(), 'bad.csv', "foo,bar\nnot,a,courier\n")
            ->assertRedirect(route('sales.orders.index'))
            ->assertSessionHas('status', __('sales_orders.tracking_import_succeeded'));

        $this->importTracking($this->internalUser(), 'missing.csv', "NO-MATCH,,,999999999999\n,,,123456789012\nORDER-1,,,\n")
            ->assertRedirect(route('sales.orders.index'))
            ->assertSessionHas('status', __('sales_orders.tracking_import_succeeded'));
    }

    public function test_tracking_import_reads_sjis_yamato_file(): void
    {
        [, $shop, $sku] = $this->salesSku();
        $order = $this->createOrder($shop, $sku, ['platform_order_id' => 'SJIS-ORDER']);
        $csv = "SJIS-ORDER,,,123456789012\n";
        $sjis = mb_convert_encoding($csv, 'SJIS-win', 'UTF-8');

        $this->importTracking($this->internalUser(), 'sjis.csv', $sjis)
            ->assertRedirect(route('sales.orders.index'))
            ->assertSessionHas('status', __('sales_orders.tracking_import_succeeded'));

        $this->assertSame('123456789012', $order->refresh()->tracking_no);
    }

    private function upload(string $name, string $contents): File
    {
        return File::createWithContent($name, $contents);
    }

    private function importTracking(User $user, string $name, string $contents): TestResponse
    {
        return $this->actingAs($user)->post(route('sales.orders.tracking-import'), [
            'tracking_file' => $this->upload($name, $contents),
        ]);
    }

    /**
     * @return array{0: Tenant, 1: Shop, 2: Sku}
     */
    private function salesSku(): array
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        $shop = Shop::factory()->for($tenant)->create(['status' => 'active']);
        $sku = $this->skuFor($tenant, $shop);

        return [$tenant, $shop, $sku];
    }

    private function skuFor(Tenant $tenant, Shop $shop): Sku
    {
        $stockItem = StockItem::factory()->for($tenant)->create();

        return Sku::factory()->for($tenant)->for($shop)->for($stockItem)->create([
            'sku_type' => 'single',
            'sku' => 'TRACK-SKU-'.fake()->unique()->numberBetween(1000, 9999),
        ]);
    }

    private function createOrder(Shop $shop, Sku $sku, array $attributes = []): SalesOrder
    {
        $order = SalesOrder::factory()->create(array_merge([
            'tenant_id' => $shop->tenant_id,
            'shop_id' => $shop->id,
            'source' => SalesOrder::SOURCE_MANUAL,
            'order_status' => SalesOrder::ORDER_STATUS_PENDING,
            'fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_UNFULFILLED,
            'platform_order_id' => 'TRACK-ORDER-'.fake()->unique()->numberBetween(1000, 9999),
            'recipient_name' => 'Taro',
            'recipient_country_code' => 'JP',
            'recipient_postal_code' => '542-0076',
            'recipient_state' => 'Osaka',
            'recipient_city' => 'Osaka',
            'recipient_address_line1' => '1-1 Namba',
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
