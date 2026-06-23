# Fulfillment Pick Summary v1.2 - Warehouse Workflow Polish

## Goal

Make the Fulfillment Pick Summary easier for warehouse staff to use during daily picking.

The current page can aggregate required SKU / stock item quantities and now correctly uses pickable stock. This task focuses on workflow polish:

- easy access from Fulfillment
- sensible default filters
- clearer printed pick sheet
- less visual noise
- safer links into pack work

Do not change inventory logic in this task.

## Current Page

Route:

```text
/fulfillment/pick-summary
```

Current behavior:

- internal users only
- requires warehouse filter before showing rows
- shows reserved fulfillment groups only
- aggregates SKU / stock item pick qty
- shows pickable qty and shortage difference

## Requirements

### 1. Add entry points

Add a visible entry point to Pick Summary from the Fulfillment area.

#### Fulfillment Group index

On `/fulfillment-groups`, add a button near the page actions:

```text
Pick Summary
```

Link to:

```text
/fulfillment/pick-summary
```

If the current Fulfillment Group index has warehouse / shipping / tenant filters selected, carry them into the pick summary URL where possible:

```text
/fulfillment/pick-summary?warehouse_id=...&shipping_method_id=...&tenant_id=...
```

Do not carry unrelated filters.

#### Navigation

If the top nav already has a Fulfillment menu, add `Pick Summary` under it.

Keep naming singular/plural consistent with the current app style.

### 2. Default warehouse behavior

If there is exactly one active warehouse, preselect it automatically.

If there are multiple active warehouses, keep current behavior:

- show empty page
- ask user to select a warehouse

Do not auto-select a warehouse when there are multiple choices.

### 3. Default date behavior

Pick Summary should default to today's reserved work only.

Default:

```text
date_from = today
date_to = today
```

Use app date handling consistently with the rest of the fulfillment pages.

Add a clear way to remove or change the date filter.

Rationale:

- Pick summary is a daily warehouse work surface.
- Old reserved groups should still be findable, but not clutter the default view.

### 4. Filter labels / chips

Show a compact active-filter row below the filters.

Examples:

```text
Warehouse: Tokyo
Date: 2026-06-24
Shipping: Yamato TQB
Tenant: ABC
```

Each chip should have an `x` remove action if it is safe to remove.

Warehouse chip:

- if multiple warehouses exist, allow clearing it
- if only one warehouse exists and was auto-selected, no need for `x`

Date chip:

- allow clearing or changing

### 5. Printed pick sheet improvements

The printed sheet should be useful on paper.

On print:

- hide navigation
- hide filters
- hide action buttons
- hide summary cards if they make the sheet too tall
- show title, warehouse, date range, shipping method, tenant, generated time

Table columns for print:

```text
Location
Stock item
SKU(s)
Product
Barcode
Pick qty
Notes
```

Do not print:

- pickable qty
- difference
- action buttons

Reason:

- printed pick sheet should tell staff what to pick, not show dashboard metrics.

### 6. Screen table improvements

For screen view, keep the current operational columns but improve readability:

- product name should use ellipsis if too long
- SKU list should not force the row too wide
- groups/orders column should show first few references and `+N more` if many
- shortage rows should be visually obvious but not too loud

Do not introduce horizontal overflow if avoidable.

### 7. Pack action behavior

The `Pack first group` action is useful but can be confusing when one pick row belongs to many groups.

Change the button label to:

```text
Pack first
```

Add a second action when there are multiple groups:

```text
View groups
```

`View groups` should link to Fulfillment Group index filtered by the relevant stock item / group references if available.

If a precise filter is not practical, keep the existing group-reference search behavior.

### 8. No inventory changes

Do not call `InventoryService`.

Do not create movements.

Do not mark anything picked, packed, shipped, or printed.

This page is read-only.

## Tests

Do not run the full suite by default.

Add or update targeted tests:

```bash
php artisan test tests/Feature/FulfillmentPickSummaryTest.php
```

### Required test cases

1. Internal user can access Pick Summary from the route.

2. Tenant user cannot access Pick Summary.

3. If exactly one active warehouse exists, the component auto-selects it.

4. If multiple active warehouses exist, no warehouse is auto-selected and the page asks the user to choose.

5. Default date filters are today.

6. Clearing / changing date filters updates visible rows correctly.

7. Fulfillment Group index has a Pick Summary link.

8. Pick Summary link carries selected warehouse / shipping / tenant filters where applicable.

9. Printed view contains warehouse / date / generated-time context.

10. Printed view does not include action buttons.

11. Rows with many group references render without overflowing the table.

12. Existing pickable-stock tests from v1.1 still pass.

## Acceptance Criteria

- Pick Summary is discoverable from Fulfillment.
- Daily default view is practical for warehouse picking.
- One-warehouse setups do not require an unnecessary warehouse click.
- Print output is a clean pick sheet.
- Page remains read-only.
- Targeted tests pass.
