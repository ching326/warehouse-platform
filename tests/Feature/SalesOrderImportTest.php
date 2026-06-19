<?php

namespace Tests\Feature;

use App\Livewire\SalesOrderImport;
use App\Models\SalesOrder;
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

    private function parseAndImport(Shop $shop, string $csv): \Livewire\Features\SupportTesting\Testable
    {
        $component = $this->parseOnly($shop, $csv);

        return $component->call('import');
    }

    private function parseOnly(Shop $shop, string $csv): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::actingAs($this->internalUser())
            ->test(SalesOrderImport::class)
            ->set('shopId', (string) $shop->id)
            ->set('file', File::createWithContent('orders.csv', $csv))
            ->call('parse');
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
