# Task: Issues v1

## Stack

Laravel 13, Livewire 4 (class-based components, not Volt), Flux UI (`livewire/flux ^2.14`),
SQLite (dev), PHP 8.3. Plain Blade views only. No TypeScript.

---

## Goal

Build an **Issues** module for after-shipment or order exception handling.

This module records and tracks cases such as:

- shipped item reported missing
- item arrived broken / damaged
- customer returned goods
- wrong item shipped
- parcel lost in transit
- customer refused delivery
- other post-shipment issue

Important principle:

**Do not undo shipped outbound orders or shipped sales orders.**

A shipped order is historical fact. If something happens after shipping, create an issue
linked to the order. Inventory changes only happen later when there is a real inventory event,
such as a returned item physically received back into the warehouse.

---

## Product Rules

### What v1 does

V1 is a tracking and workflow module:

- Create issues from a sales order detail page.
- List and filter issues.
- Show issue detail.
- Add SKU-level case lines.
- Track status, type, quantity, condition, and planned action.
- Add notes.
- Keep tenant scope and audit-friendly data.

### What v1 does not do

V1 must **not** automatically change inventory.

Do not call `InventoryService` from issue create/update.

Examples:

- A customer says "1 item missing" -> create an issue, no inventory movement.
- A customer says "item broken" but the item is not returned -> create an issue, no inventory movement.
- A returned item physically arrives at the warehouse -> this is a future "Return Receiving" flow.

Inventory changes are out of scope for v1.

---

## Naming

Use this naming consistently:

- Module display name: `Issues`
- Nav label: `Issues`
- Route prefix: `/issues`
- Model: `Issue`
- Line model: `IssueLine`
- Table: `issues`
- Table: `issue_lines`

Do not use `Order Cases` or `Return Cases` as the module name. Returns are only one type of
issue.

---

## Navigation

Add `Issues` to the top navigation, near Sales Orders / Fulfillment / Outbound.

Suggested order:

```
Inventory | SKUs | Inbound | Outbound | Sales Orders | Fulfillment | Issues | Setup
```

---

## Case Types

Use string constants on `Issue`.

```php
TYPE_MISSING = 'missing';
TYPE_DAMAGED = 'damaged';
TYPE_RETURNED = 'returned';
TYPE_WRONG_ITEM = 'wrong_item';
TYPE_LOST_IN_TRANSIT = 'lost_in_transit';
TYPE_CUSTOMER_REFUSED = 'customer_refused';
TYPE_OTHER = 'other';
```

Labels:

- Missing item
- Damaged item
- Returned item
- Wrong item
- Lost in transit
- Customer refused
- Other

---

## Case Statuses

Use string constants on `Issue`.

```php
STATUS_OPEN = 'open';
STATUS_INVESTIGATING = 'investigating';
STATUS_WAITING_RETURN = 'waiting_return';
STATUS_RECEIVED_RETURN = 'received_return';
STATUS_RESOLVED = 'resolved';
STATUS_CLOSED = 'closed';
```

Status meaning:

| Status | Meaning |
|---|---|
| open | New case created |
| investigating | Staff is checking warehouse/courier/customer evidence |
| waiting_return | Waiting for goods to come back |
| received_return | Goods returned and received, but case not fully resolved |
| resolved | Decision/action completed |
| closed | Case closed, no further action |

V1 status changes are manual. Do not auto-transition based on inventory movements.

---

## Line Conditions

Use string values on `IssueLine.condition`.

```php
CONDITION_MISSING = 'missing';
CONDITION_DAMAGED = 'damaged';
CONDITION_GOOD = 'good';
CONDITION_UNKNOWN = 'unknown';
```

Examples:

- missing: customer says the item was not received
- damaged: customer received item broken, or returned damaged item
- good: returned item appears sellable
- unknown: staff has not confirmed yet

---

## Planned Actions

Use string values on `IssueLine.action`.

```php
ACTION_NONE = 'none';
ACTION_RESEND = 'resend';
ACTION_REFUND = 'refund';
ACTION_RETURN_TO_STOCK = 'return_to_stock';
ACTION_MARK_DAMAGED = 'mark_damaged';
ACTION_WRITE_OFF = 'write_off';
ACTION_INVESTIGATE = 'investigate';
```

V1 records the planned action only.

Do not automatically:

- create a new outbound order
- refund money
- return stock to inventory
- mark stock damaged
- write off stock

Those are future operational flows.

---

## Database

### Migration 1: create `issues`

```php
Schema::create('issues', function (Blueprint $table) {
    $table->id();
    $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

    $table->foreignId('sales_order_id')->nullable()->constrained('sales_orders')->nullOnDelete();
    $table->foreignId('fulfillment_group_id')->nullable()->constrained('fulfillment_groups')->nullOnDelete();
    $table->foreignId('outbound_order_id')->nullable()->constrained('outbound_orders')->nullOnDelete();

    $table->string('issue_no')->unique();        // e.g. ISS-20260621-0001
    $table->string('issue_type');
    $table->string('status')->default('open');

    $table->timestamp('reported_at')->nullable();
    $table->string('reported_by')->nullable();  // customer / staff / courier / marketplace / other

    $table->text('note')->nullable();
    $table->timestamp('resolved_at')->nullable();

    $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
    $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
    $table->timestamps();

    $table->index(['tenant_id', 'status']);
    $table->index(['tenant_id', 'issue_type']);
    $table->index(['sales_order_id']);
    $table->index(['outbound_order_id']);
    $table->index(['created_at']);
});
```

Notes:

- `sales_order_id` is preferred when the exception is about a marketplace/customer order.
- `outbound_order_id` is useful when the exception is about a warehouse shipment.
- `fulfillment_group_id` is useful when multiple sales orders shipped together.
- At least one of `sales_order_id`, `outbound_order_id`, or `fulfillment_group_id` should be set.

### Migration 2: create `issue_lines`

```php
Schema::create('issue_lines', function (Blueprint $table) {
    $table->id();
    $table->foreignId('issue_id')->constrained('issues')->cascadeOnDelete();
    $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

    $table->foreignId('sales_order_line_id')->nullable()->constrained('sales_order_lines')->nullOnDelete();
    $table->foreignId('outbound_order_line_id')->nullable()->constrained('outbound_order_lines')->nullOnDelete();
    $table->foreignId('sku_id')->nullable()->constrained('skus')->nullOnDelete();
    $table->foreignId('stock_item_id')->nullable()->constrained('stock_items')->nullOnDelete();

    $table->unsignedInteger('qty');
    $table->string('condition')->default('unknown');
    $table->string('action')->default('investigate');
    $table->text('note')->nullable();

    $table->timestamps();

    $table->index(['tenant_id', 'sku_id']);
    $table->index(['tenant_id', 'stock_item_id']);
});
```

Rules:

- `qty` must be >= 1.
- `tenant_id` on each line must match the parent issue tenant.
- If `sku_id` or `stock_item_id` is selected manually, enforce tenant scope.
- For sales-order sourced lines, copy `sku_id` and `stock_item_id` from the sales order line where available.

---

## Models

### `Issue`

Relationships:

- `tenant()`
- `salesOrder()`
- `fulfillmentGroup()`
- `outboundOrder()`
- `lines()`
- `createdBy()`
- `updatedBy()`

Add constants for all case types and statuses.

Add helper methods:

- `typeLabel()`
- `statusLabel()`
- `statusColor()`
- `isClosed()`

Generate `issue_no` using a temporary unique value on insert, then update after the ID exists.

Do **not** insert the row with a null `issue_no`. The column is unique and not nullable.
Mirror the existing FulfillmentGroup `reference_no` pattern:

```php
'issue_no' => 'ISS-PENDING-'.Str::uuid(),
```

Then update to the final case number after create:

```php
ISS-YYYYMMDD-0001
```

Use the ID for the suffix to avoid race conditions.

Add a static helper:

Remember to import:

```php
use Carbon\CarbonInterface;
```

```php
public static function buildIssueNo(int $id, ?CarbonInterface $date = null): string
{
    $date ??= now('Asia/Tokyo');

    return 'ISS-'.$date->format('Ymd').'-'.str_pad((string) $id, 4, '0', STR_PAD_LEFT);
}
```

After create:

```php
$case->update(['issue_no' => Issue::buildIssueNo($case->id)]);
```

Store normal timestamps in DB using app default timezone. Only format the case number date in JST.
This is deliberate because issues are warehouse-operation records for a JP-oriented workflow.
It may differ from older reference-number helpers that use app-default time.

### `IssueLine`

Relationships:

- `issue()`
- `tenant()`
- `salesOrderLine()`
- `outboundOrderLine()`
- `sku()`
- `stockItem()`

Add constants for conditions and planned actions.

---

## Routes

```php
GET /issues
GET /issues/create
GET /issues/{issue}
GET /sales-orders/{order}/issues/create
```

Route names:

```php
issues.index
issues.create
issues.show
sales.orders.issues.create
```

Use `{issue}` as the route parameter so Livewire full-page route-model binding can bind to:

```php
public function mount(Issue $issue): void
```

Do not use `{case}` in the route if the component argument is named `$issue`.

---

## Livewire Components

### 1. `IssueIndex`

Page: `/issues`

Features:

- list cases
- filter by tenant
- filter by status
- filter by type
- filter by sales order
- filter by outbound order
- search by:
  - case no
  - sales order platform order ID
  - outbound ref
  - SKU
  - stock item code/name
  - note

Columns:

- Case no
- Type
- Status
- Related order
- Lines summary
- Reported at
- Updated
- Actions

Use pagination.

Internal users can see all tenants.
Tenant users can only see their own active tenant cases.

Tenant-scope helper rules:

- Use the shared tenant-scope helper if the auth hardening task has extracted one.
- If implementing the helper locally, use `$user?->user_type === 'internal'`.
- Do not reintroduce the old guest-as-internal pattern. A missing authenticated user must not be treated as internal.

### 2. `IssueCreate`

Pages:

- `/issues/create`
- `/sales-orders/{order}/issues/create`

For the sales-order-specific route:

- prefill tenant
- prefill sales order
- list the sales order lines as selectable case lines
- allow user to select one or more lines and enter qty, condition, action, note

Fields:

- Tenant (internal users only)
- Related sales order (optional)
- Related outbound order (optional)
- Case type
- Status default `open`
- Reported at
- Reported by
- Note
- Lines

Line fields:

- SKU / stock item
- Qty
- Condition
- Planned action
- Line note

Validation:

- case type required
- status required
- at least one related order reference required
- at least one line required
- line qty >= 1
- line tenant must match case tenant
- line qty cannot exceed original sales order line qty when created from a sales order line, unless user explicitly chooses "manual extra line"
- V1 caps quantity per case only. It does not enforce a cumulative cap across multiple issues
  for the same sales order line. This is accepted because v1 is a tracking module, not automatic inventory,
  refund, or settlement control.

### 3. `IssueShow`

Page: `/issues/{issue}`

Show:

- case header
- related sales order / outbound / fulfillment group links
- status and type
- notes
- lines
- audit metadata

Allow:

- update status
- update note
- update line condition/action/note

Do not allow:

- deleting a case
- deleting lines after create in v1
- inventory adjustment
- receive return

Closed/resolved cases should be read-only except note/status if internal admin decides to reopen later.
For v1, do not implement reopen.

---

## Sales Order Detail Integration

On `GET /sales-orders/{order}`, add a button:

```text
Create Issue
```

Button should link to:

```php
route('sales.orders.issues.create', $order)
```

Show existing issues for this sales order on the detail page:

- case no
- type
- status
- updated date
- link to case detail

---

## Inventory Rules

V1 must never change inventory balances.

Do not call:

- `receiveStock`
- `adjustStock`
- `markDamaged`
- `releaseReserve`
- `shipReservedStock`

Issue lines may reference `stock_item_id`, but this is for traceability only.

Future phase:

- `Return Receiving`
- receive good returned items into stock
- receive damaged returned items into damaged bucket
- create inventory movements with `ref_type = issue`

---

## Activity Log

Use the existing activity log pattern if available in the codebase.

Track:

- case created
- status changed
- note changed
- line action/condition changed

Do not log sensitive customer PII beyond existing sales order references.

---

## Language Keys

Add `lang/en/issues.php`.

Minimum keys:

- page titles
- nav label
- field labels
- case type labels
- status labels
- condition labels
- action labels
- validation messages
- success messages

If the app uses fallback locale stubs for `zh_TW`, `zh_CN`, `ja`, follow the existing locale inheritance
pattern. In this codebase that usually means empty `return [];` stubs relying on `fallback_locale = en`,
not copying English values into every locale file.

---

## Tests

Add `tests/Feature/IssueTest.php`.

Required tests:

1. internal user can open issue index
2. tenant user only sees own tenant cases
3. tenant user cannot create a case for another tenant sales order
4. create case from sales order preloads sales order lines
5. create case requires at least one related order reference
6. create case requires at least one line
7. create case stores case lines with sku, stock item, qty, condition, action
8. create case does not create inventory movements
9. create case does not change inventory balances
10. status can be updated on detail page
11. closed case is read-only in v1
12. sales order detail shows linked issues
13. index filters by case type
14. index filters by status
15. index search finds case no / order ID / SKU / note
16. issue_no is inserted first as `ISS-PENDING-{uuid}` and then updated to `ISS-YYYYMMDD-0001` style
17. guest users are not treated as internal users by issue tenant-scope helpers

Run:

```bash
php artisan test tests/Feature/IssueTest.php
php artisan test
```

---

## Acceptance Criteria

- `Issues` appears in the main navigation.
- Staff can create a case from a sales order.
- Staff can create a manual case with related order reference.
- Cases support missing/damaged/returned/wrong-item/lost/refused/other.
- Case detail clearly shows related order and SKU lines.
- No inventory quantities change when a case is created or updated.
- Tenant users cannot access other tenants' cases.
- Tests pass.

---

## Future Phases

### Phase 2: Return Receiving

When returned goods physically arrive:

- receive returned goods by issue
- choose warehouse and location
- classify condition as good/damaged
- call `InventoryService`
- write `inventory_movements.ref_type = issue`

### Phase 3: Resend / Replacement Flow

For missing or wrong item:

- create replacement outbound order from issue
- link replacement outbound to original sales order and issue
- track resend status

### Phase 4: Refund / Billing

For customer compensation:

- refund decision
- tenant billing adjustment
- warehouse liability / courier claim tracking

### Phase 5: Courier Claim Tracking

For lost/damaged in transit:

- claim number
- claim status
- claim amount
- courier response
