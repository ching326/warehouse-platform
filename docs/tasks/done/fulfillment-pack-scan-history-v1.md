# Fulfillment Pack Scan History v1

## Goal

Make packing scan activity visible and auditable.

The pack/check workflow already writes rows to `fulfillment_pack_scans` for accepted scans, wrong item scans, over-scans, and blocked-status scans. This task turns those records into useful operator/admin views so staff can answer:

- Who packed this fulfillment group?
- What barcodes were scanned?
- Was there any wrong item or over-scan during packing?
- When was each scan made?
- Which SKU / stock item did the scan match?

This is a read-only audit feature. It must not change packing quantities, inventory, fulfillment status, or shipment status.

## Scope

Build:

1. A global Pack Scan History page.
2. A scan history section on Fulfillment Group detail.
3. Links from pack screen / fulfillment group detail into the history.
4. Tests for tenant scope, filters, and result display.

Do not build:

- scan editing
- scan deletion
- packed-vs-shipped status split
- barcode coverage report
- export CSV
- dashboards/charts

## Existing Data

Use existing table/model:

- `fulfillment_pack_scans`
- `App\Models\FulfillmentPackScan`

Important columns:

- `tenant_id`
- `fulfillment_group_id`
- `sales_order_id`
- `sku_id`
- `stock_item_id`
- `barcode_scanned`
- `normalized_barcode`
- `result`
- `quantity`
- `message`
- `scanned_by_user_id`
- `created_at`

Important result values:

- `accepted`
- `wrong_item`
- `over_scan`
- `not_found`
- `blocked_status`

## Routes

Add:

```php
Route::get('/fulfillment/pack-scans', FulfillmentPackScanIndex::class)
    ->name('fulfillment.pack-scans.index');
```

Route must be inside the authenticated route group.

## Livewire Component

Create:

```text
app/Livewire/FulfillmentPackScanIndex.php
resources/views/livewire/fulfillment-pack-scan-index.blade.php
```

## Tenant Scope

Use the same tenant-scope pattern as fulfillment pages.

Rules:

- internal users can see all allowed tenant scan records
- tenant users can only see their own active tenant scan records
- guests are not internal
- if allowed tenant ids are empty, show no results / abort where appropriate

Do not trust tenant id from the request.

## Query

Base query:

```php
FulfillmentPackScan::query()
    ->with([
        'tenant',
        'fulfillmentGroup',
        'salesOrder',
        'sku',
        'stockItem',
        'scannedBy',
    ])
    ->whereIn('tenant_id', $this->allowedTenantIds())
    ->orderByDesc('created_at')
    ->orderByDesc('id')
```

If `tenant()` relation is missing on `FulfillmentPackScan`, add it.

Use pagination. Do not load all scans at once.

Recommended page size: 50.

## Filters

Support query-string filters:

- `tenant_id`
- `fulfillment_group_id`
- `result`
- `scanned_by_user_id`
- `date_from`
- `date_to`
- `q`

Search `q` should match:

- fulfillment group `reference_no`
- sales order `platform_order_id`
- SKU code
- stock item code
- stock item name
- `barcode_scanned`
- `normalized_barcode`
- `message`

Date range:

- `date_from` inclusive
- `date_to` inclusive through the end of the selected date

## Summary Cards

Show filtered totals:

- Filtered scans
- Accepted quantity
- Exceptions
- Latest scan

Definitions:

- Accepted quantity = sum of `quantity` where `result = accepted`
- Exceptions = count where `result != accepted`
- Latest scan = latest `created_at` date/time from filtered query

These totals should reflect filters, not just the current page.

## Table Columns

Columns:

1. Time
2. Fulfillment group
3. Sales order
4. Result
5. Barcode
6. Matched item
7. Qty
8. User
9. Message

Details:

- Fulfillment group: show `reference_no`; link to fulfillment group detail.
- Sales order: show `platform_order_id`; link to sales order detail if available.
- Result badge:
  - accepted = green
  - wrong_item / over_scan = red
  - blocked_status / not_found = amber or muted
- Barcode: show scanned barcode, and normalized barcode below only if different.
- Matched item:
  - prefer SKU code if `sku_id` exists
  - show stock item code/name below if available
  - for component-only scans, stock item alone is OK
- Qty:
  - show `quantity`
  - for accepted normal-mode quantity scans, quantity may be > 1
- User:
  - show user name if available
  - show `-` if null

Keep columns readable. Use ellipsis for long product names/messages.

## Fulfillment Group Detail Section

On fulfillment group detail page, add a read-only "Pack Scan History" section.

Show latest 10 scan rows for that group:

- time
- result badge
- barcode
- matched item
- qty
- user

Add link:

```text
View all scan history
```

Link to:

```text
/fulfillment/pack-scans?fulfillment_group_id={id}
```

Do not show this section if there are no scan records yet, or show a compact empty state:

```text
No pack scans yet.
```

## Pack Page Link

On `/fulfillment-groups/{group}/pack`, add a small link/button near the page actions:

```text
Scan History
```

It should open:

```text
/fulfillment/pack-scans?fulfillment_group_id={id}
```

Keep it secondary; do not make it the primary action.

## UI Style

Use the existing operational table style.

Keep this page dense and readable:

- no hero section
- no marketing copy
- no oversized cards
- no horizontal overflow on normal desktop width
- use compact badges

## Tests

Add tests to `tests/Feature/FulfillmentGroupTest.php` or a new focused test file.

Required tests:

1. internal user can view pack scan history
2. tenant user can only see own tenant scans
3. tenant user cannot see another tenant scan via `fulfillment_group_id`
4. `fulfillment_group_id` query filter works
5. `result` filter works
6. search matches barcode
7. search matches fulfillment group reference number
8. search matches SKU / stock item
9. date range filter works
10. summary cards reflect filtered query, not only current page
11. fulfillment group detail shows latest scan history
12. pack page has a Scan History link
13. result badges render accepted and exception states

Run targeted tests for the new/changed feature.

Do not rerun the full suite by default unless a broad regression concern appears.

## Acceptance Criteria

- `/fulfillment/pack-scans` exists and is read-only.
- Page is tenant-scoped.
- Page supports filters, search, summaries, and pagination.
- Fulfillment group detail shows recent scan history.
- Pack page links to scan history.
- No inventory/status/shipping behavior changes.
- Targeted tests pass.

