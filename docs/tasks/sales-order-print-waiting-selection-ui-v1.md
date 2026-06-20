# Sales Orders Print Waiting + Selection UI v1

## Goal

Update the Sales Orders index so the default view stays focused on open work, without exposing a vague "Active orders" toggle.

Add a separate **Print Waiting** shortcut for orders that are ready to print/export courier labels, improve row selection usability, and give the Address column more space.

## Important Product Decision

Do not mix these two concepts:

- **Default open-work view**
  - Normal Sales Orders default view.
  - Hides closed/historical orders.
  - Still includes operational exceptions such as on hold, backorder, cancel requested, ship ready, unfulfilled.

- **Print Waiting**
  - A shortcut filter.
  - Only shows orders that are ready for courier CSV / label printing.

## Part A: Remove Active Orders UI, Keep Default Filtering

### Requirements

1. Remove the visible **Active orders** checkbox from the Sales Orders index UI.

2. Do not remove the underlying default open-work filtering.

3. The default Sales Orders index must still hide:
   - `fulfillment_status = shipped`
   - `fulfillment_status = cancelled`
   - `order_status = cancelled`
   - `order_status = completed`

4. The default Sales Orders index must still show:
   - `order_status = pending`
   - `order_status = on_hold`
   - `order_status = backorder`
   - `order_status = cancel_requested`
   - `fulfillment_status = unfulfilled`
   - `fulfillment_status = ready`
   - `fulfillment_status = in_group`, if currently useful in the operational list

5. Treat the default open-work filter as internal behavior, not a user toggle.

6. Do not expose a simple UI control that disables default open-work filtering.

7. Users should access shipped / cancelled / completed orders only through explicit status filters plus existing date-range protections.

### Implementation Notes

- Keep the existing internal `activeOnly` / open-work logic unless there is a very small safe rename.
- If renaming is easy, prefer a clearer internal name such as `openWorkOnly`.
- Do not delete the existing export/date-range guard that depends on the internal active/open-work flag.
- Do not regress the unbounded-export protection.
- It is OK for historical status filters to continue flipping the internal open-work flag as needed.
- Do not create a simple "show all orders" toggle.
- Filter-based export should follow the same default open-work behavior.
- Explicit selected-order export may still export selected rows if allowed by the existing export rules.

## Part B: Add Print Waiting Toggle

### UI

Add a toggle/checkbox labelled:

```text
Print Waiting
```

Optional helper text:

```text
Ship ready + not exported
```

### Filter Logic

When **Print Waiting** is ON, show only orders that are ready for courier CSV / label printing.

Required conditions:

- `order_status = pending`
- `fulfillment_status = ready`
- `sales_orders.courier_csv_exported_at IS NULL`

Also exclude closed/historical states as usual:

- shipped
- cancelled
- completed

### Interaction With Other Filters

- Print Waiting should work together with shop, platform/channel, search, shipping method, and date filters.
- If Print Waiting conflicts with explicit shipped/cancelled/completed filters, show no results rather than silently changing the user's filters.
- Do not auto-clear user filters unless existing app patterns already do this clearly.

### Implementation Notes

- Add Print Waiting to the shared Sales Order filtering path so the index and filter-based exports stay consistent.
- Suggested query-string parameter: `print_waiting=1`.
- Export CSV/XLSX links from the index should carry the same `print_waiting` filter so exported rows match the visible filtered view.
- Selected-order export by explicit IDs remains selected-ID based.

## Part C: Selection UX

### Select All Checkbox

Add a select-all checkbox in the table header.

Behavior:

- Clicking it selects all visible orders on the current page.
- Clicking it again clears all visible selected orders.
- It must not select orders from other pages.
- If simple to implement, show an indeterminate state when only some visible rows are selected.

### Larger Checkboxes

Make row checkboxes easier to click.

Requirements:

- Increase checkbox visual size.
- Add a larger click target around each checkbox.
- User should be able to click near the checkbox, not exactly inside the tiny input.
- Clicking the checkbox cell area should toggle that row.
- Do not make the whole row selectable.
- Do not toggle selection when user clicks:
  - order ID link
  - shipping method dropdown
  - tracking no input
  - action buttons
  - any other row control

### Bulk Bar

- Selected count must remain accurate.
- Select-all should sync correctly when user manually selects/deselects visible rows.

## Part D: Address Column Width

The Address column is currently too narrow.

Update the Sales Orders table layout so the Address column has more width and remains readable.

Requirements:

1. Give Address a wider column than Recipient, Status, Created, and checkbox columns.

2. Address should display:
   - postal code
   - state / city
   - address line 1
   - address line 2, if present

3. Avoid text overlap with neighbouring columns.

4. Long address lines should wrap cleanly.

5. The table should remain usable on:
   - large screens, e.g. 27 inch monitor
   - small screens, e.g. 12 inch laptop

6. If the table cannot fit on small screens, prefer horizontal scroll over overlapping text.

7. Keep the Order ID column clickable for opening detail.

### Suggested Width Direction

Use a dedicated Sales Orders table column layout instead of reusing movement-table sizing.

Suggested relative priority:

- checkbox: fixed small width
- order id: medium
- address: large
- recipient: medium
- items: large
- shipping method: medium
- tracking no: medium
- status: small/medium
- created: small/medium
- note: medium, if present

## Part E: Tests

Add/update tests for:

1. Active orders checkbox is no longer rendered.

2. Default Sales Orders index hides shipped orders.

3. Default Sales Orders index hides cancelled orders.

4. Default Sales Orders index hides completed orders.

5. Default Sales Orders index still shows on hold orders.

6. Default Sales Orders index still shows backorder orders.

7. Default Sales Orders index still shows cancel requested orders.

8. Default Sales Orders index still shows ship ready orders.

9. Print Waiting ON shows ready + pending + not-exported orders.

10. Print Waiting ON hides unfulfilled orders.

11. Print Waiting ON hides on hold orders.

12. Print Waiting ON hides backorder orders.

13. Print Waiting ON hides cancel requested orders.

14. Print Waiting ON hides shipped/cancelled/completed orders.

15. Print Waiting ON hides orders already courier-exported / printed.

16. Select-all selects all visible page orders.

17. Select-all does not select orders from another page.

18. Clicking checkbox cell toggles row selection.

19. Clicking row controls does not accidentally toggle selection.

20. Bulk selected count updates correctly.

21. Address cell renders postal code, state / city, and address line 1, plus address line 2 when present.

## Part F: Mark Shipped

Add **Mark Shipped** as a Sales Order workflow action.

This belongs in the same task because shipped orders should leave the default open-work view immediately after being marked shipped.

This task establishes the shipped convention:

- Mark Shipped sets `fulfillment_status = shipped`
- Mark Shipped sets `order_status = completed`
- Mark Shipped sets `shipped_at = now()`

Add a nullable `shipped_at` timestamp column to `sales_orders` if it does not already exist.

### Detail Page

1. Add a **Mark Shipped** button to the Sales Order Detail action area.

2. Show it only when the order is shippable:
   - `order_status = pending`
   - `fulfillment_status = ready`
   - not cancelled
   - not completed
   - not already shipped

3. When clicked:
   - set `fulfillment_status = shipped`
   - set `order_status = completed`
   - set `shipped_at = now()`
   - do not add inventory movement logic in this task unless current code already does it
   - log activity if SalesOrder activity logging already exists

4. Verify activity logging captures `fulfillment_status`, `order_status`, and `shipped_at` if SalesOrder already logs changes.

5. After marking shipped, the order should disappear from the default open-work Sales Orders index.

6. The order should still be findable through explicit shipped / historical filters with the existing date-range protections.

### Index Bulk Action

1. Add **Mark Shipped** to the selected-order action bar.

2. Use the same shippable predicate as the detail page:
   - `order_status = pending`
   - `fulfillment_status = ready`

3. It should update only selected shippable orders.

4. If some selected orders are not shippable:
   - skip them
   - show a clear flash message:
     - `X order(s) marked shipped. Y order(s) skipped.`

5. Do not mark these orders as shipped:
   - on hold
   - backorder
   - cancel requested
   - cancelled
   - completed
   - already shipped
   - unfulfilled
   - in group

6. After bulk Mark Shipped, updated orders should disappear from the default open-work view.

### Additional Tests

Add/update tests:

1. Detail page shows Mark Shipped for a ship-ready order.

2. Detail page hides Mark Shipped for an unfulfilled order.

3. Detail page hides Mark Shipped for on hold orders.

4. Detail page hides Mark Shipped for backorder orders.

5. Detail page hides Mark Shipped for cancel requested orders.

6. Detail page hides Mark Shipped for cancelled orders.

7. Detail page hides Mark Shipped for already shipped orders.

8. Mark Shipped sets `fulfillment_status = shipped`.

9. Mark Shipped sets `order_status = completed`.

10. Mark Shipped sets `shipped_at`.

11. Mark Shipped order disappears from default open-work index.

12. Mark Shipped order appears through explicit shipped / historical filter.

13. Bulk Mark Shipped updates selected shippable orders.

14. Bulk Mark Shipped skips non-shippable selected orders.

15. Bulk Mark Shipped flash message shows updated/skipped counts.

## Verification

Run:

```bash
php artisan test tests/Feature/SalesOrderTest.php
php artisan test
```

Also manually check:

- `/sales-orders` default view
- Print Waiting ON/OFF
- select-all behavior
- row checkbox click target
- table readability on a wide screen
- table readability on a narrow screen
- address column does not overlap neighbouring columns and long lines wrap cleanly
