# Task: Sales Orders v5 -- Bulk Actions Expansion

## Scope note

The index already has multi-select with a single bulk action (`bulkMarkReady`). v5 expands the
multi-select toolbar with the rest of the day-to-day bulk operations:

- **Bulk hold** and **bulk release hold**
- **Bulk cancel**
- **Export selected only** (CSV / XLSX), constrained to the checked rows

This is a pure composition of work already in the codebase: the per-order status guards from
`SalesOrderDetail` (v2) and the export route from v4. No new tables, no new infrastructure.
"Export selected" was explicitly pre-flagged in the v4 follow-up.

Bulk backorder (mark / release) is intentionally NOT in scope; it shares the hold guard and is
listed under Follow-up.

---

## Pre-conditions

These must be deployed first:
- Sales Orders v2 status management (commit ab18280)
- Sales Orders v4 export (commit 0092e8a)

Do not modify existing models, constants, migrations, or passing tests. Reuse the existing
`bulkMarkReady` shape as the template for the new bulk methods.

---

## Goal

On `SalesOrderIndex`, when one or more rows are selected, expose: Mark Ready (exists), Hold,
Release hold, Cancel, and Export selected (CSV + XLSX).

Each status bulk action is **best-effort**, matching `bulkMarkReady`: it updates every selected
order that is currently eligible, silently skips the rest, and flashes an
`:updated / :skipped` result. This avoids an all-or-nothing failure when one stale row is no
longer eligible.

Tenant scoping is enforced exactly as `bulkMarkReady` does: every bulk query is constrained by
`whereIn('tenant_id', $this->allowedTenantIds())`, so a tampered `selectedIds` entry for another
tenant is filtered out and counts as skipped.

---

## Key data facts (verified against the codebase)

Status transition rules are copied verbatim from `SalesOrderDetail` (v2). Each per-order guard
is expressible as SQL `where` clauses, so the bulk version is the same eligibility filter applied
to all selected ids at once.

| Bulk action | Eligibility (must all hold) | Effect |
|---|---|---|
| Hold (`bulkHold`) | `order_status = pending` AND `fulfillment_status IN (unfulfilled, ready)` | `order_status = on_hold`, `fulfillment_status = unfulfilled` |
| Release hold (`bulkReleaseHold`) | `order_status = on_hold` AND `fulfillment_status IN (unfulfilled, ready)` | `order_status = pending` |
| Cancel (`bulkCancel`) | `order_status NOT IN (cancelled, completed)` AND `fulfillment_status IN (unfulfilled, ready)` | order: `order_status = cancelled`, `fulfillment_status = cancelled`; all its lines: `line_status = cancelled` |

- `fulfillment_status IN (unfulfilled, ready)` is the exact predicate behind
  `hasManualFulfillmentStatus()` in `SalesOrderDetail` (lines 503-509). It excludes
  `in_group`, `shipped`, and `cancelled` orders -- those are owned by the fulfillment / outbound
  flow and must not be bulk-mutated here.
- Cancel touches two tables per order (order + its lines), so it runs in a `DB::transaction`.
  Hold and release hold are single-column updates and need no transaction (same as
  `bulkMarkReady`).
- `selectedIds` is a `public array`. Always normalize with
  `array_values(array_unique(array_map('intval', $this->selectedIds)))` before use, exactly as
  `bulkMarkReady` does (line 72).

---

## Part A: bulk status actions on `SalesOrderIndex`

Add three public methods next to `bulkMarkReady`. They follow its exact shape: early return on
empty selection, normalize ids, query eligible orders scoped by tenant, update, compute
`skipped = count(selected) - updated`, clear `selectedIds`, flash a result.

```php
public function bulkHold(): void
{
    if ($this->selectedIds === []) {
        return;
    }

    $selectedIds = array_values(array_unique(array_map('intval', $this->selectedIds)));

    $updated = SalesOrder::query()
        ->whereIn('id', $selectedIds)
        ->whereIn('tenant_id', $this->allowedTenantIds())
        ->where('order_status', SalesOrder::ORDER_STATUS_PENDING)
        ->whereIn('fulfillment_status', [
            SalesOrder::FULFILLMENT_STATUS_UNFULFILLED,
            SalesOrder::FULFILLMENT_STATUS_READY,
        ])
        ->update([
            'order_status' => SalesOrder::ORDER_STATUS_ON_HOLD,
            'fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_UNFULFILLED,
        ]);

    $this->finishBulk('sales_orders.bulk_hold_result', $updated, count($selectedIds));
}

public function bulkReleaseHold(): void
{
    if ($this->selectedIds === []) {
        return;
    }

    $selectedIds = array_values(array_unique(array_map('intval', $this->selectedIds)));

    $updated = SalesOrder::query()
        ->whereIn('id', $selectedIds)
        ->whereIn('tenant_id', $this->allowedTenantIds())
        ->where('order_status', SalesOrder::ORDER_STATUS_ON_HOLD)
        ->whereIn('fulfillment_status', [
            SalesOrder::FULFILLMENT_STATUS_UNFULFILLED,
            SalesOrder::FULFILLMENT_STATUS_READY,
        ])
        ->update(['order_status' => SalesOrder::ORDER_STATUS_PENDING]);

    $this->finishBulk('sales_orders.bulk_release_hold_result', $updated, count($selectedIds));
}

public function bulkCancel(): void
{
    if ($this->selectedIds === []) {
        return;
    }

    $selectedIds = array_values(array_unique(array_map('intval', $this->selectedIds)));

    $eligibleIds = SalesOrder::query()
        ->whereIn('id', $selectedIds)
        ->whereIn('tenant_id', $this->allowedTenantIds())
        ->whereNotIn('order_status', [
            SalesOrder::ORDER_STATUS_CANCELLED,
            SalesOrder::ORDER_STATUS_COMPLETED,
        ])
        ->whereIn('fulfillment_status', [
            SalesOrder::FULFILLMENT_STATUS_UNFULFILLED,
            SalesOrder::FULFILLMENT_STATUS_READY,
        ])
        ->pluck('id')
        ->all();

    DB::transaction(function () use ($eligibleIds) {
        if ($eligibleIds === []) {
            return;
        }

        SalesOrder::query()
            ->whereIn('id', $eligibleIds)
            ->update([
                'order_status' => SalesOrder::ORDER_STATUS_CANCELLED,
                'fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_CANCELLED,
            ]);

        SalesOrderLine::query()
            ->whereIn('sales_order_id', $eligibleIds)
            ->update(['line_status' => SalesOrderLine::STATUS_CANCELLED]);
    });

    $this->finishBulk('sales_orders.bulk_cancel_result', count($eligibleIds), count($selectedIds));
}

private function finishBulk(string $messageKey, int $updated, int $selectedCount): void
{
    $this->selectedIds = [];

    session()->flash('status', __($messageKey, [
        'updated' => $updated,
        'skipped' => $selectedCount - $updated,
    ]));
}
```

Notes:
- `bulkCancel` plucks eligible ids first, then updates orders and lines inside one transaction so
  the two writes cannot diverge. `updated` is the eligible count.
- `finishBulk` is a small private helper that clears the selection and flashes the
  `:updated / :skipped` message. Optionally refactor the existing `bulkMarkReady` tail to use it
  too, but do NOT change `bulkMarkReady`'s behavior or its `sales_orders.bulk_ready_result` key.
- `SalesOrder` and `SalesOrderLine` are already imported in `SalesOrderIndex`. Add
  `use Illuminate\Support\Facades\DB;` for `bulkCancel` -- it is NOT imported yet.

---

## Part B: export selected only

Extend the v4 export route to accept an optional `ids` query param (comma-separated sales order
ids). When present and valid, the export is constrained to those orders (still within tenant
scope). When absent or explicitly empty (`ids=`), behavior is unchanged from v4 (filter-based
export). When present but invalid (`ids=abc`, `ids=0`, etc.), return an empty export rather than
falling back to a full filtered export.

### Controller change (`SalesOrderExportController`)

Parse `ids` into a clean int array and also pass whether an id filter was actually requested:

```php
$idsParam = trim((string) $request->query('ids', ''));
$hasOrderIdFilter = $request->query->has('ids') && $idsParam !== '';
$orderIds = $idsParam === ''
    ? []
    : array_values(array_unique(array_filter(
        array_map('intval', explode(',', $idsParam)),
        fn (int $id) => $id > 0
    )));

$filters = [
    'allowed_tenant_ids'   => $allowedTenantIds,
    'has_order_id_filter'  => $hasOrderIdFilter,
    'order_ids'            => $orderIds,
    'shop_id'              => $shopId,
    'shop_filter_allowed'  => $shopFilterAllowed,
    'fulfillment'          => trim((string) $request->query('fulfillment', '')),
    'order_status'         => trim((string) $request->query('order_status', '')),
    'search'               => trim((string) $request->query('q', '')),
];
```

The shop-scope hardening and 403 logic from v4 stay exactly as-is.
Important behavior:
- No `ids` param: full filtered export (v4 behavior).
- `ids=` explicitly empty: full filtered export (same as no ids).
- `ids=1,2`: selected-only export.
- `ids=abc` or `ids=0`: empty export, not full export.

### Export class change (`SalesOrdersExport`)

Add one clause inside the existing `whereHas('salesOrder', ...)` closure. The id filter lives
INSIDE the salesOrder constraint so it composes with tenant scope and never widens the result:

```php
->whereHas('salesOrder', function (Builder $query) use ($filters) {
    $query
        ->whereIn('tenant_id', $filters['allowed_tenant_ids'])
        ->when(
            $filters['has_order_id_filter'],
            fn ($q) => $filters['order_ids'] === []
                ? $q->whereRaw('1 = 0')
                : $q->whereIn('id', $filters['order_ids'])
        )
        ->when($filters['shop_id'] !== '', fn ($q) => $q->where('shop_id', (int) $filters['shop_id']))
        // ... fulfillment / order_status / search unchanged ...
})
```

Update the `$filters` PHPDoc to include
`has_order_id_filter: bool, order_ids: array<int,int>`. A tampered id for another tenant is
dropped by the existing `whereIn('tenant_id', ...)`, so no leak is possible. A malformed id list
is treated as an explicitly requested but empty selection, so it returns no rows instead of
exporting the whole filtered result set.

### Index blade: selected-actions bar

The existing selected-count bar (rendered only when `count($selectedIds) > 0`) gains the new
controls. Status actions are Livewire clicks; the two export controls are plain download links
that carry the selected ids plus the active filters.

```blade
@if (count($selectedIds) > 0)
    <div class="active-filter-row">
        <flux:badge color="blue">{{ trans_choice('sales_orders.selected_count', count($selectedIds), ['count' => count($selectedIds)]) }}</flux:badge>

        <flux:button type="button" size="sm" variant="primary" wire:click="bulkMarkReady">
            {{ __('sales_orders.btn_bulk_mark_ready') }}
        </flux:button>
        <flux:button type="button" size="sm" variant="outline" wire:click="bulkHold">
            {{ __('sales_orders.btn_bulk_hold') }}
        </flux:button>
        <flux:button type="button" size="sm" variant="outline" wire:click="bulkReleaseHold">
            {{ __('sales_orders.btn_bulk_release_hold') }}
        </flux:button>
        <flux:button
            type="button"
            size="sm"
            variant="danger"
            wire:click="bulkCancel"
            wire:confirm="{{ __('sales_orders.bulk_cancel_confirm') }}"
        >
            {{ __('sales_orders.btn_bulk_cancel') }}
        </flux:button>

        <flux:button
            as="a"
            size="sm"
            variant="ghost"
            href="{{ route('sales.orders.export', [
                'ids'          => implode(',', $selectedIds),
                'shop'         => $shopId ?: null,
                'fulfillment'  => $fulfillmentStatus ?: null,
                'order_status' => $orderStatus ?: null,
                'q'            => $search ?: null,
                'format'       => 'csv',
            ]) }}"
        >
            {{ __('sales_orders.btn_bulk_export_csv') }}
        </flux:button>
        <flux:button
            as="a"
            size="sm"
            variant="ghost"
            href="{{ route('sales.orders.export', [
                'ids'          => implode(',', $selectedIds),
                'shop'         => $shopId ?: null,
                'fulfillment'  => $fulfillmentStatus ?: null,
                'order_status' => $orderStatus ?: null,
                'q'            => $search ?: null,
                'format'       => 'xlsx',
            ]) }}"
        >
            {{ __('sales_orders.btn_bulk_export_xlsx') }}
        </flux:button>
    </div>
@endif
```

The cancel button uses `wire:confirm` because it is destructive. The export controls are
`as="a"` plain links (a file download must be a real browser navigation, same as v4).

URL length note: a selection carried in `ids` is fine for typical multi-select counts (tens to a
few hundred). It is not meant for "export thousands"; for a full-result export the operator uses
the unselected v4 export buttons, which carry no `ids`. No hard cap is required, but if a future
"select all matching filter" feature lands (see Follow-up), selected-export should switch to the
filter-based path rather than a giant `ids` list.

---

## Lang keys to add in `lang/en/sales_orders.php`

```php
// Bulk actions (v5)
'btn_bulk_hold'           => 'Hold',
'btn_bulk_release_hold'   => 'Release hold',
'btn_bulk_cancel'         => 'Cancel',
'btn_bulk_export_csv'     => 'Export selected (CSV)',
'btn_bulk_export_xlsx'    => 'Export selected (XLSX)',
'bulk_cancel_confirm'     => 'Cancel the selected orders? This also cancels their lines and cannot be undone.',
'bulk_hold_result'        => ':updated order(s) put on hold. :skipped skipped.',
'bulk_release_hold_result' => ':updated order(s) released from hold. :skipped skipped.',
'bulk_cancel_result'      => ':updated order(s) cancelled. :skipped skipped.',
```

Add keys to `lang/en/sales_orders.php` only. The `lang/ja/`, `lang/zh_TW/`, and `lang/zh_CN/`
`sales_orders.php` files inherit English wholesale via `return require ...` of the en file -- do
NOT split them into per-key stubs in this task.

---

## Tests

### `tests/Feature/SalesOrderIndexBulkTest.php` (new file; do not touch existing index tests)

Use `RefreshDatabase` and the Livewire tester, following the helper style of the existing index /
status tests.

| # | Test | What it asserts |
|---|---|---|
| 1 | `test_bulk_hold_holds_eligible_pending_orders` | selected pending+unfulfilled (and pending+ready) orders -> `on_hold` + `unfulfilled`; selection cleared; result flashed |
| 2 | `test_bulk_hold_skips_in_group_and_shipped_orders` | selected orders with `fulfillment_status in_group/shipped` are unchanged; counted as skipped |
| 3 | `test_bulk_hold_skips_non_pending_orders` | an `on_hold` or `backorder` order in the selection is skipped (only pending is eligible) |
| 4 | `test_bulk_release_hold_returns_on_hold_to_pending` | selected `on_hold` orders -> `pending`; non-on_hold skipped |
| 5 | `test_bulk_cancel_cancels_orders_and_their_lines` | eligible orders -> `order_status=cancelled`, `fulfillment_status=cancelled`, every line `line_status=cancelled` |
| 6 | `test_bulk_cancel_skips_completed_and_cancelled_and_in_group` | completed, already-cancelled, and in_group/shipped orders are skipped; their lines untouched |
| 7 | `test_bulk_actions_only_affect_allowed_tenant` | tenant user with a tampered `selectedIds` entry for another tenant -> that order is not mutated; counted as skipped |
| 8 | `test_bulk_actions_noop_on_empty_selection` | calling each bulk method with empty `selectedIds` writes nothing and flashes nothing |
| 9 | `test_bulk_result_reports_updated_and_skipped_counts` | mixed eligible/ineligible selection -> flashed message contains correct `:updated` and `:skipped` numbers |

### `tests/Feature/SalesOrderExportTest.php` (add to the existing v4 file)

| # | Test | What it asserts |
|---|---|---|
| 17 | `test_export_constrains_to_selected_ids` | `ids=` with two of three order ids -> export contains only those two orders' lines |
| 18 | `test_export_ids_respects_tenant_scope` | tenant user passes an `ids` list including another tenant's order id -> that order is excluded (no leak) |
| 19 | `test_export_empty_ids_param_behaves_like_full_export` | `ids=` empty (or absent) -> unchanged v4 behavior (all filtered rows) |
| 20 | `test_export_invalid_ids_param_returns_empty_result_not_full_export` | invalid non-empty `ids=abc,0` -> export contains no rows and does not fall back to full filtered export |

For row content use the same approach as the v4 tests: instantiate `SalesOrdersExport` with a
filters array (now including `has_order_id_filter` and `order_ids`) and assert on
`query()->get()->map(...)`. For the route itself, `Excel::fake()` + `assertDownloaded(...)`.

---

## Constraints

- No Volt. No TypeScript. Class-based Livewire only.
- Reuse the `bulkMarkReady` shape: normalize ids, scope by `allowedTenantIds()`, best-effort
  update, report `:updated / :skipped`, clear `selectedIds`.
- Status eligibility predicates MUST match `SalesOrderDetail`'s per-order guards exactly
  (hold / release hold / cancel). Do not invent new transitions.
- `bulkCancel` MUST run order + line updates in a single `DB::transaction`.
- Never bulk-mutate orders whose `fulfillment_status` is `in_group`, `shipped`, or `cancelled`.
- The cancel button MUST use `wire:confirm`.
- Export-selected reuses the v4 route/controller/export; the `ids` filter lives INSIDE the
  tenant-scoped `whereHas('salesOrder', ...)` so it can never widen the result. A tampered id is
  dropped by tenant scope.
- A malformed non-empty `ids` param must return an empty export, not the full filtered export.
- Export controls are plain `as="a"` download links, not `wire:click`, not `wire:navigate`.
- Add lang keys to `lang/en` only; do not split the inheriting locale files.
- Do not modify `bulkMarkReady`'s behavior or its `bulk_ready_result` key.
- Run `php artisan test` at the end and confirm all tests pass.

---

## Follow-up (out of scope for this task)

- **Bulk backorder** (`bulkMarkBackorder` / `bulkReleaseBackorder`): same guard as hold; add once
  there is demand. Left out to keep this task tight.
- **Select all matching filter**: a "select all N results" affordance that selects the whole
  filtered set, not just the current page. When this lands, selected-export should switch to the
  filter-based export path (no `ids`) to avoid an oversized URL.
- Extract the duplicated access-control helpers shared by the index, detail, create, import, and
  export-controller into a single trait (carried over from the v4 follow-up).
