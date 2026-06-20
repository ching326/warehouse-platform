<?php

namespace Tests\Feature;

use App\Livewire\AmazonSpapiOrderImport;
use App\Models\AmazonSpapiConnection;
use App\Models\AmazonSpapiImportRun;
use App\Models\FulfillmentGroup;
use App\Models\FulfillmentGroupOrder;
use App\Models\SalesOrder;
use App\Models\ShippingMethod;
use App\Models\Shop;
use App\Models\Sku;
use App\Models\StockItem;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Support\AmazonSpapiRegion;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class AmazonSpapiOrderImportTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_internal_user_can_open_amazon_api_import_page(): void
    {
        $this->actingAs($this->internalUser())
            ->get(route('sales.orders.import.amazon-api'))
            ->assertOk()
            ->assertSee(__('amazon_spapi_import.page_title'));
    }

    public function test_tenant_user_gets_403(): void
    {
        $this->actingAs($this->tenantUser())
            ->get(route('sales.orders.import.amazon-api'))
            ->assertForbidden();
    }

    public function test_non_amazon_shop_cannot_be_used(): void
    {
        $shop = Shop::factory()->create(['platform' => 'shopify']);

        Livewire::actingAs($this->internalUser())
            ->test(AmazonSpapiOrderImport::class)
            ->set('shopId', (string) $shop->id)
            ->call('fetchPreview')
            ->assertHasErrors(['shopId']);
    }

    public function test_shop_without_connection_shows_error(): void
    {
        $shop = $this->amazonShop();

        Livewire::actingAs($this->internalUser())
            ->test(AmazonSpapiOrderImport::class)
            ->set('shopId', (string) $shop->id)
            ->call('fetchPreview')
            ->assertHasErrors(['shopId']);
    }

    public function test_not_connected_connection_cannot_fetch_preview(): void
    {
        $connection = $this->connection(['status' => AmazonSpapiConnection::STATUS_FAILED]);

        Livewire::actingAs($this->internalUser())
            ->test(AmazonSpapiOrderImport::class)
            ->set('shopId', (string) $connection->shop_id)
            ->call('fetchPreview')
            ->assertHasErrors(['shopId']);
    }

    public function test_sync_disabled_connection_can_fetch_manual_preview(): void
    {
        $connection = $this->connection(['sync_enabled' => false]);
        $sku = $this->sku($connection->shop, 'API-SKU-1');
        $this->fakeAmazon([
            $this->amazonOrder('AMZ-1'),
        ], [
            'AMZ-1' => [$this->amazonItem($sku->sku)],
        ]);

        Livewire::actingAs($this->internalUser())
            ->test(AmazonSpapiOrderImport::class)
            ->set('shopId', (string) $connection->shop_id)
            ->call('fetchPreview')
            ->assertHasNoErrors()
            ->assertSet('parsed', true);
    }

    public function test_fetch_preview_requests_rdt_for_actionable_orders(): void
    {
        $connection = $this->connection();
        $sku = $this->sku($connection->shop, 'API-SKU-RDT');
        $this->fakeAmazon([$this->amazonOrder('AMZ-RDT')], ['AMZ-RDT' => [$this->amazonItem($sku->sku)]]);

        Livewire::actingAs($this->internalUser())
            ->test(AmazonSpapiOrderImport::class)
            ->set('shopId', (string) $connection->shop_id)
            ->call('fetchPreview')
            ->assertHasNoErrors();

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/tokens/2021-03-01/restrictedDataToken'));
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/orderItems')
            && $request->header('x-amz-access-token')[0] === 'restricted-token');
    }

    public function test_rdt_failure_blocks_preview_and_imports_nothing(): void
    {
        $connection = $this->connection();
        $this->fakeAmazon([$this->amazonOrder('AMZ-RDT-FAIL')], [], rdtStatus: 403);

        Livewire::actingAs($this->internalUser())
            ->test(AmazonSpapiOrderImport::class)
            ->set('shopId', (string) $connection->shop_id)
            ->call('fetchPreview')
            ->assertHasErrors(['api']);

        $this->assertSame(0, SalesOrder::count());
    }

    public function test_pending_order_is_skipped_without_rdt_failure(): void
    {
        $connection = $this->connection();
        $this->fakeAmazon([$this->amazonOrder('AMZ-PENDING', ['OrderStatus' => 'Pending'])], []);

        Livewire::actingAs($this->internalUser())
            ->test(AmazonSpapiOrderImport::class)
            ->set('shopId', (string) $connection->shop_id)
            ->call('fetchPreview')
            ->assertHasNoErrors()
            ->assertSet('parsedRows.0.preview_status', 'not_actionable');

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/restrictedDataToken'));
    }

    public function test_missing_pii_blocks_preview(): void
    {
        $connection = $this->connection();
        $order = $this->amazonOrder('AMZ-NO-PII');
        unset($order['ShippingAddress']);
        $this->fakeAmazon([$order], ['AMZ-NO-PII' => [$this->amazonItem('ANY-SKU')]]);

        Livewire::actingAs($this->internalUser())
            ->test(AmazonSpapiOrderImport::class)
            ->set('shopId', (string) $connection->shop_id)
            ->call('fetchPreview')
            ->assertHasErrors(['api']);
    }

    public function test_preview_groups_multiple_items_for_same_order(): void
    {
        $connection = $this->connection();
        $skuA = $this->sku($connection->shop, 'MULTI-A');
        $skuB = $this->sku($connection->shop, 'MULTI-B');
        $this->fakeAmazon([$this->amazonOrder('AMZ-MULTI')], [
            'AMZ-MULTI' => [$this->amazonItem($skuA->sku), $this->amazonItem($skuB->sku)],
        ]);

        Livewire::actingAs($this->internalUser())
            ->test(AmazonSpapiOrderImport::class)
            ->set('shopId', (string) $connection->shop_id)
            ->call('fetchPreview')
            ->assertSet('parsedRows.0.platform_order_id', 'AMZ-MULTI')
            ->assertSet('parsedRows.1.platform_order_id', 'AMZ-MULTI');
    }

    public function test_existing_order_is_marked_duplicate_and_cancel_request_is_update(): void
    {
        $connection = $this->connection();
        $this->existingOrder($connection->shop, 'AMZ-DUP');
        $this->existingOrder($connection->shop, 'AMZ-CANCEL');
        $this->fakeAmazon([
            $this->amazonOrder('AMZ-DUP'),
            $this->amazonOrder('AMZ-CANCEL', ['BuyerRequestedCancel' => ['IsBuyerRequestedCancel' => true]]),
        ], [
            'AMZ-DUP' => [$this->amazonItem('SKU-X')],
            'AMZ-CANCEL' => [$this->amazonItem('SKU-X')],
        ]);

        Livewire::actingAs($this->internalUser())
            ->test(AmazonSpapiOrderImport::class)
            ->set('shopId', (string) $connection->shop_id)
            ->call('fetchPreview')
            ->assertSet('parsedRows.0.preview_status', 'duplicate')
            ->assertSet('parsedRows.1.preview_status', 'existing_cancel_requested');
    }

    public function test_missing_sku_blocks_confirm_and_sku_lookup_is_shop_scoped(): void
    {
        $connection = $this->connection();
        $otherShop = $this->amazonShop(['tenant_id' => $connection->tenant_id]);
        $this->sku($otherShop, 'SHOP-SCOPED-SKU');
        $this->fakeAmazon([$this->amazonOrder('AMZ-MISSING')], [
            'AMZ-MISSING' => [$this->amazonItem('SHOP-SCOPED-SKU')],
        ]);

        Livewire::actingAs($this->internalUser())
            ->test(AmazonSpapiOrderImport::class)
            ->set('shopId', (string) $connection->shop_id)
            ->call('fetchPreview')
            ->assertSet('parsedRows.0.preview_status', 'missing_sku')
            ->call('confirmImport');

        $this->assertSame(0, SalesOrder::count());
    }

    public function test_confirm_imports_preview_rows_and_does_not_refetch(): void
    {
        $connection = $this->connection();
        $sku = $this->sku($connection->shop, 'IMPORT-SKU');
        $this->fakeAmazon([$this->amazonOrder('AMZ-IMPORT')], [
            'AMZ-IMPORT' => [$this->amazonItem($sku->sku, ['QuantityOrdered' => 2, 'ItemPrice' => ['Amount' => '30.00', 'CurrencyCode' => 'JPY']])],
        ]);

        $component = Livewire::actingAs($this->internalUser())
            ->test(AmazonSpapiOrderImport::class)
            ->set('shopId', (string) $connection->shop_id)
            ->call('fetchPreview');

        Http::assertSentCount(4);

        $component->call('confirmImport')->assertRedirect(route('sales.orders.index'));

        Http::assertSentCount(4);
        $order = SalesOrder::where('platform_order_id', 'AMZ-IMPORT')->firstOrFail();
        $line = $order->lines()->firstOrFail();

        $this->assertSame(SalesOrder::SOURCE_API, $order->source);
        $this->assertSame('AMZ-IMPORT-SKU-ITEM', $line->platform_line_id);
        $this->assertSame('Amazon Item IMPORT-SKU', $line->platform_product_name);
        $this->assertSame('15.00', $line->unit_price);
        $this->assertSame('JPY', $line->currency);
        $this->assertSame(1, AmazonSpapiImportRun::count());
        $this->assertNotNull($connection->refresh()->last_orders_imported_at);
    }

    public function test_confirm_rechecks_duplicates_before_writing(): void
    {
        $connection = $this->connection();
        $sku = $this->sku($connection->shop, 'RACE-SKU');
        $this->fakeAmazon([$this->amazonOrder('AMZ-RACE')], ['AMZ-RACE' => [$this->amazonItem($sku->sku)]]);

        $component = Livewire::actingAs($this->internalUser())
            ->test(AmazonSpapiOrderImport::class)
            ->set('shopId', (string) $connection->shop_id)
            ->call('fetchPreview');

        $this->existingOrder($connection->shop, 'AMZ-RACE');

        $component->call('confirmImport');

        $this->assertSame(1, SalesOrder::where('platform_order_id', 'AMZ-RACE')->count());
    }

    public function test_cancel_requested_new_and_existing_orders_are_handled_safely(): void
    {
        $connection = $this->connection();
        $sku = $this->sku($connection->shop, 'CANCEL-SKU');
        $existing = $this->existingOrder($connection->shop, 'AMZ-EXISTING-CANCEL');
        $unsafe = $this->existingOrder($connection->shop, 'AMZ-UNSAFE-CANCEL', [
            'fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_IN_GROUP,
        ]);
        $this->fakeAmazon([
            $this->amazonOrder('AMZ-NEW-CANCEL', ['BuyerRequestedCancel' => ['IsBuyerRequestedCancel' => true]]),
            $this->amazonOrder('AMZ-EXISTING-CANCEL', ['BuyerRequestedCancel' => ['IsBuyerRequestedCancel' => true]]),
            $this->amazonOrder('AMZ-UNSAFE-CANCEL', ['BuyerRequestedCancel' => ['IsBuyerRequestedCancel' => true]]),
        ], [
            'AMZ-NEW-CANCEL' => [$this->amazonItem($sku->sku)],
            'AMZ-EXISTING-CANCEL' => [$this->amazonItem($sku->sku)],
            'AMZ-UNSAFE-CANCEL' => [$this->amazonItem($sku->sku)],
        ]);

        Livewire::actingAs($this->internalUser())
            ->test(AmazonSpapiOrderImport::class)
            ->set('shopId', (string) $connection->shop_id)
            ->call('fetchPreview')
            ->call('confirmImport');

        $this->assertSame(SalesOrder::ORDER_STATUS_CANCEL_REQUESTED, SalesOrder::where('platform_order_id', 'AMZ-NEW-CANCEL')->firstOrFail()->order_status);
        $this->assertSame(SalesOrder::ORDER_STATUS_CANCEL_REQUESTED, $existing->refresh()->order_status);
        $this->assertSame(SalesOrder::ORDER_STATUS_PENDING, $unsafe->refresh()->order_status);
    }

    public function test_fulfillment_group_existing_order_is_not_overwritten(): void
    {
        $connection = $this->connection();
        $order = $this->existingOrder($connection->shop, 'AMZ-GROUP-CANCEL');
        $group = FulfillmentGroup::factory()->for($connection->shop->tenant)->create();
        FulfillmentGroupOrder::create(['fulfillment_group_id' => $group->id, 'sales_order_id' => $order->id]);
        $this->fakeAmazon([
            $this->amazonOrder('AMZ-GROUP-CANCEL', ['BuyerRequestedCancel' => ['IsBuyerRequestedCancel' => true]]),
        ], ['AMZ-GROUP-CANCEL' => [$this->amazonItem('ANY-SKU')]]);

        Livewire::actingAs($this->internalUser())
            ->test(AmazonSpapiOrderImport::class)
            ->set('shopId', (string) $connection->shop_id)
            ->call('fetchPreview')
            ->call('confirmImport');

        $this->assertSame(SalesOrder::ORDER_STATUS_PENDING, $order->refresh()->order_status);
    }

    public function test_shipping_method_mapping_and_unknown_shipping_method(): void
    {
        $connection = $this->connection();
        $skuA = $this->sku($connection->shop, 'SHIP-A');
        $skuB = $this->sku($connection->shop, 'SHIP-B');
        $this->fakeAmazon([
            $this->amazonOrder('AMZ-YAMATO', ['ShipServiceLevel' => 'Yamato Nekopos']),
            $this->amazonOrder('AMZ-UNKNOWN', ['ShipServiceLevel' => 'Mystery']),
        ], [
            'AMZ-YAMATO' => [$this->amazonItem($skuA->sku)],
            'AMZ-UNKNOWN' => [$this->amazonItem($skuB->sku)],
        ]);

        Livewire::actingAs($this->internalUser())
            ->test(AmazonSpapiOrderImport::class)
            ->set('shopId', (string) $connection->shop_id)
            ->call('fetchPreview')
            ->call('confirmImport');

        $yamato = SalesOrder::where('platform_order_id', 'AMZ-YAMATO')->firstOrFail();
        $unknown = SalesOrder::where('platform_order_id', 'AMZ-UNKNOWN')->firstOrFail();

        $this->assertSame('yamato', $yamato->shipping_method);
        $this->assertSame(ShippingMethod::where('code', 'yamato_nekopos')->value('id'), $yamato->shipping_method_id);
        $this->assertNull($unknown->shipping_method);
        $this->assertNull($unknown->shipping_method_id);
    }

    public function test_window_rules_and_utc_request_format(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-20 12:00:00', 'UTC'));
        $connection = $this->connection();
        $sku = $this->sku($connection->shop, 'WINDOW-SKU');
        $this->fakeAmazon([$this->amazonOrder('AMZ-WINDOW')], ['AMZ-WINDOW' => [$this->amazonItem($sku->sku)]]);

        Livewire::actingAs($this->internalUser())
            ->test(AmazonSpapiOrderImport::class)
            ->set('shopId', (string) $connection->shop_id)
            ->set('windowFrom', '2026-06-10T00:00')
            ->set('windowTo', '2026-06-20T11:50')
            ->call('fetchPreview')
            ->assertHasErrors(['windowFrom']);

        Livewire::actingAs($this->internalUser())
            ->test(AmazonSpapiOrderImport::class)
            ->set('shopId', (string) $connection->shop_id)
            ->set('windowFrom', '2026-06-20T11:00')
            ->set('windowTo', '2026-06-20T11:59')
            ->call('fetchPreview')
            ->assertHasErrors(['windowTo']);

        Livewire::actingAs($this->internalUser())
            ->test(AmazonSpapiOrderImport::class)
            ->set('shopId', (string) $connection->shop_id)
            ->set('windowFrom', '2026-06-20T09:00')
            ->set('windowTo', '2026-06-20T10:00')
            ->call('fetchPreview')
            ->assertHasNoErrors();

        Http::assertSent(function (Request $request) {
            if (! str_contains($request->url(), '/orders/v0/orders?')) {
                return false;
            }

            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return ($query['LastUpdatedAfter'] ?? null) === '2026-06-20T09:00:00Z'
                && ($query['LastUpdatedBefore'] ?? null) === '2026-06-20T10:00:00Z';
        });
    }

    public function test_pagination_retry_and_manual_cap_warning(): void
    {
        $connection = $this->connection();
        Http::fake([
            'https://api.amazon.com/auth/o2/token' => Http::response(['access_token' => 'access-token', 'expires_in' => 3600, 'token_type' => 'bearer']),
            $connection->endpoint.'/orders/v0/orders*' => Http::sequence()
                ->pushStatus(429)
                ->push(['payload' => ['Orders' => [$this->amazonOrder('AMZ-PAGE-1', ['OrderStatus' => 'Pending'])], 'NextToken' => 'NEXT']])
                ->push(['payload' => ['Orders' => array_map(fn ($i) => $this->amazonOrder('AMZ-CAP-'.$i, ['OrderStatus' => 'Pending']), range(1, 501))]]),
        ]);

        Livewire::actingAs($this->internalUser())
            ->test(AmazonSpapiOrderImport::class)
            ->set('shopId', (string) $connection->shop_id)
            ->call('fetchPreview')
            ->assertSet('warning', __('amazon_spapi_import.manual_cap_hit'));
    }

    private function fakeAmazon(array $orders, array $itemsByOrder, int $rdtStatus = 200): void
    {
        $endpoint = 'https://sellingpartnerapi-fe.amazon.com';
        $fakes = [
            'https://api.amazon.com/auth/o2/token' => Http::response(['access_token' => 'access-token', 'expires_in' => 3600, 'token_type' => 'bearer']),
            $endpoint.'/tokens/2021-03-01/restrictedDataToken' => Http::response(
                $rdtStatus === 200 ? ['restrictedDataToken' => 'restricted-token'] : ['message' => __('amazon_spapi_import.pii_missing')],
                $rdtStatus
            ),
        ];

        foreach ($itemsByOrder as $orderId => $items) {
            $fakes[$endpoint.'/orders/v0/orders/'.$orderId.'/orderItems*'] = Http::response(['payload' => ['OrderItems' => $items]]);
        }

        $fakes[$endpoint.'/orders/v0/orders*'] = Http::response(['payload' => ['Orders' => $orders]]);

        Http::fake($fakes);
    }

    private function amazonOrder(string $id, array $overrides = []): array
    {
        return array_merge([
            'AmazonOrderId' => $id,
            'PurchaseDate' => '2026-06-20T00:00:00Z',
            'LatestShipDate' => '2026-06-22T00:00:00Z',
            'OrderStatus' => 'Unshipped',
            'ShipServiceLevel' => 'Yamato Nekopos',
            'ShippingAddress' => [
                'Name' => 'Aiko Tanaka',
                'Phone' => '+81-90-0000-0000',
                'CountryCode' => 'JP',
                'PostalCode' => '100-0001',
                'StateOrRegion' => 'Tokyo',
                'City' => 'Chiyoda',
                'AddressLine1' => '1 Main',
                'AddressLine2' => 'Apt 2',
            ],
        ], $overrides);
    }

    private function amazonItem(string $sku, array $overrides = []): array
    {
        return array_merge([
            'OrderItemId' => 'AMZ-'.str_replace('_', '-', $sku).'-ITEM',
            'SellerSKU' => $sku,
            'Title' => 'Amazon Item '.$sku,
            'QuantityOrdered' => 1,
            'ItemPrice' => ['Amount' => '10.00', 'CurrencyCode' => 'JPY'],
        ], $overrides);
    }

    private function connection(array $attributes = []): AmazonSpapiConnection
    {
        $shop = $attributes['shop'] ?? $this->amazonShop();
        unset($attributes['shop']);

        return AmazonSpapiConnection::create(array_merge([
            'tenant_id' => $shop->tenant_id,
            'shop_id' => $shop->id,
            'seller_id' => 'SELLER123',
            'marketplace_id' => 'A1VC38T7YXB528',
            'region' => AmazonSpapiRegion::FE,
            'endpoint' => AmazonSpapiRegion::endpoint(AmazonSpapiRegion::FE),
            'lwa_client_id' => 'client-id',
            'lwa_client_secret' => 'client-secret',
            'refresh_token' => 'refresh-token',
            'sync_enabled' => true,
            'status' => AmazonSpapiConnection::STATUS_CONNECTED,
        ], $attributes));
    }

    private function amazonShop(array $attributes = []): Shop
    {
        return Shop::factory()->create(array_merge([
            'platform' => 'amazon',
            'marketplace' => 'JP',
        ], $attributes));
    }

    private function sku(Shop $shop, string $sku): Sku
    {
        return Sku::factory()
            ->for($shop->tenant)
            ->for($shop)
            ->for(StockItem::factory()->for($shop->tenant)->create())
            ->create([
                'sku' => $sku,
                'sku_type' => 'single',
                'status' => 'active',
            ]);
    }

    private function existingOrder(Shop $shop, string $platformOrderId, array $attributes = []): SalesOrder
    {
        return SalesOrder::factory()->for($shop->tenant)->for($shop)->create(array_merge([
            'source' => SalesOrder::SOURCE_MANUAL,
            'platform_order_id' => $platformOrderId,
            'order_status' => SalesOrder::ORDER_STATUS_PENDING,
            'fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_UNFULFILLED,
        ], $attributes));
    }

    private function internalUser(): User
    {
        return User::factory()->create(['user_type' => 'internal', 'is_active' => true]);
    }

    private function tenantUser(): User
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['user_type' => 'tenant', 'is_active' => true]);

        TenantUser::factory()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'status' => 'active',
        ]);

        return $user;
    }
}
