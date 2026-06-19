# Task: Sales Order Tracking Unsaved Indicator v1

## Goal

Improve the Sales Orders index tracking number inline edit UX with an `Unsaved` indicator while
keeping the save behavior conservative.

Tracking numbers should not save on every keystroke. They should autosave after the user pauses
typing, while the typed value should survive unrelated Livewire re-renders such as selecting a row
checkbox.

---

## Implementation Decision

Use Livewire draft state, not Alpine event handlers.

An Alpine local-state version was considered, but browser verification in this Laravel 13 /
Livewire 4.3 / Flux 2 stack showed that `x-on:change` / `x-on:blur` / `x-on:focusout` handlers were
not reliable enough for the save step. Native Livewire draft state with debounce is slightly more
server-driven, but it is more testable and reliable in this codebase.

---

## Requirements

### 1. Keep existing save behavior

- Do not save on every keystroke.
- Save `tracking_no` through a modest debounce after typing pauses.
- Keep tenant scoping unchanged.
- Keep `SalesOrderIndex::updateTrackingNo()` as the server-side update method.

### 2. Add row draft state

In `SalesOrderIndex`, add:

```php
public array $trackingDrafts = [];
public array $trackingSavedDrafts = [];
```

When rendering the current page of orders, initialize missing draft values from the database:

```php
foreach ($orders as $order) {
    $this->trackingDrafts[$order->id] ??= $order->tracking_no ?? '';
    $this->trackingSavedDrafts[$order->id] ??= $order->tracking_no ?? '';
}
```

Do not overwrite an existing draft value during render, because that would reintroduce the
re-render data-loss problem.

### 3. Add a save method for draft values

Add a public method:

```php
public function saveTrackingDraft(int $orderId): void
{
    $trackingNo = trim((string) ($this->trackingDrafts[$orderId] ?? ''));
    $trackingNo = $trackingNo === '' ? '' : mb_substr($trackingNo, 0, 255);

    $this->trackingDrafts[$orderId] = $trackingNo;
    $this->updateTrackingNo($orderId, $trackingNo);
    $this->trackingSavedDrafts[$orderId] = $trackingNo;
}
```

`updateTrackingNo()` must continue to scope by `allowedTenantIds()`, so tenant users can update only
their own tenant's orders.

Also add an `updatedTrackingDrafts()` hook so the debounced model sync persists the draft:

```php
public function updatedTrackingDrafts(mixed $value, string|int $key): void
{
    if (! is_numeric($key)) {
        return;
    }

    $this->saveTrackingDraft((int) $key);
}
```

### 4. Update the tracking input

Keep the existing `<flux:table.cell class="so-control-cell">`.

Use Livewire model state for the draft:

```blade
<flux:table.cell class="so-control-cell">
    @php
        $trackingDraft = (string) ($trackingDrafts[$order->id] ?? '');
        $trackingSavedDraft = (string) ($trackingSavedDrafts[$order->id] ?? ($order->tracking_no ?? ''));
        $trackingServerDirty = trim($trackingDraft) !== trim($trackingSavedDraft);
    @endphp

    <input
        type="text"
        class="table-control"
        wire:key="tracking-{{ $order->id }}"
        wire:model.live.debounce.800ms="trackingDrafts.{{ $order->id }}"
        placeholder="{{ __('sales_orders.tracking_no_placeholder') }}"
        aria-label="{{ __('sales_orders.col_tracking_no') }} {{ $order->platform_order_id ?: $order->id }}"
    >
    <span class="so-unsaved" wire:dirty wire:target="trackingDrafts.{{ $order->id }}">
        {{ __('sales_orders.tracking_unsaved') }}
    </span>
    @if ($trackingServerDirty)
        <span class="so-unsaved">
            {{ __('sales_orders.tracking_unsaved') }}
        </span>
    @endif
</flux:table.cell>
```

`wire:dirty` gives immediate feedback before the debounced save request. The server-side
`$trackingServerDirty` check keeps the `Unsaved` hint visible after an unrelated Livewire request
has synced the draft value into component state but before `saveTrackingDraft()` has persisted it.

### 5. Add lang key

In `lang/en/sales_orders.php`:

```php
'tracking_unsaved' => 'Unsaved',
```

### 6. Add CSS

Add a compact `.so-unsaved` style near the Sales Orders table styles.

- Use amber/warning styling, e.g. `color: var(--warning)`.
- Keep it small and not visually loud.

### 7. Do not change shipping method select

- It saves immediately on change.
- It does not have a typing window.

---

## Verification

Run:

```bash
php artisan test
```

If `php` is not globally available, use the Laragon PHP path already used in this project:

```bash
C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan test
```

Browser-check `/sales-orders`.

Manually verify:

1. Type into a tracking input but do not blur.
2. Confirm `Unsaved` appears.
3. Tick a row checkbox or trigger another small Livewire re-render.
4. Confirm the typed text is not lost.
5. Wait for the debounce save.
6. Confirm `Unsaved` disappears after save.
