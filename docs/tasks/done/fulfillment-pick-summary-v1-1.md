# Fulfillment Pick Summary v1.1 - Reserved Stock Availability Fix

## Goal

Fix the Fulfillment Pick Summary stock calculation so reserved fulfillment groups do not incorrectly appear as stock shortages.

The current pick summary aggregates required SKU / stock item quantities from reserved fulfillment groups, but compares them against `InventoryBalance::available_qty`.

That is misleading because `available_qty` excludes reserved stock. Fulfillment groups shown on this page are already reserved, so their stock has already moved from `available_qty` into `reserved_qty`.

Example:

- Warehouse has 5 units on hand.
- A fulfillment group reserves 5 units.
- `available_qty` becomes 0.
- Pick Summary currently shows required 5, available 0, difference -5.
- That is wrong for pick work. The stock is physically there and reserved for picking.

## Required Behavior

### 1. Replace "available" basis with "pickable" basis

For pick summary rows, show stock that can physically be picked for reserved fulfillment groups.

Use this formula:

```php
pickable_qty = on_hand_qty - hold_qty - damaged_qty
```

Do not subtract `reserved_qty` for this page.

Reason:

- Reserved stock is exactly the stock this page is trying to pick.
- Hold and damaged stock should not be pickable.

### 2. Rename display wording

The current column/key may be called `available_qty`.

For this page, change the user-facing label to something clearer:

- `Pickable`

Keep internal variable names clean. Prefer renaming the component row key from `available_qty` to `pickable_qty` if practical.

If renaming internally creates too much churn, at minimum change the label and tests so the UI meaning is correct.

### 3. Difference calculation

Update difference calculation:

```php
difference = pickable_qty - required_qty
```

Shortage row means:

```php
pickable_qty < required_qty
```

### 4. Keep reserved groups only

Do not change the group scope:

- include only `FulfillmentGroup::STATUS_RESERVED`
- exclude shipped / cancelled / closed groups

### 5. Keep warehouse-level inventory behavior

This project does not currently keep inventory balances per warehouse location.

Location hint remains informational only.

Do not try to make pick summary location-level inventory in this task.

## Files Likely Involved

- `app/Livewire/FulfillmentPickSummary.php`
- `resources/views/livewire/fulfillment-pick-summary.blade.php`
- `lang/en/fulfillment_pick.php`
- `tests/Feature/FulfillmentPickSummaryTest.php`

## Tests

Add or update targeted tests only.

### Required tests

1. Reserved stock is counted as pickable

Setup:

- Stock item has `on_hand_qty = 5`
- Fulfillment group reserves 5 units
- Therefore `available_qty = 0`, `reserved_qty = 5`

Expected:

- Pick summary shows required qty 5
- Pickable qty 5
- Difference 0
- Shortage rows 0

2. Hold stock is not pickable

Setup:

- Stock item has `on_hand_qty = 5`
- `hold_qty = 2`
- required qty 5

Expected:

- Pickable qty 3
- Difference -2
- Shortage row detected

3. Damaged stock is not pickable

Setup:

- Stock item has `on_hand_qty = 5`
- `damaged_qty = 1`
- required qty 5

Expected:

- Pickable qty 4
- Difference -1
- Shortage row detected

4. Existing virtual bundle aggregation still works

Do not break the existing virtual bundle component quantity aggregation test.

## Important Notes

- Do not run the full test suite by default unless needed.
- Run the targeted pick summary test file after changes:

```bash
php artisan test tests/Feature/FulfillmentPickSummaryTest.php
```

## Acceptance Criteria

- Pick Summary no longer reports shortages only because stock is reserved.
- User-facing column says `Pickable`, not `Available`.
- Difference and shortage count use pickable stock.
- Targeted tests pass.
