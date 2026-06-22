# Task: Return Orders v1

## Stack

Laravel 13, Livewire 4 (class-based components, not Volt), Flux UI (`livewire/flux ^2.14`),
SQLite (dev), PHP 8.3. Plain Blade views only. No TypeScript.

---

## Goal

Build a **Return Orders** module for physical return parcels coming back to the warehouse.

This module supports:

- tenant pre-announcing customer returns before they arrive
- tenant pre-announcing Amazon FBA removal returns
- staff creating unannounced returns when a parcel arrives without pre-advice
- staff receiving and inspecting returned goods
- staff recording per-line disposition
- inventory updates only when disposition is confirmed
- capture return-related costs for future tenant billing

Important principle:

**A Return Order is the physical inbound return parcel workflow.**

It is separate from **Issues**:

- Issue = problem / claim / customer complaint
- Return Order = physical returned parcel / return ASN

A Return Order may optionally link to an Issue, but it must also work without one.
For example, an FBA removal return may have no Issue and no Sales Order.

When inventory changes are created from a return, use:

```text
ref_type = return_order
ref_id = return_orders.id
```

Do not use `ref_type = issue` for return inventory movements.

---

## What v1 Covers

V1 includes:

- return order index
- create return order
- show return order detail
- tenant pre-announcement
- staff reactive create for unannounced returns
- receive parcel
- inspect lines
- per-line disposition
- inventory movements on disposition
- cost capture
- tenant scope
- activity/audit trail

V1 does not include:

- photo / attachment upload
- automatic Amazon FBA inbound shipment creation
- automatic marketplace refund handling
- billing invoice generation
- courier claim workflow
- automated expired-return job

Leave those for future phases.

---

## Naming

Use this naming consistently:

- Module display name: `Return Orders`
- Nav label: `Return Orders`
- Route prefix: `/return-orders`
- Model: `ReturnOrder`
- Line model: `ReturnOrderLine`
- Cost model: `ReturnOrderCost`
- Table: `return_orders`
- Table: `return_order_lines`
- Table: `return_order_costs`

Do not call this module `Exception Returns` or `Return Cases`.

---

## Navigation

Add `Return Orders` to the top navigation near Inbound / Outbound / Sales Orders.

Suggested order:

```text
Inventory | SKUs | Inbound | Return Orders | Outbound | Sales Orders | Fulfillment | Issues | Setup
```

---

## Relationship With Issues

Keep the two modules separate but linked.

Return Orders can have:

```php
issue_id nullable
sales_order_id nullable
outbound_order_id nullable
fulfillment_group_id nullable
```

Examples:

- Customer reports damaged item -> create Issue.
- Customer sends item back -> create Return Order linked to that Issue.
- FBA removal order -> create Return Order with no Issue.
- Unknown parcel arrives -> staff creates Return Order by tracking number, no Issue yet.

Update the Issues future-phase wording if needed:

> Physical return receiving is handled by Return Orders. Issues can link to Return Orders.
> Inventory movements from received/dispositioned returns use `ref_type = return_order`.

Issue integration:

- Add `returnOrders()` to the `Issue` model as a `hasMany(ReturnOrder::class)`.
- Show linked return orders on the Issue detail page when the Issues module exists.
- The link is informational in v1. Return Orders still own physical return receiving and inventory disposition.

---

## Status Flow

Use string constants on `ReturnOrder`.

```php
STATUS_DRAFT = 'draft';
STATUS_ANNOUNCED = 'announced';
STATUS_IN_TRANSIT = 'in_transit';
STATUS_ARRIVED = 'arrived';
STATUS_RECEIVED = 'received';
STATUS_INSPECTED = 'inspected';
STATUS_AWAITING_DISPOSITION = 'awaiting_disposition';
STATUS_DISPOSITIONED = 'dispositioned';
STATUS_CLOSED = 'closed';
STATUS_CANCELLED = 'cancelled';
STATUS_EXPIRED = 'expired';
```

V1 primary statuses:

```text
announced -> arrived -> received -> inspected -> awaiting_disposition -> dispositioned -> closed
announced -> cancelled
```

Notes:

- `draft`: optional tenant draft before submission.
- `announced`: tenant/staff created pre-advice, not physically arrived.
- `arrived`: parcel physically arrived, before detailed receiving.
- `received`: package opened / counted enough to record received lines.
- `inspected`: item conditions recorded.
- `awaiting_disposition`: waiting for tenant/staff decision.
- `dispositioned`: disposition actions confirmed; inventory effects applied where applicable.
- `closed`: finished and read-only.
- `cancelled`: pre-advice cancelled before physical arrival.
- `expired`: future automated flag for announced returns that never arrived.

Do not force disposition during receive. Receiving and disposition are separate steps.

---

## Return Types

Use string constants:

```php
TYPE_CUSTOMER_RETURN = 'customer_return';
TYPE_FBA_REMOVAL = 'fba_removal';
TYPE_MARKETPLACE_RETURN = 'marketplace_return';
TYPE_REFUSED_DELIVERY = 'refused_delivery';
TYPE_MANUAL = 'manual';
TYPE_UNKNOWN = 'unknown';
```

Return type affects reporting and future workflow.

FBA removal is first-class because it has different expectations:

- may contain mixed SKUs
- may have Amazon box labels
- SKU condition is unknown until inspection
- may not link to a Sales Order

---

## Return Reasons

Use code + optional free note.

```php
REASON_DEFECTIVE = 'defective';
REASON_WRONG_ITEM = 'wrong_item';
REASON_CUSTOMER_CHANGED_MIND = 'customer_changed_mind';
REASON_REFUSED_UNDELIVERED = 'refused_undelivered';
REASON_DAMAGED_IN_TRANSIT = 'damaged_in_transit';
REASON_FBA_REMOVAL = 'fba_removal';
REASON_RECALL = 'recall';
REASON_OVERSTOCK = 'overstock';
REASON_OTHER = 'other';
```

Do not use free text only. Structured reasons are needed for reporting.

---

## Shipping Payment Type

Use string constants:

```php
PAYMENT_PREPAID = 'prepaid';          // 允E��ぁEPAYMENT_COLLECT = 'collect';          // 着払い
PAYMENT_UNKNOWN = 'unknown';
```

If `payment_type = collect`, staff can enter the collected shipping amount at receive time.

Display labels:

- 允E��ぁE- 着払い
- Unknown

---

## Line Conditions

Use string constants on `ReturnOrderLine`.

```php
CONDITION_UNKNOWN = 'unknown';
CONDITION_RESELLABLE = 'resellable';
CONDITION_DAMAGED = 'damaged';
CONDITION_OPENED_USED = 'opened_used';
CONDITION_WRONG_ITEM = 'wrong_item';
CONDITION_MISSING = 'missing';
```

Conditions are set by staff during inspection.

---

## Line Dispositions

Disposition is per line, not per order.

Use string constants on `ReturnOrderLine`.

```php
DISPOSITION_UNDECIDED = 'undecided';
DISPOSITION_RETURN_TO_INVENTORY = 'return_to_inventory';
DISPOSITION_MARK_DAMAGED = 'mark_damaged';
DISPOSITION_HOLD_QUARANTINE = 'hold_quarantine';
DISPOSITION_RESEND_TO_CUSTOMER = 'resend_to_customer';
DISPOSITION_RESEND_TO_FBA = 'resend_to_fba';
DISPOSITION_FORWARD_ELSEWHERE = 'forward_elsewhere';
DISPOSITION_RETURN_TO_TENANT = 'return_to_tenant';
DISPOSITION_DESTROY = 'destroy';
DISPOSITION_WRITE_OFF = 'write_off';
DISPOSITION_INVESTIGATE = 'investigate';
```

Important:

- One return parcel may contain multiple SKUs with different dispositions.
- Do not store disposition only on the header.
- Disposition inventory effects happen only when staff confirms disposition.

---

## Inventory Behavior

Pre-announcement:

- no inventory change

Arrived:

- no inventory change

Received / inspected:

- no inventory change by default

Disposition confirmed:

- inventory may change depending on line disposition

Mapping:

| Disposition | Inventory action |
|---|---|
| return_to_inventory | `InventoryService::receiveStock()` into the selected warehouse |
| mark_damaged | In one DB transaction: `receiveStock()` first, then `markDamaged()` for the same qty |
| hold_quarantine | In one DB transaction: `receiveStock()` first, then `placeHold()` for the same qty |
| resend_to_customer | record intent in v1; future phase creates linked outbound order |
| resend_to_fba | record intent in v1; future phase creates FBA inbound/shipment flow |
| forward_elsewhere | record intent in v1; future phase creates outbound |
| return_to_tenant | record intent in v1; future phase creates outbound |
| destroy | write-off movement if item is physically in stock; otherwise record only |
| write_off | write-off movement if item is physically in stock; otherwise record only |
| investigate | no inventory change |
| undecided | no inventory change |

Disposition rules:

- Disposition is line-level.
- If the same SKU must be handled two ways, split it into separate return order lines.
  - Example: received qty 2, one resellable and one damaged -> create two lines with qty 1 each.
- `return_to_inventory` is intended for resellable/good stock.
- If `condition` is not `good` and staff selects `return_to_inventory`, show a clear warning/confirmation before applying the disposition.
- This is a soft guard in v1, not a hard block, because staff may intentionally override after inspection.

All inventory movements must use:

```php
[
    'ref_type' => 'return_order',
    'ref_id' => (string) $returnOrder->id,
    'user_id' => Auth::id(),
]
```

Do not use Issue as the inventory movement reference even when linked.

Important inventory-service detail:

- `receiveStock()` is the only current `InventoryService` method that brings new stock into the warehouse.
- `markDamaged()` and `placeHold()` do **not** receive new stock. They move existing available stock into damaged/hold buckets and assert enough available qty exists.
- Therefore damaged or quarantine returns must not call `markDamaged()` / `placeHold()` directly on a fresh return. They must first receive the returned qty, then move it into the correct bucket inside the same transaction.
- This produces two audit movements for damaged/hold returns:
  - receive returned qty into available stock
  - move that same qty from available into damaged or hold
- `received_location_id` and `disposition_location_id` are operational location records for staff. Current `inventory_balances` are warehouse-level, so these location IDs are not passed into `InventoryService` in v1.
- `destroy` / `write_off` for goods destroyed on arrival are record-only in v1 unless the item was first received into inventory. This is intentional; a future phase may add a dedicated receive-and-write-off workflow if stronger inventory audit is needed.

---

## Cost Capture

Capture return costs now, even though billing is future work.

Create `return_order_costs`.

Cost types:

```php
COST_FREIGHT_COLLECT = 'freight_collect';   // 着払い amount paid on receipt
COST_INSPECTION = 'inspection';
COST_RESTOCKING = 'restocking';
COST_DISPOSAL = 'disposal';
COST_RESEND_SHIPPING = 'resend_shipping';
COST_OTHER = 'other';
```

Rules:

- cost amount is decimal
- currency default `JPY`
- costs can be entered by staff
- tenant can view costs after staff enters them
- billing module can consume these later

---

## Database

Migration ordering:

- Date these migrations after the existing Issues migrations:
  - `2026_06_21_000001_create_issues_table.php`
  - `2026_06_21_000002_create_issue_lines_table.php`
- `return_orders.issue_id` depends on `issues`, so the FK must be created after that table exists.

### Migration 1: create `return_orders`

```php
Schema::create('return_orders', function (Blueprint $table) {
    $table->id();
    $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
    $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();

    $table->foreignId('issue_id')->nullable()->constrained('issues')->nullOnDelete();
    $table->foreignId('sales_order_id')->nullable()->constrained('sales_orders')->nullOnDelete();
    $table->foreignId('outbound_order_id')->nullable()->constrained('outbound_orders')->nullOnDelete();
    $table->foreignId('fulfillment_group_id')->nullable()->constrained('fulfillment_groups')->nullOnDelete();

    $table->string('return_no')->unique();         // RTN-YYYYMMDD-0001
    $table->string('status')->default('announced');
    $table->string('return_type')->default('customer_return');
    $table->string('return_reason')->nullable();
    $table->text('reason_note')->nullable();

    $table->string('external_return_id')->nullable();   // marketplace return ID / FBA removal ID
    $table->string('original_order_no')->nullable();    // marketplace order ID if not linked
    $table->string('customer_name')->nullable();
    $table->string('sender_name')->nullable();
    $table->string('sender_phone')->nullable();

    $table->string('shipping_method')->nullable();
    $table->string('tracking_no')->nullable();
    $table->string('payment_type')->default('unknown'); // prepaid / collect / unknown
    $table->decimal('collect_amount', 12, 2)->nullable();
    $table->string('collect_currency', 3)->default('JPY');

    $table->unsignedInteger('package_count')->nullable();
    $table->date('expected_arrival_date')->nullable();
    $table->timestamp('arrived_at')->nullable();
    $table->timestamp('received_at')->nullable();
    $table->timestamp('inspected_at')->nullable();
    $table->timestamp('dispositioned_at')->nullable();
    $table->timestamp('closed_at')->nullable();
    $table->timestamp('cancelled_at')->nullable();

    $table->text('note')->nullable();

    $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
    $table->foreignId('arrived_by_user_id')->nullable()->constrained('users')->nullOnDelete();
    $table->foreignId('received_by_user_id')->nullable()->constrained('users')->nullOnDelete();
    $table->foreignId('inspected_by_user_id')->nullable()->constrained('users')->nullOnDelete();
    $table->foreignId('dispositioned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
    $table->foreignId('cancelled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
    $table->timestamps();

    $table->index(['tenant_id', 'status']);
    $table->index(['tenant_id', 'return_type']);
    $table->index(['warehouse_id', 'status']);
    $table->index(['tracking_no']);
    $table->index(['external_return_id']);
    $table->index(['expected_arrival_date']);
});
```

### Migration 2: create `return_order_lines`

```php
Schema::create('return_order_lines', function (Blueprint $table) {
    $table->id();
    $table->foreignId('return_order_id')->constrained('return_orders')->cascadeOnDelete();
    $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

    $table->foreignId('sales_order_line_id')->nullable()->constrained('sales_order_lines')->nullOnDelete();
    $table->foreignId('sku_id')->nullable()->constrained('skus')->nullOnDelete();
    $table->foreignId('stock_item_id')->nullable()->constrained('stock_items')->nullOnDelete();

    $table->unsignedInteger('expected_qty')->default(0);
    $table->unsignedInteger('received_qty')->default(0);
    $table->string('condition')->default('unknown');
    $table->string('disposition')->default('undecided');

    $table->foreignId('received_location_id')->nullable()->constrained('warehouse_locations')->nullOnDelete();
    $table->foreignId('disposition_location_id')->nullable()->constrained('warehouse_locations')->nullOnDelete();

    $table->text('note')->nullable();
    $table->timestamp('received_at')->nullable();
    $table->timestamp('inspected_at')->nullable();
    $table->timestamp('dispositioned_at')->nullable();
    $table->timestamps();

    $table->index(['tenant_id', 'sku_id']);
    $table->index(['tenant_id', 'stock_item_id']);
    $table->index(['return_order_id', 'disposition']);
});
```

Notes:

- Expected and received quantities are line-level.
- Received SKU can differ from expected SKU in real returns. For v1, allow staff to edit/select SKU on lines during receiving.
- If the parcel contains an unexpected SKU, staff can add an extra line with `expected_qty = 0`.
- `received_location_id` and `disposition_location_id` identify where staff received/placed the return. They do not imply location-level inventory balances in v1.

### Migration 3: create `return_order_costs`

```php
Schema::create('return_order_costs', function (Blueprint $table) {
    $table->id();
    $table->foreignId('return_order_id')->constrained('return_orders')->cascadeOnDelete();
    $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
    $table->string('cost_type');
    $table->decimal('amount', 12, 2);
    $table->string('currency', 3)->default('JPY');
    $table->text('note')->nullable();
    $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
    $table->timestamps();

    $table->index(['tenant_id', 'cost_type']);
});
```

---

## Return Number Generation

Use a unique not-null `return_no`, generated like:

```text
RTN-YYYYMMDD-0001
```

Because the DB column is unique and not nullable, insert with a temporary value:

```php
'return_no' => 'RTN-PENDING-'.Str::uuid(),
```

Then update after create:

```php
ReturnOrder::buildReturnNo($returnOrder->id)
```

Use JST for the return number date deliberately:

```php
use Carbon\CarbonInterface;

public static function buildReturnNo(int $id, ?CarbonInterface $date = null): string
{
    $date ??= now('Asia/Tokyo');

    return 'RTN-'.$date->format('Ymd').'-'.str_pad((string) $id, 4, '0', STR_PAD_LEFT);
}
```

Store normal DB timestamps in app/default timezone.

---

## Models

### `ReturnOrder`

Relationships:

- `tenant()`
- `warehouse()`
- `issue()`
- `salesOrder()`
- `outboundOrder()`
- `fulfillmentGroup()`
- `lines()`
- `costs()`
- `createdBy()`
- `arrivedBy()`
- `receivedBy()`
- `inspectedBy()`
- `dispositionedBy()`

Helpers:

- `buildReturnNo()`
- `statusLabel()`
- `statusColor()`
- `typeLabel()`
- `reasonLabel()`
- `paymentTypeLabel()`
- `isTenantEditable()`
- `isStaffEditable()`
- `isClosed()`

### `Issue`

Add the reverse relationship:

```php
public function returnOrders(): HasMany
{
    return $this->hasMany(ReturnOrder::class);
}
```

### `ReturnOrderLine`

Relationships:

- `returnOrder()`
- `tenant()`
- `salesOrderLine()`
- `sku()`
- `stockItem()`
- `receivedLocation()`
- `dispositionLocation()`

Helpers:

- `conditionLabel()`
- `dispositionLabel()`
- `hasInventoryDisposition()`

### `ReturnOrderCost`

Relationships:

- `returnOrder()`
- `tenant()`
- `createdBy()`

---

## Permissions

Tenant users:

- can create return orders for their active tenant
- can edit draft/announced returns before staff marks arrived/received
- can view own tenant return orders
- become read-only after the return is received
- cannot receive, inspect, disposition, or enter costs

Internal users:

- can view all tenants
- can create unannounced returns
- can receive, inspect, disposition, and enter costs
- can close returns

Closed returns:

- read-only for everyone in v1

Tenant scope:

- use the shared tenant-scope helper if available
- otherwise use the hardened pattern: `$user?->user_type === 'internal'`
- do not treat guests as internal

---

## Routes

```php
GET /return-orders
GET /return-orders/create
GET /return-orders/{returnOrder}
GET /return-orders/{returnOrder}/receive
GET /return-orders/{returnOrder}/inspect
GET /return-orders/{returnOrder}/disposition
```

Route names:

```php
return-orders.index
return-orders.create
return-orders.show
return-orders.receive
return-orders.inspect
return-orders.disposition
```

Keep action routes before `/return-orders/{returnOrder}` if using route patterns where order matters.

---

## Livewire Components

### 1. `ReturnOrderIndex`

Page: `/return-orders`

Features:

- filter by tenant
- filter by warehouse
- filter by status
- filter by return type
- filter by reason
- filter by payment type
- search by:
  - return no
  - tracking no
  - original order no
  - customer name
  - external return ID
  - SKU
  - stock item code/name
  - note

Columns:

- Return no
- Tenant
- Warehouse
- Type / reason
- Tracking no
- Customer / order
- Status
- Expected / received summary
- Costs summary
- Actions

Tracking number should be prominent because staff often matches parcels by tracking number.

### 2. `ReturnOrderCreate`

Page: `/return-orders/create`

Supports:

- tenant pre-announcement
- internal staff unannounced create

Fields:

- tenant
- return warehouse
- return type
- reason code
- reason note
- optional linked issue
- optional linked sales order
- optional linked outbound order
- original order no
- external return ID
- customer name
- sender name
- sender phone
- shipping method
- tracking no
- payment type
- expected arrival date
- package count
- note
- expected lines

Expected line fields:

- SKU
- expected qty
- reason code override optional
- note

Validation:

- tenant required
- return type required
- status defaults to announced
- at least one of tracking no / original order no / external return ID / linked sales order is strongly recommended
- expected line qty must be >= 1
- SKU/stock item must belong to tenant

### 3. `ReturnOrderShow`

Page: `/return-orders/{returnOrder}`

Shows:

- return header
- linked issue / sales order / outbound / fulfillment group
- tracking no
- payment type
- costs
- expected lines
- received/inspected/dispositioned lines
- activity metadata

Actions:

- Mark arrived
- Receive
- Inspect
- Disposition
- Close
- Cancel (only before arrived/received)

### 4. `ReturnOrderReceive`

Page: `/return-orders/{returnOrder}/receive`

Staff-only.

Purpose:

- confirm parcel arrived / received
- enter collect amount if payment type is 着払い
- enter actual received quantities
- add unexpected lines
- choose received location if known

Receive should not force disposition.

After receive:

- update received quantities
- set `received_at`
- set `received_by_user_id`
- status becomes `received`

No inventory movement yet unless the implementation explicitly combines receive+disposition.
For v1, keep receive and disposition separate.

### 5. `ReturnOrderInspect`

Page: `/return-orders/{returnOrder}/inspect`

Staff-only.

Purpose:

- set line condition
- record notes
- mark status `inspected` or `awaiting_disposition`

No inventory movement.

### 6. `ReturnOrderDisposition`

Page: `/return-orders/{returnOrder}/disposition`

Staff-only.

Purpose:

- choose disposition per line
- choose disposition location where applicable
- enter additional costs
- confirm inventory effects

When confirming disposition:

- run in DB transaction
- re-check tenant scope
- apply inventory effects through `InventoryService`
- write movement context with `ref_type = return_order`
- set line `dispositioned_at`
- set order `dispositioned_at`
- set order status `dispositioned`

If any line remains `undecided`, set status `awaiting_disposition` instead.

---

## Location Rules

Receiving location is optional in v1, but supported.

Recommended:

- `received_location_id`: where staff physically put the returned item during receive
- `disposition_location_id`: final location after disposition

If warehouse location module exists, use it.

If no location is chosen:

- allow receive
- require location only for dispositions that put stock back into inventory, if the warehouse requires locations

Future:

- dedicated return receiving area per warehouse
- quarantine location per warehouse

---

## Activity Log

Use existing activity log pattern if available.

Track:

- return order created
- status changed
- arrived / received / inspected / dispositioned
- line condition changed
- line disposition changed
- costs added
- linked issue changed

Do not log unnecessary customer PII beyond existing model fields.

---

## Language Keys

Add:

```text
lang/en/return_orders.php
```

Minimum keys:

- nav label
- page titles
- fields
- statuses
- return types
- reasons
- payment types
- conditions
- dispositions
- cost types
- validation messages
- success messages

For `zh_TW`, `zh_CN`, `ja`, follow existing fallback pattern. If existing files are empty `return [];`
stubs, do the same and rely on `fallback_locale = en`.

---

## Tests

Add:

```text
tests/Feature/ReturnOrderTest.php
```

Required tests:

1. internal user can open return order index
2. tenant user only sees own tenant return orders
3. tenant user can create announced return for own tenant
4. tenant user cannot create return for another tenant
5. staff can create unannounced return
6. return_no inserts as `RTN-PENDING-{uuid}` then updates to `RTN-YYYYMMDD-0001`
7. create requires tenant and return type
8. create stores tracking no, original order no, customer name, payment type
9. create stores expected return lines
10. staff can mark return arrived
11. staff can receive return and enter collect amount
12. receive updates received qty but creates no inventory movement
13. inspect updates line condition but creates no inventory movement
14. disposition `return_to_inventory` creates inventory movement with `ref_type = return_order`
15. disposition `mark_damaged` first receives stock, then creates a damaged movement with `ref_type = return_order`
16. disposition `hold_quarantine` first receives stock, then creates a hold movement with `ref_type = return_order`
17. disposition `undecided` creates no inventory movement
18. one return can have multiple lines with different dispositions
19. same SKU can be split into separate lines for different dispositions
20. `return_to_inventory` with non-good condition shows warning/confirmation
21. staff can add unexpected line during receive
22. tenant cannot receive/inspect/disposition
23. tenant cannot edit after received
24. closed return is read-only
25. costs can be added by staff
26. linked issue appears on return order detail
27. linked return order appears on issue detail if Issues module exists
28. search finds tracking no
29. search finds original order no
30. search finds SKU / stock item code
31. filtering by status works
32. filtering by return type works
33. guest users are not treated as internal users

Run:

```bash
php artisan test tests/Feature/ReturnOrderTest.php
php artisan test
```

---

## Acceptance Criteria

- `Return Orders` appears in navigation.
- Tenants can pre-announce returns.
- Staff can create unannounced returns.
- Tracking no is searchable.
- Staff can receive returns without deciding disposition immediately.
- Staff can inspect lines and set condition.
- Staff can disposition each line independently.
- Inventory changes only happen during disposition.
- Inventory movement refs use `return_order`.
- 着払い amount can be captured.
- Return costs can be captured for future billing.
- Tenant scope is enforced.
- Tests pass.

---

## Future Phases

### Phase 2: Attachments / Photos

- upload parcel photos
- upload damaged item photos
- store evidence for tenant/courier claims

### Phase 3: Resend Automation

- disposition `resend_to_customer` creates outbound order
- disposition `return_to_tenant` creates outbound order
- disposition `forward_elsewhere` creates outbound order

### Phase 4: FBA Return / Removal Automation

- FBA removal import
- FBA shipment creation for resend_to_fba
- Amazon integration

### Phase 5: Billing

- inspection fee
- restocking fee
- freight collect fee
- disposal fee
- resend shipping fee
- tenant invoice integration

### Phase 6: Reporting

- return rate by tenant
- return rate by SKU
- return reason breakdown
- disposition breakdown
- cost reports
- expired/not-arrived returns
