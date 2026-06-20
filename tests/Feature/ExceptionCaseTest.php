<?php

namespace Tests\Feature;

use App\Livewire\ExceptionCaseCreate;
use App\Livewire\ExceptionCaseIndex;
use App\Livewire\ExceptionCaseShow;
use App\Livewire\SalesOrderDetail;
use App\Models\ExceptionCase;
use App\Models\ExceptionCaseLine;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\Shop;
use App\Models\Sku;
use App\Models\StockItem;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ExceptionCaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_internal_user_can_open_exception_case_index(): void
    {
        $this->actingAs($this->internalUser())
            ->get(route('exception-cases.index'))
            ->assertOk()
            ->assertSee(__('exception_cases.page_title'));
    }

    public function test_tenant_user_only_sees_own_tenant_cases(): void
    {
        [$ownTenant, $user] = $this->tenantUser();
        [, $ownOrder] = $this->salesOrderWithLine($ownTenant, 'OWN-CASE-SKU', 'OWN-ORDER');
        [, $otherOrder] = $this->salesOrderWithLine(Tenant::factory()->create(), 'OTHER-CASE-SKU', 'OTHER-ORDER');
        $ownCase = $this->exceptionCaseForOrder($ownOrder, 'Own tenant note');
        $otherCase = $this->exceptionCaseForOrder($otherOrder, 'Other tenant note');

        Livewire::actingAs($user)
            ->test(ExceptionCaseIndex::class)
            ->assertSee($ownCase->case_no)
            ->assertDontSee($otherCase->case_no);
    }

    public function test_tenant_user_cannot_create_case_for_another_tenant_sales_order(): void
    {
        [, $user] = $this->tenantUser();
        [, $otherOrder] = $this->salesOrderWithLine(Tenant::factory()->create(), 'OTHER-SKU', 'OTHER-FORBIDDEN');

        $this->actingAs($user)
            ->get(route('sales.orders.exception-cases.create', $otherOrder))
            ->assertForbidden();
    }

    public function test_create_case_from_sales_order_preloads_sales_order_lines(): void
    {
        [$tenant, $order, $line] = $this->salesOrderWithLine(null, 'PRELOAD-SKU', 'PRELOAD-ORDER');

        Livewire::actingAs($this->internalUser())
            ->test(ExceptionCaseCreate::class, ['order' => $order])
            ->assertSet('tenantId', (string) $tenant->id)
            ->assertSet('salesOrderId', (string) $order->id)
            ->assertSee('PRELOAD-SKU')
            ->assertSee((string) $line->quantity);
    }

    public function test_create_case_requires_at_least_one_related_order_reference(): void
    {
        [$tenant, , , $sku] = $this->salesOrderWithLine(null, 'NO-REF-SKU', 'NO-REF-ORDER');

        Livewire::actingAs($this->internalUser())
            ->test(ExceptionCaseCreate::class)
            ->set('tenantId', (string) $tenant->id)
            ->set('manualLines.0.sku_id', (string) $sku->id)
            ->set('manualLines.0.qty', 1)
            ->call('save')
            ->assertHasErrors(['salesOrderId']);
    }

    public function test_create_case_requires_at_least_one_line(): void
    {
        [$tenant, $order] = $this->salesOrderWithLine(null, 'NO-LINE-SKU', 'NO-LINE-ORDER');

        Livewire::actingAs($this->internalUser())
            ->test(ExceptionCaseCreate::class, ['order' => $order])
            ->set('manualLines.0.sku_id', '')
            ->call('save')
            ->assertHasErrors(['lines']);
    }

    public function test_create_case_stores_case_lines_with_sku_stock_item_qty_condition_and_action(): void
    {
        [$tenant, $order, $line, $sku] = $this->salesOrderWithLine(null, 'STORE-LINE-SKU', 'STORE-LINE-ORDER', quantity: 3);

        Livewire::actingAs($this->internalUser())
            ->test(ExceptionCaseCreate::class, ['order' => $order])
            ->set('salesOrderLines.0.selected', true)
            ->set('salesOrderLines.0.qty', 2)
            ->set('salesOrderLines.0.condition', ExceptionCaseLine::CONDITION_DAMAGED)
            ->set('salesOrderLines.0.action', ExceptionCaseLine::ACTION_REFUND)
            ->call('save')
            ->assertRedirect();

        $case = ExceptionCase::firstOrFail();
        $caseLine = $case->lines()->firstOrFail();

        $this->assertSame($tenant->id, $case->tenant_id);
        $this->assertSame($order->id, $case->sales_order_id);
        $this->assertSame($line->id, $caseLine->sales_order_line_id);
        $this->assertSame($sku->id, $caseLine->sku_id);
        $this->assertSame($sku->stock_item_id, $caseLine->stock_item_id);
        $this->assertSame(2, $caseLine->qty);
        $this->assertSame(ExceptionCaseLine::CONDITION_DAMAGED, $caseLine->condition);
        $this->assertSame(ExceptionCaseLine::ACTION_REFUND, $caseLine->action);
    }

    public function test_create_case_does_not_create_inventory_movements_or_change_balances(): void
    {
        [$tenant, $order, , $sku] = $this->salesOrderWithLine(null, 'NO-INV-SKU', 'NO-INV-ORDER');
        $warehouse = Warehouse::factory()->create();
        app(InventoryService::class)->adjustStock($tenant->id, $warehouse->id, $sku->stock_item_id, 10);
        $movementCount = InventoryMovement::count();
        $before = InventoryBalance::firstOrFail()->only(['on_hand_qty', 'reserved_qty', 'available_qty']);

        Livewire::actingAs($this->internalUser())
            ->test(ExceptionCaseCreate::class, ['order' => $order])
            ->set('salesOrderLines.0.selected', true)
            ->call('save');

        $after = InventoryBalance::firstOrFail()->only(['on_hand_qty', 'reserved_qty', 'available_qty']);
        $this->assertSame($movementCount, InventoryMovement::count());
        $this->assertSame($before, $after);
    }

    public function test_status_can_be_updated_on_detail_page(): void
    {
        [, $order] = $this->salesOrderWithLine(null, 'STATUS-SKU', 'STATUS-ORDER');
        $case = $this->exceptionCaseForOrder($order);

        Livewire::actingAs($this->internalUser())
            ->test(ExceptionCaseShow::class, ['exceptionCase' => $case])
            ->set('status', ExceptionCase::STATUS_INVESTIGATING)
            ->set('note', 'Checking courier evidence')
            ->call('saveCase')
            ->assertSee(__('exception_cases.case_updated'));

        $case->refresh();
        $this->assertSame(ExceptionCase::STATUS_INVESTIGATING, $case->status);
        $this->assertSame('Checking courier evidence', $case->note);
    }

    public function test_closed_case_is_read_only_in_v1(): void
    {
        [, $order] = $this->salesOrderWithLine(null, 'CLOSED-SKU', 'CLOSED-ORDER');
        $case = $this->exceptionCaseForOrder($order);
        $case->update(['status' => ExceptionCase::STATUS_CLOSED, 'resolved_at' => now()]);
        $line = $case->lines()->firstOrFail();

        Livewire::actingAs($this->internalUser())
            ->test(ExceptionCaseShow::class, ['exceptionCase' => $case])
            ->set('status', ExceptionCase::STATUS_OPEN)
            ->set("lineDrafts.{$line->id}.condition", ExceptionCaseLine::CONDITION_GOOD)
            ->call('saveCase')
            ->call('saveLines');

        $this->assertSame(ExceptionCase::STATUS_CLOSED, $case->refresh()->status);
        $this->assertSame(ExceptionCaseLine::CONDITION_UNKNOWN, $line->refresh()->condition);
    }

    public function test_sales_order_detail_shows_linked_exception_cases(): void
    {
        [, $order] = $this->salesOrderWithLine(null, 'DETAIL-LINK-SKU', 'DETAIL-LINK-ORDER');
        $case = $this->exceptionCaseForOrder($order);

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderDetail::class, ['order' => $order])
            ->assertSee(__('exception_cases.btn_create_from_order'))
            ->assertSee($case->case_no);
    }

    public function test_index_filters_by_case_type(): void
    {
        [, $orderA] = $this->salesOrderWithLine(null, 'TYPE-A-SKU', 'TYPE-A-ORDER');
        [, $orderB] = $this->salesOrderWithLine(null, 'TYPE-B-SKU', 'TYPE-B-ORDER');
        $missing = $this->exceptionCaseForOrder($orderA, type: ExceptionCase::TYPE_MISSING);
        $damaged = $this->exceptionCaseForOrder($orderB, type: ExceptionCase::TYPE_DAMAGED);

        Livewire::actingAs($this->internalUser())
            ->test(ExceptionCaseIndex::class)
            ->set('typeFilter', ExceptionCase::TYPE_DAMAGED)
            ->assertSee($damaged->case_no)
            ->assertDontSee($missing->case_no);
    }

    public function test_index_filters_by_status(): void
    {
        [, $orderA] = $this->salesOrderWithLine(null, 'STATUS-A-SKU', 'STATUS-A-ORDER');
        [, $orderB] = $this->salesOrderWithLine(null, 'STATUS-B-SKU', 'STATUS-B-ORDER');
        $open = $this->exceptionCaseForOrder($orderA, status: ExceptionCase::STATUS_OPEN);
        $resolved = $this->exceptionCaseForOrder($orderB, status: ExceptionCase::STATUS_RESOLVED);

        Livewire::actingAs($this->internalUser())
            ->test(ExceptionCaseIndex::class)
            ->set('statusFilter', ExceptionCase::STATUS_RESOLVED)
            ->assertSee($resolved->case_no)
            ->assertDontSee($open->case_no);
    }

    public function test_index_search_finds_case_no_order_id_sku_and_note(): void
    {
        [, $order] = $this->salesOrderWithLine(null, 'SEARCH-SKU-ABC', 'SEARCH-ORDER-ABC');
        $case = $this->exceptionCaseForOrder($order, 'Need courier investigation');

        Livewire::actingAs($this->internalUser())
            ->test(ExceptionCaseIndex::class)
            ->set('search', $case->case_no)
            ->assertSee($case->case_no);

        Livewire::actingAs($this->internalUser())
            ->test(ExceptionCaseIndex::class)
            ->set('search', 'SEARCH-ORDER-ABC')
            ->assertSee($case->case_no);

        Livewire::actingAs($this->internalUser())
            ->test(ExceptionCaseIndex::class)
            ->set('search', 'SEARCH-SKU-ABC')
            ->assertSee($case->case_no);

        Livewire::actingAs($this->internalUser())
            ->test(ExceptionCaseIndex::class)
            ->set('search', 'courier investigation')
            ->assertSee($case->case_no);
    }

    public function test_case_no_is_finalized_after_create(): void
    {
        [, $order] = $this->salesOrderWithLine(null, 'CASE-NO-SKU', 'CASE-NO-ORDER');

        Livewire::actingAs($this->internalUser())
            ->test(ExceptionCaseCreate::class, ['order' => $order])
            ->set('salesOrderLines.0.selected', true)
            ->call('save');

        $case = ExceptionCase::firstOrFail();
        $this->assertMatchesRegularExpression('/^EC-\d{8}-0001$/', $case->case_no);
        $this->assertFalse(str_starts_with($case->case_no, 'EC-PENDING-'));
    }

    public function test_guest_users_are_not_treated_as_internal_users(): void
    {
        Tenant::factory()->create();

        Livewire::test(ExceptionCaseIndex::class)
            ->assertForbidden();
    }

    /**
     * @return array{0: Tenant, 1: SalesOrder, 2: SalesOrderLine, 3: Sku}
     */
    private function salesOrderWithLine(?Tenant $tenant = null, string $skuCode = 'CASE-SKU', string $orderId = 'CASE-ORDER', int $quantity = 2): array
    {
        $tenant ??= Tenant::factory()->create();
        $shop = Shop::factory()->for($tenant)->create();
        $stockItem = StockItem::factory()->for($tenant)->create(['code' => $skuCode.'-STK', 'name' => $skuCode.' Stock']);
        $sku = Sku::factory()->for($tenant)->for($shop)->for($stockItem)->create([
            'sku' => $skuCode,
            'name' => $skuCode.' Name',
        ]);
        $order = SalesOrder::factory()->for($tenant)->for($shop)->create(['platform_order_id' => $orderId]);
        $line = SalesOrderLine::factory()->for($order)->for($sku)->create(['quantity' => $quantity]);

        return [$tenant, $order, $line, $sku];
    }

    private function exceptionCaseForOrder(
        SalesOrder $order,
        string $note = 'Exception note',
        string $type = ExceptionCase::TYPE_MISSING,
        string $status = ExceptionCase::STATUS_OPEN,
    ): ExceptionCase {
        $case = ExceptionCase::create([
            'tenant_id' => $order->tenant_id,
            'sales_order_id' => $order->id,
            'case_no' => 'EC-PENDING-test',
            'case_type' => $type,
            'status' => $status,
            'note' => $note,
            'created_by_user_id' => $this->internalUser()->id,
        ]);
        $case->update(['case_no' => ExceptionCase::buildCaseNo($case->id)]);
        $line = $order->lines()->with('sku')->firstOrFail();
        $case->lines()->create([
            'tenant_id' => $order->tenant_id,
            'sales_order_line_id' => $line->id,
            'sku_id' => $line->sku_id,
            'stock_item_id' => $line->sku?->stock_item_id,
            'qty' => 1,
            'condition' => ExceptionCaseLine::CONDITION_UNKNOWN,
            'action' => ExceptionCaseLine::ACTION_INVESTIGATE,
        ]);

        return $case;
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
