# Task: Inbound Order v1

## Stack

Laravel 13, Livewire 4 (class-based components, not Volt), Flux UI (`livewire/flux ^2.14`),
SQLite (dev), PHP 8.3. Plain Blade views only -- no TypeScript.

---

## What this task covers

Three pages and three Livewire components:

- `GET /inbound` -- InboundOrderIndex: list, filter, mark-arrived action, cancel action
- `GET /inbound/create` -- InboundOrderCreate: create inbound announcement (no stock changes)
- `GET /inbound/{order}/receive` -- InboundOrderReceive: staff enters actual received qty per line, calls receiveStock

**Creating an inbound order does NOT call `InventoryService`. It only writes DB records.**
**Marking an order arrived does NOT call `InventoryService`. It only updates status and metadata.**
Stock is only updated on the receive page when the operator submits actual quantities.

Inbound v1 stores receiving detail in `inbound_receipts`.
`inbound_order_lines` stores the announced line and aggregate received quantity only.
This supports split receiving for the same SKU into different warehouse locations.

---

## Status machine

```
pending -> arrived            (Mark arrived action; no stock change)
arrived -> partially_received (Some lines received on the receive page)
arrived -> received           (All lines fully received on the receive page)
partially_received -> received (Remaining lines completed on the receive page)
pending -> cancelled          (Cancel action; guard: no line has received_qty > 0)
arrived -> cancelled          (Cancel action; guard: no line has received_qty > 0)
```

Once any line has `received_qty > 0`, the order cannot be cancelled.

---

## Index page actions by status

| Status | Actions |
|---|---|
| `pending` | Mark arrived, Cancel |
| `arrived` | Receive (link to receive page) |
| `partially_received` | Receive (link to receive page) |
| `received` | (none) |
| `cancelled` | (none) |

---

## Read these files before writing any code

- `app/Services/InventoryService.php`
  Use `receiveStock(int $tenantId, int $warehouseId, int $stockItemId, int $quantity, array $context = []): InventoryMovement`
  only inside `InboundOrderReceive::save()`.
  The service owns its own transaction per call.
  When receiving multiple lines, wrap the loop in an outer `DB::transaction()` --
  Laravel handles transaction nesting safely.

- `app/Livewire/SkuCreate.php`
  Copy the following private helpers exactly:
  `isInternalUser()`, `allowedTenantIds()`, `validatedTenantId()`, `activeTenantIds()`, `nullableString()`

- `app/Livewire/InventoryIndex.php`
  Reference for `visibleTenantIds()` pattern used in the index component.

- Latest migration: `2026_06_18_000012_rebuild_inventory_movements_table.php`
  Name new migrations `2026_06_18_000013`, `2026_06_18_000014`, `2026_06_18_000015`, and `2026_06_18_000016`.
  If any of these filenames already exist, use the next available timestamp.

---

## 1. Migrations

### `2026_06_18_000013_create_warehouse_locations_table.php`

```
warehouse_locations
  id
  warehouse_id   FK -> warehouses, restrictOnDelete
  code           string
  name           string nullable
  type           string default 'storage'
  status         string default 'active'
  note           text nullable
  timestamps

  unique: [warehouse_id, code]
  indexes: [warehouse_id, status], [warehouse_id, type]
```

Type values: `storage`, `receiving`, `qc`, `packing`, `hold`, `damaged`, `other`

Notes:
- Location master table only. Do NOT build `inventory_location_balances` in this task.
- Inbound v1 records received location per receipt in `inbound_receipts.warehouse_location_id`.

### `2026_06_18_000014_create_inbound_orders_table.php`

```
inbound_orders
  id
  tenant_id              FK -> tenants,    cascadeOnDelete
  warehouse_id           FK -> warehouses, restrictOnDelete
  ref                    string nullable
  status                 string default 'pending'
  expected_at            date nullable
  note                   text nullable
  arrived_at             timestamp nullable
  arrived_by_user_id     FK -> users nullable, nullOnDelete
  received_at            timestamp nullable
  created_by_user_id     FK -> users nullable, nullOnDelete
  received_by_user_id    FK -> users nullable, nullOnDelete
  timestamps

  indexes: [tenant_id, status], [tenant_id, warehouse_id]
```

Status values: `pending`, `arrived`, `partially_received`, `received`, `cancelled`

### `2026_06_18_000015_create_inbound_order_lines_table.php`

```
inbound_order_lines
  id
  inbound_order_id     FK -> inbound_orders,      cascadeOnDelete
  tenant_id            FK -> tenants,             cascadeOnDelete
  sku_id               FK -> skus,                restrictOnDelete
  stock_item_id        FK -> stock_items,         restrictOnDelete
  expected_qty         unsignedInteger
  received_qty         unsignedInteger default 0
  note                 text nullable
  timestamps

  indexes: [inbound_order_id], [tenant_id, sku_id], [tenant_id, stock_item_id]
```

### `2026_06_18_000016_create_inbound_receipts_table.php`

```
inbound_receipts
  id
  inbound_order_id        FK -> inbound_orders,      cascadeOnDelete
  inbound_order_line_id   FK -> inbound_order_lines, cascadeOnDelete
  tenant_id               FK -> tenants,             cascadeOnDelete
  warehouse_id            FK -> warehouses,          restrictOnDelete
  warehouse_location_id   FK -> warehouse_locations, restrictOnDelete
  sku_id                  FK -> skus,                restrictOnDelete
  stock_item_id           FK -> stock_items,         restrictOnDelete
  inventory_movement_id   FK -> inventory_movements nullable, nullOnDelete
  received_qty            unsignedInteger
  received_by_user_id     FK -> users nullable, nullOnDelete
  received_at             timestamp
  note                    text nullable
  timestamps

  indexes:
    [inbound_order_id]
    [inbound_order_line_id]
    [tenant_id, stock_item_id]
    [warehouse_id, warehouse_location_id]
    [received_by_user_id]
    [inventory_movement_id]
```

---

## 2. Models

### `app/Models/WarehouseLocation.php`

```php
$fillable = ['warehouse_id', 'code', 'name', 'type', 'status', 'note'];
```

Relationships:
- `warehouse()` BelongsTo Warehouse
- `inboundReceipts()` HasMany InboundReceipt

### `app/Models/InboundOrder.php`

```php
$fillable = [
    'tenant_id', 'warehouse_id', 'ref', 'status',
    'expected_at', 'note',
    'arrived_at', 'arrived_by_user_id',
    'received_at', 'received_by_user_id',
    'created_by_user_id',
];
```

Relationships:
- `tenant()` BelongsTo Tenant
- `warehouse()` BelongsTo Warehouse
- `createdBy()` BelongsTo User (`created_by_user_id`)
- `arrivedBy()` BelongsTo User (`arrived_by_user_id`)
- `receivedBy()` BelongsTo User (`received_by_user_id`)
- `lines()` HasMany InboundOrderLine ordered by `id`

### `app/Models/InboundOrderLine.php`

```php
$fillable = [
    'inbound_order_id', 'tenant_id', 'sku_id', 'stock_item_id',
    'expected_qty', 'received_qty', 'note',
];
```

Relationships:
- `inboundOrder()` BelongsTo InboundOrder
- `sku()` BelongsTo Sku
- `stockItem()` BelongsTo StockItem
- `receipts()` HasMany InboundReceipt

### `app/Models/InboundReceipt.php`

```php
$fillable = [
    'inbound_order_id', 'inbound_order_line_id',
    'tenant_id', 'warehouse_id', 'warehouse_location_id',
    'sku_id', 'stock_item_id', 'inventory_movement_id',
    'received_qty', 'received_by_user_id', 'received_at', 'note',
];
```

Relationships:
- `inboundOrder()` BelongsTo InboundOrder
- `line()` BelongsTo InboundOrderLine (FK: `inbound_order_line_id`)
- `tenant()` BelongsTo Tenant
- `warehouse()` BelongsTo Warehouse
- `warehouseLocation()` BelongsTo WarehouseLocation
- `sku()` BelongsTo Sku
- `stockItem()` BelongsTo StockItem
- `inventoryMovement()` BelongsTo InventoryMovement
- `receivedBy()` BelongsTo User (`received_by_user_id`)

---

## 3. `app/Livewire/InboundOrderCreate.php`

### Public properties

```php
#[Url(as: 'tenant_id', except: '')]    public string $tenantId = '';
#[Url(as: 'warehouse_id', except: '')]  public string $warehouseId = '';
public string $ref = '';
public string $expectedAt = '';
public string $note = '';
public array $lines = [
    ['sku_id' => '', 'expected_qty' => '', 'note' => ''],
];
```

### Methods

`mount()`: if not internal user and `$tenantId === ''`, pre-fill from first active tenant.

`updatedTenantId()`: reset `$warehouseId`, reset `$lines` to one blank line.

`addLine()`: append `['sku_id' => '', 'expected_qty' => '', 'note' => '']`.

`removeLine(int $index)`:
```php
if (count($this->lines) <= 1) { return; }
array_splice($this->lines, $index, 1);
$this->lines = array_values($this->lines);
```

`save()`:
1. `$tenantId = $this->validatedTenantId()`
2. `$this->validateInput($tenantId)`
3. `DB::transaction()`:
   - Create `InboundOrder` with `created_by_user_id = Auth::id()`
   - For each line, load the Sku (scoped to `$tenantId`), verify it is not `virtual_bundle`
     and has a non-null `stock_item_id` (throw ValidationException if not).
     Create `InboundOrderLine` with both `sku_id` and `stock_item_id`.
4. `session()->flash('status', __('inbound.order_created'))`
5. `return redirect()->route('inbound.index')`

**`save()` does not call `InventoryService`. It only writes DB records.**

### Validation (`validateInput(int $tenantId)`)

```php
validator($this->formData(), [
    'tenant_id'    => ['required', 'integer'],
    'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')],
    'ref'          => ['nullable', 'string', 'max:255'],
    'expected_at'  => ['nullable', 'date'],
    'note'         => ['nullable', 'string', 'max:1000'],
    'lines'        => [
        'required', 'array', 'min:1',
        function ($attribute, $value, $fail) {
            $ids = collect($value)->pluck('sku_id')->filter()->values();
            if ($ids->count() !== $ids->unique()->count()) {
                $fail(__('inbound.duplicate_skus'));
            }
        },
    ],
    'lines.*.sku_id'       => ['required', 'integer',
                               Rule::exists('skus', 'id')->where('tenant_id', $tenantId)],
    'lines.*.expected_qty' => ['required', 'integer', 'min:1'],
    'lines.*.note'         => ['nullable', 'string', 'max:500'],
])->validate();
```

### Private helpers

Copy exactly from `SkuCreate`:
`isInternalUser()`, `allowedTenantIds()`, `validatedTenantId()`, `activeTenantIds()`, `nullableString()`

Add comment to `isInternalUser()`:
`// TODO: remove unauthenticated fallback when auth is implemented`

Also add: `tenantOptions()`, `warehouseOptions()`, `skuOptions()`, `currentTenant()`

`skuOptions()`:
- filter by `tenant_id`
- exclude `virtual_bundle` (`where('sku_type', '!=', 'virtual_bundle')`)
- require `stock_item_id IS NOT NULL` (`whereNotNull('stock_item_id')`)
- search across `sku`, `name`, `platform_sku`, `platform_label_code`,
  and via `whereHas('stockItem')` on `code`, `name`, `barcode`
- eager-load `shop:id,code`, `stockItem:id,code,name`
- limit 50, order by `sku`

### `render()`

Pass `tenants`, `warehouses`, `skus`, `showTenantSelect`, `currentTenant`.
Layout:
```php
->layout('inventory', [
    'title'    => __('inbound.create_page_title'),
    'subtitle' => __('inbound.create_page_subtitle'),
])
```

---

## 4. `app/Livewire/InboundOrderIndex.php`

Use `WithPagination`.

### Public properties

```php
public string $tenantId = '';
public string $warehouseId = '';
public string $status = '';
public int $perPage = 15;
```

### `orders()` query

Eager-load `tenant:id,code,name`, `warehouse:id,code,name`, `withCount('lines')`.
Filter by `visibleTenantIds()`, `$tenantId`, `$warehouseId`, `$status`.
Order by `id desc`. Paginate.

### `markArrived(int $orderId)`

1. Load order -- guard: `tenant_id` in `visibleTenantIds()`, `status === 'pending'`.
2. `$order->update(['status' => 'arrived', 'arrived_at' => now(), 'arrived_by_user_id' => Auth::id()])`
3. `session()->flash('status', __('inbound.order_arrived'))`

**Does not call `InventoryService`. No stock change.**

### `cancel(int $orderId)`

1. Load order -- guard: `tenant_id` in `visibleTenantIds()`,
   `status` is `pending` or `arrived`,
   and no line has `received_qty > 0` (`$order->lines()->where('received_qty', '>', 0)->doesntExist()`).
2. `$order->update(['status' => 'cancelled'])`
3. `session()->flash('status', __('inbound.order_cancelled'))`

### Private helpers

`isInternalUser()`, `visibleTenantIds()`, `tenantOptions()`, `warehouseOptions()`

### `render()`

Pass `orders`, `tenants`, `warehouses`, `showTenantFilter`,
`statuses` (hardcoded: `['pending', 'arrived', 'partially_received', 'received', 'cancelled']`).
Layout:
```php
->layout('inventory', [
    'title'    => __('inbound.page_title'),
    'subtitle' => __('inbound.page_subtitle'),
])
```

---

## 5. `app/Livewire/InboundOrderReceive.php`

### Public properties

```php
public int $orderId;

// Keyed by line ID: [ line_id => ['actual_qty' => '5', 'location_id' => ''] ]
public array $lineInputs = [];
```

### `mount(InboundOrder $order)`

1. Guard: verify `$order->tenant_id` is in `visibleTenantIds()` -- `abort(403)` if not.
2. Guard: `$order->status` must be `arrived` or `partially_received` -- `abort(403)` if not.
3. `$this->orderId = $order->id`
4. Populate `$lineInputs` for lines with remaining qty:
   ```php
   foreach ($order->lines as $line) {
       $remaining = $line->expected_qty - $line->received_qty;
       if ($remaining <= 0) { continue; }
       $this->lineInputs[$line->id] = [
           'actual_qty'  => (string) $remaining,
           'location_id' => '',
       ];
   }
   ```

### `save()`

1. Load order with `lines` -- re-verify status (`arrived` or `partially_received`) and tenant visibility.
2. Build and run validation:
   ```php
   $rules = [];
   foreach ($order->lines as $line) {
       $remaining = $line->expected_qty - $line->received_qty;
       if ($remaining <= 0) { continue; }
       $lineId = $line->id;
       $rules["lineInputs.{$lineId}.actual_qty"] = [
           'required',
           'integer',
           'min:0',
           "max:{$remaining}",   // no over receive in MVP
       ];
       $rules["lineInputs.{$lineId}.location_id"] = [
           Rule::requiredIf(fn () => (int) ($this->lineInputs[$lineId]['actual_qty'] ?? 0) > 0),
           'nullable',
           'integer',
           Rule::exists('warehouse_locations', 'id')
               ->where('warehouse_id', $order->warehouse_id)
               ->where('status', 'active'),
       ];
   }
   validator($this->lineInputs, $rules)->validate();
   ```
3. Wrap in `DB::transaction()`:
   ```php
   foreach ($order->lines as $line) {
       $remaining = $line->expected_qty - $line->received_qty;
       if ($remaining <= 0) { continue; }
       $actualQty = (int) ($this->lineInputs[$line->id]['actual_qty'] ?? 0);
       if ($actualQty <= 0) { continue; }   // operator chose to skip this line
       $locationId = (int) ($this->lineInputs[$line->id]['location_id'] ?? 0);
       $movement = app(InventoryService::class)->receiveStock(
           $order->tenant_id,
           $order->warehouse_id,
           $line->stock_item_id,
           $actualQty,
           [
               'ref_type' => 'inbound_order',
               'ref_id'   => (string) $order->id,
               'user_id'  => Auth::id(),
               'note'     => $line->note ?? $order->note,
           ]
       );
       InboundReceipt::create([
           'inbound_order_id'      => $order->id,
           'inbound_order_line_id' => $line->id,
           'tenant_id'             => $order->tenant_id,
           'warehouse_id'          => $order->warehouse_id,
           'warehouse_location_id' => $locationId,
           'sku_id'                => $line->sku_id,
           'stock_item_id'         => $line->stock_item_id,
           'inventory_movement_id' => $movement->id,
           'received_qty'          => $actualQty,
           'received_by_user_id'   => Auth::id(),
           'received_at'           => now(),
           'note'                  => $line->note ?? $order->note,
       ]);
       $line->update([
           'received_qty' => $line->received_qty + $actualQty,
       ]);
   }
   // Recalculate order status after all lines are updated
   $order->refresh();
   $allDone = $order->lines->every(fn ($l) => $l->received_qty >= $l->expected_qty);
   $anyDone = $order->lines->some(fn ($l)  => $l->received_qty > 0);
   $newStatus = match (true) {
       $allDone => 'received',
       $anyDone => 'partially_received',
       default  => $order->status,
   };
   $order->update([
       'status'              => $newStatus,
       'received_at'         => $allDone ? now() : $order->received_at,
       'received_by_user_id' => $allDone ? Auth::id() : $order->received_by_user_id,
   ]);
   ```
4. Catch `InvalidArgumentException $e`:
   Outer `DB::transaction()` rolls back automatically.
   Re-throw as `ValidationException::withMessages(['lineInputs' => $e->getMessage()])`.
5. `session()->flash('status', __('inbound.order_received'))`
6. `return redirect()->route('inbound.index')`

### Private helpers

`isInternalUser()`, `visibleTenantIds()`, `nullableString()`

`locationOptions(int $warehouseId)`: return active `WarehouseLocation` records for the warehouse,
ordered by `code`, returning `id`, `code`, `name`.

### `render()`

Load order with `lines.sku:id,sku,name`, `lines.stockItem:id,code,name`.
Pass `order`, `locationOptions` (result of `locationOptions($order->warehouse_id)`).
Layout:
```php
->layout('inventory', [
    'title'    => __('inbound.receive_page_title'),
    'subtitle' => __('inbound.receive_page_subtitle'),
])
```

---

## 6. `resources/views/livewire/inbound-order-create.blade.php`

Three sections using `table-shell flux-panel form-panel` structure (same as `sku-create.blade.php`).

### Section 1 -- Order header

- Tenant: select if internal, readonly text if not (same pattern as `sku-create.blade.php`)
- Warehouse: `flux:select wire:model.live="warehouseId"`
- Ref, Expected date (`type="date"`), Note
- Back button to `route('inbound.index')` in panel header

### Section 2 -- Lines

For each line (`@foreach ($lines as $index => $line)`):
```
SKU select:         wire:model="lines.{{ $index }}.sku_id"
Expected qty:       wire:model="lines.{{ $index }}.expected_qty"  type="number" min="1" step="1"
Line note:          wire:model="lines.{{ $index }}.note"
Remove button:      wire:click="removeLine({{ $index }})"  hidden if count($lines) <= 1

@error("lines.{$index}.sku_id")       ... @enderror
@error("lines.{$index}.expected_qty") ... @enderror
```

After loop: Add line button `wire:click="addLine()"`, `@error('lines')` for duplicate SKU error.

### Section 3 -- Form actions

Cancel (`route('inbound.index')`), Submit primary.

---

## 7. `resources/views/livewire/inbound-order-index.blade.php`

### Flash messages

Show `session('status')` (success).

### Toolbar

Tenant filter (if internal), warehouse filter, status filter -- all `wire:model.live`.
Create button linking to `route('inbound.create')`.

### Table columns

Ref / Tenant + Warehouse / Expected date / Lines / Status / Actions

### Per row

- **Ref**: `$order->ref ?: '-'`
- **Tenant + Warehouse**: `$order->tenant->code` / `$order->warehouse->code`
- **Expected**: formatted `Y-m-d` or `-`
- **Lines**: `$order->lines_count`
- **Status badge colors**:
  - `pending` -> amber
  - `arrived` -> blue
  - `partially_received` -> indigo
  - `received` -> green
  - `cancelled` -> zinc
- **Actions** (follow the status machine table exactly):
  - `pending`:
    Mark arrived: `wire:click="markArrived({{ $order->id }})"` with
    `wire:confirm="__('inbound.confirm_arrive')"` variant outline
    Cancel: `wire:click="cancel({{ $order->id }})"` with
    `wire:confirm="__('inbound.confirm_cancel')"` variant subtle
  - `arrived` or `partially_received`:
    Receive button (link) to `route('inbound.receive', $order->id)` variant outline
  - `received` or `cancelled`: no actions

---

## 8. `resources/views/livewire/inbound-order-receive.blade.php`

Single form `wire:submit="save"`.

### Section 1 -- Order summary (read-only)

Show: tenant, warehouse, ref, expected_at, current status. No inputs.

### Section 2 -- Lines to receive

If all lines already fully received: show `__('inbound.all_lines_received')` message and no form inputs.

Otherwise, for each line where `$line->received_qty < $line->expected_qty`:

```
SKU code + name (read-only)
Expected qty (read-only)
Already received qty (read-only, shown only if > 0)
Remaining qty (read-only: expected - received)

Actual qty to receive now:
  flux:input type="number"
  wire:model="lineInputs.{{ $line->id }}.actual_qty"
  min="0" step="1"
  :label="__('inbound.field_actual_qty')"
  Hint text: __('inbound.field_actual_qty_hint')

Receiving location (shown only when actual_qty input > 0, or always show and let validation handle it):
  flux:select wire:model="lineInputs.{{ $line->id }}.location_id"
  :label="__('inbound.field_receiving_location')"
  options from $locationOptions

@error("lineInputs.{$line->id}.actual_qty")  ... @enderror
@error("lineInputs.{$line->id}.location_id") ... @enderror
```

`@error('lineInputs')` below all lines for service-level errors.

### Section 3 -- Form actions

Cancel button back to `route('inbound.index')`, Submit primary (`__('inbound.btn_submit_receive')`).

---

## 9. `routes/web.php`

Add:

```php
Route::get('/inbound',                 \App\Livewire\InboundOrderIndex::class)->name('inbound.index');
Route::get('/inbound/create',          \App\Livewire\InboundOrderCreate::class)->name('inbound.create');
Route::get('/inbound/{order}/receive', \App\Livewire\InboundOrderReceive::class)->name('inbound.receive');
```

The `{order}` segment uses Laravel route model binding (`InboundOrder`).

---

## 10. `lang/en/inbound.php` (new file)

```php
return [
    'page_title'             => 'Inbound Orders',
    'page_subtitle'          => 'Track expected stock arrivals and confirm receipt.',
    'create_page_title'      => 'Create Inbound Order',
    'create_page_subtitle'   => 'Announce an expected stock delivery. Stock is not updated until you receive the order.',
    'receive_page_title'     => 'Receive Inbound Order',
    'receive_page_subtitle'  => 'Enter actual received quantities. Stock is updated immediately on submit.',
    'section_header'         => 'Order Details',
    'section_header_hint'    => 'Choose the tenant, warehouse, and expected arrival date.',
    'section_lines'          => 'Items',
    'section_lines_hint'     => 'Add one line per SKU. Virtual bundle SKUs are not allowed.',
    'section_order_summary'  => 'Order Summary',
    'section_receive_lines'  => 'Receive Lines',
    'section_receive_hint'   => 'Enter the actual quantity received for each line. Enter 0 to skip a line for now.',
    'field_tenant'           => 'Tenant',
    'field_warehouse'        => 'Warehouse',
    'field_ref'              => 'Reference',
    'field_ref_hint'         => 'Optional customer PO or delivery reference number.',
    'field_expected_at'      => 'Expected arrival date',
    'field_note'             => 'Note',
    'field_sku'              => 'SKU',
    'field_expected_qty'     => 'Expected qty',
    'field_already_received' => 'Already received',
    'field_remaining_qty'    => 'Remaining',
    'field_actual_qty'       => 'Actual qty to receive',
    'field_actual_qty_hint'  => 'Enter 0 to skip. Cannot exceed remaining qty. Location required when qty is greater than 0.',
    'field_receiving_location' => 'Receiving location',
    'field_line_note'        => 'Line note',
    'btn_create'             => 'Create inbound order',
    'btn_back'               => 'Back to inbound orders',
    'btn_cancel'             => 'Cancel',
    'btn_submit'             => 'Create inbound order',
    'btn_receive'            => 'Receive',
    'btn_submit_receive'     => 'Confirm receipt',
    'btn_mark_arrived'       => 'Mark arrived',
    'btn_cancel_order'       => 'Cancel order',
    'btn_add_line'           => 'Add line',
    'btn_remove_line'        => 'Remove',
    'order_created'          => 'Inbound order created.',
    'order_arrived'          => 'Inbound order marked as arrived.',
    'order_received'         => 'Stock updated from inbound receipt.',
    'order_cancelled'        => 'Inbound order cancelled.',
    'confirm_arrive'         => 'Mark this order as arrived? This confirms the goods are physically at the warehouse.',
    'confirm_cancel'         => 'Cancel this inbound order? Stock will not be added to inventory.',
    'duplicate_skus'         => 'Each SKU may only appear once per inbound order.',
    'sku_not_receivable'     => 'This SKU cannot be received because it has no stock item or is a virtual bundle.',
    'col_ref'                => 'Ref',
    'col_tenant_warehouse'   => 'Tenant / Warehouse',
    'col_expected_at'        => 'Expected',
    'col_lines'              => 'Lines',
    'col_status'             => 'Status',
    'col_actions'            => 'Actions',
    'status_pending'             => 'Pending',
    'status_arrived'             => 'Arrived',
    'status_partially_received'  => 'Partially received',
    'status_received'            => 'Received',
    'status_cancelled'           => 'Cancelled',
    'empty_state'            => 'No inbound orders match the current filters.',
    'all_statuses'           => 'All statuses',
    'select_tenant'          => 'Select tenant',
    'select_location'        => 'Select location',
    'no_active_tenant'       => 'No active tenant',
    'all_lines_received'     => 'All lines on this order have already been received.',
];
```

Create stub copies in `lang/zh_TW/inbound.php`, `lang/zh_CN/inbound.php`, `lang/ja/inbound.php`
with the same English values and `// TODO: translate this file. English values are placeholders.` at top.

---

## 11. Nav link

In `resources/views/inventory.blade.php`, add "Inbound" alongside
Inventory / Movements / SKUs / Stock Adjustment, pointing to `route('inbound.index')`.

---

## Constraints

- All user-visible strings via `__()`. Use `:label="__('key')"` for Flux -- never `label="{{ __('key') }}"`.
- ASCII punctuation only. No Unicode dashes, curly quotes, or non-ASCII characters anywhere.
- Quantity public properties must be `string`; cast to `(int)` only when passing to the service or writing to DB.
- Do not add auth middleware. `isInternalUser()` returns `true` when unauthenticated.
  Add comment: `// TODO: remove unauthenticated fallback when auth is implemented`
- `markArrived()` does NOT call `InventoryService`. Status + metadata only.
- `InboundOrderCreate::save()` does NOT call `InventoryService`. DB records only.
- Only `InboundOrderReceive::save()` calls `receiveStock()`.
- `save()` in `InboundOrderReceive` wraps all service calls in a single `DB::transaction()`.
- Each positive actual receipt creates one `inbound_receipts` row.
- `inbound_order_lines.received_qty` is an aggregate progress field and is updated after each receipt.
- `inbound_receipts.inventory_movement_id` should link to the `InventoryMovement` returned by `InventoryService::receiveStock()`.
- `inbound_orders.received_by_user_id` means the user who completed the full order. Per-receipt staff identity is stored in `inbound_receipts.received_by_user_id`.
- Lines with `actual_qty = 0` are silently skipped -- no service call, no DB update for that line.
- Lines with `actual_qty > 0` require a valid active location in the order's warehouse.
- Over receive is NOT allowed in MVP: `actual_qty` max is `expected_qty - received_qty` per line.
  Validate using `"max:{$remaining}"` in the rules array, computed per line.
- Location validation MUST use `Rule::requiredIf(fn () => (int)($this->lineInputs[$lineId]['actual_qty'] ?? 0) > 0)`.
  Do NOT use `required_if:field,value` string syntax -- it does not support `>` comparisons.
- Cancel guard: `status` must be `pending` or `arrived`, AND no line has `received_qty > 0`.
- `received_at` and `received_by_user_id` are only set when ALL lines are fully received.
- Do not build inventory location balances (`inventory_location_balances`) in this task.
- Do not add a detail / show page in v1.
- Damaged goods are out of scope for v1. MVP only receives normal qty via `receiveStock()`.

---

## Verification

When done, reply with only the git commit hash.
