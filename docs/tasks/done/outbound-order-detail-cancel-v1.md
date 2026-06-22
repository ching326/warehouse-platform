# Task: Outbound Order Detail + Safer Cancel v1

## Stack

Laravel 13, Livewire 4 (class-based components, not Volt), Flux UI (`livewire/flux ^2.14`),
SQLite (dev), PHP 8.3. Plain Blade views only. No TypeScript.

---

## Goal

Make outbound cancellation safer.

Current problem:

- `/outbound` shows `Cancel order` directly in each table row.
- Cancel is a low-frequency, high-risk action.
- Row-level cancel can be clicked by mistake too easily.

New behavior:

- `/outbound` row actions should only show the common action: `Ship`.
- Remove `Cancel order` from the outbound index table.
- Make the outbound order number clickable.
- Clicking the order number opens an outbound order detail page.
- `Cancel order` is available only on the detail page.

---

## Routes

Add:

```php
GET /outbound/{order}
```

Route name:

```php
outbound.show
```

Important route order:

```php
Route::get('/outbound', OutboundOrderIndex::class)->name('outbound.index');
Route::get('/outbound/create', OutboundOrderCreate::class)->name('outbound.create');
Route::get('/outbound/{order}/ship', OutboundOrderShip::class)->name('outbound.ship');
Route::get('/outbound/{order}', OutboundOrderDetail::class)->name('outbound.show');
```

Keep `/outbound/{order}/ship` before `/outbound/{order}`.

---

## Livewire Component

Create:

```php
app/Livewire/OutboundOrderDetail.php
```

Page:

```text
/outbound/{order}
```

Responsibilities:

- show outbound order detail
- show shipment / recipient / line summary
- show inventory reservation / shipment status
- allow cancellation only when order status is `pending`

Tenant scope:

- internal users can see all outbound orders
- tenant users can only see outbound orders for their active tenant
- unauthorized access returns 404 or 403; be consistent with existing outbound pages

Important implementation detail:

Route-model binding alone is not enough. `mount(OutboundOrder $order)` can receive an order outside
the current user's tenant scope. Re-scope it inside the component:

```php
$this->order = OutboundOrder::query()
    ->whereIn('tenant_id', $this->visibleTenantIds())
    ->findOrFail($order->id);
```

Use the existing outbound page helper name `visibleTenantIds()` for consistency. Do not introduce a
different helper name unless a shared auth/tenant helper already exists.

The cancel action must also re-load/re-check the order through the same tenant-scoped query before
changing anything.

Load relationships:

```php
tenant
warehouse
createdBy
shippedBy
cancelledBy
parentLines.sku
parentLines.stockItem
parentLines.childLines.stockItem
```

---

## Index Page Changes

File:

```php
resources/views/livewire/outbound-order-index.blade.php
```

### 1. Remove row-level Cancel button

For pending orders, the action column should show only:

```text
Ship
```

Remove `Cancel order` from this table.

Do not call `cancel()` from the index UI anymore.

Remove `OutboundOrderIndex::cancel()` after migrating the existing cancel tests to
`OutboundOrderDetail`. Do not keep dead index-cancel code just to satisfy old tests.

### 2. Make order number clickable

In the first column, currently it shows:

```text
ref
#id
```

Change `#id` into a link:

```php
<a href="{{ route('outbound.show', $order) }}" wire:navigate>#{{ $order->id }}</a>
```

If `ref` is empty, still show clickable `#id`.

The row itself does not need to be clickable. Only the order number is enough.

---

## Detail Page UI

Create Blade view:

```php
resources/views/livewire/outbound-order-detail.blade.php
```

Use the same visual style as other detail pages.

Suggested sections:

### Header card

Show:

- order ref or `-`
- clickable/visible `#id`
- status badge
- tenant
- warehouse
- expected ship date
- created date
- created by

### Actions card

If status is `pending`, show:

- `Ship` teal primary button
- `Cancel order` danger button aligned to the right

Cancel button rules:

- only visible when `status = pending`
- red/danger style
- requires `wire:confirm`
- text should warn that reserved stock will be returned

If status is `shipped` or `cancelled`, no cancel button.

### Recipient card

Show:

- recipient name
- phone
- postal code
- country code
- state / city
- address lines

Show `-` for empty values.

### Shipment card

Show:

- shipping method
- courier
- tracking no.
- package count
- package weight
- shipped at
- shipped by
- ship note

### Lines card

Show parent lines.

Columns:

- SKU
- Stock item
- Qty
- Note

For virtual bundle parent lines:

- show bundle SKU as parent
- show child component lines below it, indented
- child lines show component stock item code/name and component qty

---

## Cancellation Logic

Move the existing safe cancel logic from `OutboundOrderIndex::cancel()` into `OutboundOrderDetail`.
After moving it, remove the cancel method from `OutboundOrderIndex`.

Requirements:

- only pending orders can be cancelled
- cancellation runs in a DB transaction
- re-load the order through the tenant-scoped query before cancelling
- release reserved stock by iterating the existing `leafLines` relation, matching the current index cancel logic
- call `InventoryService::releaseReserve()` with the same context payload currently used by index cancel:

```php
[
    'ref_type' => 'outbound_order',
    'ref_id' => (string) $order->id,
    'user_id' => Auth::id(),
]
```

- update:
  - `status = cancelled`
  - `cancelled_at = now()`
  - `cancelled_by_user_id = Auth::id()`
- write inventory movements through `InventoryService::releaseReserve()`
- redirect back to `/outbound` or stay on detail with a success message

Recommended UX:

- after successful cancel, stay on detail page and show status badge as cancelled
- show flash message: `Outbound order cancelled. Reserved stock has been returned.`

Do not allow cancelling:

- shipped orders
- already cancelled orders
- orders outside the user's tenant scope

---

## Language Keys

Add to `lang/en/outbound.php` as needed:

```php
'detail_page_title' => 'Outbound Order',
'detail_page_subtitle' => 'Review shipment, recipient, and reserved stock details.',
'section_actions' => 'Order Actions',
'section_recipient' => 'Recipient',
'section_lines' => 'Items',
'section_metadata' => 'Order Details',
'btn_view' => 'View',
'btn_back_to_index' => 'Back to outbound orders',
```

Reuse existing:

- `btn_ship`
- `btn_cancel_order`
- `confirm_cancel`
- `order_cancelled`
- status labels

---

## Tests

Update / add tests in:

```php
tests/Feature/OutboundOrderTest.php
```

Required tests:

Before adding new tests, migrate the existing cancel tests away from `OutboundOrderIndex::class`:

- `test_cancel_releases_reserved_stock`
- `test_cancel_is_blocked_for_shipped_order`
- the existing out-of-scope or already-cancelled cancel test around the same section

Those tests should call `cancel` on `OutboundOrderDetail::class` instead.

1. outbound index no longer shows row-level `Cancel order` for pending orders
2. outbound index shows `Ship` for pending orders
3. outbound index order number links to `outbound.show`
4. outbound detail route renders for an internal user
5. outbound detail route is tenant-scoped for tenant users
6. pending outbound detail shows `Cancel order`
7. shipped outbound detail does not show `Cancel order`
8. cancelled outbound detail does not show `Cancel order`
9. cancelling from detail releases reserved stock
10. cancelling from detail writes release reserve inventory movement
11. cancelling from detail sets `cancelled_at` and `cancelled_by_user_id`
12. cannot cancel another tenant's outbound order
13. detail page shows virtual bundle child component lines

Run:

```bash
php artisan test tests/Feature/OutboundOrderTest.php
php artisan test
```

---

## Acceptance Criteria

- `/outbound` no longer has a visible `Cancel order` row button.
- `/outbound` order number is clickable.
- `/outbound/{order}` detail page exists.
- Pending outbound orders can be cancelled only from the detail page.
- Cancel still releases reserved stock correctly.
- Shipped/cancelled orders cannot be cancelled.
- Tenant scope is enforced.
- Tests pass.
