# Task: Fulfillment Pack Check v1.1

## Stack

Laravel 13, Livewire 4 (class-based components, not Volt), Flux UI (`livewire/flux ^2.14`),
SQLite (dev), PHP 8.3. Plain Blade views only. No TypeScript.

---

## Goal

Improve Pack Check v1 so it better matches the real warehouse workflow:

1. tracking numbers are saved in one normalized format
2. staff selects warehouse and shipping method before scanning labels
3. tracking lookup searches a smaller, safer group of fulfillment records
4. product barcode matching handles multiple SKU lines that share the same physical stock item/barcode

This task builds on:

```text
docs/tasks/fulfillment-pack-check-v1.md
```

---

## Background

In the actual packing workflow, staff scans the barcode on a printed shipping label.
The scanned value should open the order / fulfillment group details on PC or smartphone.

Printed labels are usually handled by shipping method / courier batch:

- Yamato Nekopos
- Yamato TQB
- Sagawa THB
- other methods later

Therefore Pack Start should not search every fulfillment group blindly.
Staff should first select the current warehouse and shipping method, then scan the label.

---

## What v1.1 Covers

V1.1 includes:

- normalize tracking numbers before saving
- update every tracking-no write path to use the same normalizer
- Pack Start warehouse filter
- Pack Start shipping method filter
- require both filters before scanning, unless explicitly stated otherwise
- lookup only reserved fulfillment groups in selected warehouse/method
- prefer matching product lines with remaining qty before declaring over-scan
- tests

V1.1 does not include:

- separate `normalized_tracking_no` DB columns
- `fulfillment_tracking_refs` lookup table
- pack batch / wave management
- printed-label batch id
- per-order multi-package tracking support
- quantity input mode

Those can come later.

---

## Tracking Number Storage Rule

Do **not** add a separate `normalized_tracking_no` field in this phase.

Instead:

> Always save tracking numbers in normalized form.

Example:

```text
input:  1234-5678-9012
stored: 123456789012
```

This applies to every place that writes tracking numbers.

---

## Tracking Number Normalizer

Create one shared helper/service method.

Suggested location:

```text
App\Support\TrackingNumber
```

or, if there is already a good support/service place, use that.

Method:

```php
TrackingNumber::normalize(?string $value): ?string
```

Rules:

```text
trim
uppercase
remove spaces
remove hyphens
remove common separators
return null if empty after normalization
```

Separators to remove:

```text
space
hyphen -
underscore _
dot .
slash /
backslash \
colon :
semicolon ;
pipe |
```

Examples:

```text
1234-5678-9012 -> 123456789012
1234 5678 9012 -> 123456789012
  ab-123 cd  -> AB123CD
--- -> null
```

Do not use this normalizer for SKU / product barcode matching.
Product SKU codes may legitimately contain hyphens.

---

## Update Tracking Write Paths

Find all places that save tracking numbers and normalize before saving.

At minimum, check:

```text
SalesOrderIndex inline tracking update
SalesOrderDetail tracking update
FulfillmentGroupIndex tracking update
FulfillmentGroupDetail shipping/tracking update
Fulfillment tracking import
Sales order tracking import
Outbound ship form
Outbound create/edit if it stores tracking no
Marketplace shipping notice / courier import paths if they save tracking
```

Every write should save:

```php
'tracking_no' => TrackingNumber::normalize($input)
```

Do not store hyphenated values in DB after this task.

If a tracking number input normalizes to null:

- store null if the field is optional
- reject if the field is required in that context

---

## Display Tracking Numbers

V1.1 can display stored tracking numbers as-is.

Do not spend time reformatting into hyphenated display.

Later phase can add display formatting per courier.

---

## Pack Start UI

Page:

```text
/fulfillment/pack
```

Add filters above scan input:

```text
Warehouse
Shipping method
Scan tracking no.
```

Warehouse:

- dropdown
- active warehouses only
- if only one active warehouse, preselect it

Shipping method:

- dropdown
- active shipping methods only
- show method name/code
- preferably grouped or sorted by carrier/order

Scan input:

- disabled until warehouse and shipping method are selected
- auto-focus after both filters are selected
- label: `Scan tracking no.`

If staff tries to scan without selecting required filters:

```text
Please select warehouse and shipping method first.
```

---

## Pack Start Query

When scanning a tracking number, normalize the scanned value with `TrackingNumber::normalize()`.

If normalized scan is null:

```text
show not found / invalid scan
do not query
```

Lookup should only search:

```text
fulfillment_groups.status = reserved
fulfillment_groups.warehouse_id = selected warehouse
fulfillment_groups.shipping_method_id = selected shipping method
allowed tenant ids
```

Then match the normalized stored tracking number.

Check these fields:

```text
fulfillment_groups.tracking_no
fulfillment_group_orders.tracking_no
sales_orders.tracking_no
outbound_orders.tracking_no
```

Since all tracking numbers should now be stored normalized, comparisons can be direct DB comparisons where possible.

Do not load all fulfillment groups into PHP just to filter them.

Acceptable v1.1 query strategy:

- use `where()` / `whereHas()` / `orWhereHas()` with direct tracking no compare
- keep tenant/warehouse/method/status constraints applied to every branch

Important:

Avoid an `orWhere` that escapes tenant/warehouse/method/status scope.
Wrap OR conditions inside a closure.

Example shape:

```php
FulfillmentGroup::query()
    ->whereIn('tenant_id', $allowedTenantIds)
    ->where('status', FulfillmentGroup::STATUS_RESERVED)
    ->where('warehouse_id', $warehouseId)
    ->where('shipping_method_id', $shippingMethodId)
    ->where(function ($query) use ($trackingNo) {
        $query
            ->where('tracking_no', $trackingNo)
            ->orWhereHas('groupOrders', fn ($q) => $q->where('tracking_no', $trackingNo))
            ->orWhereHas('orders', fn ($q) => $q->where('tracking_no', $trackingNo))
            ->orWhereHas('outboundOrder', fn ($q) => $q->where('tracking_no', $trackingNo));
    })
```

If multiple groups match:

```text
show multiple matches error
do not guess
```

If no group matches:

```text
show no matching fulfillment group
```

---

## Shipping Method Mismatch

Because staff selects shipping method first, scanning a label from another method should not open the group.

Show a useful message:

```text
No matching fulfillment group found for this warehouse and shipping method.
Check the selected shipping method or scan the correct label.
```

Do not automatically switch shipping method based on the scanned label.

---

## Product Barcode Matching Fix

Fix the Pack Check v1 bug:

When multiple pack lines match the same scanned barcode, prefer a line with remaining quantity.

Current bad behavior:

```text
Line A matches barcode and is complete
Line B matches same barcode and is incomplete
Scan barcode again -> system picks Line A first -> over-scan
```

Correct behavior:

```text
Find all matching lines
If none -> wrong item
Pick first matching line where remaining_qty > 0
If found -> accept scan for that line
If all matching lines are complete -> over-scan
```

Pseudo-code:

```php
$matchedLines = collect($lines)
    ->filter(fn ($line) => $service->lineMatchesScan($line, $normalized))
    ->values();

if ($matchedLines->isEmpty()) {
    // wrong item
}

$matchedLine = $matchedLines->first(fn ($line) => $line['remaining_qty'] > 0);

if (! $matchedLine) {
    // over scan
}

// accept scan
```

Add a regression test:

- two sales order lines
- two SKUs
- same `stock_item_id`
- same `stock_items.barcode`
- scan the stock item barcode twice
- both required quantities become complete
- no over-scan is created

---

## Pack Start State Persistence

Warehouse and shipping method selections may be stored in the URL query string.

Suggested:

```text
?warehouse_id=1&shipping_method_id=2
```

This helps staff refresh page without losing station setup.

Do not store this as a global user preference in v1 unless already easy.

---

## Tests

Add / update tests.

Required:

1. `TrackingNumber::normalize()` removes hyphens/spaces/separators
2. normalizer returns null for blank/separator-only input
3. SalesOrder tracking write path stores normalized value
4. FulfillmentGroup tracking write path stores normalized value
5. tracking import stores normalized values
6. Pack Start requires warehouse and shipping method before scan
7. Pack Start preselects warehouse if only one active warehouse exists
8. Pack Start normalizes scanned tracking no before lookup
9. Pack Start finds group by `fulfillment_groups.tracking_no`
10. Pack Start finds group by `fulfillment_group_orders.tracking_no`
11. Pack Start finds group by `sales_orders.tracking_no`
12. Pack Start finds group by `outbound_orders.tracking_no`
13. Pack Start does not find group from a different warehouse
14. Pack Start does not find group from a different shipping method
15. Pack Start does not find shipped/cancelled group
16. Pack Start multiple matches still blocks and does not guess
17. query does not load all fulfillment groups into PHP for tracking lookup
18. product barcode matching prefers remaining line before over-scan
19. two SKUs sharing one stock item barcode can both be completed by scanning same barcode twice

Run:

```bash
php artisan test tests/Feature/FulfillmentGroupTest.php
```

Also run tracking-related tests:

```bash
php artisan test tests/Feature/SalesOrderTest.php tests/Feature/SalesOrderTrackingImportTest.php
```

If those files have been renamed/moved, run the current equivalent tracking import tests.

Before handoff:

```bash
php artisan test
```

---

## Acceptance Criteria

- DB stores tracking no without hyphens/spaces
- Pack Start requires warehouse + shipping method
- scanning label opens only matching reserved fulfillment group
- lookup no longer loads all fulfillment groups into PHP
- wrong warehouse / wrong shipping method does not match
- separator-only scan does not accidentally match blank tracking fields
- product scan matching handles shared stock item barcode correctly
- tests pass

---

## Future Phases

### Tracking lookup table

If tracking lookup needs to support many packages / many sources, add:

```text
fulfillment_tracking_refs
```

with:

```text
tenant_id
fulfillment_group_id
source_type
source_id
tracking_no
```

Since v1.1 stores normalized tracking directly, this table can be added later without changing the scanner UX.

### Packing status

Later add status:

```text
packing
```

Flow:

```text
reserved -> packing -> shipped
```

V1.1 can keep using `reserved` until shipped.

