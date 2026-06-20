# Sales Orders Filter Toolbar + Filter Chips v1

## Goal

Improve the Sales Orders index filter UI so it works more like the old order-manage system:

- Date filter sits in the main top filter row with platform/shop/status filters.
- Top filter controls only show short filter labels, not current selected values.
- Applied filters are shown as removable filter chips/labels.
- Removing a chip removes that filter.
- Add an **Others** filter menu with operational shortcuts:
  - multi-item / multi-qty orders
  - printed
  - not printed

## Dependency

This task depends on:

```text
docs/tasks/sales-order-print-waiting-selection-ui-v1.md
```

That task must implement Print Waiting as a real shared Sales Order filter before this task wires:

- Print Waiting chips
- Print Waiting / Printed conflict behavior
- `print_waiting` query string handling
- export links carrying `print_waiting`

If Print Waiting is not implemented yet, do that task first.

Old system references:

- `C:\laragon\www\order-manage\amzorder\index.php`
  - `order_date_filter`
  - `other_filter`
  - `More than 1 item`
  - `Printed`
  - `Not Printed`

- `C:\laragon\www\order-manage\amzorder\js\order.js`
  - `refreshFilter()`
  - selected filter tags/chips
  - `.set-filters-remove`
  - `other_filter_item`

## Current Problem

The current Sales Orders toolbar uses wide controls such as:

- `All platforms`
- `All shops`
- `All fulfillment`
- `All statuses`

This wastes width and limits how many filters can fit on one row.

The user also has to inspect each dropdown to know which filters are active. The old system solved this with filter labels/chips below the toolbar.

## Part A: Top Filter Row Layout

Move the date filter into the same top filter area as the other filters.

Top filters should include:

- Platform / Channel
- Shop
- Fulfillment status
- Order status
- Shipping method
- Order date
- Others
- Global search

### Labels

For top filter controls, show only the filter category label, not the current value.

Examples:

- `Platform`
- `Shop`
- `Fulfillment`
- `Order Status`
- `Shipping`
- `Order Date`
- `Others`

Do not show:

- `All platforms`
- `All shops`
- `All fulfillment`
- `All statuses`

The selected values should be shown as filter chips instead.

### Width

The goal is to fit more filters into the top toolbar.

Use compact controls:

- smaller fixed-width buttons/dropdowns for filter categories
- global search can stay wider
- avoid long placeholder/current-value text in filter buttons

## Part B: Applied Filter Chips

Add an applied-filter chip row below the top toolbar.

Each active filter should render a chip.

Examples:

```text
Platform: Amazon  x
Shop: ABC amazon JP  x
Fulfillment: Ship Ready  x
Order Status: Backorder  x
Shipping: Yamato  x
Order Date: Last 30 days  x
Others: Multi-line / Multi-qty  x
Others: Not Printed  x
Search: Tanaka  x
Print Waiting  x
```

### Chip Behavior

1. Clicking the `x` removes that specific filter only.

2. If a filter has multiple selected values, prefer one chip per value.

3. Removing one value should keep the other selected values.

4. If a date custom range is active, show one chip:

```text
Order Date: 2026-06-01 to 2026-06-20  x
```

5. If only date from is active:

```text
Order Date: From 2026-06-01  x
```

6. If only date to is active:

```text
Order Date: To 2026-06-20  x
```

7. Removing any Order Date chip should reset the date filter to the internal default:

```text
date_range = all
date_from = ''
date_to = ''
```

Do not reset to `last_30_days` just because **All time** is no longer visible in the menu.

8. If no filters are active, the chip row may be hidden.

9. Default open-work filtering should not need a chip unless the UI already communicates it elsewhere.

10. Print Waiting should show a chip when enabled.

## Part C: Others Filter

Add an **Others** filter menu at the right side of the top filter row.

It should include:

1. Multi-item order
2. Printed
3. Not printed

### Naming

Use this label:

```text
Multi-item order
```

Meaning:

- orders with more than one SKU line, OR
- orders with one SKU line but quantity greater than 1

This is clearer than "More than 1" and better for warehouse users.

Optional helper text inside menu:

```text
Multiple SKUs or quantity greater than 1
```

### Multi-item Filter Logic

When enabled, show orders where:

- visible ready line count > 1, OR
- any visible ready line quantity > 1

Scope this filter to lines where:

```text
sales_order_lines.line_status = ready
```

Reason:

- The Sales Orders index displays ready lines as the operational item set.
- Cancelled/non-ready lines should not make an order look like a multi-item order if the operator only sees one ready line.

Implementation options:

- `whereHas('lines', quantity > 1)` OR
- `withCount('lines')` / subquery count > 1

Make sure pagination is not broken by joins.

Prefer `whereExists` / subqueries over joining lines directly if joins would duplicate orders.

### Printed / Not Printed Logic

Printed:

- `courier_csv_exported_at IS NOT NULL`

Not Printed:

- `courier_csv_exported_at IS NULL`

Printed and Not Printed are mutually exclusive.

If user selects Printed, remove Not Printed.

If user selects Not Printed, remove Printed.

### Interaction With Print Waiting

Print Waiting already implies:

- `fulfillment_status = ready`
- `order_status = pending`
- `courier_csv_exported_at IS NULL`

If Print Waiting is ON and user selects Printed:

- the shared filter must safely produce zero rows.

Preferred UI behavior:

- disable Printed while Print Waiting is ON
- show a tooltip/helper: `Printed orders cannot be in Print Waiting.`

The server-side/shared filter must still be safe if a raw URL sends both:

```text
print_waiting=1&others=printed
```

This should naturally become:

```text
courier_csv_exported_at IS NULL
AND courier_csv_exported_at IS NOT NULL
```

Result: zero rows, not a silent filter mutation.

## Part D: Date Filter

Move Order Date into the top filter row.

Available presets should remain compact:

- Today
- Last 3 days
- Last 7 days
- Last 30 days
- Last 3 months
- Last 1 year
- Custom

`Today` is new work.

Add:

- `DATE_TODAY` constant
- `dateRanges()` entry
- `dateWindow()` case using today start/end
- label/lang key
- tests

The visible date menu should not expose an unrestricted **All time** option.

Important:

- Keep `DATE_ALL` internally.
- Keep `date_range = all` as the implicit default for the open-work view.
- Do not change the default value in a way that breaks the current open-work default query.
- Existing date-range/export protections must remain intact.
- Removing the Order Date chip resets back to internal `DATE_ALL`, even though **All time** is not shown as a selectable menu option.

Custom date should use `YYYY-MM-DD`.

Date chips should show the selected value.

Do not allow historical all-time queries that bypass existing date-range protections.

## Part E: Global Search

Keep the existing global search.

Search should remain a direct input, not hidden inside a dropdown.

The applied chip should show only when search is not empty:

```text
Search: {query}  x
```

Removing the chip clears the search.

## Part F: Query String / State

Filters should stay shareable via query string where the current app already supports it.

Add query string support for new filters:

- `others[]` or `others=multi_item,not_printed`
- exact naming can follow current project conventions

Suggested values:

- `multi_item`
- `printed`
- `not_printed`

Keep existing filters compatible:

- platform
- shop
- fulfillment
- order_status
- shipping
- date_range
- date_from
- date_to
- search / q
- print_waiting

Note:

- `print_waiting` must be normalized by the shared SalesOrderFilters logic before this task depends on it.
- If `SalesOrderFilters::normalize()` currently drops `print_waiting`, fix that in the Print Waiting task first.

## Part G: Export Consistency

Filter-based CSV/XLSX export should match the currently filtered visible order set.

Therefore export links must carry:

- platform
- shop
- fulfillment
- order status
- shipping method
- date range
- custom date values
- global search
- print waiting
- others filters

Selected-order export by explicit IDs remains selected-ID based.

## Part H: UI Details

### Filter Buttons

Use compact filter buttons/dropdowns:

```text
Platform v
Shop v
Fulfillment v
Order Status v
Shipping v
Order Date v
Others v
```

Do not put selected values inside these buttons.

### Active State

If a filter has active values, visually mark the button as active.

Examples:

- slightly teal border
- soft teal background
- small dot

Do not rely only on the chips, because users should still see which filter categories are active.

### Chips

Chips should be compact and easy to remove.

Use a clear `x` icon or small close button.

Do not make chips too tall; this is an operational table page.

Implementation note:

- Reuse existing value label helpers where possible, such as the current SalesOrderIndex filter label/value formatter.
- Avoid creating a second parallel label map if one already exists.
- Existing `All ...` lang keys may become unused after removing selected values from filter buttons; clean them up only if they are truly no longer referenced.

## Part I: Tests

Add/update tests for:

1. Top filters render category labels only.

2. Top filters do not render `All platforms`, `All shops`, `All fulfillment`, or `All statuses` as button text.

3. Order Date filter is rendered in the top filter row.

4. Applied platform filter renders a removable chip.

5. Applied shop filter renders a removable chip.

6. Applied fulfillment filter renders a removable chip.

7. Applied order status filter renders a removable chip.

8. Applied shipping filter renders a removable chip.

9. Applied date filter renders a removable chip.

10. Applied search renders a removable chip.

11. Removing a chip clears only that filter.

12. Others menu renders Multi-item order, Printed, and Not Printed.

13. Multi-item order filter includes orders with multiple SKU lines.

14. Multi-item order filter includes orders with one line and quantity greater than 1.

15. Multi-item order filter excludes orders with one line and quantity 1.

16. Multi-item order filter excludes an order with one ready line and one cancelled/non-ready line.

17. Printed filter shows orders with `courier_csv_exported_at IS NOT NULL`.

18. Not Printed filter shows orders with `courier_csv_exported_at IS NULL`.

19. Printed and Not Printed are mutually exclusive.

20. Print Waiting plus Printed conflict returns zero rows server-side.

21. Today date preset filters to today's orders.

22. Export links include Others filter params.

23. Existing date-range protections still pass.

24. Existing Sales Orders index tests still pass.

## Manual Checks

Check in browser:

- top toolbar fits on a wide screen
- top toolbar remains usable on a 12 inch screen
- chips wrap cleanly without covering the table
- removing each chip updates results
- Others -> Multi-item order filters correctly
- Others -> Printed / Not Printed filters correctly
- export uses the same filtered results

## Verification

Run:

```bash
php artisan test tests/Feature/SalesOrderTest.php
php artisan test tests/Feature/SalesOrderExportTest.php
php artisan test
```
