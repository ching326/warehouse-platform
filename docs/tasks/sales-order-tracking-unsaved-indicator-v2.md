# Task: Sales Order Tracking Unsaved Indicator v2

## Goal

Fix the Sales Orders index tracking number unsaved indicator.

Current issue seen in the GIF:

- `Unsaved` appears squeezed beside the tracking input.
- It visually collides with the Status column.
- It looks like a layout bug rather than a clear save state.
- There are two unsaved mechanisms (`wire:dirty` and server-side dirty state), which can flicker or duplicate.

This task is only for the tracking number input/unsaved indicator. Do not change the rest of the Sales Orders UI.

---

## Files

Likely files:

- `app/Livewire/SalesOrderIndex.php`
- `resources/views/livewire/sales-order-index.blade.php`
- `resources/views/inventory.blade.php`
- `tests/Feature/SalesOrderTest.php`

---

## Required UI behavior

For each tracking number input:

1. Input stays in the Tracking No. column.
2. When the draft value differs from the last saved value, show `Unsaved`.
3. `Unsaved` must appear **below the input**, not beside it.
4. `Unsaved` must not overlap or push into the Status column.
5. After autosave succeeds, `Unsaved` disappears.
6. If save does not affect any row, do not mark the draft as saved.

---

## Blade changes

In `resources/views/livewire/sales-order-index.blade.php`, wrap the input and unsaved label:

```blade
<div class="tracking-field">
    <input
        type="text"
        class="table-control"
        wire:key="tracking-{{ $order->id }}"
        wire:model.live.debounce.800ms="trackingDrafts.{{ $order->id }}"
        placeholder="{{ __('sales_orders.tracking_no_placeholder') }}"
        aria-label="{{ __('sales_orders.col_tracking_no') }} {{ $order->platform_order_id ?: $order->id }}"
    >

    <span
        class="tracking-unsaved"
        wire:dirty
        wire:target="trackingDrafts.{{ $order->id }}"
    >
        {{ __('sales_orders.tracking_unsaved') }}
    </span>
</div>
```

Important:

- Keep the `wire:dirty` unsaved span. It is the correct immediate client-side signal while the user is typing and before the debounced autosave request finishes.
- Remove the server-side `$trackingServerDirty` unsaved span from the Blade.
- Do not render two unsaved labels.
- Do not calculate `$trackingServerDirty` in the Blade unless it is still needed for something else.

Reason:

- With `wire:model.live.debounce.800ms`, `wire:dirty` is what appears immediately when the input differs from Livewire's last known server value.
- After the debounce request runs, `updatedTrackingDrafts()` calls `saveTrackingDraft()`, and a successful save makes `trackingDrafts[id] === trackingSavedDrafts[id]` before the next render.
- Therefore a server-side dirty label is normally false during real usage, while `wire:dirty` is the useful short-lived typing feedback.
- Keeping both can duplicate or flicker. Keeping only server-side dirty can make `Unsaved` disappear entirely during normal typing.

---

## CSS changes

In `resources/views/inventory.blade.php`, replace or update the old `.so-unsaved` styling.

Use:

```css
.tracking-field {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 4px;
    min-width: 170px;
}

.tracking-field .table-control {
    width: 100%;
}

.tracking-unsaved {
    display: inline-flex;
    width: fit-content;
    border-radius: 999px;
    background: var(--warning-soft);
    color: var(--warning);
    padding: 2px 6px;
    font-size: 11px;
    font-weight: 600;
    line-height: 1.2;
}
```

Remove `.so-unsaved` if no longer used.

The indicator must be visually small and clear, but not look like stray text.

---

## Livewire correctness fix

Current risk:

`saveTrackingDraft()` calls `updateTrackingNo()` and then always updates `trackingSavedDrafts`.
If the order is outside the user's tenant scope or the order id is tampered, the UI can still think it was saved.

Change `updateTrackingNo()` to return whether a scoped order was found and handled.
Do not rely on the raw affected row count, because MySQL can return `0` when the submitted value is identical to the existing database value.

```php
public function updateTrackingNo(int $orderId, string $value): bool
{
    $trackingNo = trim($value);
    $trackingNo = $trackingNo === '' ? null : mb_substr($trackingNo, 0, 255);

    $order = SalesOrder::query()
        ->whereIn('tenant_id', $this->allowedTenantIds())
        ->whereKey($orderId)
        ->first();

    if (! $order) {
        return false;
    }

    $order->update(['tracking_no' => $trackingNo]);

    return true;
}
```

Then update `saveTrackingDraft()`:

```php
public function saveTrackingDraft(int $orderId): void
{
    $trackingNo = trim((string) ($this->trackingDrafts[$orderId] ?? ''));
    $trackingNo = $trackingNo === '' ? '' : mb_substr($trackingNo, 0, 255);

    $this->trackingDrafts[$orderId] = $trackingNo;

    $saved = $this->updateTrackingNo($orderId, $trackingNo);

    if ($saved) {
        $this->trackingSavedDrafts[$orderId] = $trackingNo;
    }
}
```

Do not set `trackingSavedDrafts` when `updateTrackingNo()` returns `false`.

Existing callers that ignore the return value should keep working.

---

## Tests

Update `tests/Feature/SalesOrderTest.php`.

Required tests:

1. `test_sales_order_index_saves_tracking_draft`
   - Existing test should still pass.
   - It should confirm `trackingDrafts` and `trackingSavedDrafts` both become trimmed saved value after a valid save.

2. `test_sales_order_index_does_not_mark_tracking_draft_saved_when_order_is_out_of_scope`
   - Tenant user has access to tenant A.
   - Attempt to save draft for tenant B order id.
   - Assert tenant B order `tracking_no` remains unchanged/null.
   - Assert `trackingSavedDrafts.{orderId}` is not set to the tampered draft value.

3. `test_sales_order_index_tracking_unsaved_indicator_uses_wire_dirty_label`
   - Render the page.
   - Assert the tracking input is wrapped in `.tracking-field`.
   - Assert the `tracking_unsaved` label is present with `wire:dirty`.
   - Assert there is no second server-rendered `$trackingServerDirty` label if practical.
   - Do not try to assert the live dirty/clean timing in PHPUnit; `wire:dirty` is client-side behavior.

4. `test_sales_order_index_tracking_unsaved_indicator_has_wrapper`
   - Render the page.
   - Assert `.tracking-field` and `.tracking-unsaved` appear in the tracking cell.

Run:

```bash
php artisan test tests/Feature/SalesOrderTest.php
php artisan test
```

If `php` is not globally available:

```bash
C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan test tests\Feature\SalesOrderTest.php
C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan test
```

---

## Browser verification

Open:

```text
http://127.0.0.1:8000/sales-orders
```

Verify:

- Type into a tracking number input.
- `Unsaved` appears below the input.
- It does not touch or overlap the Status column.
- After autosave completes, `Unsaved` disappears.
- No duplicate `Unsaved` labels appear.
- The indicator should be visible briefly while typing, then disappear after the debounced save completes.

Take a screenshot or visually confirm in the in-app browser.

---

## Constraints

- Do not change unrelated Sales Orders layout.
- Do not change shipping method behavior.
- Do not change bulk actions.
- Do not add migrations.
- Do not add tracking import.
- Do not add pack scan.
- Keep tenant scoping server-side.
- Keep class-based Livewire only.
