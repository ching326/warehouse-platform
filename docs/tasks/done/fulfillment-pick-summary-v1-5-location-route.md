# Fulfillment Pick Summary v1.5 - Location Route Sorting

## Goal

Make Fulfillment Pick Summary useful as a real warehouse pick sheet by sorting and grouping rows by warehouse location.

The page currently aggregates required stock item quantities and shows a location hint, but rows are sorted mainly by stock item code. For warehouse work, staff usually want to walk the warehouse in location order.

This task keeps the page read-only and improves only the pick route / print layout.

## Current Behavior

Route:

```text
/fulfillment/pick-summary
```

Current logic:

- internal users only
- warehouse-local date filtering
- reserved fulfillment groups only
- aggregates SKU / stock item required qty
- shows `location_hint`
- screen table and print table exist

Current limitation:

- `location_hint` is displayed but not used as the main route order.
- print sheet is not optimized for walking through warehouse locations.

## Requirements

### 1. Sort pick rows by warehouse route

Sort rows in this order:

1. location hint present first
2. `location_hint` ascending
3. stock item code ascending
4. SKU code ascending as fallback

Rows without a location hint should appear at the bottom.

Suggested sort key:

```php
[
    $row['location_hint'] === '-' ? 1 : 0,
    $row['location_hint'] === '-' ? 'ZZZZZZ' : $row['location_hint'],
    $row['stock_item']?->code ?? '',
    implode(',', $row['sku_codes']),
]
```

Do not change how `location_hint` is calculated in this task.

### 2. Add location group headers in print view

In the printed pick sheet, group rows by `location_hint`.

Example:

```text
Location A-01
  STK-001 ...
  STK-002 ...

Location A-02
  STK-010 ...

No location
  STK-999 ...
```

The screen table can remain a normal table, but the print table should make location sections obvious.

### 3. Add screen grouping cue

On screen, add a subtle visual cue when the location changes.

Acceptable options:

- a small location label row before each new location group, or
- stronger top border on first row of each new location, or
- a compact badge in the location column

Keep it dense. Do not make the table card-heavy.

### 4. Rename location label

Current column can stay as `Location`, but avoid wording like `Location hint` in user-facing table headers.

Use:

```text
Location
```

Reason:

- Staff do not need to know it is derived from receipts/returns.
- It is operationally the best-known location.

Internal variable name can stay `location_hint` if changing it creates churn.

### 5. Add "No location" wording

Replace bare `-` display in UI/print with:

```text
No location
```

Only for display. Internal value can remain `-`.

### 6. Keep pickable stock logic unchanged

Do not change:

```php
pickable_qty = on_hand_qty - hold_qty - damaged_qty
```

Do not reintroduce `available_qty`.

### 7. Keep page read-only

Do not:

- update inventory
- mark picked
- mark packed
- mark shipped
- create movements
- modify fulfillment groups

## Files Likely Involved

- `app/Livewire/FulfillmentPickSummary.php`
- `resources/views/livewire/fulfillment-pick-summary.blade.php`
- `lang/en/fulfillment_pick.php`
- `tests/Feature/FulfillmentPickSummaryTest.php`

## Tests

Run targeted tests only:

```bash
php artisan test tests/Feature/FulfillmentPickSummaryTest.php
```

### Required tests

1. Rows sort by location before stock item code

Setup:

- row A: location `B-01`, stock item `STK-001`
- row B: location `A-01`, stock item `STK-999`

Expected order:

```text
A-01 row
B-01 row
```

2. Rows without location appear last

Setup:

- one row with location `A-01`
- one row with no location

Expected:

- `A-01` row appears before no-location row

3. Rows inside same location sort by stock item code

Setup:

- location `A-01`, stock item `STK-002`
- location `A-01`, stock item `STK-001`

Expected order:

```text
STK-001
STK-002
```

4. Print view includes location group labels

Expected:

- print table contains `Location A-01`
- print table contains `No location` for missing locations

5. User-facing column says `Location`

Expected:

- table header says `Location`
- does not show `Location hint`

6. Existing tests still pass

Especially:

- pickable stock tests
- warehouse timezone tests
- print context test
- virtual bundle aggregation test

## Acceptance Criteria

- Pick Summary rows are ordered like a warehouse walking route.
- Print sheet is grouped by location.
- Missing locations are clearly shown as `No location`.
- User-facing wording uses `Location`, not `Location hint`.
- Page remains read-only.
- Targeted Pick Summary tests pass.
