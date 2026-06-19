# Task: Sales Order Index Filters + Note Column UI v1

## Goal

Improve the Sales Orders index into a stronger daily shipping work surface.

The page should let staff quickly find and filter orders by:

- platform, shop, fulfillment status, order status
- shipping method, including multiple selected methods
- order date range
- broad search across order, address, recipient, SKU, tracking, and note

Also add a `Note` column back to the table so staff can see order notes without opening detail.

This task is UI/query behavior only. Do not implement courier CSV generation here.

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
    - last 3 days
    - last 7 days
    - last 1 month
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

- If the business needs `yamato_nekopos`, `yamato_takkyubin`, etc., add them as explicit shipping
  method values in a separate task.
- Courier export should then map detailed methods back to a carrier family:
  - `yamato_nekopos` -> Yamato export
  - `yamato_takkyubin` -> Yamato export
  - `sagawa_*` -> Sagawa export

Do not mix this migration into the filter UI task.

---

## Date Filter Semantics

Use **Order date** as the user-facing label.

Query rule:

- Prefer `sales_orders.platform_ordered_at` when it is not null.
- Fall back to `sales_orders.created_at` when `platform_ordered_at` is null.

Reason:

- Amazon reports have a real platform purchase/order date.
- Manually created orders may not have `platform_ordered_at`.

Implementation approach:

Use a SQL expression or grouped conditions so the effective date is:

```sql
COALESCE(platform_ordered_at, created_at)
```

Date presets should use the app date/time consistently. For UI filtering, app timezone is OK unless
a later courier/date task says otherwise. Display dates as `YYYY-MM-DD`.

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

#[Url(as: 'date_range', except: '')]
public string $dateRange = 'last_7_days';

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

Acceptable simpler v1:

- Use a visually clean checkbox group panel in the filter area.
- Do not use the native `<select multiple>` unless it looks acceptable and is easy to use.

Important:

- Multi-select filters should be easy on a 12-inch laptop.
- Avoid very tall panels that push the table too far down.
- If options exceed about 8 items, make the option list scroll.

---

## Suggested Layout

Use three compact rows above the table.

### Row 1: Main Filters

Left to right:

1. Platform multi-select
2. Shop multi-select
3. Fulfillment status multi-select
4. Order status multi-select
5. Shipping method multi-select
6. Create order button
7. Import CSV button

Keep export CSV / XLSX buttons either:

- in the same row if space allows, or
- in the selected-actions row, grouped with other export actions.

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

- Last 3 days
- Last 7 days
- Last 1 month
- Last 3 months
- Last 1 year
- Custom

If `Custom` is selected, show:

- Date from
- Date to

If not custom, hide or disable the custom date inputs.

---

## Query Requirements

Update the `render()` query.

### Platform filter

Filter by `shops.platform`.

Use `whereHas('shop', ...)` or join carefully.

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

### Date filter

Use effective order date:

```sql
COALESCE(platform_ordered_at, created_at)
```

Preset ranges:

- `last_3_days`
- `last_7_days`
- `last_1_month`
- `last_3_months`
- `last_1_year`
- `custom`

For custom:

- validate/ignore invalid date strings safely
- if only from is provided, filter from that date onward
- if only to is provided, filter up to that date

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

Use `whereHas('lines.sku.stockItem', ...)` as needed.

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

If selected ids are provided, selected ids should still take priority for bulk export, but keep the
filters in the URL where existing behavior already does so.

Update `SalesOrdersExport` and export controller if they currently only understand scalar filters.

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
'all_platforms' => 'All platforms',
'all_shops' => 'All shops',
'all_fulfillment_status' => 'All fulfillment',
'all_order_status' => 'All statuses',
'all_shipping_methods' => 'All shipping methods',
'multi_selected' => ':count selected',
'date_last_3_days' => 'Last 3 days',
'date_last_7_days' => 'Last 7 days',
'date_last_1_month' => 'Last 1 month',
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

1. `test_sales_order_index_shows_note_column`
   - Order has note.
   - Assert note visible.
   - Assert column label visible.

2. `test_sales_order_index_searches_address_phone_tracking_note_and_sku`
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

3. `test_sales_order_index_filters_by_multiple_platforms`
   - Create Amazon, Rakuten, Shopify shops/orders.
   - Select two platforms.
   - Assert only those two platforms appear.

4. `test_sales_order_index_filters_by_multiple_shops`
   - Select two shop ids.
   - Assert selected shops appear and unselected shop does not.

5. `test_sales_order_index_filters_by_multiple_fulfillment_statuses`
   - Select two statuses.
   - Assert matching orders appear.

6. `test_sales_order_index_filters_by_multiple_order_statuses`
   - Select two statuses.
   - Assert matching orders appear.

7. `test_sales_order_index_filters_by_multiple_shipping_methods`
   - Select Yamato and Sagawa.
   - Assert both appear.
   - Assert Japan Post / unset do not appear.

8. `test_sales_order_index_filters_by_unset_shipping_method`
   - Select internal not-set value.
   - Assert null shipping method orders appear.

9. `test_sales_order_index_filters_by_order_date_preset`
   - Create orders with `platform_ordered_at` across dates.
   - Select last 7 days.
   - Assert older order hidden.

10. `test_sales_order_index_order_date_filter_falls_back_to_created_at`
    - Create one order with null `platform_ordered_at`.
    - Ensure preset/custom date uses `created_at`.

11. `test_sales_order_index_filters_by_custom_order_date_range`
    - Set custom from/to.
    - Assert only orders in range appear.

12. `test_sales_order_index_filter_changes_clear_selection`
    - Select an order.
    - Change any filter.
    - Assert `selectedIds` is empty.

13. `test_sales_order_index_export_links_include_new_filters`
    - Set multi filters.
    - Assert export CSV/XLSX URLs include the new filter parameters.

Required SalesOrdersExport tests:

14. `test_sales_order_export_respects_multi_select_filters`
    - Export should match index filters for platforms, shops, statuses, shipping methods, and date.

15. `test_sales_order_export_search_matches_new_search_fields`
    - Export search should match tracking/order note/SKU/stock item short name too.

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
- Do not change the meaning of stored `shipping_method` values in this task.
- Do not remove existing bulk action buttons.
- Do not remove existing import/export buttons.
- Do not use `wire:navigate` for file downloads.
- Make sure table text does not overlap.
- Horizontal scroll is acceptable on small screens.
- Keep the interface dense and operational, not marketing-like.
