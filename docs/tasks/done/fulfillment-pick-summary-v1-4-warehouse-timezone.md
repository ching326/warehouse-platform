# Fulfillment Pick Summary v1.4 - Warehouse Timezone

## Goal

Make Fulfillment Pick Summary date behavior use the selected warehouse timezone instead of a hard-coded Japan timezone.

v1.3 fixed the immediate JST issue by using `Asia/Tokyo`.

That is correct for Japan warehouses, but the system already supports warehouses in multiple countries and each warehouse has its own `timezone` column:

```php
warehouses.timezone
```

Examples:

- Japan warehouse: `Asia/Tokyo`
- China warehouse: `Asia/Shanghai`
- US warehouse: `America/Los_Angeles`

Pick Summary is a warehouse work surface, so "today" and date filters should mean the selected warehouse's local calendar day.

## Current Problem

Current code uses:

```php
private function warehouseTimezone(): string
{
    return 'Asia/Tokyo';
}
```

This works for JP warehouse, but it is wrong for CN / US warehouse.

Example:

- Selected warehouse timezone: `America/Los_Angeles`
- Current time: `2026-06-24 00:30 Asia/Tokyo`
- Los Angeles local date is still `2026-06-23`
- Pick Summary should default to `2026-06-23` for that warehouse, not `2026-06-24`

## Requirements

### 1. Use selected warehouse timezone

Replace the hard-coded timezone helper with selected warehouse timezone.

Suggested behavior:

```php
private function warehouseTimezone(): string
{
    return $this->selectedWarehouse()?->timezone ?: 'Asia/Tokyo';
}
```

Add a small helper to fetch selected warehouse safely:

```php
private function selectedWarehouse(): ?Warehouse
```

Rules:

- Only return a warehouse if `warehouseId` is set.
- If the selected warehouse does not exist, return null.
- If timezone is empty or invalid, fall back to `Asia/Tokyo`.

Do not trust unvalidated user input as a timezone string.

### 2. Resolve warehouse before default date

`mount()` currently sets default date before or around warehouse auto-selection.

Reorder the logic:

1. authorize internal user
2. auto-select warehouse if exactly one active warehouse exists
3. resolve warehouse timezone
4. set default `dateFrom` / `dateTo` only if query string did not provide them

This matters because default date depends on warehouse timezone.

### 3. Do not overwrite explicit URL date params

If URL has:

```text
?date_from=2026-06-20&date_to=2026-06-21
```

do not replace them with warehouse-local today.

Only default empty dates.

### 4. Date filter uses selected warehouse timezone

Continue using UTC timestamp boundaries, but derive the boundaries from selected warehouse timezone:

```php
$fromUtc = Carbon::parse($this->dateFrom, $this->warehouseTimezone())
    ->startOfDay()
    ->utc();

$toUtc = Carbon::parse($this->dateTo, $this->warehouseTimezone())
    ->endOfDay()
    ->utc();
```

Do not reintroduce `whereDate()`.

### 5. Generated time uses selected warehouse timezone

The print heading generated time should use selected warehouse timezone:

```php
now($this->warehouseTimezone())->format('Y-m-d H:i')
```

### 6. Filter summary and chips

No wording change is required, but the displayed date values should now mean selected warehouse local date.

Optional small improvement:

Show timezone in print summary only, e.g.

```text
Timezone: Asia/Tokyo
```

This is optional. Keep the UI compact.

### 7. Warehouse switching behavior

If user changes warehouse manually:

- keep existing `dateFrom` / `dateTo` strings
- interpret those strings in the newly selected warehouse timezone

Do not auto-clear dates on warehouse change.

Reason:

- User selected a calendar date.
- The date should remain visually stable.
- Query meaning follows the selected warehouse timezone.

## Files Likely Involved

- `app/Livewire/FulfillmentPickSummary.php`
- `tests/Feature/FulfillmentPickSummaryTest.php`

## Tests

Run targeted tests only:

```bash
php artisan test tests/Feature/FulfillmentPickSummaryTest.php
```

### Required tests

1. JP warehouse defaults to JP local date

Setup:

- Warehouse timezone: `Asia/Tokyo`
- Freeze time: `2026-06-24 00:30 Asia/Tokyo`
- Pass warehouse in query string or auto-select it as the only active warehouse

Expected:

```text
dateFrom = 2026-06-24
dateTo = 2026-06-24
```

2. US warehouse defaults to US local date

Setup:

- Warehouse timezone: `America/Los_Angeles`
- Freeze time: `2026-06-24 00:30 Asia/Tokyo`

Expected:

```text
dateFrom = 2026-06-23
dateTo = 2026-06-23
```

3. Explicit date query params are preserved

Setup:

- Warehouse timezone: `America/Los_Angeles`
- URL / component params set `date_from = 2026-06-20`, `date_to = 2026-06-21`

Expected:

```text
dateFrom = 2026-06-20
dateTo = 2026-06-21
```

4. US warehouse date filter uses US day boundaries

Setup:

- Warehouse timezone: `America/Los_Angeles`
- Filter date: `2026-06-23`
- Create one reserved group at `2026-06-23 23:30 America/Los_Angeles`
- Create another reserved group at `2026-06-24 00:30 America/Los_Angeles`

Expected:

- first group appears
- second group does not appear

5. Invalid or missing warehouse timezone falls back safely

Setup:

- Warehouse timezone is empty or invalid

Expected:

- component still renders
- default date uses fallback timezone
- no exception is thrown

6. Existing JST tests still pass

Keep the v1.3 tests that verify JST behavior. They should now pass through the warehouse timezone helper.

## Acceptance Criteria

- Pick Summary dates are warehouse-local, not hard-coded JST.
- JP warehouse behavior remains unchanged.
- US / CN warehouse behavior is correct around timezone boundaries.
- URL date filters are not overwritten.
- Query still uses UTC timestamp comparisons, not `whereDate()`.
- Targeted pick summary tests pass.
