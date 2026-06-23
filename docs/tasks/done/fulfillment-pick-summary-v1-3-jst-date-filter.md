# Fulfillment Pick Summary v1.3 - JST Date Filtering

## Goal

Fix Fulfillment Pick Summary date behavior so the daily pick sheet uses Japan warehouse dates, not the Laravel app timezone.

The Laravel app is configured as UTC:

```php
'timezone' => 'UTC'
```

But warehouse picking is a Japan operation. The Pick Summary default date and date filters should be based on `Asia/Tokyo`.

Without this fix, the page can show the wrong day around JST midnight.

Example:

- Current time: `2026-06-24 00:30 JST`
- UTC date is still `2026-06-23`
- Current code using `today()` can default to `2026-06-23`
- Warehouse staff expect `2026-06-24`

## Current Problem

Current implementation uses:

```php
today()->toDateString()
```

and filters with:

```php
whereDate('created_at', '>=', ...)
whereDate('created_at', '<=', ...)
```

Both are UTC/app-timezone based.

For a Japan daily picking workflow, date strings in the UI should mean JST calendar days.

## Requirements

### 1. Default date uses JST

In `FulfillmentPickSummary::mount()`, default `dateFrom` and `dateTo` to today's date in `Asia/Tokyo`.

Use:

```php
now('Asia/Tokyo')->toDateString()
```

or a small helper method, e.g.

```php
private function warehouseTimezone(): string
{
    return 'Asia/Tokyo';
}
```

Prefer a helper if the timezone is used in more than one place.

### 2. Date filter interprets input dates as JST days

When user selects:

```text
date_from = 2026-06-24
date_to = 2026-06-24
```

the query should include records from:

```text
2026-06-24 00:00:00 Asia/Tokyo
to
2026-06-24 23:59:59.999999 Asia/Tokyo
```

converted to UTC for database comparison.

Do not use `whereDate()` for this page.

Use timestamp range comparisons instead:

```php
$fromUtc = Carbon::parse($this->dateFrom, 'Asia/Tokyo')
    ->startOfDay()
    ->utc();

$toUtc = Carbon::parse($this->dateTo, 'Asia/Tokyo')
    ->endOfDay()
    ->utc();

$query->where('created_at', '>=', $fromUtc);
$query->where('created_at', '<=', $toUtc);
```

### 3. Generated time uses JST

The print heading currently shows generated time.

Display generated time in JST:

```php
now('Asia/Tokyo')->format('Y-m-d H:i')
```

This should match the operator's local warehouse day.

### 4. Filter summary uses the same date values

The filter summary can continue showing the selected date strings.

No need to show timezone text in every chip, but the behavior must be documented by tests.

### 5. Keep scope unchanged

Do not change:

- internal-only access
- reserved-only group filter
- warehouse filter requirement
- pickable stock logic
- print sheet columns

## Files Likely Involved

- `app/Livewire/FulfillmentPickSummary.php`
- `tests/Feature/FulfillmentPickSummaryTest.php`

Possibly:

- `lang/en/fulfillment_pick.php` only if wording needs a small clarification.

## Tests

Run targeted tests only:

```bash
php artisan test tests/Feature/FulfillmentPickSummaryTest.php
```

### Required tests

1. Default date uses JST, not UTC

Freeze time to:

```text
2026-06-24 00:30:00 Asia/Tokyo
```

Expected:

```text
dateFrom = 2026-06-24
dateTo = 2026-06-24
```

This test should fail if the code uses UTC `today()`.

2. JST date filter includes records created after JST midnight

Create a reserved fulfillment group with database `created_at` equivalent to:

```text
2026-06-24 00:30 Asia/Tokyo
```

That is:

```text
2026-06-23 15:30 UTC
```

Set filter:

```text
date_from = 2026-06-24
date_to = 2026-06-24
```

Expected:

- group is shown

3. JST date filter excludes records from previous JST day

Create another reserved group at:

```text
2026-06-23 23:30 Asia/Tokyo
```

That is:

```text
2026-06-23 14:30 UTC
```

Set filter:

```text
date_from = 2026-06-24
date_to = 2026-06-24
```

Expected:

- previous-JST-day group is not shown

4. Generated time is displayed in JST

Freeze time to a value where UTC date differs from JST date.

Expected:

- print heading generated time uses the JST date/time

5. Existing pick summary tests still pass

Especially:

- pickable stock tests
- one-warehouse auto-select test
- print context test

## Acceptance Criteria

- Pick Summary default date is correct for Japan warehouse staff.
- Date filters are JST calendar days.
- Query uses UTC timestamp boundaries, not `whereDate()`.
- Generated time is shown in JST.
- Targeted pick summary tests pass.
