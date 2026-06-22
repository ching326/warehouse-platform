# Task: Fulfillment Pack Check v1

## Stack

Laravel 13, Livewire 4 (class-based components, not Volt), Flux UI (`livewire/flux ^2.14`),
SQLite (dev), PHP 8.3. Plain Blade views only. No TypeScript.

---

## Goal

Add a packing verification flow for warehouse staff.

Staff should be able to:

1. scan a shipping label tracking number or enter an order/reference number
2. open the matching fulfillment group packing screen
3. scan product barcodes one by one
4. verify the correct SKU and quantity before shipping
5. mark the fulfillment group shipped only after all required items are scanned

This is the warehouse-side check to prevent:

- wrong item packed
- missing item
- too many items packed
- shipping the wrong order

V1 is intentionally strict and simple:

**Every unit must be scanned one by one.**

Do not build quantity-entry mode in v1.

---

## What v1 Covers

V1 includes:

- pack-check start page / scan input
- fulfillment group pack page
- tracking no / order reference lookup
- product barcode scan input
- per-line required qty / scanned qty / remaining qty
- wrong-barcode warning
- over-scan warning
- all-complete guard before mark shipped
- mark shipped from pack page
- scan audit records
- tenant scoping
- tests

V1 does not include:

- quantity input mode
- per-SKU strict/normal mode setting
- high-risk SKU setting
- mobile camera barcode SDK
- weighing
- box size measurement
- label printing
- wave picking
- multi-pack station assignment
- shortage workflow
- partial shipment from pack page

Leave those for future phases.

---

## Routes

Add routes:

```text
GET /fulfillment/pack
GET /fulfillment-groups/{group}/pack
```

Names:

```text
fulfillment.pack.start
fulfillment-groups.pack
```

Components:

```text
App\Livewire\FulfillmentPackStart
App\Livewire\FulfillmentGroupPack
```

Route placement:

- inside existing auth group
- only warehouse/internal users should access
- tenant users should not access pack check unless you intentionally allow tenant warehouse operation later

---

## User Flow

### Step 1: Start pack check

Page:

```text
/fulfillment/pack
```

UI:

- one large scan input
- auto focus on page load
- label: `Scan tracking no. or order no.`
- helper text: `Scan a shipping label barcode, fulfillment reference, or platform order ID.`

Staff scans:

- shipping label tracking no
- fulfillment group reference no
- sales order platform order id
- outbound order ref if linked

When found:

- redirect to `/fulfillment-groups/{group}/pack`

When not found:

- show red error
- keep input focused

### Step 2: Pack page

Page:

```text
/fulfillment-groups/{group}/pack
```

Display:

- fulfillment group reference no
- recipient name
- tracking no
- shipping method
- order count
- item count
- status

Main scan input:

- auto focus
- label: `Scan product barcode`
- Enter submits scan

Item table:

```text
SKU
Stock item
Barcode
Product name / short name
Required qty
Scanned qty
Remaining qty
Status
```

Status:

```text
Not started
In progress
Complete
```

All lines complete:

- enable `Mark shipped`

Not all complete:

- disable `Mark shipped`
- show message: `Scan all required items before shipping.`

---

## Tracking Number / Order Lookup

The barcode reader behaves like keyboard input.
No special hardware integration is needed.

However, tracking no matching needs normalization.

### `normalizeTrackingNo()`

Add a helper method/service:

```text
trim
uppercase
remove spaces
remove hyphens
remove common separators
```

Examples:

```text
1234-5678-9012 -> 123456789012
1234 5678 9012 -> 123456789012
```

Do not use this normalization for product SKU code matching.
Product SKU codes may legitimately contain hyphens.

### Lookup order

When scanning on `/fulfillment/pack`, try to match in this order:

1. `fulfillment_groups.reference_no`
2. `fulfillment_group_orders.tracking_no`
3. `sales_orders.tracking_no`
4. `sales_orders.platform_order_id`
5. linked `outbound_orders.ref`

Tracking fields should compare using normalized values.

Reference/order id fields should compare exact value and trimmed value.

If multiple fulfillment groups match:

- do not guess
- show `Multiple matches found. Please search by fulfillment reference.`

If group status is shipped:

- show `This fulfillment group is already shipped.`
- allow view read-only pack history if implemented
- do not allow new scans

If group status is cancelled:

- block pack check

---

## Product Barcode Matching

Product scan input should match:

1. `skus.barcode`
2. `stock_items.barcode`
3. optionally `skus.sku` as fallback

Use normalized product barcode:

```text
trim
remove leading/trailing spaces
```

Do not remove hyphens globally for product matching.

Reason:

- SKU codes may include hyphens
- platform SKU values may include hyphens

If needed later, add a separate normalized barcode column.

### Matching rules

For a scan:

1. find matching pack line
2. if no line matches, reject scan
3. if line already complete, reject scan as over-scan
4. otherwise increment scanned qty by 1
5. write scan audit record

Wrong scan message:

```text
Barcode does not match this shipment.
```

Over-scan message:

```text
This item is already fully scanned.
```

Successful scan:

```text
Scanned {sku}
```

Use visual feedback:

- success: green
- warning/error: red

Optional:

- browser beep on error
- keep this simple; no dependency required

---

## Data Model

Add scan audit table.

### `fulfillment_pack_scans`

```text
id
tenant_id
fulfillment_group_id
fulfillment_group_order_id nullable
sales_order_id nullable
sku_id nullable
stock_item_id nullable
barcode_scanned
normalized_barcode
result
message
scanned_by_user_id
created_at
```

No `updated_at`.

Allowed `result` values:

```text
accepted
wrong_item
over_scan
not_found
blocked_status
```

Indexes:

```text
tenant_id + fulfillment_group_id
fulfillment_group_id + created_at
barcode_scanned
scanned_by_user_id + created_at
```

Foreign keys:

```text
tenant_id -> tenants.id restrictOnDelete
fulfillment_group_id -> fulfillment_groups.id cascadeOnDelete
fulfillment_group_order_id -> fulfillment_group_orders.id nullOnDelete
sales_order_id -> sales_orders.id nullOnDelete
sku_id -> skus.id nullOnDelete
stock_item_id -> stock_items.id nullOnDelete
scanned_by_user_id -> users.id nullOnDelete
```

### Why store scan rows?

This gives audit history:

- who scanned
- what barcode was scanned
- whether it was accepted
- wrong item attempts
- over-scan attempts
- time of packing

---

## Scan Progress Storage

V1 should not rely only on Livewire component state.

The system must survive refresh.

Use `fulfillment_pack_scans` accepted rows to calculate scanned qty.

For each required pack line:

```text
scanned_qty = count accepted scans matching that SKU/stock item in this fulfillment group
remaining_qty = required_qty - scanned_qty
```

This means:

- refresh does not lose progress
- audit log is the source of truth
- no separate scanned_qty column is needed in v1

If performance becomes an issue later, add a cached progress table.

---

## Pack Lines

Build pack lines from fulfillment group member orders.

Use ready lines only:

```text
sales_order_lines.line_status = ready
```

Group by:

```text
sku_id
stock_item_id
```

Required qty:

```text
sum(quantity)
```

For virtual bundle:

- pack actual component stock items, not the virtual bundle SKU itself
- use existing bundle component logic already used for fulfillment reservation
- each component required qty = sales line qty x component qty

If this is too risky for v1, explicitly block pack check for virtual bundle lines and show a clear message.

Preferred:

**Use component stock item lines**, because warehouse staff pack physical goods.

---

## Mark Shipped

Add `Mark shipped` button on pack page.

Guard:

- fulfillment group status must be reserved
- all pack lines must be complete
- current user must be allowed to access tenant

When clicked:

1. re-load fulfillment group with lock
2. re-calculate pack progress from accepted scans
3. if incomplete, reject
4. mark related outbound order shipped using existing shipping logic/service
5. mark fulfillment group shipped
6. update member sales orders as shipped via existing observer/back-write if present
7. record activity log

Important:

Do not duplicate outbound shipping logic inside the component if a service already exists.

If `ShipOutboundOrderService` exists, use it.
If not, extract the existing `OutboundOrderShip` shipping logic into a service first.

---

## Permissions / Tenant Scope

Use the existing tenant visibility pattern, but do not introduce guest-as-internal fallback.

Rules:

- guest cannot access
- tenant user cannot access pack check in v1
- internal / warehouse staff can access groups in allowed tenants
- every lookup must scope to allowed tenant ids

Do not rely on route model binding alone.

For `/fulfillment-groups/{group}/pack`, re-query:

```php
FulfillmentGroup::whereIn('tenant_id', $this->allowedTenantIds())->findOrFail($group->id)
```

---

## UI Details

### Scan input behavior

- auto focus on load
- auto focus after each scan
- clear input after submit
- submit on Enter
- large enough for scanner / keyboard use
- no debounce; scanner submits full value quickly

### Feedback area

Show last scan result above the line table.

Success:

```text
Scanned ABC-SKU-001
```

Error:

```text
Barcode does not match this shipment.
```

Use the global toast only for page-level actions.
For scan feedback, use an inline fixed area so table does not jump.

### Completion

When all lines complete:

- show green `Ready to ship` state
- enable mark shipped button

### Wrong item

Wrong scans should be very visible:

- red message
- optional beep
- do not increment any qty

---

## Navigation

Add entry points:

- Fulfillment index row action: `Pack`
- Fulfillment detail page: `Pack Check`
- Main nav can later add `Pack` under Fulfillment, but not required in v1

The Pack button should only show for:

```text
status = reserved
```

For shipped groups:

- show `View pack history` later
- not required in v1

---

## Language Keys

Add language keys under:

```text
lang/en/fulfillment_pack.php
```

Stub other locales with:

```php
return [];
```

Rely on fallback locale.

Required keys:

```text
page_title
start_page_title
scan_tracking_placeholder
scan_product_placeholder
wrong_item
over_scan
not_found
already_shipped
cancelled_group
multiple_matches
scan_all_before_shipping
ready_to_ship
mark_shipped
scanned_message
pack_complete
required_qty
scanned_qty
remaining_qty
```

---

## Tests

Add feature tests.

Required tests:

1. guest cannot access pack start
2. tenant user cannot access pack start in v1
3. internal user can open pack start
4. scan fulfillment reference redirects to pack page
5. scan tracking no redirects to pack page
6. tracking no normalization matches hyphen/no-hyphen formats
7. scan unknown tracking no shows error
8. multiple tracking matches shows error and does not guess
9. tenant-scoped lookup does not find another tenant's group
10. pack page shows required SKU lines and qty
11. scan matching `skus.barcode` increments scanned qty
12. scan matching `stock_items.barcode` increments scanned qty
13. scan wrong barcode creates `wrong_item` scan and does not increment qty
14. scan completed item again creates `over_scan` and does not increment qty
15. refresh/re-render keeps scanned progress from `fulfillment_pack_scans`
16. mark shipped is disabled/rejected before all lines complete
17. mark shipped succeeds after all lines complete
18. mark shipped uses existing outbound shipping service / movement logic
19. shipped group cannot accept new scans
20. cancelled group cannot accept scans
21. virtual bundle group creates component pack lines, or is explicitly blocked if deferred
22. scan audit records user id and barcode

Run:

```bash
php artisan test tests/Feature/FulfillmentGroupTest.php
```

Also run the relevant outbound/sales tests:

```bash
php artisan test tests/Feature/OutboundOrderTest.php tests/Feature/SalesOrderTest.php
```

Before handoff, run:

```bash
php artisan test
```

---

## Acceptance Criteria

- staff can scan tracking no and open the correct pack page
- staff can scan product barcode one unit at a time
- wrong product scans are rejected
- over-scans are rejected
- scan progress survives page refresh
- mark shipped is blocked until all items are scanned
- mark shipped works after all items are scanned
- tenant scope is enforced
- scan audit records are stored
- tests pass

---

## Future Phases

### v2: Quantity input mode

Allow staff to scan once and enter qty.

Only for low-risk SKUs.

### v3: Per-SKU strict flag

Add SKU/stock item setting:

```text
requires_unit_scan
```

If true:

- every unit must be scanned
- qty input disabled

### v4: Shortage handling

Allow:

- mark shortage
- partial ship
- hold remaining
- notify tenant

### v5: Mobile camera scanner

Use camera-based barcode scanner for phones.

V1 should work with normal barcode reader keyboard input only.

