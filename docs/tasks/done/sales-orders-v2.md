# Task: Sales Orders v2 -- Status Management and Line Editing

## Pre-conditions

Sales Orders v1 (commit a16e848) and Fulfillment Groups v1 (commit bafb8f1) must be deployed.

Existing models, constants, and passing tests must not be modified.

---

## Context

Sales orders are created with `fulfillment_status = unfulfilled` and `order_status = pending`.
There is currently no UI to:
- Transition an order to `ready` so it can be grouped for fulfillment
- Put an order on hold or mark it as backorder
- Edit order lines after creation

This task adds those actions to `SalesOrderDetail` and adds bulk mark-as-ready to
`SalesOrderIndex`.

---

## Status transition rules

### fulfillment_status

```
unfulfilled --> ready        (Mark Ready: operator confirms order is shippable)
ready --> unfulfilled        (Unmark Ready: pull back from fulfillment queue)
```

Only `in_group`, `shipped`, `cancelled` are terminal from the fulfillment side.
`FulfillmentGroupCreate` and `OutboundOrderObserver` manage those transitions; do not
add UI actions for them here.

### order_status

```
pending --> on_hold          (Hold: pause processing)
on_hold --> pending          (Release Hold)
pending --> backorder        (Backorder: waiting for stock or supplier)
backorder --> pending        (Release Backorder)
```

`cancelled` and `completed` are terminal; no release action.

### Combined guard table

Before allowing any status action, check both columns:

| Action | Allowed order_status | Allowed fulfillment_status |
|---|---|---|
| Mark Ready | pending | unfulfilled |
| Unmark Ready | pending | ready |
| Hold | pending | unfulfilled, ready |
| Release Hold | on_hold | unfulfilled, ready |
| Backorder | pending | unfulfilled, ready |
| Release Backorder | backorder | unfulfilled, ready |
| Edit Lines | pending, on_hold | unfulfilled, ready |

Reject silently (flash error, no state change) if preconditions not met.

### Mark Ready validation

Before setting `fulfillment_status = ready`, validate:
- `order_status = pending`
- `fulfillment_status = unfulfilled`
- `ship_together_key` is not null (i.e. recipient address is present)
- At least one line with `line_status = ready`
- Every ready line must be shippable. A line is shippable if its SKU has `stock_item_id`
  set, OR `sku_type = virtual_bundle` with at least one `bundleComponent`. Any ready line
  that fails this check blocks the whole order from being marked ready. Cancelled lines
  are ignored.

If validation fails, flash an error message and stay on the page. Do not abort 403.

---

## What this task must build

### 1. Status actions on `SalesOrderDetail`

#### Button visibility by state

Render buttons conditionally based on the current `order_status` and `fulfillment_status`.
Read-only states show no action buttons (except a disabled Cancel for context, if desired).

| order_status | fulfillment_status | Visible buttons |
|---|---|---|
| pending | unfulfilled | Mark Ready, Hold, Backorder, Edit Lines, Cancel |
| pending | ready | Unmark Ready, Hold, Backorder, Edit Lines, Cancel |
| on_hold | unfulfilled | Release Hold, Edit Lines, Cancel |
| on_hold | ready | Release Hold, Edit Lines, Cancel |
| backorder | unfulfilled | Release Backorder, Cancel |
| backorder | ready | Release Backorder, Cancel |
| pending | in_group | (read-only) |
| pending | shipped | (read-only) |
| cancelled | cancelled | (read-only) |
| completed | shipped | (read-only) |

In the Blade view, compute a `$editable` boolean from the loaded order:

```php
$editable = in_array($order->order_status, ['pending', 'on_hold'])
    && in_array($order->fulfillment_status, ['unfulfilled', 'ready']);
```

Use `$editable` to show/hide the Edit Lines section. Use individual status checks for
the other buttons.

Add the following public methods. Each reloads the order via `allowedTenantIds()` scope
before acting, same as `cancelOrder()`.

#### `markReady(): void`

```php
public function markReady(): void
{
    $order = SalesOrder::query()
        ->whereIn('tenant_id', $this->allowedTenantIds())
        ->with('lines.sku.bundleComponents.componentStockItem')
        ->findOrFail($this->orderId);

    if ($order->order_status !== SalesOrder::ORDER_STATUS_PENDING
        || $order->fulfillment_status !== SalesOrder::FULFILLMENT_STATUS_UNFULFILLED) {
        session()->flash('error', __('sales_orders.cannot_mark_ready'));
        return;
    }

    if (! $order->ship_together_key) {
        session()->flash('error', __('sales_orders.ready_requires_address'));
        return;
    }

    $readyLines = $order->lines->where('line_status', SalesOrderLine::STATUS_READY);

    if ($readyLines->isEmpty()) {
        session()->flash('error', __('sales_orders.ready_requires_shippable_line'));
        return;
    }

    // Every ready line must be shippable. One unshippable line blocks the whole order.
    foreach ($readyLines as $line) {
        $sku = $line->sku;
        $shippable = false;

        if ($sku?->stock_item_id !== null) {
            $shippable = true;
        }

        if ($sku?->sku_type === 'virtual_bundle' && $sku->bundleComponents->isNotEmpty()) {
            $shippable = $sku->bundleComponents->every(fn ($component) =>
                $component->componentStockItem
                && $component->componentStockItem->tenant_id === $order->tenant_id
            );
        }

        if (! $shippable) {
            session()->flash('error', __('sales_orders.ready_requires_shippable_line'));
            return;
        }
    }

    $order->update(['fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_READY]);
    session()->flash('status', __('sales_orders.marked_ready'));
}
```

#### `unmarkReady(): void`

```php
public function unmarkReady(): void
{
    $order = SalesOrder::query()
        ->whereIn('tenant_id', $this->allowedTenantIds())
        ->findOrFail($this->orderId);

    if ($order->order_status !== SalesOrder::ORDER_STATUS_PENDING
        || $order->fulfillment_status !== SalesOrder::FULFILLMENT_STATUS_READY) {
        session()->flash('error', __('sales_orders.cannot_unmark_ready'));
        return;
    }

    $order->update(['fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_UNFULFILLED]);
    session()->flash('status', __('sales_orders.unmarked_ready'));
}
```

#### `hold(): void`

```php
public function hold(): void
{
    $order = SalesOrder::query()
        ->whereIn('tenant_id', $this->allowedTenantIds())
        ->findOrFail($this->orderId);

    if ($order->order_status !== SalesOrder::ORDER_STATUS_PENDING
        || ! in_array($order->fulfillment_status, [
            SalesOrder::FULFILLMENT_STATUS_UNFULFILLED,
            SalesOrder::FULFILLMENT_STATUS_READY,
        ], true)) {
        session()->flash('error', __('sales_orders.cannot_hold'));
        return;
    }

    // If order was ready, pull it back to unfulfilled when holding.
    $order->update([
        'order_status'       => SalesOrder::ORDER_STATUS_ON_HOLD,
        'fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_UNFULFILLED,
    ]);
    session()->flash('status', __('sales_orders.put_on_hold'));
}
```

**Why unmark ready on hold:** an order on hold should not be groupable. Pulling it back to
`unfulfilled` ensures it cannot appear in `FulfillmentGroupCreate`.

#### `releaseHold(): void`

```php
public function releaseHold(): void
{
    $order = SalesOrder::query()
        ->whereIn('tenant_id', $this->allowedTenantIds())
        ->findOrFail($this->orderId);

    if ($order->order_status !== SalesOrder::ORDER_STATUS_ON_HOLD
        || ! in_array($order->fulfillment_status, [
            SalesOrder::FULFILLMENT_STATUS_UNFULFILLED,
            SalesOrder::FULFILLMENT_STATUS_READY,
        ], true)) {
        session()->flash('error', __('sales_orders.not_on_hold'));
        return;
    }

    $order->update(['order_status' => SalesOrder::ORDER_STATUS_PENDING]);
    session()->flash('status', __('sales_orders.hold_released'));
}
```

#### `markBackorder(): void`

Same guard as `hold()` but sets `order_status = backorder` and also resets
`fulfillment_status = unfulfilled`.
Use the backorder lang keys, not the hold keys:
- failure: `sales_orders.cannot_backorder`
- success: `sales_orders.marked_backorder`

#### `releaseBackorder(): void`

Same guard as `releaseHold()` but checks `order_status = backorder`.
Sets `order_status = pending`. Does not change `fulfillment_status`.
Also require `fulfillment_status` to be `unfulfilled` or `ready`; terminal fulfillment
states such as `in_group`, `shipped`, and `cancelled` must not be released by these
manual status actions.
Use the backorder release lang keys:
- failure: `sales_orders.not_on_backorder`
- success: `sales_orders.backorder_released`

---

### 2. Line editing on `SalesOrderDetail`

Allow adding, removing, and updating lines while order is editable
(`order_status` in `[pending, on_hold]` AND `fulfillment_status` in `[unfulfilled, ready]`).

Add these public properties to `SalesOrderDetail`:

```php
public bool $editingLines = false;
public array $draftLines  = [];   // [['sku_id' => '', 'quantity' => 1, 'note' => ''], ...]
```

#### `editLines(): void`

Load current non-cancelled lines into `$draftLines`. Set `$editingLines = true`.

```php
public function editLines(): void
{
    $order = $this->loadEditableOrder();
    $this->draftLines = $order->lines
        ->where('line_status', SalesOrderLine::STATUS_READY)
        ->values()
        ->map(fn ($l) => [
            'sku_id'   => (string) $l->sku_id,
            'quantity' => $l->quantity,
            'note'     => (string) $l->note,
        ])
        ->all();
    $this->editingLines = true;
}
```

#### `addDraftLine(): void`

Append `['sku_id' => '', 'quantity' => 1, 'note' => '']` to `$draftLines`.

#### `removeDraftLine(int $index): void`

Unset `$draftLines[$index]` and re-index with `array_values`.

#### `saveLines(): void`

Validate all draft lines, then replace existing non-cancelled lines:

```php
public function saveLines(): void
{
    $order = $this->loadEditableOrder();

    $tenantId = $order->tenant_id;

    validator(['lines' => $this->draftLines], [
        'lines'                => ['required', 'array', 'min:1'],
        'lines.*.sku_id'       => ['required', 'integer', Rule::exists('skus', 'id')->where('tenant_id', $tenantId)],
        'lines.*.quantity'     => ['required', 'integer', 'min:1', 'max:9999'],
        'lines.*.note'         => ['nullable', 'string', 'max:500'],
    ])->validate();

    DB::transaction(function () use ($order, $tenantId) {
        // Cancel existing ready lines
        $order->lines()->where('line_status', SalesOrderLine::STATUS_READY)->update([
            'line_status' => SalesOrderLine::STATUS_CANCELLED,
        ]);

        // Create new lines
        foreach ($this->draftLines as $draft) {
            $order->lines()->create([
                'sku_id'      => (int) $draft['sku_id'],
                'quantity'    => (int) $draft['quantity'],
                'note'        => $this->nullableString($draft['note'] ?? ''),
                'line_status' => SalesOrderLine::STATUS_READY,
            ]);
        }

        // If the order was ready but lines changed, pull back to unfulfilled
        // so the operator must re-review before grouping.
        if ($order->fulfillment_status === SalesOrder::FULFILLMENT_STATUS_READY) {
            $order->update(['fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_UNFULFILLED]);
        }
    });

    $this->editingLines = false;
    session()->flash('status', __('sales_orders.lines_updated'));
}
```

**Why pull back to unfulfilled on line edit:** the ready status is an operator assertion
that the lines are correct. Editing lines invalidates that assertion.

`saveLines()` does not recalculate `ship_together_key`. Line edits do not change the
recipient address; only `saveRecipient()` touches address fields, and `SalesOrderObserver`
already recalculates the key on those saves. No additional observer call is needed here.

Add private helper:

```php
private function loadEditableOrder(): SalesOrder
{
    $order = SalesOrder::query()
        ->whereIn('tenant_id', $this->allowedTenantIds())
        ->with('lines.sku')
        ->findOrFail($this->orderId);

    if (! in_array($order->order_status, [SalesOrder::ORDER_STATUS_PENDING, SalesOrder::ORDER_STATUS_ON_HOLD], true)
        || ! in_array($order->fulfillment_status, [SalesOrder::FULFILLMENT_STATUS_UNFULFILLED, SalesOrder::FULFILLMENT_STATUS_READY], true)) {
        abort(403);
    }

    return $order;
}
```

---

### 3. Bulk Mark Ready on `SalesOrderIndex`

Add a bulk action that marks multiple selected orders as ready in one operation.
Only orders that pass all `markReady` preconditions are updated; orders that fail are
skipped silently (not aborted). Flash a summary: "3 orders marked ready. 1 skipped."

Add to `SalesOrderIndex`:

```php
public array $selectedIds = [];

public function bulkMarkReady(): void
{
    if (empty($this->selectedIds)) {
        return;
    }

    $selectedIds = array_values(array_unique(array_map('intval', $this->selectedIds)));

    $orders = SalesOrder::query()
        ->whereIn('id', $selectedIds)
        ->whereIn('tenant_id', $this->allowedTenantIds())
        ->where('order_status', SalesOrder::ORDER_STATUS_PENDING)
        ->where('fulfillment_status', SalesOrder::FULFILLMENT_STATUS_UNFULFILLED)
        ->whereNotNull('ship_together_key')
        ->whereHas('lines', fn ($q) =>
            $q->where('line_status', SalesOrderLine::STATUS_READY)
        )
        // Exclude orders that still have any un-shippable ready line (no stock_item_id
        // and not a virtual_bundle with components). Those must be fixed before bulk ready.
        ->whereDoesntHave('lines', fn ($q) =>
            $q->where('line_status', SalesOrderLine::STATUS_READY)
              ->whereHas('sku', fn ($q2) =>
                  $q2->whereNull('stock_item_id')
                     ->where(fn ($q3) =>
                         $q3->where('sku_type', '!=', 'virtual_bundle')
                            ->orWhereDoesntHave('bundleComponents')
                     )
              )
        )
        ->get();

    $updated = $orders->count();
    $orders->each->update(['fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_READY]);

    $skipped = count($selectedIds) - $updated;
    $this->selectedIds = [];

    session()->flash('status', __('sales_orders.bulk_ready_result', [
        'updated' => $updated,
        'skipped' => $skipped,
    ]));
}
```

The bulk query intentionally does not re-check cross-tenant bundle component ownership in
SQL. That invariant belongs to SKU bundle component creation and is also checked defensively
in the single-order `markReady()` PHP path. Keeping the bulk SQL to simple existence and
missing-component checks avoids fragile correlated subqueries across SQLite and MySQL.

UI: add a checkbox column to the index table. Show bulk action bar when at least one row
is selected. One button: "Mark Ready". Use Livewire's `wire:model` on `selectedIds`.

When any index filter changes, clear selected IDs as well as resetting pagination:

```php
public function updatedShopId(): void
{
    $this->selectedIds = [];
    $this->resetPage();
}

public function updatedFulfillmentStatus(): void
{
    $this->selectedIds = [];
    $this->resetPage();
}

public function updatedOrderStatus(): void
{
    $this->selectedIds = [];
    $this->resetPage();
}

public function updatedSearch(): void
{
    $this->selectedIds = [];
    $this->resetPage();
}
```

---

## Lang keys to add in `lang/en/sales_orders.php`

```php
// Mark ready / unmark
'cannot_mark_ready'             => 'This order cannot be marked ready.',
'ready_requires_address'        => 'A delivery address is required before marking ready.',
'ready_requires_shippable_line' => 'At least one shippable line is required before marking ready.',
'marked_ready'                  => 'Order marked as ready.',
'cannot_unmark_ready'           => 'This order cannot be unmarked.',
'unmarked_ready'                => 'Order returned to unfulfilled.',

// Hold
'cannot_hold'                   => 'This order cannot be put on hold.',
'put_on_hold'                   => 'Order put on hold.',
'not_on_hold'                   => 'This order is not on hold.',
'hold_released'                 => 'Hold released.',

// Backorder
'cannot_backorder'              => 'This order cannot be marked as backorder.',
'marked_backorder'              => 'Order marked as backorder.',
'not_on_backorder'              => 'This order is not on backorder.',
'backorder_released'            => 'Backorder released.',

// Line editing
'lines_updated'                 => 'Order lines updated.',

// Bulk
'bulk_ready_result'             => ':updated order(s) marked ready. :skipped skipped.',
```

Add stubs (value = key) to `lang/ja/`, `lang/zh_TW/`, `lang/zh_CN/`.

---

## Tests to add in `tests/Feature/SalesOrderTest.php`

Do not modify existing tests. Append the following cases.

| # | Test | What it asserts |
|---|---|---|
| 1 | `test_mark_ready_succeeds` | unfulfilled order with address and ready line -> fulfillment_status = ready |
| 2 | `test_mark_ready_requires_address` | order with no ship_together_key -> error flash, status unchanged |
| 3 | `test_mark_ready_requires_shippable_line` | all lines cancelled -> error flash |
| 4 | `test_mark_ready_blocked_when_order_on_hold` | order_status = on_hold -> error flash |
| 5 | `test_unmark_ready_succeeds` | ready order -> unfulfilled |
| 6 | `test_unmark_ready_blocked_when_in_group` | fulfillment_status = in_group -> error flash |
| 7 | `test_hold_succeeds_and_resets_fulfillment_to_unfulfilled` | pending+ready order -> on_hold, unfulfilled |
| 8 | `test_hold_blocked_when_in_group` | in_group -> error flash |
| 9 | `test_release_hold_succeeds` | on_hold -> pending, fulfillment unchanged |
| 10 | `test_mark_backorder_succeeds` | pending -> backorder, unfulfilled |
| 11 | `test_release_backorder_succeeds` | backorder -> pending |
| 12 | `test_edit_lines_replaces_ready_lines` | save new lines -> old ready lines cancelled, new lines created |
| 13 | `test_edit_lines_resets_fulfillment_if_was_ready` | ready order -> save lines -> fulfillment_status = unfulfilled |
| 14 | `test_edit_lines_blocked_when_in_group` | in_group -> loadEditableOrder aborts 403 |
| 15 | `test_edit_lines_rejects_wrong_tenant_sku` | sku from other tenant -> validation error |
| 16 | `test_bulk_mark_ready_updates_eligible_skips_ineligible` | 2 orders: 1 with address+line, 1 without address -> 1 updated, 1 skipped; flash shows counts |
| 17 | `test_bulk_mark_ready_ignores_other_tenant_orders` | selected IDs include another tenant's order -> not updated |
| 18 | `test_bulk_mark_ready_rejects_virtual_bundle_without_components` | virtual bundle with no components is skipped |
| 19 | `test_release_hold_blocked_when_fulfillment_status_terminal` | on_hold + terminal fulfillment status is not released |
| 20 | `test_mark_ready_blocked_when_ready_line_has_unshippable_sku` | order with a ready line whose SKU has null `stock_item_id` and `sku_type != virtual_bundle` -> error flash, status unchanged |

---

## Constraints

- No Volt. No TypeScript. Class-based Livewire only.
- `saveLines()` must use `DB::transaction()`.
- `loadEditableOrder()` must use `allowedTenantIds()` scope.
- Mark Ready validation is done in PHP, not a separate validation rule. Do not throw
  ValidationException for these -- use `session()->flash('error', ...)` and `return`.
  The UI already displays the flash error banner.
- `hold()` and `markBackorder()` must reset `fulfillment_status = unfulfilled` if the
  order was in `ready` state. An order that is on hold or on backorder must not appear
  in `FulfillmentGroupCreate`.
- Bulk mark ready uses a single `->each->update()` call inside no transaction (each order
  is independent; partial success is acceptable and reported in the flash).
- Bulk mark ready must de-duplicate selected IDs before querying and before calculating
  skipped count.
- Clear `selectedIds` whenever index filters/search change.
- Do not add a line_status column to draft lines. `draftLines` only holds ready lines;
  cancelled lines are not included.
- Do not modify `SalesOrderObserver`. The observer only handles `ship_together_key`
  recalculation.
- Run `php artisan test` at the end and confirm all tests pass.
