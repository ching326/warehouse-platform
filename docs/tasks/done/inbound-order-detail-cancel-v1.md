# Task: Inbound Order Detail + Safer Cancel v1

## Stack

Laravel 13, Livewire 4 class-based components, Flux UI, SQLite dev, PHP 8.3, plain Blade.
Use ASCII punctuation in code, Blade, lang files, and this task file.

---

## Goal

Make inbound cancellation safer without slowing down the normal receiving workflow.

Current problem:

- `/inbound` shows `Cancel order` directly in each table row.
- Cancel is a low-frequency, high-risk action.
- Row-level cancel can be clicked by mistake too easily.

New behavior:

- `/inbound` keeps common actions only:
  - pending orders: `Mark arrived`
  - arrived / partially received orders: `Receive`
- Remove `Cancel order` from the inbound index table.
- Make the inbound order number clickable.
- Clicking the order number opens an inbound order detail page.
- `Cancel order` is available only on the detail page.

This mirrors the safer outbound detail/cancel pattern in `docs/tasks/outbound-order-detail-cancel-v1.md`.

---

## Routes

Add:

```php
GET /inbound/{order}
```

Route name:

```php
inbound.show
```

Important route order:

```php
Route::get('/inbound', InboundOrderIndex::class)->name('inbound.index');
Route::get('/inbound/create', InboundOrderCreate::class)->name('inbound.create');
Route::get('/inbound/{order}/receive', InboundOrderReceive::class)->name('inbound.receive');
Route::get('/inbound/{order}', InboundOrderDetail::class)->name('inbound.show');
```

Keep `/inbound/{order}/receive` before `/inbound/{order}`.

---

## Livewire Component

Create:

```php
app/Livewire/InboundOrderDetail.php
```

Page:

```text
/inbound/{order}
```

Responsibilities:

- show inbound order detail
- show expected lines and received progress
- allow marking arrived when status is `pending`
- allow receiving when status is `arrived` or `partially_received`
- allow cancellation only from detail page when it is safe

Tenant scope:

- internal users can see all inbound orders
- tenant users can only see inbound orders for their active tenant
- unauthorized access returns 404 or 403; be consistent with existing inbound/outbound pages

Important implementation detail:

Route-model binding alone is not enough. `mount(InboundOrder $order)` can receive an order outside
the current user's tenant scope. Re-scope it inside the component:

```php
$this->order = InboundOrder::query()
    ->whereIn('tenant_id', $this->visibleTenantIds())
    ->findOrFail($order->id);
```

Use the existing inbound page helper name `visibleTenantIds()` for consistency. If internal users
use `null` to mean all tenants, mirror the current `InboundOrderIndex` behavior exactly.

Any action method (`markArrived`, `cancel`) must also re-load/re-check the order through the same
tenant-scoped query before changing anything.

Load relationships:

```php
tenant
warehouse
createdBy
arrivedBy
receivedBy
lines.sku
lines.stockItem
lines.receipts.location
```

Use only relationships that exist; if `receipts.location` is not available, show receipt/location
summary from the existing receipt relationship structure.

---

## Index Page Changes

File:

```php
resources/views/livewire/inbound-order-index.blade.php
```

### 1. Remove row-level Cancel button

For pending orders, the action column should show only:

```text
Mark arrived
```

For arrived or partially received orders, the action column should show only:

```text
Receive
```

Remove `Cancel order` from this table.

Do not call `cancel()` from the index UI anymore.

Remove `InboundOrderIndex::cancel()` after migrating the existing cancel tests to
`InboundOrderDetail`. Do not keep dead index-cancel code just to satisfy old tests.

### 2. Make order number clickable

In the first column, currently it shows:

```text
ref
#id
```

Change `#id` into a clear link:

```php
<a href="{{ route('inbound.show', $order) }}" wire:navigate>#{{ $order->id }}</a>
```

If `ref` is empty, still show clickable `#id`.

The row itself does not need to be clickable. Only the order number is enough.

Style it so it is obviously clickable, similar to the outbound order-number link:

- accent color
- bold
- underline or strong link treatment
- small but visible

---

## Detail Page UI

Create Blade view:

```php
resources/views/livewire/inbound-order-detail.blade.php
```

Use the same visual style as other detail pages.

Suggested sections:

### Header card

Show:

- order ref or `-`
- visible `#id`
- status badge
- tenant
- warehouse
- expected arrival date
- created date
- created by
- arrived date
- arrived by
- received date
- received by

### Actions card

If status is `pending`, show:

- `Mark arrived` teal primary button
- `Cancel order` danger button aligned to the right

If status is `arrived`, show:

- `Receive` teal primary button
- `Cancel order` danger button aligned to the right only when no line has received quantity

If status is `partially_received`, show:

- `Receive` teal primary button
- no cancel button

If status is `received` or `cancelled`, show no action buttons.

Cancel button rules:

- visible only when status is `pending`, or status is `arrived` and no line has `received_qty > 0`
- red/danger style
- requires `wire:confirm`
- confirm text should warn that this does not add stock to inventory and cannot be used after receiving starts

### Order details card

Show:

- reference
- note
- expected arrival date
- package count if the column exists
- courier / tracking no. if the columns exist

Use `-` for empty values.

### Lines card

Show expected lines.

Columns:

- SKU
- Stock item
- Expected qty
- Received qty
- Remaining qty
- Note

For each line:

- show SKU code and name
- show stock item code/name if linked
- remaining qty = expected_qty - received_qty
- highlight partially received / over-received only if current logic supports it

Optional but useful:

- show receipt history under each line if `InboundReceipt` records exist
- show received location names if available

---

## Mark Arrived Logic

Move or duplicate the existing safe `markArrived()` behavior into `InboundOrderDetail`.

Requirements:

- only pending orders can be marked arrived
- re-load through tenant-scoped query before update
- update:
  - `status = arrived`
  - `arrived_at = now()`
  - `arrived_by_user_id = Auth::id()`
- after success, stay on detail page and show flash message

Keep the `Mark arrived` button on `/inbound` index for workflow speed, unless you intentionally
choose to move it to detail only. If it stays on the index, both index and detail actions must use
identical tenant/status guards.

---

## Cancellation Logic

Move the existing safe cancel logic from `InboundOrderIndex::cancel()` into `InboundOrderDetail`.
After moving it, remove the cancel method from `InboundOrderIndex`.

Current index logic allows cancelling only when:

- status is `pending` or `arrived`
- no inbound order line has `received_qty > 0`

Keep that behavior.

Requirements:

- cancellation runs in a DB transaction or a single guarded update
- re-load the order through the tenant-scoped query before cancelling
- block cancellation when any line has `received_qty > 0`
- update:
  - `status = cancelled`
- do not call `InventoryService`
- do not change stock or inventory movements
- after successful cancel, stay on detail page and show status badge as cancelled
- show flash message: `Inbound order cancelled. Stock was not added to inventory.`

Do not allow cancelling:

- partially received orders
- received orders
- already cancelled orders
- orders outside the user's tenant scope

---

## Language Keys

Add to `lang/en/inbound.php` as needed:

```php
'detail_page_title' => 'Inbound Order',
'detail_page_subtitle' => 'Review arrival, expected items, and receiving progress.',
'section_actions' => 'Order Actions',
'section_metadata' => 'Order Details',
'section_lines' => 'Items',
'btn_view' => 'View',
'btn_back_to_index' => 'Back to inbound orders',
'cannot_cancel_after_receiving' => 'Inbound orders cannot be cancelled after receiving has started.',
'order_cancelled_detail' => 'Inbound order cancelled. Stock was not added to inventory.',
```

Reuse existing:

- `btn_mark_arrived`
- `btn_receive`
- `btn_cancel_order`
- `confirm_arrive`
- `confirm_cancel`
- status labels

Follow the existing locale-stub pattern for `ja` / `zh_*`.

---

## Tests

Update / add tests in:

```php
tests/Feature/InboundOrderTest.php
```

Before adding new tests, migrate existing cancel tests away from `InboundOrderIndex::class`:

- existing test that cancels a pending/arrived inbound order
- existing test that blocks cancel after receiving has started, if present
- any existing out-of-scope cancel test

Those tests should call `cancel` on `InboundOrderDetail::class` instead.

Required tests:

1. inbound index no longer shows row-level `Cancel order` for pending orders
2. inbound index still shows `Mark arrived` for pending orders
3. inbound index still shows `Receive` for arrived / partially received orders
4. inbound index order number links to `inbound.show`
5. inbound detail route renders for an internal user
6. inbound detail route is tenant-scoped for tenant users
7. pending inbound detail shows `Mark arrived` and `Cancel order`
8. arrived inbound detail shows `Receive` and `Cancel order` when nothing is received
9. partially received inbound detail shows `Receive` and does not show `Cancel order`
10. received inbound detail does not show `Cancel order`
11. cancelled inbound detail does not show `Cancel order`
12. marking arrived from detail sets `arrived_at` and `arrived_by_user_id`
13. cancelling from detail sets status to cancelled
14. cancelling from detail does not create inventory movements
15. cancelling from detail is blocked after any line has `received_qty > 0`
16. cannot cancel another tenant's inbound order
17. detail page shows expected lines with expected / received / remaining quantities

Run:

```bash
php artisan test tests/Feature/InboundOrderTest.php
php artisan test
```

---

## Acceptance Criteria

- `/inbound` no longer has a visible row-level `Cancel order` button.
- `/inbound` order number is clickable and clearly styled as a link.
- `/inbound/{order}` detail page exists.
- Pending inbound orders can be cancelled only from the detail page.
- Arrived inbound orders can be cancelled from the detail page only if receiving has not started.
- Partially received / received / cancelled inbound orders cannot be cancelled.
- Cancel does not change inventory.
- Tenant scope is enforced.
- Tests pass.
