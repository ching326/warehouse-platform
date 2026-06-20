# Task: Fix unbounded Sales Orders export bypass

## Context

This is a Laravel 13 + Livewire 4 warehouse app. The Sales Orders index has a CSV/XLSX export
endpoint. The export is meant to never run an unbounded query over the full order history, because at
high order volume that materializes a huge result set into one file (memory/DB risk).

The index view defaults to "All dates + active orders only" and auto-switches to a 30-day window when
a user selects a historical status. The export endpoint has guards that are supposed to mirror this.
There is a gap: a hand-crafted export URL can bypass the guard and export the entire history.

This is a defensive hardening fix. The endpoint is authenticated and tenant-scoped, so this is not a
data-leak; the risk is performance (exporting all historical orders at once). The normal UI never
produces the bypassing combination, but the export is a plain GET URL that can be crafted by hand.

Use plain ASCII punctuation only in code and any text you add. No em-dashes, smart quotes, or arrows.

---

## The bug

Two pieces must line up.

### 1. The export guard

File: `app/Http/Controllers/SalesOrderExportController.php`

Current guard (inside `__invoke`, after building `$filters`):

```php
if (! $hasOrderIdFilter) {
    if (SalesOrderFilters::dateRangeError($filters)) {
        abort(422, __('sales_orders.date_range_too_wide'));
    }

    if (SalesOrderFilters::hasHistoricalStatus($filters) && $filters['date_range'] === SalesOrderFilters::DATE_ALL) {
        abort(422, __('sales_orders.export_requires_date_range'));
    }
}
```

`$hasOrderIdFilter` is true when explicit selected order ids are passed (the `ids=` query param).
Selected-id exports are intentionally exempt from the broad guards.

### 2. The active-only constraint

File: `app/Support/SalesOrderFilters.php`, method `applyToOrderQuery`

The active-only constraint is only applied when there is no status filter AND `active_only` is true:

```php
if (($filters['fulfillment'] ?? []) === [] && ($filters['order_status'] ?? []) === [] && ($filters['active_only'] ?? true)) {
    self::applyActiveOnly($query);
}
```

`applyActiveOnly` excludes historical orders:

```php
private static function applyActiveOnly(Builder $query): void
{
    $query
        ->whereNotIn('order_status', [
            SalesOrder::ORDER_STATUS_COMPLETED,
            SalesOrder::ORDER_STATUS_CANCELLED,
        ])
        ->whereNotIn('fulfillment_status', [
            SalesOrder::FULFILLMENT_STATUS_SHIPPED,
            SalesOrder::FULFILLMENT_STATUS_CANCELLED,
        ]);
}
```

### The bypass

Request the export with `?active_only=0` and `date_range=all` and NO status filter
(no `fulfillment`, no `order_status`, no `ids`). Then:

- `hasHistoricalStatus($filters)` is false, because no status was explicitly selected, so guard #2
  does not fire.
- `active_only` is false, so `applyActiveOnly()` is skipped.
- `date_range = all`, so no date bound is applied.

Result: the query has no date bound and no status/active constraint, so it exports the tenant's entire
order history including all shipped/completed/cancelled orders across all time.

---

## Required rule

An "all dates" filter-based export is only allowed if the result set cannot include historical orders.

The result set CAN include historical orders when either:

1. an explicit status filter names a historical status (already handled by `hasHistoricalStatus`), OR
2. there is no status filter AND `active_only` is false (this is the gap: nothing limits the rows).

The result set CANNOT include historical orders when:

- `active_only` is true with no status filter (the default backlog view), OR
- the explicit status filter only contains active statuses.

So: block an all-dates filter-based export when it "includes historical orders" by the definition
above. Selected-id exports (`ids=`) remain exempt.

Historical statuses are:

- `fulfillment_status`: `shipped`, `cancelled`
- `order_status`: `completed`, `cancelled`

(`hasHistoricalStatus` already encodes this; reuse it.)

---

## Fix

### Step 1: add a helper to `SalesOrderFilters`

Put the rule next to the filter logic so it can be unit-tested directly. Add this public static method
to `app/Support/SalesOrderFilters.php`:

```php
/**
 * True when a filter-based export must require an explicit date range,
 * i.e. an all-dates export that could include historical (shipped/completed/cancelled) orders.
 *
 * @param array<string,mixed> $filters
 */
public static function requiresExplicitDateRange(array $filters): bool
{
    if (($filters['date_range'] ?? self::DATE_ALL) !== self::DATE_ALL) {
        return false;
    }

    $hasStatusFilter = ($filters['fulfillment'] ?? []) !== [] || ($filters['order_status'] ?? []) !== [];

    $includesHistorical = self::hasHistoricalStatus($filters)
        || (! $hasStatusFilter && ! ($filters['active_only'] ?? true));

    return $includesHistorical;
}
```

### Step 2: use it in the export controller

Replace the existing historical guard block in
`app/Http/Controllers/SalesOrderExportController.php` with:

```php
if (! $hasOrderIdFilter) {
    if (SalesOrderFilters::dateRangeError($filters)) {
        abort(422, __('sales_orders.date_range_too_wide'));
    }

    if (SalesOrderFilters::requiresExplicitDateRange($filters)) {
        abort(422, __('sales_orders.export_requires_date_range'));
    }
}
```

This preserves existing behavior (historical status + all dates is still blocked) and additionally
blocks the `active_only=0` + no status + all dates bypass. It still allows:

- the default active backlog all-dates export (active_only on, no status),
- an explicit active-only status filter (e.g. order_status=pending) + all dates,
- any export with an explicit non-all date range,
- selected-id exports.

The lang key `sales_orders.export_requires_date_range` already exists. If for some reason it does not,
add it to `lang/en/sales_orders.php` with a clear message such as:
`'export_requires_date_range' => 'Choose a date range to export shipped, completed, or cancelled orders.'`

---

## Tests

Add to `tests/Feature/SalesOrderExportTest.php`. Match the existing test style in that file (it uses
`RefreshDatabase`, an internal user helper, and `route('sales.orders.export', [...])`).

1. `test_export_blocks_active_only_off_with_no_status_and_all_dates`
   - Seed at least one shipped or completed order for a tenant.
   - As an internal user, GET `route('sales.orders.export', ['active_only' => '0', 'date_range' => 'all'])`.
   - Assert HTTP 422.

2. `test_export_allows_default_active_all_dates`
   - Seed an active (e.g. pending/unfulfilled) order.
   - GET `route('sales.orders.export')` with no special params (active-only default, all dates).
   - Assert HTTP 200 (export succeeds).

3. `test_export_still_blocks_historical_status_with_all_dates`
   - GET export with `order_status=completed` (or `fulfillment=shipped`) and `date_range=all`.
   - Assert HTTP 422. (Regression guard for the existing behavior.)

4. `test_export_allows_selected_ids_regardless_of_date_range`
   - Seed a shipped order, pass its id via `ids=` with `date_range=all`.
   - Assert HTTP 200. (Selected-id exports stay exempt.)

If helpful, also add a direct unit test of the helper:

5. `test_requires_explicit_date_range_rule`
   - Assert `SalesOrderFilters::requiresExplicitDateRange([...])` returns true/false for the four
     combinations above.

---

## How to run tests

```bash
php artisan test tests/Feature/SalesOrderExportTest.php
```

If `php` is not on PATH (this project uses Laragon):

```bash
C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan test tests/Feature/SalesOrderExportTest.php
```

Then run the full suite to make sure nothing else broke:

```bash
php artisan test
```

---

## Constraints

- Do not change selected-id export behavior (`ids=` must stay exempt from the broad guards).
- Do not weaken the existing v4 order-id export guard (empty `ids=` must not export everything as
  "selected").
- Do not change index/Livewire behavior; this is an export-endpoint hardening only.
- Reuse `SalesOrderFilters::hasHistoricalStatus`; do not duplicate the historical-status list.
- Keep all added text/code in plain ASCII punctuation.
