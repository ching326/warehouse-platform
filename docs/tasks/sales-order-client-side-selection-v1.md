# Task: Make Sales Orders row selection instant (client-side), keep the selected count

## Problem

On the Sales Orders index, selecting rows feels slow, especially the header "select all"
checkbox. The cause is NOT counting selected rows. The cause is that selection state lives on
the server:

- The header checkbox calls `wire:click="toggleVisibleSelection"` (a Livewire server action).
- Each row checkbox uses `wire:model.live="selectedIds"`.

Every toggle does a network round-trip and re-renders the whole component, which re-runs the
paginated orders query (`simplePaginate(30)` with eager loads) and rebuilds all 30 rows. That
round-trip is the latency.

## Goal

Make checkbox selection update the UI immediately with no per-click server round-trip, while
keeping:

- the selected count display,
- the bulk action buttons (Mark Ready, Mark Shipped, Hold, Release hold, Cancel),
- the selected CSV/XLSX export links and the courier export menu,
- correct behavior of the existing server methods, which read `$this->selectedIds`.

Selection should sync to the server lazily (deferred), so that when the user clicks a bulk or
courier action the server already has the right `selectedIds`. Do not change any query logic,
`SalesOrderFilters`, or the export controller.

Use plain ASCII punctuation only in code and Blade. No em-dashes, smart quotes, or arrows.

## Files

- `resources/views/livewire/sales-order-index.blade.php`
- `tests/Feature/SalesOrderTest.php`
- (No change needed in `app/Livewire/SalesOrderIndex.php` except optionally keeping
  `toggleVisibleSelection` for tests; see Tests section.)

## Approach: Alpine state entangled with Livewire (deferred)

Wrap the selection action row and the table in a single Alpine component whose `selected`
array is entangled (deferred, two-way) with the Livewire `selectedIds` property, and whose
`visible` array is entangled with `visibleOrderIds`. In Livewire 3/4, `$wire.entangle('x')` is
deferred by default (no request per change), and re-syncs from the server on each render, so:

- Toggling a checkbox updates Alpine state instantly (no round-trip).
- When the user clicks a bulk/courier action (a `wire:click`), the latest `selected` is sent
  with that request, so server methods see the correct ids.
- When `filterChanged()` clears `selectedIds = []` server-side, the entangled Alpine value
  resets too (existing behavior preserved).

Add the Alpine scope around the block that currently starts at the
`<div class="sales-order-action-row" ...>` and ends after the closing `</div>` of
`<div class="table-scroll">`:

```blade
<div
    x-data="{
        selected: $wire.entangle('selectedIds'),
        visible: $wire.entangle('visibleOrderIds'),
        has() { return this.selected.length > 0; },
        inVisible(id) { return this.visible.map(String).includes(String(id)); },
        isSelected(id) { return this.selected.map(String).includes(String(id)); },
        toggleRow(id) {
            id = String(id);
            const list = this.selected.map(String);
            const i = list.indexOf(id);
            if (i === -1) { this.selected = [...list, id]; }
            else { list.splice(i, 1); this.selected = list; }
        },
        get allVisibleSelected() {
            const v = this.visible.map(String);
            return v.length > 0 && v.every(id => this.selected.map(String).includes(id));
        },
        get someVisibleSelected() {
            const v = this.visible.map(String);
            const n = v.filter(id => this.selected.map(String).includes(id)).length;
            return n > 0 && n < v.length;
        },
        toggleAll() {
            const v = this.visible.map(String);
            if (this.allVisibleSelected) {
                this.selected = this.selected.map(String).filter(id => !v.includes(id));
            } else {
                this.selected = [...new Set([...this.selected.map(String), ...v])];
            }
        },
    }"
>
    ... selection action row + table go here ...
</div>
```

Note: `selectedIds` are compared with `intval` server-side, so storing them as strings on the
client is fine.

## Selection action row changes

Replace the server-driven count and disabled states with Alpine bindings so they update
instantly.

- Count badge: drive the number from Alpine. Keep it simple and instant:

```blade
<flux:badge x-bind:color="has() ? 'blue' : 'zinc'">
    <span x-text="selected.length"></span> {{ __('sales_orders.selected_suffix') }}
</flux:badge>
```

  Add a lang key `selected_suffix` (for English, `selected`). Replacing the `trans_choice`
  pluralization with a single suffix is acceptable here; instant feedback is the priority.

- Every bulk button: change `:disabled="! $hasSelection"` to `x-bind:disabled="! has()"`.
  Keep the `wire:click="bulkMarkReady"` etc. unchanged. Remove the now-unused
  `@php $hasSelection = ... @endphp`.

- Export group: render both states in the DOM and toggle with Alpine instead of `@if`:
  - The selected-export `<details>` and courier `<details>`: add `x-show="has()"`.
  - The two disabled placeholder buttons: add `x-show="! has()"`.

- Selected CSV/XLSX export links: the ids must reflect the live client selection. Render the
  base URL without ids server-side, then bind `:href` to append the current selection:

```blade
@php
    $selectedCsvBase = route('sales.orders.export', array_filter(array_merge($exportFilters, ['format' => 'csv']), fn ($v) => $v !== null));
    $selectedXlsxBase = route('sales.orders.export', array_filter(array_merge($exportFilters, ['format' => 'xlsx']), fn ($v) => $v !== null));
@endphp
<a x-bind:href="'{{ $selectedCsvBase }}' + '&ids=' + selected.join(',')">{{ __('sales_orders.btn_bulk_export_csv') }}</a>
<a x-bind:href="'{{ $selectedXlsxBase }}' + '&ids=' + selected.join(',')">{{ __('sales_orders.btn_bulk_export_xlsx') }}</a>
```

  The base URL always contains `?format=...`, so appending `&ids=` is safe.

- Courier export buttons keep `wire:click="validateCourierExport('yamato')"` etc. unchanged;
  the deferred entangle means the server has the current `selectedIds` when the click fires.

## Header and row checkbox changes

Header (select all) checkbox: drive entirely from Alpine. This also fixes the indeterminate
state properly (reactive bind), so remove the old `x-init` / `x-effect` / `data-indeterminate`
hack and the server `@checked($allVisibleSelected)`:

```blade
<input
    type="checkbox"
    x-bind:checked="allVisibleSelected"
    x-bind:indeterminate="someVisibleSelected"
    x-on:change="toggleAll()"
    aria-label="{{ __('sales_orders.select_visible_orders') }}"
>
```

Also remove the server-side `@php $visibleIds = ...; $allVisibleSelected = ...; $someVisibleSelected = ...; @endphp`
block that fed the old markup.

Row checkbox: replace `wire:model.live="selectedIds"` with Alpine binding so row toggles are
also instant:

```blade
<input
    type="checkbox"
    x-bind:checked="isSelected({{ $order->id }})"
    x-on:change="toggleRow({{ $order->id }})"
    aria-label="{{ __('sales_orders.select_order') }} {{ $order->platform_order_id ?: $order->id }}"
>
```

Keep the larger `.so-checkbox-hitbox` label wrapper as is.

## Server component

- Keep `public array $selectedIds = [];` and `public array $visibleOrderIds = [];` and the
  `render()` line that sets `$this->visibleOrderIds`.
- Keep the bulk methods unchanged (they read `$this->selectedIds`).
- You may keep the existing `toggleVisibleSelection()` method so the existing unit test that
  calls it directly still passes. It is no longer wired in the Blade, but it is harmless and
  useful as a programmatic/testable path. Do not delete it unless you also update that test.

## Tests

In `tests/Feature/SalesOrderTest.php`:

1. `test_sales_order_index_checkbox_hitboxes_are_rendered_without_row_selection` currently
   asserts `wire:click="toggleVisibleSelection"`, `wire:model.live="selectedIds"`, and
   `$el.indeterminate`. Update these assertions to the new client-side markup, for example
   assert the header has `x-on:change="toggleAll()"` and `x-bind:indeterminate`, and that a row
   checkbox has `x-on:change="toggleRow(`. Do not weaken to trivial matches; assert real
   markers of the new behavior.

2. Keep `test_sales_order_index_select_all_selects_only_visible_page_orders` working by keeping
   the `toggleVisibleSelection()` method (it sets `selectedIds` from `visibleOrderIds`
   server-side), or rewrite it to set `visibleOrderIds` and `selectedIds` and assert. Either
   way it should still prove select-all only covers the current page.

3. Bulk action tests (`bulkMarkShipped`, etc.) set `selectedIds` directly and call the method;
   these stay valid because `selectedIds` is still a Livewire property.

Run the suite and fix any assertion that referenced the old `wire:model.live="selectedIds"` or
the count `trans_choice` text.

## Verification

```bash
php artisan test tests/Feature/SalesOrderTest.php
php artisan test
```

If `php` is not on PATH (Laragon):

```bash
C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan test tests/Feature/SalesOrderTest.php
```

Manual browser checks on `/sales-orders`:

- Click the header checkbox: all visible rows select instantly, count updates instantly, no
  visible delay or table flash.
- Click it again: clears instantly.
- Select some but not all rows: header shows the indeterminate dash; selecting the rest makes
  it solid checked; deselecting one returns it to the dash.
- With rows selected, the Selected and Courier export menus appear; the Selected CSV/XLSX
  links download exactly the selected orders.
- Bulk Mark Ready / Hold / etc. still act on the selected rows.
- Apply a filter: selection clears (existing behavior).
