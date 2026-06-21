<?php

namespace Tests\Feature;

use App\Livewire\IssueCreate;
use App\Livewire\IssueIndex;
use App\Livewire\IssueShow;
use App\Livewire\SalesOrderDetail;
use App\Models\Issue;
use App\Models\IssueLine;
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

class IssueTest extends TestCase
{
    use RefreshDatabase;

    public function test_internal_user_can_open_issue_index(): void
    {
        $this->actingAs($this->internalUser())
            ->get(route('issues.index'))
            ->assertOk()
            ->assertSee(__('issues.page_title'));
    }

    public function test_tenant_user_only_sees_own_tenant_issues(): void
    {
        [$ownTenant, $user] = $this->tenantUser();
        [, $ownOrder] = $this->salesOrderWithLine($ownTenant, 'OWN-ISSUE-SKU', 'OWN-ORDER');
        [, $otherOrder] = $this->salesOrderWithLine(Tenant::factory()->create(), 'OTHER-ISSUE-SKU', 'OTHER-ORDER');
        $ownCase = $this->issueForOrder($ownOrder, 'Own tenant note');
        $otherCase = $this->issueForOrder($otherOrder, 'Other tenant note');

        Livewire::actingAs($user)
            ->test(IssueIndex::class)
            ->assertSee($ownCase->issue_no)
            ->assertDontSee($otherCase->issue_no);
    }

    public function test_tenant_user_cannot_create_issue_for_another_tenant_sales_order(): void
    {
        [, $user] = $this->tenantUser();
        [, $otherOrder] = $this->salesOrderWithLine(Tenant::factory()->create(), 'OTHER-SKU', 'OTHER-FORBIDDEN');

        $this->actingAs($user)
            ->get(route('sales.orders.issues.create', $otherOrder))
            ->assertForbidden();
    }

    public function test_create_issue_from_sales_order_preloads_sales_order_lines(): void
    {
        [$tenant, $order, $line] = $this->salesOrderWithLine(null, 'PRELOAD-SKU', 'PRELOAD-ORDER');

        Livewire::actingAs($this->internalUser())
            ->test(IssueCreate::class, ['order' => $order])
            ->assertSet('tenantId', (string) $tenant->id)
            ->assertSet('salesOrderId', (string) $order->id)
            ->assertSee('PRELOAD-SKU')
            ->assertSee((string) $line->quantity);
    }

    public function test_create_issue_requires_at_least_one_related_order_reference(): void
    {
        [$tenant, , , $sku] = $this->salesOrderWithLine(null, 'NO-REF-SKU', 'NO-REF-ORDER');

        Livewire::actingAs($this->internalUser())
            ->test(IssueCreate::class)
            ->set('tenantId', (string) $tenant->id)
            ->set('manualLines.0.sku_id', (string) $sku->id)
            ->set('manualLines.0.qty', 1)
            ->call('save')
            ->assertHasErrors(['salesOrderId']);
    }

    public function test_create_issue_requires_at_least_one_line(): void
    {
        [$tenant, $order] = $this->salesOrderWithLine(null, 'NO-LINE-SKU', 'NO-LINE-ORDER');

        Livewire::actingAs($this->internalUser())
            ->test(IssueCreate::class, ['order' => $order])
            ->set('manualLines.0.sku_id', '')
            ->call('save')
            ->assertHasErrors(['lines']);
    }

    public function test_create_issue_stores_issue_lines_with_sku_stock_item_qty_condition_and_action(): void
    {
        [$tenant, $order, $line, $sku] = $this->salesOrderWithLine(null, 'STORE-LINE-SKU', 'STORE-LINE-ORDER', quantity: 3);

        Livewire::actingAs($this->internalUser())
            ->test(IssueCreate::class, ['order' => $order])
            ->set('salesOrderLines.0.selected', true)
            ->set('salesOrderLines.0.qty', 2)
            ->set('salesOrderLines.0.condition', IssueLine::CONDITION_DAMAGED)
            ->set('salesOrderLines.0.action', IssueLine::ACTION_REFUND)
            ->call('save')
            ->assertRedirect();

        $case = Issue::firstOrFail();
        $caseLine = $case->lines()->firstOrFail();

        $this->assertSame($tenant->id, $case->tenant_id);
        $this->assertSame($order->id, $case->sales_order_id);
        $this->assertSame($line->id, $caseLine->sales_order_line_id);
        $this->assertSame($sku->id, $caseLine->sku_id);
        $this->assertSame($sku->stock_item_id, $caseLine->stock_item_id);
        $this->assertSame(2, $caseLine->qty);
        $this->assertSame(IssueLine::CONDITION_DAMAGED, $caseLine->condition);
        $this->assertSame(IssueLine::ACTION_REFUND, $caseLine->action);
    }

    public function test_create_issue_does_not_create_inventory_movements_or_change_balances(): void
    {
        [$tenant, $order, , $sku] = $this->salesOrderWithLine(null, 'NO-INV-SKU', 'NO-INV-ORDER');
        $warehouse = Warehouse::factory()->create();
        app(InventoryService::class)->adjustStock($tenant->id, $warehouse->id, $sku->stock_item_id, 10);
        $movementCount = InventoryMovement::count();
        $before = InventoryBalance::firstOrFail()->only(['on_hand_qty', 'reserved_qty', 'available_qty']);

        Livewire::actingAs($this->internalUser())
            ->test(IssueCreate::class, ['order' => $order])
            ->set('salesOrderLines.0.selected', true)
            ->call('save');

        $after = InventoryBalance::firstOrFail()->only(['on_hand_qty', 'reserved_qty', 'available_qty']);
        $this->assertSame($movementCount, InventoryMovement::count());
        $this->assertSame($before, $after);
    }

    public function test_status_can_be_updated_on_detail_page(): void
    {
        [, $order] = $this->salesOrderWithLine(null, 'STATUS-SKU', 'STATUS-ORDER');
        $case = $this->issueForOrder($order);

        Livewire::actingAs($this->internalUser())
            ->test(IssueShow::class, ['issue' => $case])
            ->set('status', Issue::STATUS_INVESTIGATING)
            ->set('note', 'Checking courier evidence')
            ->call('saveIssue')
            ->assertSee(__('issues.issue_updated'));

        $case->refresh();
        $this->assertSame(Issue::STATUS_INVESTIGATING, $case->status);
        $this->assertSame('Checking courier evidence', $case->note);
    }

    public function test_closed_issue_is_read_only_in_v1(): void
    {
        [, $order] = $this->salesOrderWithLine(null, 'CLOSED-SKU', 'CLOSED-ORDER');
        $case = $this->issueForOrder($order);
        $case->update(['status' => Issue::STATUS_CLOSED, 'resolved_at' => now()]);
        $line = $case->lines()->firstOrFail();

        Livewire::actingAs($this->internalUser())
            ->test(IssueShow::class, ['issue' => $case])
            ->set('status', Issue::STATUS_OPEN)
            ->set("lineDrafts.{$line->id}.condition", IssueLine::CONDITION_GOOD)
            ->call('saveIssue')
            ->call('saveLines');

        $this->assertSame(Issue::STATUS_CLOSED, $case->refresh()->status);
        $this->assertSame(IssueLine::CONDITION_UNKNOWN, $line->refresh()->condition);
    }

    public function test_sales_order_detail_shows_linked_issues(): void
    {
        [, $order] = $this->salesOrderWithLine(null, 'DETAIL-LINK-SKU', 'DETAIL-LINK-ORDER');
        $case = $this->issueForOrder($order);

        Livewire::actingAs($this->internalUser())
            ->test(SalesOrderDetail::class, ['order' => $order])
            ->assertSee(__('issues.btn_create_from_order'))
            ->assertSee($case->issue_no);
    }

    public function test_index_filters_by_issue_type(): void
    {
        [, $orderA] = $this->salesOrderWithLine(null, 'TYPE-A-SKU', 'TYPE-A-ORDER');
        [, $orderB] = $this->salesOrderWithLine(null, 'TYPE-B-SKU', 'TYPE-B-ORDER');
        $missing = $this->issueForOrder($orderA, type: Issue::TYPE_MISSING);
        $damaged = $this->issueForOrder($orderB, type: Issue::TYPE_DAMAGED);

        Livewire::actingAs($this->internalUser())
            ->test(IssueIndex::class)
            ->set('typeFilter', Issue::TYPE_DAMAGED)
            ->assertSee($damaged->issue_no)
            ->assertDontSee($missing->issue_no);
    }

    public function test_index_filters_by_status(): void
    {
        [, $orderA] = $this->salesOrderWithLine(null, 'STATUS-A-SKU', 'STATUS-A-ORDER');
        [, $orderB] = $this->salesOrderWithLine(null, 'STATUS-B-SKU', 'STATUS-B-ORDER');
        $open = $this->issueForOrder($orderA, status: Issue::STATUS_OPEN);
        $resolved = $this->issueForOrder($orderB, status: Issue::STATUS_RESOLVED);

        Livewire::actingAs($this->internalUser())
            ->test(IssueIndex::class)
            ->set('statusFilter', Issue::STATUS_RESOLVED)
            ->assertSee($resolved->issue_no)
            ->assertDontSee($open->issue_no);
    }

    public function test_index_hides_closed_and_resolved_issues_by_default(): void
    {
        [, $orderA] = $this->salesOrderWithLine(null, 'DEFAULT-OPEN-SKU', 'DEFAULT-OPEN-ORDER');
        [, $orderB] = $this->salesOrderWithLine(null, 'DEFAULT-RESOLVED-SKU', 'DEFAULT-RESOLVED-ORDER');
        [, $orderC] = $this->salesOrderWithLine(null, 'DEFAULT-CLOSED-SKU', 'DEFAULT-CLOSED-ORDER');
        $open = $this->issueForOrder($orderA, status: Issue::STATUS_OPEN);
        $resolved = $this->issueForOrder($orderB, status: Issue::STATUS_RESOLVED);
        $closed = $this->issueForOrder($orderC, status: Issue::STATUS_CLOSED);

        Livewire::actingAs($this->internalUser())
            ->test(IssueIndex::class)
            ->assertSee($open->issue_no)
            ->assertDontSee($resolved->issue_no)
            ->assertDontSee($closed->issue_no);
    }

    public function test_issue_status_colors_are_limited_to_red_green_and_blue(): void
    {
        $this->assertSame('red', (new Issue(['status' => Issue::STATUS_OPEN]))->statusColor());
        $this->assertSame('green', (new Issue(['status' => Issue::STATUS_RESOLVED]))->statusColor());
        $this->assertSame('green', (new Issue(['status' => Issue::STATUS_CLOSED]))->statusColor());
        $this->assertSame('blue', (new Issue(['status' => Issue::STATUS_INVESTIGATING]))->statusColor());
        $this->assertSame('blue', (new Issue(['status' => Issue::STATUS_WAITING_RETURN]))->statusColor());
        $this->assertSame('blue', (new Issue(['status' => Issue::STATUS_RECEIVED_RETURN]))->statusColor());
    }

    public function test_index_search_finds_issue_no_order_id_sku_and_note(): void
    {
        [, $order] = $this->salesOrderWithLine(null, 'SEARCH-SKU-ABC', 'SEARCH-ORDER-ABC');
        $case = $this->issueForOrder($order, 'Need courier investigation');

        Livewire::actingAs($this->internalUser())
            ->test(IssueIndex::class)
            ->set('search', $case->issue_no)
            ->assertSee($case->issue_no);

        Livewire::actingAs($this->internalUser())
            ->test(IssueIndex::class)
            ->set('search', 'SEARCH-ORDER-ABC')
            ->assertSee($case->issue_no);

        Livewire::actingAs($this->internalUser())
            ->test(IssueIndex::class)
            ->set('search', 'SEARCH-SKU-ABC')
            ->assertSee($case->issue_no);

        Livewire::actingAs($this->internalUser())
            ->test(IssueIndex::class)
            ->set('search', 'courier investigation')
            ->assertSee($case->issue_no);
    }

    public function test_issue_no_is_finalized_after_create(): void
    {
        [, $order] = $this->salesOrderWithLine(null, 'ISSUE-NO-SKU', 'ISSUE-NO-ORDER');

        Livewire::actingAs($this->internalUser())
            ->test(IssueCreate::class, ['order' => $order])
            ->set('salesOrderLines.0.selected', true)
            ->call('save');

        $case = Issue::firstOrFail();
        $this->assertMatchesRegularExpression('/^ISS-\d{8}-0001$/', $case->issue_no);
        $this->assertFalse(str_starts_with($case->issue_no, 'ISS-PENDING-'));
    }

    public function test_guest_users_are_not_treated_as_internal_users(): void
    {
        Tenant::factory()->create();

        Livewire::test(IssueIndex::class)
            ->assertForbidden();
    }

    /**
     * @return array{0: Tenant, 1: SalesOrder, 2: SalesOrderLine, 3: Sku}
     */
    private function salesOrderWithLine(?Tenant $tenant = null, string $skuCode = 'ISSUE-SKU', string $orderId = 'ISSUE-ORDER', int $quantity = 2): array
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

    private function issueForOrder(
        SalesOrder $order,
        string $note = 'Issue note',
        string $type = Issue::TYPE_MISSING,
        string $status = Issue::STATUS_OPEN,
    ): Issue {
        $case = Issue::create([
            'tenant_id' => $order->tenant_id,
            'sales_order_id' => $order->id,
            'issue_no' => 'ISS-PENDING-test',
            'issue_type' => $type,
            'status' => $status,
            'note' => $note,
            'created_by_user_id' => $this->internalUser()->id,
        ]);
        $case->update(['issue_no' => Issue::buildIssueNo($case->id)]);
        $line = $order->lines()->with('sku')->firstOrFail();
        $case->lines()->create([
            'tenant_id' => $order->tenant_id,
            'sales_order_line_id' => $line->id,
            'sku_id' => $line->sku_id,
            'stock_item_id' => $line->sku?->stock_item_id,
            'qty' => 1,
            'condition' => IssueLine::CONDITION_UNKNOWN,
            'action' => IssueLine::ACTION_INVESTIGATE,
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
