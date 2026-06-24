# Task: Fulfillment Pack Check v1.4 - Scan Correction and Pack Audit UI

## Stack

Laravel 13, Livewire 4 class components, Flux UI, PHP 8.3, SQLite dev. Plain Blade views only. No TypeScript.

---

## Goal

Add a safe correction workflow to the fulfillment pack check page.

Current flow already supports:

- scan tracking no. to open a fulfillment group
- scan product barcode
- Normal mode quantity entry
- Strict mode one-scan-per-item
- high-risk strict-only lines
- mark shipped after all lines complete

Missing operational need:

> Staff will sometimes scan the wrong product, scan the right product too many times, or enter the wrong quantity. Before shipment is marked shipped, they need a safe way to undo accepted scan quantity with an audit trail.

This task adds:

1. visible scan history on the pack page
2. undo / void accepted scans before shipment
3. corrected progress calculation that excludes voided scans
4. audit-safe record keeping, no hard delete

---

## Key Rule

Do not delete scan records.

Corrections must be audit-friendly:

- original accepted scan stays in `fulfillment_pack_scans`
- voiding creates or marks correction metadata
- progress excludes voided accepted quantity

---

## Data Model

Update `fulfillment_pack_scans`.

Add columns:

```php
$table->timestamp('voided_at')->nullable()->after('quantity');
$table->foreignId('voided_by_user_id')->nullable()->after('voided_at')->constrained('users')->nullOnDelete();
$table->string('void_reason')->nullable()->after('voided_by_user_id');
```

Notes:

- `voided_at = null` means active scan.
- Only accepted scans should normally be voidable.
- Wrong item / over scan / blocked status rows are already audit events and should not need voiding in v1.4.

Model:

```text
FulfillmentPackScan fillable add:
- voided_at
- voided_by_user_id
- void_reason

casts add:
- voided_at => datetime
```

Relationships:

```php
voidedBy(): BelongsTo(User::class, 'voided_by_user_id')
```

---

## Progress Calculation

Update accepted scan quantity calculation.

Current logic sums accepted scan quantity.

Change to:

```text
result = accepted
voided_at is null
```

Wrong / over / blocked rows still do not count.
Voided accepted rows must not count.

---

## Pack Page UI

Page:

```text
/fulfillment-groups/{group}/pack
```

Add a compact `Recent scans` panel below the scan input / feedback area and above the pack lines table.

Show latest 10 scan records for the current fulfillment group.

Columns / content:

```text
Time
Result
SKU / Stock item
Barcode scanned
Qty
User
Action
```

Keep it compact. This page is an operations screen, not a reporting page.

### Result Labels

Use simple labels:

```text
Accepted
Wrong item
Over scan
Blocked
Voided
```

Color rule:

- Accepted: green
- Voided: gray
- Wrong / Over / Blocked: red or amber

Avoid too many colors.

### Undo Button

For scan rows where:

```text
result = accepted
voided_at is null
group.status = reserved
```

show:

```text
Undo
```

Button should be small and not visually louder than the main scan/ship controls.

When clicked:

- ask for confirmation with `wire:confirm`
- no modal required in v1.4

Suggested confirm copy:

```text
Undo this accepted scan? Packed quantity will be reduced.
```

Do not show Undo when:

- group is shipped
- group is cancelled
- scan is already voided
- scan result is not accepted

---

## Livewire Component Behavior

Component:

```text
App\Livewire\FulfillmentGroupPack
```

Add method:

```php
public function voidScan(int $scanId): void
```

Rules:

1. Internal user only.
2. Load scan through current group and allowed tenant scope.
3. Only allow when group status is `reserved`.
4. Only allow `result = accepted`.
5. Only allow if `voided_at` is null.
6. Set:

```php
voided_at = now()
voided_by_user_id = Auth::id()
void_reason = 'manual_pack_correction'
```

7. Re-render progress.
8. Dispatch `pack-scan-focus` after voiding.
9. Show success feedback:

```text
Scan undone.
```

If not allowed, show a clear error and do not change DB.

### Pending Quantity Interaction

If a pending quantity prompt exists and staff clicks Undo:

- keep behavior simple: cancel the pending quantity first, then void the scan
- or block undo until pending quantity is confirmed/cancelled

Recommended v1.4 behavior:

```text
Block undo while pending quantity exists.
Message: Confirm or cancel the pending quantity first.
```

Reason: avoids staff changing progress while a pending scan is waiting.

---

## Scan History Query

In `render()` or a helper, load recent scans:

```text
fulfillment_pack_scans
where fulfillment_group_id = current group
latest id first
limit 10
with sku, stockItem, scannedBy, voidedBy
```

Make sure no tenant leak:

- group is already loaded by allowed tenant ids
- scan query should be constrained to that group id

---

## Activity / Audit

The scan table itself is the audit trail.

Do not add activitylog entries in v1.4 unless existing pack scan actions already use activitylog.

Audit requirements:

- original barcode scanned remains visible
- original quantity remains visible
- voided time/user visible in DB
- progress excludes voided quantity

---

## Edge Cases

### Quantity Scan Undo

If accepted scan has `quantity = 3`, undo removes all 3 from progress.

Do not partially void quantity in v1.4.

If partial correction is needed, staff should:

1. undo the scan quantity 3
2. scan again and enter correct quantity

### Already Shipped

If group is shipped, no undo is allowed.

Packing audit becomes read-only after shipment.

### Over Scan Records

Do not undo over-scan / wrong-item rows.

They are warning audit records only and do not affect progress.

---

## Language Keys

Add to `lang/en/fulfillment_pack.php`:

```php
'recent_scans' => 'Recent scans',
'scan_result_accepted' => 'Accepted',
'scan_result_wrong_item' => 'Wrong item',
'scan_result_over_scan' => 'Over scan',
'scan_result_blocked_status' => 'Blocked',
'scan_voided' => 'Voided',
'undo_scan' => 'Undo',
'undo_scan_confirm' => 'Undo this accepted scan? Packed quantity will be reduced.',
'scan_undone' => 'Scan undone.',
'scan_cannot_undo' => 'This scan cannot be undone.',
'confirm_or_cancel_quantity_before_undo' => 'Confirm or cancel the pending quantity before undoing a scan.',
```

Use existing result constants if names differ.

---

## Tests

Add tests in:

```text
tests/Feature/FulfillmentGroupTest.php
```

Required tests:

1. recent scans panel shows accepted scan rows
2. accepted scan can be voided before shipment
3. voided accepted scan no longer counts toward progress
4. undo of quantity scan removes the whole quantity
5. cannot undo wrong item / over scan / blocked scan
6. cannot undo already voided scan
7. cannot undo scan after group shipped
8. cannot undo scan from another tenant/out-of-scope group
9. cannot undo while pending quantity prompt exists
10. voiding dispatches scanner focus event
11. scan history still shows voided row with voided state
12. after undo, staff can re-scan and complete the group

Run targeted tests only:

```bash
php artisan test tests/Feature/FulfillmentGroupTest.php
```

Do not run full suite unless there is a specific cross-module concern.

---

## Do Not Do In This Task

Do not add:

- partial void of a quantity scan
- manager approval workflow
- photo evidence
- pack station user assignment
- packed status
- separate pack audit report page
- export of scan history

Those can be later phases.

---

## Acceptance Criteria

- Staff can undo an accepted pack scan before shipment.
- Progress recalculates immediately after undo.
- No scan rows are deleted.
- Shipped/cancelled groups remain read-only.
- Audit history clearly shows what was scanned and what was voided.
- Tests cover the correction workflow.
