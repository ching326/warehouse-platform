<?php

namespace Tests\Feature;

use App\Livewire\BillingRunIndex;
use App\Models\FeeRate;
use App\Models\InboundOrder;
use App\Models\InboundOrderLine;
use App\Models\InboundReceipt;
use App\Models\InventoryMovement;
use App\Models\Invoice;
use App\Models\OutboundOrder;
use App\Models\OutboundOrderLine;
use App\Models\ReturnOrder;
use App\Models\ReturnOrderCost;
use App\Models\ReturnOrderLine;
use App\Models\StockItem;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Billing\BillingRunException;
use App\Services\Billing\BillingRunService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BillingRunTest extends TestCase
{
    use RefreshDatabase;

    public function test_storage_bills_m3_months_from_opening_on_hand_and_records_missing_dimensions(): void
    {
        [$tenant, $warehouse] = $this->tenantWarehouse('UTC');
        $good = StockItem::factory()->for($tenant)->create([
            'length_value' => 100,
            'width_value' => 100,
            'height_value' => 100,
            'dimension_unit' => 'cm',
        ]);
        $mm = StockItem::factory()->for($tenant)->create([
            'length_value' => 1000,
            'width_value' => 1000,
            'height_value' => 1000,
            'dimension_unit' => 'mm',
        ]);
        $missing = StockItem::factory()->for($tenant)->create([
            'length_value' => null,
            'width_value' => null,
            'height_value' => null,
            'dimension_unit' => 'cm',
        ]);

        $this->rate($tenant, FeeRate::TYPE_STORAGE, FeeRate::UNIT_PER_M3_MONTH, 100);
        $this->movement($tenant, $warehouse, $good, 10, 10, '2026-01-31 10:00:00');
        $this->movement($tenant, $warehouse, $mm, 2, 2, '2026-01-31 10:00:00');
        $this->movement($tenant, $warehouse, $missing, 3, 3, '2026-01-31 10:00:00');

        $invoice = app(BillingRunService::class)->generate($tenant, '2026-02');
        $line = $invoice->lines->where('fee_type', FeeRate::TYPE_STORAGE)->firstOrFail();

        $this->assertSame('12.0000', $line->quantity);
        $this->assertSame('1200.00', $line->amount);
        $this->assertSame(56, $line->sources()->count());
        $this->assertSame('missing_dimensions', $invoice->warnings[0]['code']);
        $this->assertContains($missing->id, $invoice->warnings[0]['ids']);
    }

    public function test_inbound_handling_bills_receipts_not_cumulative_line_received_qty(): void
    {
        [$tenant, $warehouse] = $this->tenantWarehouse();
        $stockItem = StockItem::factory()->for($tenant)->create();
        $this->rate($tenant, FeeRate::TYPE_HANDLING_INBOUND, FeeRate::UNIT_PER_UNIT, 10);
        $inbound = InboundOrder::factory()->for($tenant)->for($warehouse)->create();
        $line = InboundOrderLine::factory()->for($inbound, 'inboundOrder')->for($stockItem)->create([
            'tenant_id' => $tenant->id,
            'received_qty' => 999,
        ]);
        $receipt = InboundReceipt::factory()->for($inbound, 'inboundOrder')->for($line, 'line')->for($tenant)->for($warehouse)->for($stockItem)->create([
            'received_qty' => 5,
            'received_at' => '2026-02-10 12:00:00',
        ]);

        $invoice = app(BillingRunService::class)->generate($tenant, '2026-02');
        $invoiceLine = $invoice->lines->where('fee_type', FeeRate::TYPE_HANDLING_INBOUND)->firstOrFail();

        $this->assertSame('5.0000', $invoiceLine->quantity);
        $this->assertSame('50.00', $invoiceLine->amount);
        $this->assertDatabaseHas('invoice_line_sources', [
            'invoice_line_id' => $invoiceLine->id,
            'source_type' => 'inbound_receipt',
            'source_id' => $receipt->id,
        ]);
    }

    public function test_outbound_order_and_unit_billing_counts_consolidated_shipments_and_leaf_lines(): void
    {
        [$tenant, $warehouse] = $this->tenantWarehouse();
        $this->rate($tenant, FeeRate::TYPE_HANDLING_OUTBOUND_ORDER, FeeRate::UNIT_PER_ORDER, 50);
        $this->rate($tenant, FeeRate::TYPE_HANDLING_OUTBOUND_UNIT, FeeRate::UNIT_PER_UNIT, 3);
        $outbound = $this->shippedOutbound($tenant, $warehouse, '2026-02-12 10:00:00', ['reason' => OutboundOrder::REASON_CUSTOMER_ORDER]);
        OutboundOrderLine::factory()->for($outbound, 'order')->for($tenant)->create(['stock_item_id' => null, 'qty' => 1]);
        OutboundOrderLine::factory()->for($outbound, 'order')->for($tenant)->create(['stock_item_id' => StockItem::factory()->for($tenant), 'qty' => 2]);
        OutboundOrderLine::factory()->for($outbound, 'order')->for($tenant)->create(['stock_item_id' => StockItem::factory()->for($tenant), 'qty' => 3]);
        $reship = $this->shippedOutbound($tenant, $warehouse, '2026-02-13 10:00:00', ['reason' => OutboundOrder::REASON_RE_SHIP]);
        OutboundOrderLine::factory()->for($reship, 'order')->for($tenant)->create(['stock_item_id' => StockItem::factory()->for($tenant), 'qty' => 4]);

        $invoice = app(BillingRunService::class)->generate($tenant, '2026-02');
        $orderLine = $invoice->lines->where('fee_type', FeeRate::TYPE_HANDLING_OUTBOUND_ORDER)->firstOrFail();
        $unitLine = $invoice->lines->where('fee_type', FeeRate::TYPE_HANDLING_OUTBOUND_UNIT)->firstOrFail();

        $this->assertSame('2.0000', $orderLine->quantity);
        $this->assertSame('100.00', $orderLine->amount);
        $this->assertSame('9.0000', $unitLine->quantity);
        $this->assertSame('27.00', $unitLine->amount);
    }

    public function test_qc_bills_return_order_lines_by_inspected_at(): void
    {
        [$tenant, $warehouse] = $this->tenantWarehouse();
        $this->rate($tenant, FeeRate::TYPE_QC, FeeRate::UNIT_PER_UNIT, 8);
        $return = $this->returnOrder($tenant, $warehouse);
        $line = ReturnOrderLine::query()->create([
            'return_order_id' => $return->id,
            'tenant_id' => $tenant->id,
            'received_qty' => 4,
            'inspected_at' => '2026-02-18 09:00:00',
        ]);

        $invoice = app(BillingRunService::class)->generate($tenant, '2026-02');
        $invoiceLine = $invoice->lines->where('fee_type', FeeRate::TYPE_QC)->firstOrFail();

        $this->assertSame('4.0000', $invoiceLine->quantity);
        $this->assertSame('32.00', $invoiceLine->amount);
        $this->assertDatabaseHas('invoice_line_sources', [
            'invoice_line_id' => $invoiceLine->id,
            'source_type' => 'return_order_line',
            'source_id' => $line->id,
        ]);
    }

    public function test_postage_and_return_shipping_use_markup_and_cost_incurred_month(): void
    {
        [$tenant, $warehouse] = $this->tenantWarehouse();
        $this->rate($tenant, FeeRate::TYPE_POSTAGE, FeeRate::UNIT_PERCENT, 0, 10);
        $this->rate($tenant, FeeRate::TYPE_RETURN_SHIPPING, FeeRate::UNIT_PERCENT, 0, 20);
        $outbound = $this->shippedOutbound($tenant, $warehouse, '2026-02-12 10:00:00', [
            'courier_cost' => 1000,
            'courier_cost_currency' => 'JPY',
        ]);
        $return = $this->returnOrder($tenant, $warehouse, ['received_at' => '2026-01-20 10:00:00']);
        $freight = ReturnOrderCost::query()->create([
            'return_order_id' => $return->id,
            'tenant_id' => $tenant->id,
            'cost_type' => ReturnOrderCost::COST_FREIGHT_COLLECT,
            'amount' => 500,
            'currency' => 'JPY',
            'cost_incurred_at' => '2026-02-03 10:00:00',
        ]);
        ReturnOrderCost::query()->create([
            'return_order_id' => $return->id,
            'tenant_id' => $tenant->id,
            'cost_type' => ReturnOrderCost::COST_RESEND_SHIPPING,
            'amount' => 999,
            'currency' => 'JPY',
            'cost_incurred_at' => '2026-02-03 10:00:00',
        ]);

        $invoice = app(BillingRunService::class)->generate($tenant, '2026-02');
        $postage = $invoice->lines->where('fee_type', FeeRate::TYPE_POSTAGE)->firstOrFail();
        $returnShipping = $invoice->lines->where('fee_type', FeeRate::TYPE_RETURN_SHIPPING)->firstOrFail();

        $this->assertSame('1000.00', $postage->cost_base);
        $this->assertSame('1100.00', $postage->amount);
        $this->assertSame('500.00', $returnShipping->cost_base);
        $this->assertSame('600.00', $returnShipping->amount);
        $this->assertDatabaseHas('invoice_line_sources', ['source_type' => 'outbound_order', 'source_id' => $outbound->id]);
        $this->assertDatabaseHas('invoice_line_sources', ['source_type' => 'return_order_cost', 'source_id' => $freight->id]);
    }

    public function test_mid_month_rate_change_splits_lines(): void
    {
        [$tenant, $warehouse] = $this->tenantWarehouse();
        $this->rate($tenant, FeeRate::TYPE_HANDLING_INBOUND, FeeRate::UNIT_PER_UNIT, 10, null, 'JPY', '2026-02-01', '2026-02-15');
        $this->rate($tenant, FeeRate::TYPE_HANDLING_INBOUND, FeeRate::UNIT_PER_UNIT, 20, null, 'JPY', '2026-02-16');
        $stockItem = StockItem::factory()->for($tenant)->create();
        $inbound = InboundOrder::factory()->for($tenant)->for($warehouse)->create();
        $line = InboundOrderLine::factory()->for($inbound, 'inboundOrder')->for($stockItem)->create(['tenant_id' => $tenant->id]);
        InboundReceipt::factory()->for($inbound, 'inboundOrder')->for($line, 'line')->for($tenant)->for($warehouse)->for($stockItem)->create(['received_qty' => 5, 'received_at' => '2026-02-10 10:00:00']);
        InboundReceipt::factory()->for($inbound, 'inboundOrder')->for($line, 'line')->for($tenant)->for($warehouse)->for($stockItem)->create(['received_qty' => 7, 'received_at' => '2026-02-20 10:00:00']);

        $invoice = app(BillingRunService::class)->generate($tenant, '2026-02');
        $lines = $invoice->lines->where('fee_type', FeeRate::TYPE_HANDLING_INBOUND)->values();

        $this->assertCount(2, $lines);
        $this->assertSame('5.0000', $lines[0]->quantity);
        $this->assertSame('50.00', $lines[0]->amount);
        $this->assertSame('7.0000', $lines[1]->quantity);
        $this->assertSame('140.00', $lines[1]->amount);
    }

    public function test_warehouse_timezone_places_events_in_local_period(): void
    {
        [$tenant, $warehouse] = $this->tenantWarehouse('Asia/Tokyo');
        $this->rate($tenant, FeeRate::TYPE_HANDLING_OUTBOUND_ORDER, FeeRate::UNIT_PER_ORDER, 100);
        $this->shippedOutbound($tenant, $warehouse, '2026-01-31 15:30:00');

        $february = app(BillingRunService::class)->generate($tenant, '2026-02');

        $this->assertSame(1, $february->lines->where('fee_type', FeeRate::TYPE_HANDLING_OUTBOUND_ORDER)->count());
    }

    public function test_no_rates_abort_without_creating_invoice(): void
    {
        [$tenant, $warehouse] = $this->tenantWarehouse();
        $otherTenant = Tenant::factory()->create();
        $this->rate($otherTenant, FeeRate::TYPE_POSTAGE, FeeRate::UNIT_PERCENT, 0, 10);

        try {
            app(BillingRunService::class)->generate($tenant, '2026-02');
            $this->fail('No-rate billing run should fail.');
        } catch (BillingRunException) {
            $this->assertDatabaseCount('invoices', 0);
        }
    }

    public function test_rate_currency_disagreement_aborts(): void
    {
        [$tenant] = $this->tenantWarehouse();

        $this->rate($tenant, FeeRate::TYPE_POSTAGE, FeeRate::UNIT_PERCENT, 0, 10, 'JPY');
        $this->rate($tenant, FeeRate::TYPE_HANDLING_INBOUND, FeeRate::UNIT_PER_UNIT, 5, null, 'USD');
        $this->expectException(BillingRunException::class);
        app(BillingRunService::class)->generate($tenant, '2026-02');
    }

    public function test_foreign_currency_source_without_matching_rate_warns_instead_of_aborting(): void
    {
        [$tenant, $warehouse] = $this->tenantWarehouse();
        // A billable JPY rate (so the run has rates + currency), but NO postage rate.
        $this->rate($tenant, FeeRate::TYPE_HANDLING_OUTBOUND_ORDER, FeeRate::UNIT_PER_ORDER, 100);
        // Shipped order carrying a USD courier cost that will not be billed (no postage rate).
        $this->shippedOutbound($tenant, $warehouse, '2026-02-12 10:00:00', [
            'courier_cost' => 5,
            'courier_cost_currency' => 'USD',
        ]);

        $invoice = app(BillingRunService::class)->generate($tenant, '2026-02');

        // Run did not abort: handling billed, postage not billed, recorded as a no_rate warning.
        $this->assertSame(Invoice::STATUS_DRAFT, $invoice->status);
        $this->assertTrue($invoice->lines->contains('fee_type', FeeRate::TYPE_HANDLING_OUTBOUND_ORDER));
        $this->assertFalse($invoice->lines->contains('fee_type', FeeRate::TYPE_POSTAGE));
        $this->assertTrue(collect($invoice->warnings)->contains(fn (array $w): bool => $w['code'] === 'no_rate'));
    }

    public function test_warnings_idempotent_rerun_total_and_finalized_invoice_freeze(): void
    {
        [$tenant, $warehouse] = $this->tenantWarehouse();
        $this->rate($tenant, FeeRate::TYPE_POSTAGE, FeeRate::UNIT_PERCENT, 0, 10);
        $order = $this->shippedOutbound($tenant, $warehouse, '2026-02-12 10:00:00', [
            'courier_cost' => 1000,
            'courier_cost_currency' => 'JPY',
        ]);
        $this->shippedOutbound($tenant, $warehouse, '2026-02-13 10:00:00', ['courier_cost' => null]);

        $invoice = app(BillingRunService::class)->generate($tenant, '2026-02');
        $this->assertSame('1100.00', $invoice->total);
        $this->assertSame('missing_courier_cost', $invoice->warnings[0]['code']);

        $order->update(['courier_cost' => 2000]);
        $rerun = app(BillingRunService::class)->generate($tenant, '2026-02');
        $this->assertSame('2200.00', $rerun->total);
        $this->assertSame(1, Invoice::query()->count());

        app(BillingRunService::class)->finalize($rerun);
        $order->update(['courier_cost' => 3000]);
        try {
            app(BillingRunService::class)->generate($tenant, '2026-02');
            $this->fail('Finalized invoice regeneration should fail.');
        } catch (BillingRunException) {
            $this->assertSame('2200.00', Invoice::query()->firstOrFail()->total);
        }
    }

    public function test_billing_page_is_internal_only(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['user_type' => 'tenant', 'is_active' => true]);
        TenantUser::factory()->create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'status' => 'active']);

        $this->actingAs($user)->get(route('setup.billing.index'))->assertForbidden();
        Livewire::actingAs($user)->test(BillingRunIndex::class)->assertForbidden();
    }

    private function tenantWarehouse(string $timezone = 'UTC'): array
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        $warehouse = Warehouse::factory()->create(['timezone' => $timezone, 'status' => 'active']);

        return [$tenant, $warehouse];
    }

    private function rate(
        Tenant $tenant,
        string $feeType,
        string $unit,
        float $rate = 0,
        ?float $markupPct = null,
        string $currency = 'JPY',
        string $from = '2026-01-01',
        ?string $to = null
    ): FeeRate {
        return FeeRate::query()->create([
            'tenant_id' => $tenant->id,
            'fee_type' => $feeType,
            'unit' => $unit,
            'rate' => $rate,
            'markup_pct' => $markupPct,
            'currency' => $currency,
            'effective_from' => $from,
            'effective_to' => $to,
        ]);
    }

    private function movement(Tenant $tenant, Warehouse $warehouse, StockItem $stockItem, int $delta, int $after, string $createdAt): void
    {
        InventoryMovement::query()->create([
            'tenant_id' => $tenant->id,
            'warehouse_id' => $warehouse->id,
            'stock_item_id' => $stockItem->id,
            'movement_type' => InventoryMovement::TYPE_ADJUST,
            'quantity_delta' => $delta,
            'balance_after' => $after,
            'on_hand_delta' => $delta,
            'available_delta' => $delta,
            'on_hand_after' => $after,
            'available_after' => $after,
            'created_at' => $createdAt,
        ]);
    }

    private function shippedOutbound(Tenant $tenant, Warehouse $warehouse, string $shippedAt, array $overrides = []): OutboundOrder
    {
        return OutboundOrder::factory()->for($tenant)->for($warehouse)->create(array_merge([
            'reason' => OutboundOrder::REASON_CUSTOMER_ORDER,
            'status' => OutboundOrder::STATUS_SHIPPED,
            'shipped_at' => $shippedAt,
            'courier_cost' => null,
            'courier_cost_currency' => null,
        ], $overrides));
    }

    private function returnOrder(Tenant $tenant, Warehouse $warehouse, array $overrides = []): ReturnOrder
    {
        return ReturnOrder::query()->create(array_merge([
            'tenant_id' => $tenant->id,
            'warehouse_id' => $warehouse->id,
            'return_no' => 'RTN-TEST-'.fake()->unique()->numerify('######'),
            'status' => ReturnOrder::STATUS_RECEIVED,
            'return_type' => ReturnOrder::TYPE_CUSTOMER_RETURN,
            'return_reason' => ReturnOrder::REASON_OTHER,
            'payment_type' => ReturnOrder::PAYMENT_UNKNOWN,
        ], $overrides));
    }
}
