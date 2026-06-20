# Task: Tidy Sales Orders filter toolbar (compact Print Ready, sentence-case labels, Clear all)

## Context

Laravel 13 + Livewire 4 (class-based) + Flux UI warehouse app. This is a small UI-only
polish task on the Sales Orders index toolbar. Do not change any filtering/query logic,
the shared `SalesOrderFilters` class, or the export behavior. Behavior stays identical;
only layout, styling, labels, and one new "clear all" convenience action change.

Use plain ASCII punctuation only in code, Blade, and lang files. No em-dashes, smart quotes,
or arrow glyphs.

## Files

- `resources/views/livewire/sales-order-index.blade.php` (toolbar markup, chip row)
- `resources/views/inventory.blade.php` (the page CSS lives in a `<style>` block here)
- `app/Livewire/SalesOrderIndex.php` (add one `clearAllFilters()` method)
- `lang/en/sales_orders.php` (label text + one new key)
- `tests/Feature/SalesOrderTest.php` (add/adjust tests)

## Current state and root cause

The top filter row is a CSS grid:

```css
.sales-order-filter-grid {
    display: grid;
    grid-template-columns: repeat(7, minmax(112px, 132px)) minmax(220px, 1fr);
    gap: 10px;
    margin-bottom: 12px;
}
```

The 7 filter `<details>` menus (Platform, Shop, Fulfillment, Order Status, Shipping,
Order Date, Others) fill columns 1-7. The Print Ready toggle is the 8th grid child, so it
lands in the trailing `minmax(220px, 1fr)` column. Because grid items default to
`justify-self: stretch`, the toggle (a `display: flex` label with a border) stretches across
the whole remaining width. That is the large empty bordered box on the right of the row.

The search input is the 9th grid child, so it wraps to its own second row. That is the
current, desired location for search. Keep it there.

Current toggle markup (around lines 197-209 of the Blade file):

```blade
<label class="print-waiting-toggle print-ready-toggle compact-filter-toggle">
    <input class="print-ready-toggle-input" type="checkbox" wire:model.live="printWaiting">
    <span class="print-ready-switch" aria-hidden="true"></span>
    <span class="print-ready-label">{{ __('sales_orders.print_waiting') }}</span>
</label>

<div class="sales-order-search-row">
    <flux:input
        wire:model.live.debounce.300ms="search"
        :label="__('common.search')"
        :placeholder="__('sales_orders.search_placeholder')"
    />
</div>
```

The chip row already exists below the grid and only renders when there are active chips:

```blade
@if ($activeFilterChips !== [])
    <div class="filter-chip-row" data-testid="sales-order-filter-chips">
        @foreach ($activeFilterChips as $chip)
            <button type="button" class="filter-chip" wire:click="removeFilterChip('{{ $chip['group'] }}', '{{ $chip['value'] }}')">
                <span>{{ $chip['text'] }}</span>
                <strong aria-hidden="true">x</strong>
            </button>
        @endforeach
    </div>
@endif
```

There is already a `removeFilterChip(string $group, string $value = '')` method on the
component. There is no "clear all" method yet.

## Change 1: Make the Print Ready toggle compact (remove the empty box)

Keep the toggle exactly where it is in the markup (8th grid child, immediately after the
Others menu). Do NOT move it to a new row and do NOT move search.

In `resources/views/inventory.blade.php`, stop the toggle from stretching across the last
grid column. Add `justify-self: start;` so it shrinks to its content width and sits at the
left edge of that column (right after the Others button). The remaining space in the column
becomes empty, borderless grid track, which is invisible.

Apply it to the toggle's own selector so only this element is affected, for example add to
the existing `.print-ready-toggle` rule:

```css
.print-ready-toggle {
    justify-self: start;
}
```

Do not change the grid `grid-template-columns`. Do not change the search row.

Acceptance for this change:
- The Print Ready control is a compact pill the same height as the filter buttons.
- It sits directly to the right of the Others button.
- There is no large empty bordered box on the row.
- Search remains on its own row, in its current position, unchanged.

## Change 2: Stop shouting the filter labels (sentence case)

The filter category labels currently render in ALL CAPS because of:

```css
.filter-menu summary span {
    ...
    text-transform: uppercase;
}
```

Remove the `text-transform: uppercase;` from that rule so the labels render in normal case.

Then make the visible label text sentence case (first word capitalized only) in
`lang/en/sales_orders.php`. Update these values:

- `filter_fulfillment` => `Fulfillment`
- `filter_order_status` => `Order status`
- `filter_shipping` => `Shipping`
- `filter_order_date` => `Order date`
- `filter_others` => `Others`
- `field_platform` => `Platform`
- `field_shop` => `Shop`

Note: these keys are the ones used in the filter `<summary>` blocks. Confirm the exact keys
referenced in the Blade summaries and update those values only. Leave the chip label keys
(`chip_platform`, `chip_shop`, `chip_fulfillment`, `chip_order_status`, `chip_shipping`,
`chip_order_date`, `chip_others`) as they are unless a test requires alignment.

If any existing test asserts the old casing of a label string (for example `Order Status`),
update that assertion to the new sentence-case string. Do not weaken a test to a partial
match just to make it pass; assert the new exact text.

## Change 3: Add a "Clear all" action to the chip row

Add a `clearAllFilters()` public method to `app/Livewire/SalesOrderIndex.php` that resets
every user-facing filter to its default and then reuses the existing change handler. Do not
touch the internal `activeOnly` open-work flag.

```php
public function clearAllFilters(): void
{
    $this->platforms = [];
    $this->shopIds = [];
    $this->fulfillmentStatusesFilter = [];
    $this->orderStatusesFilter = [];
    $this->shippingMethodsFilter = [];
    $this->othersFilter = [];
    $this->dateRange = SalesOrderFilters::DATE_ALL;
    $this->dateFrom = '';
    $this->dateTo = '';
    $this->search = '';
    $this->printWaiting = false;

    $this->filterChanged();
}
```

`filterChanged()` already calls `normalizeFilterState(false)` (no request fallback), so this
will actually clear the filters rather than re-reading them from the query string.

In the Blade chip row, add a "Clear all" button after the `@foreach`, inside the same
`.filter-chip-row` container so it only appears when chips are present:

```blade
<button type="button" class="filter-chip-clear" wire:click="clearAllFilters">
    {{ __('sales_orders.clear_all_filters') }}
</button>
```

Add the lang key in `lang/en/sales_orders.php`:

```php
'clear_all_filters' => 'Clear all',
```

Add a small style for `.filter-chip-clear` in `resources/views/inventory.blade.php`, near the
existing `.filter-chip` rules. It should read as a quiet text action, not a chip:

```css
.filter-chip-clear {
    border: none;
    background: transparent;
    color: var(--accent);
    cursor: pointer;
    font-size: 12px;
    font-weight: 700;
    padding: 6px 8px;
}
```

## Out of scope

- Do not move the search input.
- Do not change `SalesOrderFilters`, the export controller, or any query logic.
- Do not change the selection action bar (Mark Ready / Mark Shipped / Hold / Release hold /
  Cancel / Selected / Courier). It is fine as is.
- Do not rename the `print_waiting` lang key or the `$printWaiting` property. Only the visible
  label text may stay as the team prefers ("Print Ready" or "Print Waiting"); if you change
  the visible word, change only the value of the existing label key, not the key name.

## Tests

In `tests/Feature/SalesOrderTest.php`:

1. Add a test: applying several filters then calling `clearAllFilters` resets them.
   - Set `platforms`, `orderStatusesFilter`, `othersFilter`, `search`, `printWaiting`, and a
     `dateRange` other than `all`.
   - Call `clearAllFilters`.
   - Assert each property is back to its empty/default value (`[]`, `''`, `false`,
     `SalesOrderFilters::DATE_ALL`).

2. Add a test: the chip row renders a "Clear all" control when at least one filter is active,
   and does not render the chip row at all when no filters are active.

3. Fix any existing assertions that break due to the sentence-case label changes.

Keep all added text in plain ASCII punctuation.

## Verification

```bash
php artisan test tests/Feature/SalesOrderTest.php
php artisan test
```

If `php` is not on PATH (this project uses Laragon):

```bash
C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan test tests/Feature/SalesOrderTest.php
```

Then manually check `/sales-orders`:

- Print Ready is a compact pill right after Others, with no empty box.
- Filter labels are sentence case, not ALL CAPS.
- Search is still on its own row in its current spot.
- Selecting a filter shows chips; "Clear all" appears and resets everything in one click.
