# Fulfillment Pick Summary v1

## Goal

Give warehouse staff a SKU-level picking summary before packing.

The pack station now works well for checking each order/group. Before staff reaches the pack station, they need to know:

- which SKU / stock item to pick
- total quantity needed
- which orders/groups need it
- current available quantity
- product barcode / alias hints
- warehouse location hint if available

This task builds a read-only pick summary page for reserved fulfillment groups.

## Scope

Build:

1. Pick Summary page under Fulfillment.
2. Filters by warehouse, shipping method, tenant, and date.
3. Summary grouped by stock item / SKU.
4. Links back to fulfillment groups / pack page.
5. Basic print-friendly view.

Do not build:

- location-level inventory deduction
- pick confirmation
- wave picking
- pick list assignment to staff
- packed vs shipped status split
- barcode coverage report
- inventory movements

## Route

Add:

```php
Route::get('/fulfillment/pick-summary', FulfillmentPickSummary::class)
    ->name('fulfillment.pick-summary');
```

Route must be inside authenticated group.

## Component

Create:

```text
app/Livewire/FulfillmentPickSummary.php
resources/views/livewire/fulfillment-pick-summary.blade.php
```

## Auth / Tenant Scope

For v1, this page is internal-user only.

Rules:

- guest gets 403
- tenant user gets 403
- internal user can see fulfillment groups for allowed tenants
- never trust tenant id from request

Use:

```php
Auth::user()?->user_type === 'internal'
```

Do not reintroduce guest-as-internal fallback.

## Data Source

Use fulfillment groups:

```text
fulfillment_groups.status = reserved
```

Filter by:

- `warehouse_id`
- `shipping_method_id`
- `tenant_id`
- created date range or planned/order date if available

For v1, date filter can use fulfillment group `created_at` if no better field exists.

Do not include:

- shipped groups
- cancelled groups

## Filters

Query-string filters:

- `warehouse_id`
- `shipping_method_id`
- `tenant_id`
- `date_from`
- `date_to`
- `q`

Search `q` matches:

- stock item code
- stock item name
- stock item short name
- SKU code
- SKU barcode
- stock item barcode
- fulfillment group reference
- sales order platform order id

Date range:

- inclusive date_from
- inclusive date_to through end of day

## Pick Line Aggregation

Aggregate fulfillment group lines into pick rows.

Normal SKU:

- group by `sku_id + stock_item_id`

Virtual bundle:

- explode bundle components
- group by `stock_item_id`
- `sku_id = null` is acceptable for component rows
- required qty = sales order line qty * component qty

Use existing `FulfillmentPackService::packLines()` logic where possible to avoid duplicating bundle rules.

Important:

- do not count cancelled/non-ready sales order lines
- only count lines that the pack page would require
- do not count already shipped/cancelled fulfillment groups

## Columns

Columns:

1. Stock item
2. SKU(s)
3. Product name
4. Barcode
5. Required qty
6. Available qty
7. Difference
8. Location hint
9. Groups / Orders
10. Actions

Details:

### Stock Item

Show:

- stock item code
- product type / strict-risk badges if applicable

### SKU(s)

Show:

- SKU code(s) that contribute to this pick row
- for virtual bundle component row, show "Bundle component"

### Product Name

Use:

1. stock item short name
2. stock item name
3. SKU name fallback

Use ellipsis for long names.

### Barcode

Show:

- stock item barcode or SKU barcode
- if barcode aliases exist, show small `+N aliases`

Do not expand all aliases in v1 unless simple.

### Required Qty

Total required quantity across filtered reserved fulfillment groups.

### Available Qty

Read from `inventory_balances` for selected warehouse.

Use:

```text
available = on_hand - reserved - hold - damaged
```

If no balance row exists, show 0.

### Difference

```text
available_qty - required_qty
```

Style:

- negative = red
- 0 to low = amber
- enough = normal/green

This is advisory only. Do not block anything in v1.

### Location Hint

The system has `warehouse_locations`, but inventory is still warehouse-level.

So in v1, location is only an operational hint.

Possible sources:

- latest inbound receipt location for stock item in this warehouse
- latest return disposition location
- if none, show `-`

Do not imply exact bin-level inventory.

Label the column as:

```text
Location hint
```

### Groups / Orders

Show:

- count of fulfillment groups
- count of sales orders
- optionally first few group references

Link:

- group reference links to fulfillment group detail or pack page

### Actions

Actions:

- View groups
- Pack first group

Keep actions secondary.

## Summary Cards

Show:

- Pick rows
- Required qty
- Shortage rows
- Groups included

Definitions:

- Pick rows = number of aggregated rows
- Required qty = sum required qty
- Shortage rows = rows where available qty < required qty
- Groups included = distinct fulfillment group count

## Print-Friendly View

Add a simple print style:

- hide navigation/buttons/filter controls when printing
- keep table readable
- show selected warehouse/shipping method/date filters

No PDF export in v1.

## Performance Notes

This page can aggregate many groups.

Keep v1 safe:

- require warehouse filter before showing results
- optionally require shipping method filter if query becomes heavy
- cap initial result to a reasonable date range if needed
- do not load all details for every group if not needed

If no warehouse is selected:

```text
Select a warehouse to view pick summary.
```

## Tests

Add targeted tests.

Required tests:

1. guest cannot access
2. tenant user cannot access
3. page asks for warehouse before showing results
4. reserved group appears in pick summary
5. shipped/cancelled groups are excluded
6. normal SKU qty aggregates correctly
7. virtual bundle component qty aggregates correctly
8. available qty is read from inventory balance
9. shortage row is detected when required qty > available qty
10. search matches stock item code/name
11. search matches SKU code
12. warehouse filter prevents other warehouse groups from appearing
13. summary cards reflect filtered rows

Run targeted fulfillment pick summary tests only.

Do not rerun full suite by default unless a broad regression concern appears.

## Acceptance Criteria

- Internal staff can see what to pick by SKU/stock item.
- Page is warehouse-scoped and tenant-safe.
- Reserved fulfillment groups only.
- Bundle components aggregate correctly.
- Available qty and shortage indicators are shown.
- Location is clearly labeled as a hint, not exact inventory.
- No inventory or fulfillment status changes.
- Targeted tests pass.
