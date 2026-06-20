# Task: Sales Order Index Filters + Note Column UI v1

## Goal

Improve the Sales Orders index into a stronger daily shipping work surface.

The page should let staff quickly find and filter orders by:

- platform, shop, fulfillment status, order status
- shipping method, including multiple selected methods
- order date range
- broad search across order, address, recipient, SKU, tracking, and note

Also add a `Note` column back to the table so staff can see order notes without opening detail.

This task covers Sales Orders index UI/query behavior plus the stored `sales_orders.order_date`
performance column. Do not implement courier CSV generation here.

---

## Naming Decision

Use **Platform**, not Channel.

Reason:

- `shops.platform` already exists.
- Shop setup UI already uses platform.
- SKU fields use platform naming.
- Introducing "channel" now would create two words for the same concept.

Examples:

- Amazon
- Rakuten
- Shopify
- TikTok
- Yahoo
- Manual

---

## Current State

`SalesOrderIndex` currently has single-value filters:

- shop
- fulfillment status
- order status
- search

The table currently shows:

- checkbox
- order id
- address
- recipient
- SKU/items
- shipping method inline dropdown
- tracking no. input
- status
- created / printed date

Requested changes:

1. Add `Note` column on the right side of the table.
2. Keep the current search as the page's **global search**.
3. Global search should cover:
   - platform order id
   - address
   - recipient name
   - recipient phone
   - SKU code
   - SKU name
   - stock item short name
   - stock item name
   - tracking no.
   - order note
   - line note
4. Add Platform filter to the left of Shop.
5. Platform filter should be multi-select.
6. Shop filter should become multi-select.
7. Fulfillment status filter should become multi-select.
8. Order status filter should become multi-select.
9. Add Shipping method filter to the right of Order status.
10. Shipping method filter should be multi-select.
11. Add order date filter:
    - all dates
    - last 3 days
    - last 7 days
    - last 30 days
    - last 3 months
    - last 1 year
    - custom from/to

---

## Important Shipping Method Note

The current system stores simple shipping method values:

- `yamato`
- `sagawa`
- `japan_post`
- `other`
- `null`

The user mentioned selecting multiple Yamato services such as Nekopos and Takkyubin together.

For this task:

- Build the filter as a true multi-select.
- It should work with the current simple values.
- Do not rename or migrate shipping method values in this task.

Future improvement:

- If the business needs `yamato_nekopos`, `yamato_tqb`, `sagawa_thb`, etc., add them as explicit shipping
  method values in a separate task.
- Courier export should then map detailed methods back to a carrier family:
  - `yamato_nekopos` -> Yamato export
  - `yamato_tqb` -> Yamato export
  - `sagawa_thb` -> Sagawa export

Do not mix this migration into the filter UI task.

---

## Date Filter Semantics + Performance Foundation

Use **Order date** as the user-facing label.

Add a stored `sales_orders.order_date` column in this task.

Meaning:

```text
order_date = platform_ordered_at date/time if present, otherwise created_at
```

Use `order_date` for:

- Sales Orders index date filter
- Sales Orders index default sorting
- Sales Orders CSV/XLSX export filters

Reason:

- Amazon reports have a real platform purchase/order date.
- Manually created orders may not have `platform_ordered_at`.
- `WHERE COALESCE(platform_ordered_at, created_at)` is not reliably index-friendly. It can force a
  full scan as order volume grows.
- A stored `order_date` column lets MySQL use normal indexes.

Migration:

```php
Schema::table('sales_orders', function (Blueprint $table) {
    $table->timestamp('order_date')->nullable()->after('platform_ordered_at');
    $table->index(['tenant_id', 'order_date'], 'sales_orders_tenant_order_date_idx');
    $table->index(['tenant_id', 'fulfillment_status', 'order_date'], 'sales_orders_tenant_fulfillment_order_date_idx');
    $table->index(['tenant_id', 'order_status', 'order_date'], 'sales_orders_tenant_status_order_date_idx');
});
```

Backfill (existing production rows):

- Put the backfill logic in a reusable callable action, e.g. `App\Actions\BackfillSalesOrderDate`.
  The migration calls this action.
- Reason: the migration runs against an empty database under test `RefreshDatabase`, so a backfill
  written inline as a one-off `DB::table()->update()` cannot be tested with a seed-then-migrate flow.
  There are no rows present when the migration runs. A callable action lets a test insert a row and
  invoke the backfill directly. See test #1.
- The action sets `order_date = COALESCE(platform_ordered_at, created_at)` for rows where it is null.

Write path (all new rows):

- Add `order_date` to `App\Models\SalesOrder` fillable and casts.
- Guarantee `order_date` at the model level with a `creating` hook so factory inserts, the importer,
  and manual create all populate it uniformly. Do not rely on each app code path to set it; the
  factory does not set it, so factory-created orders would otherwise have a null `order_date` and the
  date-filter tests would break.

```php
protected static function booted(): void
{
    static::creating(function (SalesOrder $order) {
        if ($order->order_date !== null) {
            return;
        }

        if ($order->platform_ordered_at !== null) {
            $order->order_date = $order->platform_ordered_at;

            return;
        }

        // No platform date: pin order_date to the same instant as created_at.
        // The creating event fires before Laravel's updateTimestamps(), so setting
        // created_at/updated_at here makes them dirty and updateTimestamps() skips them,
        // guaranteeing order_date === created_at exactly (not a few microseconds off).
        $timestamp = $order->created_at ?? $order->freshTimestamp();

        $order->created_at ??= $timestamp;
        $order->updated_at ??= $timestamp;
        $order->order_date = $timestamp;
    });
}
```

- Do not use a bare `freshTimestamp()` for the fallback. The `creating` event runs before Laravel
  sets `created_at`, so a separate `freshTimestamp()` would differ from `created_at` by a few
  microseconds and make any `order_date = created_at` assertion flaky.
- When editing `platform_ordered_at` in the future, recompute `order_date`.
- Once the hook exists, the factory and tests do not need to set `order_date` explicitly.

Do not add many speculative indexes. For v1, keep only:

- `(tenant_id, order_date)`
- `(tenant_id, fulfillment_status, order_date)`
- `(tenant_id, order_status, order_date)`

Do not add `shipping_method + order_date` or `shop_id + order_date` unless profiling later proves it
is needed.

---

## Livewire State

Update `App\Livewire\SalesOrderIndex`.

Replace single-value filter properties with array properties where needed.

Recommended properties:

```php
#[Url(as: 'platforms', except: [])]
public array $platforms = [];

#[Url(as: 'shops', except: [])]
public array $shopIds = [];

#[Url(as: 'fulfillment', except: [])]
public array $fulfillmentStatusesFilter = [];

#[Url(as: 'order_status', except: [])]
public array $orderStatusesFilter = [];

#[Url(as: 'shipping', except: [])]
public array $shippingMethodsFilter = [];

#[Url(as: 'date_range', except: 'all')]
public string $dateRange = 'all';

#[Url(as: 'active_only', except: true)]
public bool $activeOnly = true;

#[Url(as: 'date_from', except: '')]
public string $dateFrom = '';

#[Url(as: 'date_to', except: '')]
public string $dateTo = '';

#[Url(as: 'q', except: '')]
public string $search = '';
```

Migration concern:

- Existing URLs may still pass old `shop`, `fulfillment`, `order_status` scalar params.
- Do not break route rendering for old scalar URLs.
- Do not break existing Sales Orders export v4 tests that still pass scalar filters.
- Backward-compatible scalar-to-array normalization is mandatory.

Old URL examples that must not 500:

```text
/sales-orders?shop=1
/sales-orders?fulfillment=ready
/sales-orders?order_status=pending
```

Normalize these into the new array properties in `mount()` before the first render:

```text
shop=1                -> shopIds = ['1']
fulfillment=ready     -> fulfillmentStatusesFilter = ['ready']
order_status=pending  -> orderStatusesFilter = ['pending']
```

Avoid hydrating a typed `public array` property directly from an old scalar query value. If Livewire
would hydrate `?fulfillment=ready` into an array property and cause a TypeError, use different new URL
aliases or read the raw request query in `mount()` and normalize safely.

Whenever any filter changes:

- clear `selectedIds`
- reset pagination

Add updated hooks for all new filter properties.

---

## Multi-Select UI

Flux may not have the exact multi-select UX needed. Use a simple, reliable implementation:

- A compact button-like dropdown / popover.
- Inside: checkbox list.
- Show selected count in the button label.

Examples:

- `Platform: 2 selected`
- `Shop: All shops`
- `Fulfillment: Ship Ready, In fulfillment`
- `Shipping: Yamato, Sagawa`

Important visual decision after reviewing the rendered v1:

- Do **not** ship five always-open checkbox panels as the final v1 UI.
- Always-open panels are functional, but they read as a raw admin form and consume too much vertical
  space before the table.
- The finished v1 should use compact dropdown/popover-style multi-select controls.
- A native `<details>` dropdown is acceptable if it is styled to match the current Flux/table visual
  language and remains keyboard/mouse usable.
- Native checkboxes inside the dropdown are acceptable; native `<select multiple>` is not.

Important:

- Multi-select filters should be easy on a 12-inch laptop.
- Avoid very tall panels that push the table too far down.
- If options exceed about 8 items, make the option list scroll.
- The closed filter row should stay compact enough that the table begins near the first viewport on
  normal laptop width.

---

## Suggested Layout

Use compact filter/search/date rows above the table.

Important:

- This task owns filter/search/date behavior.
- `docs/tasks/sales-order-index-toolbar-layout-v1.md` owns Create / Import / Export / selection
  action placement.
- Do not place Create / Import / Export controls in the filter rows.

### Row 1: Main Filters

Left to right:

1. Platform multi-select
2. Shop multi-select
3. Fulfillment status multi-select
4. Order status multi-select
5. Shipping method multi-select

### Row 2: Global Search

Keep the existing search concept as a global search for the whole order list. It may stay in the
main toolbar or move to its own row if the toolbar becomes too crowded, but do not replace it with a
field-specific search.

Recommended: make it wide enough to feel like a global search, even if it remains in the toolbar.

Placeholder:

```text
Search order, address, recipient, SKU, tracking, note...
```

If a separate row is used, it should sit directly under the main filter row. If space is acceptable,
keeping it in Row 1 is fine.

### Row 3: Order Date Filter

Use a segmented/preset control plus custom dates:

- All dates
- Last 3 days
- Last 7 days
- Last 30 days
- Last 3 months
- Last 1 year
- Custom

If `Custom` is selected, show:

- Date from
- Date to

If not custom, hide or disable the custom date inputs.

Default behavior:

- Default to `All dates`.
- Do not default to `last_7_days`.
- Default to `Active orders only`.
- Reason: Sales Orders index is a warehouse shipping work surface. Old unshipped/backlog orders must
  remain visible by default, while old shipped/completed/cancelled history should not be loaded
  without an explicit date range.
- Show a visible filter chip/control such as `Active orders` so users understand shipped/completed
  orders are not shown by default.

Active orders rule:

```text
order_status NOT IN (completed, cancelled)
AND fulfillment_status NOT IN (shipped, cancelled)
```

If the user explicitly selects historical statuses such as shipped/completed/cancelled:

- turn off `activeOnly`
- if `date_range = all`, visibly set `date_range = last_30_days`
- do not silently mutate without reflecting it in the date control

General status-filter rule:

- If any explicit fulfillment status or order status filter is selected, do not also apply the
  default `activeOnly` NOT IN filter.
- Reason: an explicit status filter is the user's actual intent. `activeOnly` must not silently
  subtract from it.

---

## Query Requirements

Update the `render()` query.

### Platform filter

Filter by `shops.platform`.

Use `whereHas('shop', ...)` or join carefully.

Build platform filter options from active shops visible to the current user:

```php
Shop::query()
    ->whereIn('tenant_id', $this->allowedTenantIds())
    ->where('status', 'active')
    ->select('platform')
    ->distinct()
```

This keeps options aligned with actual shop setup.

### Shop filter

Filter by `sales_orders.shop_id IN (...)`.

Only allow shop ids inside `allowedTenantIds()`.

### Fulfillment status filter

Filter by `sales_orders.fulfillment_status IN (...)`.

Only accept known status values.

### Order status filter

Filter by `sales_orders.order_status IN (...)`.

Only accept known status values.

### Shipping method filter

Filter by `sales_orders.shipping_method IN (...)`.

Special value:

- Use `__empty__` or similar internal value for `Not set`.
- Map it to `whereNull('shipping_method')`.

Example:

- selected: `['yamato', '__empty__']`
- SQL: `(shipping_method = 'yamato' OR shipping_method IS NULL)`

Unset means `NULL`, not an empty string. Current inline update code stores unset as `NULL`; imported
and manually-created orders should also use `NULL`. Add test coverage with an imported/created order
whose shipping method was never edited through the UI.

### Date filter

Use stored order date:

```sql
sales_orders.order_date
```

Preset ranges:

- `all`
- `last_3_days`
- `last_7_days`
- `last_30_days`
- `last_3_months`
- `last_1_year`
- `custom`

If `date_range = all`, do not apply any date constraint.

Historical status guard:

- If user selects shipped/completed/cancelled historical statuses while `date_range = all`, visibly
  switch date range to `last_30_days`.
- Keep the date control honest: the UI must show `Last 30 days`.
- Do not hard-block to a blank page unless validation fails for an explicit invalid custom range.

For custom:

- validate/ignore invalid date strings safely
- if only from is provided, filter from that date onward
- if only to is provided, filter up to that date
- `date_to` must be inclusive for the whole selected day. Use `< date_to + 1 day` or
  `<= date_to 23:59:59`, not `<= date_to 00:00:00`.
- maximum custom range is 365 days. If the user selects a wider range, show a validation warning and
  do not run the broad query.

Pagination:

- Use `simplePaginate(30)` on the Sales Orders index instead of `paginate(30)`.
- Reason: `paginate()` performs a total `COUNT(*)` over the whole filtered set. On large shipped
  history this count query is often more expensive than loading the current page.
- The index does not need an exact total row count; previous/next pagination is enough for this
  operational page.
- Verify the existing `<flux:table :paginate="$orders">` UI works with Laravel's simple paginator.
  `simplePaginate()` returns a `Paginator`, not a `LengthAwarePaginator`.
- If Flux table pagination expects a length-aware paginator, replace that pagination UI with a simple
  previous/next control for this page.

### Search

Search must cover:

- `sales_orders.platform_order_id`
- `sales_orders.recipient_name`
- `sales_orders.recipient_phone`
- `sales_orders.recipient_postal_code`
- `sales_orders.recipient_state`
- `sales_orders.recipient_city`
- `sales_orders.recipient_address_line1`
- `sales_orders.recipient_address_line2`
- `sales_orders.tracking_no`
- `sales_orders.note`
- `sales_order_lines.note`
- `skus.sku`
- `skus.name`
- `stock_items.short_name`
- `stock_items.name`

Use nested `orWhereHas` inside the search OR-closure. Do not use a single
`whereHas('lines.sku.stockItem', ...)` for all SKU fields, because that only searches stock item
fields and misses `skus.sku`, `skus.name`, and `sales_order_lines.note`.

Required structure:

```php
$query->where(function ($query) use ($like) {
    $query
        ->where('platform_order_id', 'like', $like)
        ->orWhere('recipient_name', 'like', $like)
        ->orWhere('recipient_phone', 'like', $like)
        ->orWhere('recipient_postal_code', 'like', $like)
        ->orWhere('recipient_state', 'like', $like)
        ->orWhere('recipient_city', 'like', $like)
        ->orWhere('recipient_address_line1', 'like', $like)
        ->orWhere('recipient_address_line2', 'like', $like)
        ->orWhere('tracking_no', 'like', $like)
        ->orWhere('note', 'like', $like)
        ->orWhereHas('lines', fn ($lineQuery) => $lineQuery
            ->where('note', 'like', $like))
        ->orWhereHas('lines.sku', fn ($skuQuery) => $skuQuery
            ->where('sku', 'like', $like)
            ->orWhere('name', 'like', $like))
        ->orWhereHas('lines.sku.stockItem', fn ($stockItemQuery) => $stockItemQuery
            ->where('short_name', 'like', $like)
            ->orWhere('name', 'like', $like));
});
```

The nested conditions must be `orWhereHas`, not `whereHas`, otherwise SKU/note search may accidentally
AND with the order column search.

Performance note:

- This v1 global search uses `%LIKE%` and several `orWhereHas` clauses.
- It is intentionally broad for operator convenience, but it is not index-friendly at high volume.
- The date/status guards above are still required.
- Future search optimization should use FULLTEXT, a search index table, or a dedicated search service.

Avoid N+1:

Keep eager loading:

```php
->with(['shop.tenant', 'lines.sku.stockItem'])
```

---

## Table Changes

Add `Note` column on the right side.

Recommended column order:

1. checkbox
2. Order ID
3. Address
4. Recipient
5. Items
6. Shipping method
7. Tracking no.
8. Status
9. Created / Printed
10. Note

Note display:

- Show order note only.
- Keep it compact.
- If note is long, truncate visually and put full text in `title`.
- If no note, show `-` or muted `No note`.

Do not show line notes in the table note column. Line notes are searchable, but table should stay
compact.

Update empty-state colspan to match the new column count.

---

## Export Links Must Carry New Filters

The existing sales order CSV/XLSX export links should include the new filters:

- platforms
- shops
- fulfillment statuses
- order statuses
- shipping methods
- date range
- date from
- date to
- q/search

Use the same query parameter names as the index URL aliases:

```text
platforms
shops
fulfillment
order_status
shipping
date_range
date_from
date_to
q
```

If selected ids are provided, selected ids should still take priority for bulk export, but keep the
filters in the URL where existing behavior already does so.

Update `SalesOrdersExport` and export controller to accept both:

- new array filters
- old scalar filters

Backward-compatible examples:

```text
shop=1                    -> shops = [1]
fulfillment=ready         -> fulfillment = ['ready']
order_status=pending      -> order_status = ['pending']
shipping=yamato,sagawa    -> shipping = ['yamato', 'sagawa']
```

Do not break the v4 order-id export guard:

- `ids=` empty must not export everything as "selected".
- selected ids, when non-empty, still take priority over filters.

Export performance guard:

- Export has no pagination, so it must be stricter than the index view.
- These broad-export guards apply to filter-based exports only.
- If non-empty selected ids are provided, export only those selected ids after tenant scoping and keep
  the existing selected-id priority behavior.
- For filter-based exports with no explicit fulfillment/order status filter, apply the same default
  active-orders rule as the index. Do not export all historical orders by default.
- If the user explicitly requests all statuses / historical statuses with `date_range = all`, block
  the export and ask for a date range.
- Do not allow `All dates + shipped/completed/cancelled` export.
- If historical statuses are selected, require an explicit date range.
- Custom export date range maximum is 365 days for v1.
- If a safer operational limit is needed, use 90 days for historical export and document it in the
  validation message.
- Export query should stream/chunk rows where possible; do not load very large result sets into memory.

Do not break:

- Sales Orders export v4 tests
- Import CSV
- courier export buttons

---

## Lang Keys

Add/update `lang/en/sales_orders.php` only.

Suggested keys:

```php
'field_platform' => 'Platform',
'field_shipping_method' => 'Shipping method',
'field_order_date' => 'Order date',
'field_date_from' => 'Date from',
'field_date_to' => 'Date to',
'date_all' => 'All dates',
'all_platforms' => 'All platforms',
'all_shops' => 'All shops',
'all_fulfillment_status' => 'All fulfillment',
'all_order_status' => 'All statuses',
'all_shipping_methods' => 'All shipping methods',
'multi_selected' => ':count selected',
'active_orders' => 'Active orders',
'date_last_3_days' => 'Last 3 days',
'date_last_7_days' => 'Last 7 days',
'date_last_30_days' => 'Last 30 days',
'date_last_3_months' => 'Last 3 months',
'date_last_1_year' => 'Last 1 year',
'date_custom' => 'Custom',
'search_placeholder' => 'Search order, address, recipient, SKU, tracking, note...',
'col_note' => 'Note',
```

Do not split locale files yet. Other locales inherit English during development.

---

## Tests

Add/update tests in `tests/Feature/SalesOrderTest.php` and `tests/Feature/SalesOrderExportTest.php`.

Required SalesOrderIndex tests:

1. `test_backfill_sales_order_date_action_sets_order_date`
   - Insert rows with a null `order_date`: one with `platform_ordered_at`, one without.
   - Call the `BackfillSalesOrderDate` action directly.
   - Assert the row with `platform_ordered_at` gets `order_date = platform_ordered_at`.
   - Assert the row without `platform_ordered_at` gets `order_date = created_at`.
   - Do not try to assert this purely from the migration run: under `RefreshDatabase` the migration
     executes against an empty table before any order exists, so the backfill must be tested through
     the callable action.

2. `test_sales_order_creating_hook_sets_order_date`
   - Factory-create an order with `platform_ordered_at` set; assert `order_date = platform_ordered_at`.
   - Factory-create an order with null `platform_ordered_at`; assert `order_date = created_at`.
   - Confirms the model `creating` hook populates `order_date` for factory/import/manual create
     without each path setting it explicitly.

3. `test_sales_order_index_uses_simple_pagination`
   - Assert rendered paginator does not require exact total count, or assert component uses
     `simplePaginate`.

4. `test_sales_order_index_default_view_shows_active_backlog_all_dates`
   - Old unshipped/backlog order remains visible by default.
   - Old shipped/completed order is hidden by default.
   - Active filter chip/control is visible.

5. `test_sales_order_index_historical_status_defaults_to_last_30_days`
   - Select shipped/completed/cancelled historical status while date range is all.
   - Assert date range becomes last 30 days and UI reflects it.

6. `test_sales_order_index_custom_date_range_cannot_exceed_365_days`
   - Set a custom range over 365 days.
   - Assert validation warning and no broad query/export.

7. `test_sales_order_index_shows_note_column`
   - Order has note.
   - Assert note visible.
   - Assert column label visible.

8. `test_sales_order_index_searches_address_phone_tracking_note_and_sku`
   - Create orders where each searchable field is unique.
   - Search each term and assert matching order appears while unrelated order does not.
   - Include:
     - address
     - phone
     - tracking no
     - order note
     - SKU code
     - SKU name
     - stock item short name

9. `test_sales_order_index_filters_by_multiple_platforms`
   - Create Amazon, Rakuten, Shopify shops/orders.
   - Select two platforms.
   - Assert only those two platforms appear.

10. `test_sales_order_index_filters_by_multiple_shops`
   - Select two shop ids.
   - Assert selected shops appear and unselected shop does not.

11. `test_sales_order_index_filters_by_multiple_fulfillment_statuses`
   - Select two statuses.
   - Assert matching orders appear.

12. `test_sales_order_index_filters_by_multiple_order_statuses`
   - Select two statuses.
   - Assert matching orders appear.

13. `test_sales_order_index_filters_by_multiple_shipping_methods`
   - Select Yamato and Sagawa.
   - Assert both appear.
   - Assert Japan Post / unset do not appear.

14. `test_sales_order_index_filters_by_unset_shipping_method`
   - Select internal not-set value.
   - Assert null shipping method orders appear.

15. `test_sales_order_index_filters_by_order_date_preset`
   - Create orders with `platform_ordered_at` across dates.
   - Select last 7 days.
   - Assert older order hidden.

16. `test_sales_order_index_order_date_filter_uses_backfilled_created_at`
    - Create one order with null `platform_ordered_at`.
    - Ensure preset/custom date uses `created_at`.

17. `test_sales_order_index_filters_by_custom_order_date_range`
    - Set custom from/to.
    - Assert only orders in range appear.

18. `test_sales_order_index_filter_changes_clear_selection`
    - Select an order.
    - Change any filter.
    - Assert `selectedIds` is empty.

19. `test_sales_order_index_export_links_include_new_filters`
    - Set multi filters.
    - Assert export CSV/XLSX URLs include the new filter parameters.

Existing test migration:

- Update existing SalesOrder index tests that currently call `->set('shopId', (string) $shop->id)`.
- New property is `shopIds`, so those direct Livewire property sets should become:

```php
->set('shopIds', [(string) $shop->id])
```

- This is separate from URL scalar normalization. URL normalization protects old browser URLs;
  direct Livewire test property names still need to be updated.
- Update the existing empty-state colspan assertion from `colspan="9"` to `colspan="10"` because the
  table gains a Note column.
- Any index test that expects shipped/completed/cancelled orders to be visible must explicitly set a
  historical status/date filter or disable `activeOnly`; the default index view intentionally hides
  historical orders.

Required SalesOrdersExport tests:

20. `test_sales_order_export_respects_multi_select_filters`
    - Export should match index filters for platforms, shops, statuses, shipping methods, and date.

21. `test_sales_order_export_search_matches_new_search_fields`
    - Export search should match tracking/order note/SKU/stock item short name too.

22. `test_sales_order_export_blocks_unbounded_historical_export`
    - No explicit status filter + all dates should export active orders only, not full history.
    - Historical status + all dates should be blocked.
    - Historical status + valid explicit date range should proceed.

23. `test_sales_order_export_rejects_custom_range_over_365_days`
    - Export should not materialize very broad historical ranges.

Run:

```bash
php artisan test
```

If `php` is not globally available:

```bash
C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan test
```

---

## Constraints

- Class-based Livewire only.
- No Volt.
- No TypeScript.
- Keep tenant scoping server-side.
- Do not implement courier CSV generation in this task.
- Do add `sales_orders.order_date` and use it for date filtering/sorting/export filtering.
- Do not use `COALESCE(platform_ordered_at, created_at)` in normal index/export filter queries.
- Use `simplePaginate(30)` on this operational index page.
- Do not change the meaning of stored `shipping_method` values in this task.
- Do not remove existing bulk action buttons.
- Do not remove existing import/export buttons.
- Do not use `wire:navigate` for file downloads.
- Make sure table text does not overlap.
- Horizontal scroll is acceptable on small screens.
- Keep the interface dense and operational, not marketing-like.
