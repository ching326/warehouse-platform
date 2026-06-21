<?php

namespace Tests\Feature;

use App\Livewire\SalesOrderIndex;
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
use Livewire\Livewire;
use Tests\TestCase;

class SalesOrderTrackingImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_yamato_tracking_import_updates_by_exact_and_suffix_platform_order_id(): void
    {
        [$tenant, $shop, $sku] = $this->salesSku();
        $exact = $this->createOrder($shop, $sku, ['platform_order_id' => 'A000-20260619-1000']);
        $suffix = $this->createOrder($shop, $sku, ['platform_order_id' => 'B000-20260619-1001']);
        $csv = "注文番号,Name,Postal,伝票番号\n"
            ."A000-20260619-1000,,,123456789012\n"
            ."20260619-1001,,,987654321098\n";

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderIndex::class)
            ->set('trackingImportFile', $this->upload('yamato.csv', $csv))
            ->call('previewTrackingImport')
            ->assertSet('trackingImportCourier', 'yamato')
            ->assertSet('trackingImportSummary.will_update', 2)
            ->call('confirmTrackingImport');

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
        $csv = "お問い合わせ送り状No,お客様管理番号\n"
            ."456789012345,SG-ORDER-1\n";

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderIndex::class)
            ->set('trackingImportFile', $this->upload('sagawa.csv', $csv))
            ->call('previewTrackingImport')
            ->assertSet('trackingImportCourier', 'sagawa')
            ->assertSet('trackingImportSummary.will_update', 1)
            ->call('confirmTrackingImport');

        $this->assertSame('456789012345', $order->refresh()->tracking_no);
    }

    public function test_tracking_import_respects_tenant_scope(): void
    {
        [$tenant, $user] = $this->tenantUser();
        $shop = Shop::factory()->for($tenant)->create(['status' => 'active']);
        $sku = $this->skuFor($tenant, $shop);
        $ownOrder = $this->createOrder($shop, $sku, ['platform_order_id' => 'OWN-ORDER']);

        [$otherTenant, $otherShop, $otherSku] = $this->salesSku();
        $otherOrder = $this->createOrder($otherShop, $otherSku, ['platform_order_id' => 'OTHER-ORDER']);
        $csv = "注文番号,Name,Postal,伝票番号\n"
            ."OWN-ORDER,,,111111111111\n"
            ."OTHER-ORDER,,,222222222222\n";

        Livewire::actingAs($user)
            ->test(SalesOrderIndex::class)
            ->set('trackingImportFile', $this->upload('tenant-yamato.csv', $csv))
            ->call('previewTrackingImport')
            ->assertSet('trackingImportSummary.will_update', 1)
            ->assertSet('trackingImportSummary.unmatched', 1)
            ->call('confirmTrackingImport');

        $this->assertSame('111111111111', $ownOrder->refresh()->tracking_no);
        $this->assertNull($otherOrder->refresh()->tracking_no);
        $this->assertNotSame($tenant->id, $otherTenant->id);
    }

    public function test_tracking_import_does_not_match_internal_sales_order_id(): void
    {
        [, $shop, $sku] = $this->salesSku();
        $order = $this->createOrder($shop, $sku, ['platform_order_id' => 'PLATFORM-ONLY']);
        $csv = "注文番号,Name,Postal,伝票番号\n"
            .$order->id.",,,123123123123\n";

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderIndex::class)
            ->set('trackingImportFile', $this->upload('internal-id.csv', $csv))
            ->call('previewTrackingImport')
            ->assertSet('trackingImportSummary.will_update', 0)
            ->assertSet('trackingImportSummary.unmatched', 1)
            ->call('confirmTrackingImport');

        $this->assertNull($order->refresh()->tracking_no);
    }

    public function test_tracking_import_marks_duplicate_platform_order_id_as_ambiguous(): void
    {
        [$tenant, $shopA, $skuA] = $this->salesSku();
        $shopB = Shop::factory()->for($tenant)->create(['status' => 'active']);
        $skuB = $this->skuFor($tenant, $shopB);
        $first = $this->createOrder($shopA, $skuA, ['platform_order_id' => 'DUP-ORDER']);
        $second = $this->createOrder($shopB, $skuB, ['platform_order_id' => 'DUP-ORDER']);
        $csv = "注文番号,Name,Postal,伝票番号\nDUP-ORDER,,,123456789012\n";

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderIndex::class)
            ->set('trackingImportFile', $this->upload('ambiguous.csv', $csv))
            ->call('previewTrackingImport')
            ->assertSet('trackingImportSummary.ambiguous', 1)
            ->call('confirmTrackingImport');

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
        $csv = "注文番号,Name,Postal,伝票番号\nSAME-ORDER,,,123456789012\n";

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderIndex::class)
            ->set('trackingImportFile', $this->upload('same.csv', $csv))
            ->call('previewTrackingImport')
            ->assertSet('trackingImportSummary.already_same', 1)
            ->call('confirmTrackingImport');

        $this->assertSame('123456789012', $order->refresh()->tracking_no);
    }

    public function test_tracking_import_handles_missing_values_and_unknown_courier(): void
    {
        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderIndex::class)
            ->set('trackingImportFile', $this->upload('bad.csv', "foo,bar\nnot,a,courier\n"))
            ->call('previewTrackingImport')
            ->assertSet('trackingImportCourier', 'unknown')
            ->assertSet('trackingImportError', __('sales_orders.tracking_import_unknown_courier'));

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderIndex::class)
            ->set('trackingImportFile', $this->upload('missing.csv', "注文番号,Name,Postal,伝票番号\n,,,123456789012\nORDER-1,,,\n"))
            ->call('previewTrackingImport')
            ->assertSet('trackingImportSummary.skipped', 2);
    }

    public function test_tracking_import_reads_sjis_yamato_file(): void
    {
        [, $shop, $sku] = $this->salesSku();
        $order = $this->createOrder($shop, $sku, ['platform_order_id' => 'SJIS-ORDER']);
        $csv = "注文番号,Name,Postal,伝票番号\nSJIS-ORDER,,,123456789012\n";
        $sjis = mb_convert_encoding($csv, 'SJIS-win', 'UTF-8');

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderIndex::class)
            ->set('trackingImportFile', $this->upload('sjis.csv', $sjis))
            ->call('previewTrackingImport')
            ->assertSet('trackingImportCourier', 'yamato')
            ->call('confirmTrackingImport');

        $this->assertSame('123456789012', $order->refresh()->tracking_no);
    }

    private function upload(string $name, string $contents): File
    {
        return File::createWithContent($name, $contents);
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
