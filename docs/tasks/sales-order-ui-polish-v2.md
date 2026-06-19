# Task: Sales Order UI Polish v2

## Goal

Polish Sales Orders index and detail UI based on the latest shipping workflow feedback.

This is a UI/label/layout task only. Do not add migrations. Do not change status transition logic.

---

## Part A: Sales Orders index table

File:

- `resources/views/livewire/sales-order-index.blade.php`
- `app/Livewire/SalesOrderIndex.php`
- `lang/en/sales_orders.php`

### 1. Move Shop content under Order ID

Current table has a separate `Shop` column.

Change:

- Remove the separate `Shop` column.
- Move existing shop display under the platform order id inside the order id cell.
- The cell should show:
  - first line: clickable platform order id
  - second line: shop name
  - third line: tenant code / platform

Example:

```blade
<flux:link href="{{ route('sales.orders.show', $order) }}" wire:navigate>
    <strong>{{ $order->platform_order_id ?: '-' }}</strong>
</flux:link>
<span class="subtle">{{ $order->shop->name }}</span>
<span class="subtle">{{ $order->shop->tenant->code }} / {{ $order->shop->platform }}</span>
```

Do not show `sales_orders.id` under the order id.

### 2. Rename Platform Order ID column to Order ID

Change lang key:

```php
'col_platform_order_id' => 'Order ID',
```

Do not rename database fields. This is display text only.

### 3. Address postcode should not be bold

In Address column:

- Do not wrap postcode in `<strong>`.
- Use muted/subtle text for postcode.
- Keep state/city/address lines readable.

Example:

```blade
<span class="subtle">{{ $order->recipient_postal_code ?: '-' }}</span>
```

### 4. Items column: show stock item short name after SKU

Currently item line shows:

```text
qty x SKU
```

Change to:

```text
qty x SKU - short name
```

Source of short name:

1. `$line->sku?->stockItem?->short_name`
2. if null/empty, `$line->sku?->name`
3. if both empty, show only SKU

Example:

```blade
@php
    $skuCode = $line->sku?->sku ?? '-';
    $itemLabel = trim((string) ($line->sku?->stockItem?->short_name ?: $line->sku?->name ?: ''));
@endphp

<strong>{{ $skuCode }}</strong>
@if ($itemLabel !== '')
    <span class="subtle">- {{ $itemLabel }}</span>
@endif
```

Update `SalesOrderIndex` query eager loading:

```php
->with(['shop.tenant', 'lines.sku.stockItem'])
```

Keep showing only ready lines:

```php
$order->lines->where('line_status', \App\Models\SalesOrderLine::STATUS_READY)
```

### 5. Fulfillment status and order status should share one column

Current table has:

- Fulfillment column
- Status column

Change:

- Combine both badges into one column.
- Column label can be `Status`.
- Show fulfillment badge first, order status badge second.
- Keep both badges compact.

Example:

```blade
<div class="status-stack">
    <flux:badge color="{{ $this->fulfillmentStatusColor($order->fulfillment_status) }}">
        {{ $this->fulfillmentStatusLabel($order->fulfillment_status) }}
    </flux:badge>
    <flux:badge color="{{ $this->orderStatusColor($order->order_status) }}">
        {{ $this->orderStatusLabel($order->order_status) }}
    </flux:badge>
</div>
```

Add CSS if needed:

```css
.status-stack {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
}
```

### 6. Rename Ready label

Change fulfillment status label from `Ready` to one of:

- preferred: `Ship Ready`
- alternative: `Ready to ship`

Use **Ship Ready** for compact table UI.

Update:

```php
'fulfillment_ready' => 'Ship Ready',
```

Do not rename constants or DB values. Only display text changes.

Button labels may stay `Mark Ready` / `Unmark Ready` unless tests become inconsistent. The status
badge should show `Ship Ready`.

### 7. Bulk action buttons always visible

Current selected-actions row only appears when `count($selectedIds) > 0`.

Change:

- Always show the bulk action row.
- If no selected rows:
  - badge should show `0 orders selected`
  - action buttons should be disabled
  - export selected controls must be genuinely non-navigable
- If selected rows exist:
  - behavior remains the same.

Actions that should always be visible:

- selected count badge
- Mark Ready
- Hold
- Release hold
- Cancel
- Export selected (CSV)
- Export selected (XLSX)

Implementation guidance:

```blade
@php
    $hasSelection = count($selectedIds) > 0;
@endphp

<div class="active-filter-row sales-order-action-row">
    <flux:badge color="{{ $hasSelection ? 'blue' : 'zinc' }}">
        {{ trans_choice('sales_orders.selected_count', count($selectedIds), ['count' => count($selectedIds)]) }}
    </flux:badge>

    <flux:button ... :disabled="! $hasSelection">
        ...
    </flux:button>
</div>
```

For export selected links:

- When there is no selection, do **not** render a real export link.
- Render disabled buttons instead, or render `href="#"` with both `aria-disabled="true"` and a
  click prevent handler.
- This is a hard correctness requirement, not only styling.
- Do not call the export route with empty selected ids from the always-visible row.
- Reason: the v5 export controller treats `ids=` as "no id filter", so a clickable
  `Export selected` link with zero selected rows could export the full filtered result set.
- Keep full export CSV/XLSX buttons in the top toolbar unchanged.

### 8. Update column count / empty colspan

After removing Shop column and combining statuses:

Expected columns:

1. checkbox
2. Order ID
3. Address
4. Recipient
5. Items
6. Shipping method
7. Tracking no.
8. Status
9. Created / Printed

Update empty-state colspan to `9`.

---

## Part B: Sales Order detail action layout

File:

- `resources/views/livewire/sales-order-detail.blade.php`
- `resources/views/inventory.blade.php` if CSS helper is needed
- `lang/en/sales_orders.php` if label changes are needed

### 1. Move Cancel Order button into Order Actions row

Current `Cancel order` button is near the bottom beside Back to Orders.

Change:

- Move `Cancel order` into the main `Order Actions` section.
- Place it on the same row as:
  - Unmark Ready
  - Hold
  - Backorder
  - Edit Lines
- Align Cancel Order to the right side of that action row.
- Keep the existing visibility/guard condition:

```blade
@if (
    in_array($order->order_status, ['pending', 'on_hold', 'backorder'], true)
    && in_array($order->fulfillment_status, ['unfulfilled', 'ready'], true)
)
```

- Keep `wire:confirm`.
- Remove the old bottom Cancel Order button.
- Bottom row should only keep Back to Orders.

Suggested structure:

```blade
<div class="sales-order-detail-actions">
    <div class="sales-order-detail-actions-main">
        {{-- mark/unmark/hold/backorder/edit lines buttons --}}
    </div>

    <div class="sales-order-detail-actions-danger">
        {{-- cancel order button --}}
    </div>
</div>
```

CSS:

```css
.sales-order-detail-actions {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
}

.sales-order-detail-actions-main {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.sales-order-detail-actions-danger {
    margin-left: auto;
}
```

### 2. Use teal background for main action buttons

Buttons such as:

- Mark Ready
- Unmark Ready
- Hold
- Release hold
- Backorder
- Release backorder
- Edit Lines

should use teal background styling.

Do not make Cancel Order teal. Cancel remains danger/red.

Use existing Flux `variant="primary"`. In this app, Flux primary is already teal.

The following buttons are currently `variant="outline"` and must be switched to `variant="primary"`:

- `unmarkReady`
- `hold`
- `markBackorder`
- `editLines`

These buttons are already primary and should remain primary:

- `markReady`
- `releaseHold`
- `releaseBackorder`

Cancel Order must remain `variant="danger"`.

Do not use `var(--teal)`. That CSS variable does not exist in this app. If a custom class becomes
necessary later, use the existing app accent variable, `var(--accent)`, but v2 should prefer Flux
primary.

---

## Tests

Add/update tests around Sales Orders UI.

Required coverage:

1. `test_sales_order_index_groups_shop_under_order_id`
   - Assert no separate Shop column header.
   - Assert platform order id, shop name, tenant code/platform appear in the same order id area.

2. `test_sales_order_index_renames_platform_order_id_column_to_order_id`
   - Assert `Order ID` visible.
   - Assert `Platform order ID` header not visible on index.

3. `test_sales_order_index_address_postcode_is_not_bold`
   - Render page and assert postcode is present.
   - If possible, assert it is not inside `<strong>`.

4. `test_sales_order_index_items_show_stock_item_short_name_or_sku_name`
   - SKU with stock item short name should show short name after SKU.
   - SKU without stock item short name should show SKU name.

5. `test_sales_order_index_combines_fulfillment_and_order_status_columns`
   - Assert there is no separate Fulfillment column.
   - Assert status column contains both `Ship Ready` and order status.

6. `test_sales_order_index_bulk_action_row_is_visible_with_no_selection`
   - Assert selected count shows zero.
   - Assert Mark Ready / Hold / Cancel / Export selected labels are visible.
   - Assert status actions are disabled when no selection.
   - Assert export selected controls are genuinely non-navigable when no selection.
   - Specifically assert there is no link to `sales.orders.export` containing `ids=` when
     `selectedIds` is empty.

7. `test_sales_order_index_empty_state_colspan_matches_new_column_count`
   - Empty result should use `colspan="9"`.

8. `test_sales_order_detail_cancel_button_is_in_actions_header_area`
   - Assert Cancel Order appears in the action section.
   - Assert bottom form actions no longer contain Cancel Order beside Back to Orders.

9. `test_sales_order_detail_main_actions_use_teal_style`
   - Assert main action buttons include expected teal class or primary variant, depending on implementation.
   - Assert Cancel Order remains danger/red.

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

- No migrations.
- No database field renames.
- No status logic changes.
- No route changes.
- Do not break existing export/download links.
- Do not remove current filters.
- Keep tenant scoping unchanged.
- Keep file downloads as real links, not `wire:navigate`.
- Class-based Livewire only.
- No TypeScript.
- Text must not overlap on desktop or small laptop widths. Use `.table-scroll` and sensible min-widths.
