<?php

namespace Tests\Feature;

use App\Livewire\FulfillmentPackScanIndex;
use App\Models\FulfillmentGroup;
use App\Models\FulfillmentPackScan;
use App\Models\SalesOrder;
use App\Models\Shop;
use App\Models\Sku;
use App\Models\StockItem;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FulfillmentPackScanHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_internal_user_can_view_pack_scan_history(): void
    {
        [, , , , , $scan] = $this->packScanFixture(barcode: 'SCAN-HISTORY-001');

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentPackScanIndex::class)
            ->assertSee('SCAN-HISTORY-001')
            ->assertSee($scan->fulfillmentGroup->reference_no);
    }

    public function test_tenant_user_only_sees_own_tenant_scans(): void
    {
        [$tenant, $user] = $this->tenantUser();
        $this->packScanFixture($tenant, barcode: 'OWN-SCAN-001');
        $this->packScanFixture(Tenant::factory()->create(), barcode: 'OTHER-SCAN-001');

        Livewire::actingAs($user)
            ->test(FulfillmentPackScanIndex::class)
            ->assertSee('OWN-SCAN-001')
            ->assertDontSee('OTHER-SCAN-001');
    }

    public function test_guest_cannot_view_pack_scan_history(): void
    {
        Livewire::test(FulfillmentPackScanIndex::class)
            ->assertForbidden();
    }

    public function test_tenant_user_cannot_see_other_tenant_scan_via_group_filter(): void
    {
        [$tenant, $user] = $this->tenantUser();
        $this->packScanFixture($tenant, barcode: 'OWN-SCAN-002');
        [, $otherGroup] = $this->packScanFixture(Tenant::factory()->create(), barcode: 'OTHER-SCAN-002');

        Livewire::withQueryParams(['fulfillment_group_id' => $otherGroup->id])
            ->actingAs($user)
            ->test(FulfillmentPackScanIndex::class)
            ->assertDontSee('OTHER-SCAN-002')
            ->assertDontSee('OWN-SCAN-002')
            ->assertSee('No pack scans yet.');
    }

    public function test_fulfillment_group_filter_works(): void
    {
        [, $group] = $this->packScanFixture(barcode: 'GROUP-FILTER-IN');
        $this->packScanFixture(barcode: 'GROUP-FILTER-OUT');

        Livewire::withQueryParams(['fulfillment_group_id' => $group->id])
            ->actingAs($this->internalUser())
            ->test(FulfillmentPackScanIndex::class)
            ->assertSee('GROUP-FILTER-IN')
            ->assertDontSee('GROUP-FILTER-OUT');
    }

    public function test_result_filter_works_and_badges_render_states(): void
    {
        $this->packScanFixture(barcode: 'ACCEPTED-SCAN', result: FulfillmentPackScan::RESULT_ACCEPTED);
        $this->packScanFixture(barcode: 'WRONG-SCAN', result: FulfillmentPackScan::RESULT_WRONG_ITEM);

        Livewire::withQueryParams(['result' => FulfillmentPackScan::RESULT_WRONG_ITEM])
            ->actingAs($this->internalUser())
            ->test(FulfillmentPackScanIndex::class)
            ->assertSee('Wrong item')
            ->assertSee('WRONG-SCAN')
            ->assertDontSee('ACCEPTED-SCAN');

        Livewire::actingAs($this->internalUser())
            ->test(FulfillmentPackScanIndex::class)
            ->assertSee('Accepted')
            ->assertSee('Wrong item');
    }

    public function test_search_matches_barcode_group_reference_sku_and_stock_item(): void
    {
        [, $group, , $sku, $stockItem] = $this->packScanFixture(
            barcode: 'BARCODE-SEARCH-001',
            skuCode: 'SKU-SEARCH-001',
            stockCode: 'STOCK-SEARCH-001',
            stockName: 'Searchable Stock Item',
        );

        foreach (['BARCODE-SEARCH-001', $group->reference_no, 'SKU-SEARCH-001', 'STOCK-SEARCH-001', 'Searchable Stock Item'] as $term) {
            Livewire::withQueryParams(['q' => $term])
                ->actingAs($this->internalUser())
                ->test(FulfillmentPackScanIndex::class)
                ->assertSee('BARCODE-SEARCH-001')
                ->assertSee($sku->sku)
                ->assertSee($stockItem->code);
        }
    }

    public function test_date_range_filter_works(): void
    {
        $this->packScanFixture(barcode: 'DATE-IN', createdAt: '2026-06-15 10:00:00');
        $this->packScanFixture(barcode: 'DATE-OUT', createdAt: '2026-06-10 10:00:00');

        Livewire::withQueryParams(['date_from' => '2026-06-15', 'date_to' => '2026-06-15'])
            ->actingAs($this->internalUser())
            ->test(FulfillmentPackScanIndex::class)
            ->assertSee('DATE-IN')
            ->assertDontSee('DATE-OUT');
    }

    public function test_summary_cards_reflect_filtered_query_not_current_page(): void
    {
        for ($i = 1; $i <= 55; $i++) {
            $this->packScanFixture(barcode: 'PAGE-SUMMARY-'.$i, quantity: $i === 1 ? 6 : 1);
        }
        $this->packScanFixture(barcode: 'PAGE-SUMMARY-EXCEPTION', result: FulfillmentPackScan::RESULT_OVER_SCAN);

        Livewire::withQueryParams(['q' => 'PAGE-SUMMARY'])
            ->actingAs($this->internalUser())
            ->test(FulfillmentPackScanIndex::class)
            ->assertSee('Filtered scans')
            ->assertSee('56')
            ->assertSee('Accepted quantity')
            ->assertSee('60')
            ->assertSee('Exceptions')
            ->assertSee('1');
    }

    public function test_fulfillment_group_detail_shows_latest_scan_history(): void
    {
        [, $group] = $this->packScanFixture(barcode: 'GROUP-DETAIL-SCAN');

        $this->actingAs($this->internalUser())
            ->get(route('fulfillment-groups.show', $group))
            ->assertOk()
            ->assertSee('Scan History')
            ->assertSee('GROUP-DETAIL-SCAN')
            ->assertSee(route('fulfillment.pack-scans.index', ['fulfillment_group_id' => $group->id]), false);
    }

    public function test_pack_page_has_scan_history_link(): void
    {
        [, $group] = $this->packScanFixture(barcode: 'PACK-PAGE-HISTORY');

        $this->actingAs($this->internalUser())
            ->get(route('fulfillment-groups.pack', $group))
            ->assertOk()
            ->assertSee('Scan History')
            ->assertSee(route('fulfillment.pack-scans.index', ['fulfillment_group_id' => $group->id]), false);
    }

    private function packScanFixture(
        ?Tenant $tenant = null,
        string $barcode = 'PACK-SCAN-001',
        string $result = FulfillmentPackScan::RESULT_ACCEPTED,
        int $quantity = 1,
        string $skuCode = 'PACK-SCAN-SKU',
        string $stockCode = 'PACK-SCAN-STOCK',
        string $stockName = 'Pack Scan Stock',
        ?string $createdAt = null,
    ): array {
        $tenant ??= Tenant::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $shop = Shop::factory()->for($tenant)->create();
        $stockItem = StockItem::factory()->for($tenant)->create([
            'code' => $stockCode,
            'name' => $stockName,
        ]);
        $sku = Sku::factory()->for($tenant)->for($shop)->for($stockItem)->create([
            'sku' => $skuCode,
            'name' => $skuCode.' Name',
        ]);
        $order = SalesOrder::factory()->for($tenant)->for($shop)->create([
            'platform_order_id' => 'SO-'.$barcode,
        ]);
        $group = FulfillmentGroup::factory()
            ->for($tenant)
            ->for($warehouse)
            ->create();
        $user = $this->internalUser();
        $scan = FulfillmentPackScan::create([
            'tenant_id' => $tenant->id,
            'fulfillment_group_id' => $group->id,
            'sales_order_id' => $order->id,
            'sku_id' => $sku->id,
            'stock_item_id' => $stockItem->id,
            'barcode_scanned' => $barcode,
            'normalized_barcode' => $barcode,
            'result' => $result,
            'quantity' => $quantity,
            'message' => 'Scan message '.$barcode,
            'scanned_by_user_id' => $user->id,
        ]);

        if ($createdAt !== null) {
            $scan->forceFill(['created_at' => $createdAt])->save();
        }

        return [$tenant, $group, $order, $sku, $stockItem, $scan->refresh(), $user];
    }

    private function internalUser(): User
    {
        return User::factory()->create([
            'user_type' => 'internal',
            'is_active' => true,
        ]);
    }

    private function tenantUser(): array
    {
        $tenant = Tenant::factory()->create();
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
