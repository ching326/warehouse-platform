# Task: Fulfillment Pack Check v1.2

## Stack

Laravel 13, Livewire 4 (class-based components, not Volt), Flux UI (`livewire/flux ^2.14`),
SQLite (dev), PHP 8.3. Plain Blade views only. No TypeScript.

---

## Goal

Fix the remaining Pack Check v1.1 data migration gap, then add the next small step for operational use.

This task has two parts:

1. backfill existing tracking numbers into the new normalized storage format
2. improve Pack Check usability after the core lookup/scanning logic is working

Build on:

```text
docs/tasks/fulfillment-pack-check-v1.md
docs/tasks/fulfillment-pack-check-v1-1.md
```

---

## Part A: Backfill Existing Tracking Numbers

### Problem

Commit `4372130` normalized new tracking number writes using:

```text
App\Support\TrackingNumber::normalize()
```

The Pack Start lookup now compares scanned normalized tracking numbers directly against DB values.

That works for newly saved tracking numbers.

But existing DB rows may still contain hyphenated or spaced tracking numbers:

```text
1234-5678-9012
1234 5678 9012
```

A scanned label usually returns:

```text
123456789012
```

Without backfill, old records will not match.

### Required Fix

Add a backfill action and migration to normalize existing tracking numbers.

Suggested action:

```text
App\Actions\BackfillNormalizedTrackingNumbers
```

The action should normalize tracking number columns using:

```php
TrackingNumber::normalize()
```

Tables / columns to backfill:

```text
fulfillment_groups.tracking_no
fulfillment_group_orders.tracking_no
sales_orders.tracking_no
outbound_orders.tracking_no
return_orders.tracking_no
```

Only update rows where:

```text
tracking_no is not null
tracking_no != normalized value
```

If normalization returns null:

- set the field to null

Use query builder / chunking, not Livewire logic.

Do not use model events.

Reason:

- migration replay should be deterministic
- avoids side effects
- avoids activity log spam

### Migration

Add a migration after Pack Check v1.1 migrations.

Example name:

```text
2026_06_23_000001_backfill_normalized_tracking_numbers.php
```

Migration `up()`:

```php
app(BackfillNormalizedTrackingNumbers::class)->handle();
```

Migration `down()`:

- no-op
- do not try to restore hyphens

### Tests

Add tests:

1. backfill normalizes fulfillment group tracking no
2. backfill normalizes fulfillment group order tracking no
3. backfill normalizes sales order tracking no
4. backfill normalizes outbound order tracking no
5. backfill normalizes return order tracking no
6. backfill converts separator-only tracking no to null
7. Pack Start can find an old hyphenated tracking no after backfill

Use direct DB inserts/updates in tests to simulate old data.

---

## Part B: Pack Check Operational Polish

### 1. Add Pack action visibility

Ensure the `Pack` button appears on:

```text
/fulfillment-groups
/fulfillment-groups/{group}
```

Only show for:

```text
status = reserved
```

For shipped/cancelled groups:

- do not show Pack button
- or show disabled `Packed/Shipped` state

### 2. Keep station filters in URL

Pack Start should keep:

```text
warehouse_id
shipping_method_id
```

in query string.

Example:

```text
/fulfillment/pack?warehouse_id=1&shipping_method_id=2
```

This lets staff refresh page without resetting station setup.

If this already exists, add tests confirming it.

### 3. Improve scan focus

After:

- selecting warehouse
- selecting shipping method
- successful scan
- failed scan

the scan input should stay focused or refocus.

Add browser-friendly Livewire dispatch if needed:

```text
pack-scan-focus
```

### 4. Better wrong-station message

When scan does not match selected warehouse/shipping method, message should be clear:

```text
No matching fulfillment group found for this warehouse and shipping method.
Check the selected shipping method or scan the correct label.
```

Do not show generic `not found` if filters are selected.

### 5. Add recent scan feedback

On Pack Start page, after a failed scan:

- keep the scanned value visible in a small line

Example:

```text
Last scan: 123456789012
```

This helps staff know what the scanner sent.

Do not store this in DB.

### 6. Make Pack page scan result area stable

The scan feedback area on pack page must not push table rows down.

Use fixed/min height for the message area.

If already done, keep it and add visual/test coverage only if practical.

---

## Part C: Do Not Do Yet

Do not add:

- normalized tracking columns
- fulfillment tracking refs table
- packing status
- quantity input mode
- high-risk SKU flags
- camera scanner
- pack batch / wave feature

Those are later phases.

---

## Tests

Run targeted:

```bash
php artisan test tests/Feature/FulfillmentGroupTest.php
```

Also run:

```bash
php artisan test tests/Feature/OutboundOrderTest.php tests/Feature/SalesOrderTest.php
```

Before handoff:

```bash
php artisan test
```

---

## Acceptance Criteria

- existing hyphenated tracking numbers are normalized by migration/action
- Pack Start can find records created before the normalize-on-save change
- separator-only tracking numbers become null
- station filters stay in URL
- Pack buttons only show where packing is allowed
- scan input remains usable for repeated scanner operation
- tests pass

