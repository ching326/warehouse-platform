# Task: Fulfillment Pack Check v1.3 - Quantity Entry and Strict Scan Mode

## Stack

Laravel 13, Livewire 4 class components, Flux UI, PHP 8.3, SQLite dev. Plain Blade views only. No TypeScript.

---

## Goal

Improve the existing fulfillment pack check flow so warehouse staff can pack faster for normal orders while keeping strict per-piece barcode control for higher-risk items.

Current state:

- `/fulfillment/pack` lets staff select warehouse + shipping method, then scan a tracking no. / label to open a fulfillment group.
- `/fulfillment-groups/{group}/pack` shows required SKU lines and accepts product barcode scans.
- Current behavior is effectively strict mode: every accepted scan adds quantity `1`.
- When all lines are complete, staff can mark the fulfillment group shipped.

This task adds:

1. Normal scan mode: scan once, then enter quantity.
2. Strict scan mode: every physical item must be scanned one by one.
3. High-risk item guard: some lines always require strict scan, even if the page is in normal mode.
4. Better scan feedback and tests around quantity handling.

---

## Product Rules

### Normal Pack Check

Use for ordinary low-risk goods.

Flow:

1. Staff scans a product barcode.
2. If exactly one pack line matches and still has remaining quantity, show a small quantity entry control.
3. Default quantity is `1`.
4. Maximum quantity is that line's remaining quantity.
5. Staff can confirm quantity, e.g. enter `3` for a line requiring 3 units.
6. System creates accepted scan records equal to the confirmed quantity, or one scan record with `quantity = 3` if you add quantity support to the scan table.

Recommendation for v1.3:

- Prefer adding `quantity` to `fulfillment_pack_scans`.
- Existing rows should default to `1`.
- `acceptedScanCount()` must sum `quantity`, not count rows.

### Strict Barcode Check

Use for expensive, regulated, easy-to-mix, or complaint-prone goods.

Flow:

1. Staff scans every physical unit.
2. Manual quantity entry is not allowed.
3. Each accepted scan adds exactly `1`.
4. If required qty is 3, staff must scan 3 times.

### High-Risk Line Rule

A line is strict-only if its stock item is high-risk.

For v1.3, compute high-risk from existing stock item fields. Do not add new DB fields unless absolutely needed.

Treat a line as high-risk when any of these are true:

```text
stock_items.is_dangerous_goods = true
stock_items.requires_expiry_tracking = true
stock_items.requires_lot_tracking = true
stock_items.product_type in: food, is_battery, with_battery
```

If the project has slightly different product type slugs, use the existing slugs from product type settings.

High-risk behavior:

- normal page mode does not override high-risk line strictness
- quantity input must be disabled/skipped for high-risk lines
- show a compact label: `Strict scan`

---

## UI Requirements

Page:

```text
/fulfillment-groups/{group}/pack
```

### 1. Add Pack Mode Control

Add a compact segmented control near the barcode input:

```text
Normal | Strict
```

Default:

```text
Normal
```

Persist only in component state for now.
Do not save user preference in v1.3.

Label copy:

```text
Pack mode
Normal
Strict
```

### 2. Line Display

Each line should show:

```text
SKU code
barcode
product name / short name
required qty
scanned qty
remaining qty
status
strict label when strict-only
```

Keep the table dense. Do not make rows card-like.

### 3. Normal Mode Quantity Entry

When a scan matches a normal non-strict line with remaining qty > 1:

Show a small inline confirmation area near the scan input or feedback area:

```text
Scanned: ABC-SKU-001
Remaining: 3
Quantity: [ 1 ] [Add]
```

Rules:

- min = 1
- max = remaining qty
- integer only
- default = 1
- pressing Enter confirms
- Escape / Cancel clears pending quantity
- after confirm, focus returns to barcode input

If remaining qty is exactly 1:

- accept immediately with quantity 1
- no quantity prompt needed

### 4. Strict Mode Behavior

When page mode is Strict:

- never show quantity prompt
- every accepted scan quantity = 1

When line is high-risk:

- never show quantity prompt even in Normal mode
- every accepted scan quantity = 1

### 5. Wrong Item / Over Scan

Keep current behavior, with this important rule:

- when a barcode matches multiple lines, prefer a matching line with `remaining_qty > 0`
- only declare over-scan after all matching lines are already complete

This was already fixed in an earlier task. Do not regress it.

### 6. Completion

When all lines are complete:

- keep existing `Mark shipped` behavior
- button remains disabled until all lines complete
- if a pending quantity prompt exists, staff must confirm or cancel it before shipping

Do not add a new `packed` status in v1.3.

---

## Data Model

### fulfillment_pack_scans

Add nullable/defaulted quantity column:

```php
$table->unsignedInteger('quantity')->default(1)->after('result');
```

Backfill existing rows automatically through default value.

Model:

```text
FulfillmentPackScan::$fillable add quantity
casts quantity => integer
```

Validation:

- quantity must be >= 1
- rejected/wrong/over/block scan records should still use quantity = 1 unless there is a strong reason otherwise
- accepted scans in normal mode may use quantity > 1

### Count Logic

Update accepted scan count:

Current likely behavior:

```php
->count()
```

Change to:

```php
->sum('quantity')
```

Only accepted scans count toward progress:

```text
result = accepted
```

Wrong item / over scan / blocked status do not count.

---

## Livewire Component Behavior

Component:

```text
App\Livewire\FulfillmentGroupPack
```

Add public state:

```php
public string $packMode = 'normal'; // normal | strict
public ?array $pendingQuantityScan = null;
public int $pendingQuantity = 1;
```

Suggested pending payload:

```php
[
    'line_key' => '...',
    'sku_id' => 123|null,
    'stock_item_id' => 456|null,
    'display' => 'ABC-SKU-001',
    'barcode_scanned' => '...',
    'normalized_barcode' => '...',
    'remaining_qty' => 3,
    'strict_only' => false,
]
```

Methods:

```php
public function scan(FulfillmentPackService $service): void
public function confirmPendingQuantity(): void
public function cancelPendingQuantity(): void
public function updatedPackMode(): void
```

Rules:

- if staff scans a new barcode while `pendingQuantityScan` exists, cancel the previous pending quantity and process the new scan
- when pending quantity is confirmed, write one accepted scan with that quantity
- clamp/reject quantity greater than remaining qty
- after confirm/cancel, dispatch `pack-scan-focus`

---

## Service Behavior

Service:

```text
App\Services\Fulfillment\FulfillmentPackService
```

Add helpers if useful:

```php
public function lineIsStrictOnly(array $line): bool
public function acceptedScanQuantity(FulfillmentGroup $group, array $line): int
```

`packLinesWithProgress()` should include:

```text
strict_only: bool
scanned_qty: summed accepted quantity
remaining_qty: required - scanned
```

Do not duplicate line matching logic in the component if a service helper is cleaner.

---

## Activity / Audit

Existing `fulfillment_pack_scans` is the audit record.

For quantity > 1 accepted scans, store:

```text
quantity = entered qty
barcode_scanned
normalized_barcode
sku_id
stock_item_id
scanned_by_user_id
```

No separate activity log is required in v1.3.

---

## Permissions / Scope

Keep current behavior:

- internal users only
- tenant users cannot access pack check pages
- no guest-as-internal fallback
- group lookup must be scoped by allowed tenant ids

Do not weaken tenant scoping.

---

## Tests

Add or update tests in:

```text
tests/Feature/FulfillmentGroupTest.php
```

Required tests:

1. existing strict scan behavior still works with default quantity = 1
2. accepted scan progress sums `quantity`, not row count
3. normal mode scan with remaining qty > 1 shows pending quantity prompt
4. confirming pending quantity adds that quantity to scanned qty
5. pending quantity cannot exceed remaining qty
6. remaining qty = 1 accepts immediately without prompt
7. strict mode never shows quantity prompt and adds only 1
8. high-risk stock item in normal mode still requires strict scan
9. wrong item scan records quantity 1 and does not affect progress
10. over-scan records quantity 1 and does not affect progress
11. matching barcode shared by multiple lines still prefers a line with remaining qty > 0
12. cannot mark shipped while a pending quantity prompt exists
13. can mark shipped after all lines complete using a quantity > 1 accepted scan
14. tenant/guest users still cannot access pack pages

Run:

```bash
php artisan test tests/Feature/FulfillmentGroupTest.php
```

Also run related outbound/sales tests if shipping behavior is touched:

```bash
php artisan test tests/Feature/OutboundOrderTest.php tests/Feature/SalesOrderTest.php
```

---

## Do Not Do In This Task

Do not add:

- camera scanning
- mobile-specific PWA behavior
- wave picking / batch packing
- box size measurement
- packed status
- shipping label printing
- inventory location deduction
- lot/expiry capture
- user preference persistence for pack mode

Those are later phases.

---

## Acceptance Criteria

- Staff can pack ordinary multi-qty lines faster by scanning once and entering qty.
- Strict mode still supports one-scan-per-item control.
- High-risk lines cannot use manual quantity entry.
- All pack progress uses summed accepted scan quantity.
- Existing scan audit trail remains useful.
- Mark shipped remains blocked until all required quantities are scanned.
- Tests pass.
