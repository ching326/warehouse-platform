# Task: Sales Order Index Shipping UI v1

## Goal

Improve the Sales Orders index table so staff can use it as a daily shipping work surface.

The page should show enough information to pack/export orders without opening every detail page:

- Platform order ID is the clickable link to the order detail.
- Recipient shows name + phone.
- Address is visible in its own column.
- Ordered SKUs are visible as `qty x sku`.
- Shipping method can be edited inline with a dropdown.
- Tracking number can be edited inline with an input.
- Created date also shows the courier CSV exported date when present.

This is a Sales Orders index UI/data enhancement. It does not implement courier CSV export itself.

---

## Current state

`SalesOrderIndex` currently shows:

- checkbox
- shop
- platform order ID, with `sales_orders.id` underneath
- recipient name + city/postal code
- fulfillment status
- order status
- created date
- View button

Requested changes:

1. Platform order ID column:
   - Do not show `sales_orders.id` underneath.
   - The platform order ID itself should be clickable and link to the order detail page.
   - Remove the separate View column/button.

2. Recipient column:
   - Show recipient name.
   - Show recipient phone number under the name.

3. Address column:
   - Add a new column to the left of Recipient.
   - Show recipient postal code, state/city, address line 1, address line 2.
   - Keep it compact but readable.

4. Items column:
   - Add a new column to the right of Recipient.
   - Show order lines as `QTY x SKU`.
   - If `quantity > 1`, display the quantity in red.
   - If `quantity = 1`, keep it normal/muted.
   - Example:
     - `1 x ABC-CHG30-BLK`
     - `3 x ABC-CABLE-C-1M-WHT` where `3` is red.

5. Shipping method column:
   - Add a new column to the right of Items.
   - Display a dropdown.
   - User can edit the order's shipping method inline.

6. Tracking number column:
   - Add a new column to the right of Shipping method.
   - Display an input field.
   - User can edit the order's tracking number inline.

7. Created column:
   - Show created date as `YYYY-MM-DD`.
   - Under it, show `Printed: YYYY-MM-DD` if the order has been exported to courier CSV.
   - For now, treat "printed date" as "courier CSV exported date".

---

## Data model changes

Add a new migration for `sales_orders`:

```php
$table->string('shipping_method')->nullable()->after('fulfillment_status');
$table->string('tracking_no')->nullable()->after('shipping_method');
$table->timestamp('courier_csv_exported_at')->nullable()->after('tracking_no');
```

Notes:

- `shipping_method` is nullable because older/imported orders may not have it yet.
- `tracking_no` is nullable because tracking is usually uploaded/entered after label creation.
- `courier_csv_exported_at` is nullable because courier export is not implemented for every order yet.
- Do not call the field `printed_at` yet. In this system the real event we know is CSV export, not physical label printing.

Update `App\Models\SalesOrder`:

- Add to `$fillable`:
  - `shipping_method`
  - `tracking_no`
  - `courier_csv_exported_at`
- Add a Laravel 11+ `casts()` method, matching the convention used by sibling models:

```php
protected function casts(): array
{
    return [
        'courier_csv_exported_at' => 'datetime',
    ];
}
```

`SalesOrder` currently has no `$casts` property and no `casts()` method. Do not add the old
`protected $casts` property style unless the project convention changes first.

---

## Shipping method options

For v1, use a simple fixed list in `SalesOrderIndex`.

```php
private function shippingMethodOptions(): array
{
    return [
        '' => __('sales_orders.shipping_method_unset'),
        'yamato' => __('sales_orders.shipping_method_yamato'),
        'sagawa' => __('sales_orders.shipping_method_sagawa'),
        'japan_post' => __('sales_orders.shipping_method_japan_post'),
        'other' => __('sales_orders.shipping_method_other'),
    ];
}
```

Pass it to the view as `shippingMethodOptions`.

Future courier export must use this field to prevent exporting a Yamato order in Sagawa format.
That prevention is not part of this task, but this field is the foundation for it.

---

## Livewire behavior

Update `SalesOrderIndex`.

### Authorization policy

Internal users can update `shipping_method` and `tracking_no` for any visible tenant order.
Tenant users, representing sellers, can update `shipping_method` and `tracking_no` for their own
tenant's orders only.

Do not add an internal-only guard to these two update methods. The server-side protection is the
existing `allowedTenantIds()` scope on every update query, so a tenant user cannot update another
tenant's order by tampering with the order id.

### Query

The table now needs order lines and SKUs, so eager load them:

```php
->with(['shop.tenant', 'lines.sku'])
```

Keep tenant scoping and filters unchanged.

### Inline shipping method update

Add a public method:

```php
public function updateShippingMethod(int $orderId, string $value): void
{
    $allowed = array_keys($this->shippingMethodOptions());

    if (! in_array($value, $allowed, true)) {
        return;
    }

    SalesOrder::query()
        ->whereIn('tenant_id', $this->allowedTenantIds())
        ->whereKey($orderId)
        ->update(['shipping_method' => $value === '' ? null : $value]);
}
```

### Inline tracking number update

Add a public method:

```php
public function updateTrackingNo(int $orderId, string $value): void
{
    $trackingNo = trim($value);

    SalesOrder::query()
        ->whereIn('tenant_id', $this->allowedTenantIds())
        ->whereKey($orderId)
        ->update(['tracking_no' => $trackingNo === '' ? null : mb_substr($trackingNo, 0, 255)]);
}
```

Notes:

- Both methods must scope by `allowedTenantIds()`.
- No user can update another tenant's order by tampering with the order id.
- Tenant users are intentionally allowed to update these two fields for their own orders.
- This page is allowed to edit only `shipping_method` and `tracking_no`.
- Do not allow editing recipient, status, SKU lines, or quantities from this table.
- Truncate tracking numbers to 255 characters before saving so a pasted long value cannot cause
  a database error on MySQL strict mode.

---

## Blade changes

Update `resources/views/livewire/sales-order-index.blade.php`.

### Column order

Use this order:

1. checkbox
2. shop
3. platform order ID
4. address
5. recipient
6. items
7. shipping method
8. tracking no.
9. fulfillment status
10. order status
11. created / printed

Remove the Actions/View column.
Update the empty-state row to use `colspan="11"` so empty search results span the full new
table width.

### Platform order ID

Make platform order ID clickable:

```blade
<flux:link href="{{ route('sales.orders.show', $order) }}" wire:navigate>
    <strong>{{ $order->platform_order_id ?: '-' }}</strong>
</flux:link>
```

Do not show `#{{ $order->id }}` under it.

### Address

Display compact address:

```blade
<strong>{{ $order->recipient_postal_code ?: '-' }}</strong>
<span class="subtle">{{ trim(($order->recipient_state ?? '').' '.($order->recipient_city ?? '')) ?: '-' }}</span>
<span class="subtle">{{ $order->recipient_address_line1 ?: '-' }}</span>
@if ($order->recipient_address_line2)
    <span class="subtle">{{ $order->recipient_address_line2 }}</span>
@endif
```

### Recipient

```blade
<strong>{{ $order->recipient_name ?: '-' }}</strong>
<span class="subtle">{{ $order->recipient_phone ?: '-' }}</span>
```

### Items

Show each line as `qty x sku`.

```blade
@foreach ($order->lines->where('line_status', \App\Models\SalesOrderLine::STATUS_READY) as $line)
    <div class="so-item-line">
        @if ($line->quantity > 1)
            <strong class="danger-text">{{ $line->quantity }}</strong>
        @else
            <span class="subtle">{{ $line->quantity }}</span>
        @endif
        <span class="subtle">x</span>
        <strong>{{ $line->sku?->sku ?? '-' }}</strong>
    </div>
@endforeach
```

Only show ready lines. Do not show cancelled lines on the shipping work surface, because old
cancelled lines may remain after line edits and would mislead packers.

### Shipping method dropdown

Use a native `select` or Flux select. Keep it compact.

Example using native select:

```blade
<select
    class="table-control"
    aria-label="{{ __('sales_orders.col_shipping_method') }} {{ $order->platform_order_id ?: $order->id }}"
    x-on:change="$wire.updateShippingMethod({{ $order->id }}, $event.target.value)"
>
    @foreach ($shippingMethodOptions as $value => $label)
        <option value="{{ $value }}" @selected(($order->shipping_method ?? '') === $value)>
            {{ $label }}
        </option>
    @endforeach
</select>
```

### Tracking no. input

```blade
<input
    type="text"
    class="table-control"
    value="{{ $order->tracking_no }}"
    placeholder="{{ __('sales_orders.tracking_no_placeholder') }}"
    aria-label="{{ __('sales_orders.col_tracking_no') }} {{ $order->platform_order_id ?: $order->id }}"
    x-on:change="$wire.updateTrackingNo({{ $order->id }}, $event.target.value)"
>
```

Save on change/blur via `x-on:change`, not on every keystroke, because tracking number typing
should not save mid-typing.
Use Alpine's `x-on:change` with `$wire` for the value handoff, because this project is on
Livewire 4 and there is no existing project precedent for `$event.target.value` inside a
`wire:change` action expression.

Because the tracking input is intentionally uncontrolled, a separate Livewire re-render before
the field blurs can restore the last saved database value. This is acceptable for v1; users should
commit the tracking number by changing focus before using unrelated live controls.

### Created / Printed

```blade
<strong>{{ $order->created_at->format('Y-m-d') }}</strong>
@if ($order->courier_csv_exported_at)
    <span class="subtle">
        {{ __('sales_orders.printed_date_label') }} {{ $order->courier_csv_exported_at->format('Y-m-d') }}
    </span>
@endif
```

---

## Styling

Add small reusable CSS rules in the existing layout CSS if they do not already exist:

```css
.table-control {
    width: 100%;
    min-width: 120px;
    border: 1px solid var(--line);
    border-radius: 6px;
    padding: 6px 8px;
    font: inherit;
    background: #fff;
}

.so-item-line {
    display: flex;
    align-items: baseline;
    gap: 4px;
    white-space: nowrap;
}

.danger-text {
    color: var(--danger);
}
```

Keep the table inside the existing `.table-scroll` wrapper. This page will be wide, so horizontal
scroll is acceptable on small screens, but text must not overlap.
Because the table now has 11 columns and two inline controls, raise the Sales Orders table
minimum width if needed so controls do not get crushed before horizontal scrolling starts.

---

## Lang keys

Add to `lang/en/sales_orders.php` only:

```php
'col_address' => 'Address',
'col_items' => 'Items',
'col_shipping_method' => 'Shipping method',
'col_tracking_no' => 'Tracking no.',
'printed_date_label' => 'Printed:',
'tracking_no_placeholder' => 'Tracking no.',
'shipping_method_unset' => 'Not set',
'shipping_method_yamato' => 'Yamato',
'shipping_method_sagawa' => 'Sagawa',
'shipping_method_japan_post' => 'Japan Post',
'shipping_method_other' => 'Other',
```

Do not split `lang/ja`, `lang/zh_TW`, or `lang/zh_CN`. They inherit English for now.

---

## Tests

Add/update tests for Sales Orders index.

Required coverage:

1. `test_sales_order_index_hides_internal_id_under_platform_order_id`
   - Render page.
   - Assert platform order id is visible.
   - Assert `#{{ sales_orders.id }}` is not visible.

2. `test_sales_order_index_platform_order_id_links_to_detail`
   - Assert the platform order ID anchor points to `route('sales.orders.show', $order)`.

3. `test_sales_order_index_shows_recipient_phone_and_address`
   - Assert recipient phone, postal code, city/state, address line 1 are visible.

4. `test_sales_order_index_shows_line_quantities_and_skus`
   - Order has two lines.
   - Assert `1 x SKU-A` and `3 x SKU-B` are visible.
   - Add a cancelled historical line with a distinct SKU and assert that distinct SKU is not visible.

5. `test_sales_order_index_updates_shipping_method_with_tenant_scope`
   - Livewire call `updateShippingMethod($order->id, 'yamato')`.
   - Assert DB updated.
   - Livewire call `updateShippingMethod($order->id, 'unknown_method')`.
   - Assert the field remains unchanged.
   - For a tenant user, update the user's own tenant order and assert DB updated.
   - For a tenant user, attempt another tenant order id and assert it is not updated.

6. `test_sales_order_index_updates_tracking_no_with_tenant_scope`
   - Livewire call `updateTrackingNo($order->id, '1234567890')`.
   - Assert DB updated.
   - For a tenant user, update the user's own tenant order and assert DB updated.
   - For a tenant user, attempt another tenant order id and assert it is not updated.

7. `test_sales_order_index_shows_printed_date_when_courier_csv_exported`
   - Set `courier_csv_exported_at`.
   - Assert `Printed: YYYY-MM-DD` is visible.

8. `test_sales_order_index_has_no_view_action_column`
   - Assert old view button label is not rendered on the index page.

9. `test_sales_order_index_empty_state_spans_all_columns`
   - Render an empty result.
   - Assert the empty-state cell uses `colspan="11"`.

Run full test suite:

```bash
php artisan test
```

If `php` is not globally available, use the Laragon PHP path already used in this project:

```bash
C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan test
```

---

## Constraints

- Class-based Livewire only.
- No Volt.
- No TypeScript.
- Keep tenant scoping on all inline updates.
- Do not implement courier CSV export in this task.
- Do not implement tracking upload in this task.
- Do not change sales order detail behavior.
- Do not remove existing filters, bulk actions, or export buttons.
- Keep export/download buttons as plain links.
- Make sure table text does not overlap. Use horizontal scroll on small screens if needed.
