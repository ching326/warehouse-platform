# Task: Fulfillment Groups v1

## Stack

Laravel 13, Livewire 4 (class-based components, not Volt), Flux UI (`livewire/flux ^2.14`),
SQLite (dev), PHP 8.3. Plain Blade views only. No TypeScript.

---

## Architecture

```
Sales Order (fulfillment_status: ready)
  --> FulfillmentGroup  (planning/grouping layer)
        --> OutboundOrder  (linked via fulfillment_group_id)
              --> InventoryMovement  (via InventoryService on ship)
```

**Key principle:** FulfillmentGroupCreate calls InventoryService::reserveStock() directly
and creates a linked OutboundOrder with its lines, mirroring how OutboundOrderCreate works.
Shipping is owned by OutboundOrderShip -- not by FulfillmentGroup.
This keeps one unified outbound flow for packing, courier export, and tracking.
FulfillmentGroup does not trigger reservation or shipping through the OutboundOrder model
itself; OutboundOrder is the record, not the actor.

**FulfillmentGroup is a planning layer** that:
- Groups sales orders sharing the same recipient address (same `ship_together_key`)
- Snapshots the delivery address at group creation time
- Creates a linked OutboundOrder to handle the actual stock movement
- Tracks back-written status from the OutboundOrder via an Observer

---

## Status machines

### FulfillmentGroup.status

```
reserved --> shipped
reserved --> cancelled
```

- `reserved`: group created, stock reserved by FulfillmentGroupCreate using InventoryService; linked OutboundOrder has status = pending
- `shipped`: OutboundOrder was shipped; Observer back-writes this
- `cancelled`: OutboundOrder was cancelled; Observer back-writes this; sales orders returned to `ready`

A cancelled group is a closed, permanent record. It is never reused or reopened.
Sales orders that return to `ready` after cancellation can be selected into a new group.

Leave room in the DB for future statuses (export, label, packing) without migration changes.
Use `string` column with no enum constraint.

### SalesOrder.fulfillment_status (already exists, updated by this flow)

```
ready --> in_group  (on FulfillmentGroup create)
in_group --> shipped  (when OutboundOrder ships, via Observer)
in_group --> ready  (when OutboundOrder cancels, via Observer)
```

---

## Pre-conditions

This task depends on Sales Orders Phase 1 being complete. The following must exist before
executing this spec:

- `SalesOrderIndex`, `SalesOrderCreate`, `SalesOrderDetail` Livewire components
- `SalesOrder` and `SalesOrderLine` models with all constants used below
  (`FULFILLMENT_STATUS_READY`, `FULFILLMENT_STATUS_IN_GROUP`, `FULFILLMENT_STATUS_SHIPPED`,
  `FULFILLMENT_STATUS_CANCELLED`, `ORDER_STATUS_COMPLETED`)
- Sales Orders nav link already in navigation
- `sales_orders.ship_together_key` is populated for ready orders. Fulfillment Groups v1 only
  supports orders with a non-null `ship_together_key`; orders without one must be rejected by
  the create flow.

Do not execute this task if Sales Orders Phase 1 is incomplete. The Fulfillment Group
components import SalesOrder constants and route to `sales.orders.show`.

---

## What this task must build

Migration run order is strict because of FK dependencies:

```
000006_create_fulfillment_groups_table.php          (no deps)
000007_add_fulfillment_group_id_to_outbound_orders_table.php  (refs fulfillment_groups)
000008_create_fulfillment_group_orders_table.php    (refs both)
```

### Migration 1: create `fulfillment_groups`

File: `2026_06_19_000006_create_fulfillment_groups_table.php`

```php
Schema::create('fulfillment_groups', function (Blueprint $table) {
    $table->id();
    $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
    $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
    $table->string('reference_no')->unique();           // e.g. FG-20260619-0001
    $table->string('status')->default('reserved');     // reserved | shipped | cancelled
    $table->string('ship_together_key');               // must match across all attached orders
    // recipient address snapshot (copied from sales orders at group creation time)
    $table->string('recipient_name')->nullable();
    $table->string('recipient_phone')->nullable();
    $table->string('recipient_country_code', 2)->nullable();
    $table->string('recipient_postal_code')->nullable();
    $table->string('recipient_state')->nullable();
    $table->string('recipient_city')->nullable();
    $table->string('recipient_address_line1')->nullable();
    $table->string('recipient_address_line2')->nullable();
    // shipping (filled on ship)
    $table->string('courier')->nullable();
    $table->string('tracking_no')->nullable();
    $table->text('note')->nullable();
    $table->timestamp('shipped_at')->nullable();
    $table->foreignId('shipped_by_user_id')->nullable()->constrained('users')->nullOnDelete();
    $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
    $table->timestamps();

    $table->index(['tenant_id', 'status']);
    $table->index(['ship_together_key']);
});
```

### Migration 2: add `fulfillment_group_id` to `outbound_orders`

File: `2026_06_19_000007_add_fulfillment_group_id_to_outbound_orders_table.php`

```php
Schema::table('outbound_orders', function (Blueprint $table) {
    $table->foreignId('fulfillment_group_id')
        ->nullable()
        ->after('id')
        ->constrained('fulfillment_groups')
        ->nullOnDelete();
});
```

### Migration 3: create `fulfillment_group_orders`

File: `2026_06_19_000008_create_fulfillment_group_orders_table.php`

```php
Schema::create('fulfillment_group_orders', function (Blueprint $table) {
    $table->id();
    $table->foreignId('fulfillment_group_id')->constrained()->cascadeOnDelete();
    $table->foreignId('sales_order_id')->constrained('sales_orders')->restrictOnDelete();
    $table->timestamps();

    $table->unique(['fulfillment_group_id', 'sales_order_id']);
    $table->index(['sales_order_id']);
});
```

---

## Models

### `app/Models/FulfillmentGroup.php`

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class FulfillmentGroup extends Model
{
    use HasFactory, LogsActivity;

    public const STATUS_RESERVED  = 'reserved';
    public const STATUS_SHIPPED   = 'shipped';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'tenant_id', 'warehouse_id', 'reference_no', 'status', 'ship_together_key',
        'recipient_name', 'recipient_phone', 'recipient_country_code',
        'recipient_postal_code', 'recipient_state', 'recipient_city',
        'recipient_address_line1', 'recipient_address_line2',
        'courier', 'tracking_no', 'note',
        'shipped_at', 'shipped_by_user_id', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return ['shipped_at' => 'datetime'];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('fulfillment_group')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function tenant(): BelongsTo    { return $this->belongsTo(Tenant::class); }
    public function warehouse(): BelongsTo { return $this->belongsTo(Warehouse::class); }
    public function outboundOrder(): HasOne { return $this->hasOne(OutboundOrder::class); }

    public function orders(): BelongsToMany
    {
        return $this->belongsToMany(SalesOrder::class, 'fulfillment_group_orders');
    }

    /**
     * Generate reference number from the saved record's own id.
     * Call this AFTER create(), passing the model's id.
     * Format: FG-YYYYMMDD-{id} -- globally unique because id is the PK.
     * The numeric part does not reset daily; the date is informational for operators.
     * If id exceeds 9999, str_pad will naturally return the longer id string.
     * No race condition: id is assigned by the DB before this is called.
     */
    public static function buildReferenceNo(int $id): string
    {
        return 'FG-' . now()->format('Ymd') . '-' . str_pad($id, 4, '0', STR_PAD_LEFT);
    }
}
```

### `app/Models/FulfillmentGroupOrder.php` (pivot)

```php
class FulfillmentGroupOrder extends Model
{
    protected $fillable = ['fulfillment_group_id', 'sales_order_id'];
}
```

### Update `app/Models/OutboundOrder.php`

Add `fulfillment_group_id` to `$fillable`:
```php
'fulfillment_group_id',
```

Add relation:
```php
public function fulfillmentGroup(): BelongsTo
{
    return $this->belongsTo(FulfillmentGroup::class);
}
```

---

## Observer: `app/Observers/OutboundOrderObserver.php`

Register in `AppServiceProvider::boot()`:
```php
OutboundOrder::observe(OutboundOrderObserver::class);
```

The observer listens on `updated` and back-writes to FulfillmentGroup and SalesOrders
when the linked OutboundOrder transitions to `shipped` or `cancelled`.

```php
namespace App\Observers;

use App\Models\FulfillmentGroup;
use App\Models\OutboundOrder;
use App\Models\SalesOrder;

class OutboundOrderObserver
{
    public function updated(OutboundOrder $order): void
    {
        if ($order->fulfillment_group_id === null) {
            return;
        }

        $group = FulfillmentGroup::find($order->fulfillment_group_id);
        if (! $group) {
            return;
        }

        if ($order->wasChanged('status')) {
            if ($order->status === OutboundOrder::STATUS_SHIPPED) {
                // Back-write: group shipped, sales orders completed
                $group->update([
                    'status'              => FulfillmentGroup::STATUS_SHIPPED,
                    'shipped_at'          => $order->shipped_at,
                    'shipped_by_user_id'  => $order->shipped_by_user_id,
                    'courier'             => $order->courier,
                    'tracking_no'         => $order->tracking_no,
                ]);

                $group->orders()->update([
                    'fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_SHIPPED,
                    'order_status'       => SalesOrder::ORDER_STATUS_COMPLETED,
                ]);
            }

            if ($order->status === OutboundOrder::STATUS_CANCELLED) {
                // Back-write: group cancelled, sales orders return to ready
                $group->update(['status' => FulfillmentGroup::STATUS_CANCELLED]);

                $group->orders()->update([
                    'fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_READY,
                ]);
            }
        }
    }
}
```

---

## Livewire components

### Access control pattern

Copy this exactly into every component (same as SalesOrderIndex/Create):

```php
private bool $allowedTenantIdsResolved = false;
private array $allowedTenantIdsCache   = [];

// TODO: remove unauthenticated fallback when auth is implemented
private function isInternalUser(): bool
{
    $user = Auth::user();
    return ! $user || $user->user_type === 'internal';
}

private function allowedTenantIds(): array
{
    if ($this->allowedTenantIdsResolved) {
        return $this->allowedTenantIdsCache;
    }
    $this->allowedTenantIdsResolved = true;
    if ($this->isInternalUser()) {
        return $this->allowedTenantIdsCache = Tenant::query()->pluck('id')->all();
    }
    return $this->allowedTenantIdsCache = Auth::user()
        ->tenantUsers()
        ->where('status', 'active')
        ->pluck('tenant_id')
        ->all();
}

private function authorizeTenantAccess(): void
{
    if (! $this->isInternalUser() && $this->allowedTenantIds() === []) {
        abort(403);
    }
}
```

---

### 1. `FulfillmentGroupIndex` -- `GET /fulfillment-groups`

Route name: `fulfillment-groups.index`

Filters (URL-bound with `#[Url]`): `tenantId`, `statusFilter`, `search` (reference_no or tracking_no).
Paginate 30. Tenant filter visible to internal users only.
`mount()`: call `authorizeTenantAccess()`.

Table columns:
- Reference No (`FG-20260619-0001`)
- Tenant / Warehouse (stacked)
- Recipient (snapshot name + city)
- Orders (count of attached sales orders)
- Status badge
- Shipped at (or dash)
- Actions: "View" button linking to `fulfillment-groups.show`

---

### 2. `FulfillmentGroupCreate` -- `GET /fulfillment-groups/create`

Route name: `fulfillment-groups.create`

**Purpose:** Select a set of ready sales orders sharing the same `ship_together_key`,
then create a FulfillmentGroup and a linked OutboundOrder that reserves stock.

Public properties:
```php
public string $tenantId     = '';
public string $warehouseId  = '';
public string $shipKey      = '';    // ship_together_key to filter eligible orders
public array  $selectedOrderIds = [];
```

`mount()`: call `authorizeTenantAccess()`. For tenant users, auto-set `tenantId`.

**UI flow:**
1. Select tenant (internal users) + warehouse
2. Select a `ship_together_key` from available keys (dropdown of distinct keys with ready orders
   in that tenant, showing recipient name + count of matching orders per key)
3. When key selected, show the matching orders as a checklist:
   - platform_order_id (or `#id`), recipient name, line count
   - All orders with the same key are pre-checked (user can deselect)
4. Submit creates the group

**`save()` logic:**

Validation before transaction:
- `tenantId`: required, in `allowedTenantIds()`
- `warehouseId`: required, exists in `warehouses` with `status = active`
- `selectedOrderIds`: non-empty, all belong to the selected tenant
- All selected orders must have `fulfillment_status = ready`
- All selected orders must share exactly the same `ship_together_key` (not null)

Inside `DB::transaction()`:

Do not rely on the UI checkbox state. Server-side validation inside the locked transaction
is authoritative. This prevents two browser tabs from creating overlapping groups for the
same orders.

```php
// 1. Lock selected sales orders to prevent race conditions.
//    lockForUpdate() blocks any concurrent transaction from reading these rows
//    until this transaction commits or rolls back.
//    Note: lockForUpdate() is a no-op in SQLite. Re-check logic still runs correctly
//    in dev/test; the exclusive row lock is a production (MySQL/Postgres) concern only.
$orders = SalesOrder::whereIn('id', $this->selectedOrderIds)
    ->where('tenant_id', $tenantId)
    ->lockForUpdate()
    ->get();

// 2. Re-check fulfillment_status inside the lock.
//    An order that was ready when the form loaded may have been grabbed by a
//    concurrent request between form load and submit.
foreach ($orders as $order) {
    if ($order->fulfillment_status !== SalesOrder::FULFILLMENT_STATUS_READY) {
        throw ValidationException::withMessages([
            'selectedOrderIds' => __('fulfillment_groups.order_no_longer_ready', ['id' => $order->id]),
        ]);
    }
}

// 3. Verify all share the same ship_together_key
$keys = $orders->pluck('ship_together_key')->unique();
if ($keys->count() !== 1 || $keys->first() === null) {
    throw ValidationException::withMessages([
        'selectedOrderIds' => __('fulfillment_groups.orders_must_share_key'),
    ]);
}

// 4. Take recipient snapshot from the first order
$firstOrder = $orders->first();

// 5. Create FulfillmentGroup
// reference_no is generated from the auto-incremented id to avoid race conditions.
// Create with a placeholder, then update immediately after insert.
$group = FulfillmentGroup::create([
    'tenant_id'               => $tenantId,
    'warehouse_id'            => (int) $this->warehouseId,
    'reference_no'            => 'FG-PENDING-' . \Illuminate\Support\Str::uuid(),  // placeholder; overwritten below
    'status'                  => FulfillmentGroup::STATUS_RESERVED,
    'ship_together_key'       => $firstOrder->ship_together_key,
    'recipient_name'          => $firstOrder->recipient_name,
    'recipient_phone'         => $firstOrder->recipient_phone,
    'recipient_country_code'  => $firstOrder->recipient_country_code,
    'recipient_postal_code'   => $firstOrder->recipient_postal_code,
    'recipient_state'         => $firstOrder->recipient_state,
    'recipient_city'          => $firstOrder->recipient_city,
    'recipient_address_line1' => $firstOrder->recipient_address_line1,
    'recipient_address_line2' => $firstOrder->recipient_address_line2,
    'created_by_user_id'      => Auth::id(),
]);
$group->update(['reference_no' => FulfillmentGroup::buildReferenceNo($group->id)]);

// 6. Attach sales orders to group
$group->orders()->attach($this->selectedOrderIds);

// 7. Collect lines grouped by (sku_id + stock_item_id) for outbound lines,
//    and by stock_item_id for stock reservation.
//    Two SKUs sharing the same stock_item must each get their own outbound line
//    so the outbound page shows the correct SKU breakdown.
//    Skip lines where line_status != ready, or sku has no stock_item_id.
$bySkuAndItem   = []; // ["$skuId:$stockItemId" => ['sku_id', 'stock_item_id', 'qty']]
$byStockItem    = []; // [$stockItemId => total_qty]  -- used for reserveStock calls

foreach ($orders as $order) {
    foreach ($order->lines()->where('line_status', SalesOrderLine::STATUS_READY)->get() as $line) {
        $sku = $line->sku;
        if (! $sku || ! $sku->stock_item_id) {
            continue;
        }
        $key = $sku->id . ':' . $sku->stock_item_id;
        $bySkuAndItem[$key] ??= ['sku_id' => $sku->id, 'stock_item_id' => $sku->stock_item_id, 'qty' => 0];
        $bySkuAndItem[$key]['qty'] += $line->quantity;
        $byStockItem[$sku->stock_item_id] = ($byStockItem[$sku->stock_item_id] ?? 0) + $line->quantity;
    }
}

// 8. Create linked OutboundOrder
$outbound = OutboundOrder::create([
    'fulfillment_group_id'    => $group->id,
    'tenant_id'               => $tenantId,
    'warehouse_id'            => (int) $this->warehouseId,
    'ref'                     => $group->reference_no,
    'status'                  => OutboundOrder::STATUS_PENDING,
    'recipient_name'          => $firstOrder->recipient_name,
    'recipient_phone'         => $firstOrder->recipient_phone,
    'recipient_country_code'  => $firstOrder->recipient_country_code,
    'recipient_postal_code'   => $firstOrder->recipient_postal_code,
    'recipient_state'         => $firstOrder->recipient_state,
    'recipient_city'          => $firstOrder->recipient_city,
    'recipient_address_line1' => $firstOrder->recipient_address_line1,
    'recipient_address_line2' => $firstOrder->recipient_address_line2,
    'created_by_user_id'      => Auth::id(),
]);

// 9. Reserve stock per stock_item_id (aggregate across all SKUs that map to same item).
//    Then create one outbound line per sku + stock_item combination.
foreach ($byStockItem as $stockItemId => $totalQty) {
    app(InventoryService::class)->reserveStock(
        tenantId:    $tenantId,
        warehouseId: (int) $this->warehouseId,
        stockItemId: $stockItemId,
        quantity:    $totalQty,
        context: [
            'ref_type' => 'fulfillment_group',
            'ref_id'   => (string) $group->id,
            'user_id'  => Auth::id(),
        ],
    );
}

foreach ($bySkuAndItem as $agg) {
    $outbound->lines()->create([
        'tenant_id'             => $tenantId,
        'sku_id'                => $agg['sku_id'],
        'stock_item_id'         => $agg['stock_item_id'],
        'qty'                   => $agg['qty'],
        'inventory_movement_id' => null,  // filled on ship
    ]);
}

// 10. Update sales orders to in_group
SalesOrder::whereIn('id', $this->selectedOrderIds)
    ->update(['fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_IN_GROUP]);
```

Transaction error handling:

```php
// Correct pattern: let InvalidArgumentException escape the closure so Laravel
// rolls back automatically. Catch it OUTSIDE the transaction, not inside.
try {
    $group = DB::transaction(function () use (...) {
        // ... all steps above ...
        // Do NOT catch InvalidArgumentException here.
        return $group;
    });
} catch (\InvalidArgumentException $e) {
    // Transaction is already rolled back at this point.
    session()->flash('error', $e->getMessage());
    return;
}

session()->flash('status', __('fulfillment_groups.group_created'));
return $this->redirect(route('fulfillment-groups.show', $group), navigate: true);
```

Do not catch `InvalidArgumentException` inside the `DB::transaction()` closure.
If caught inside without re-throwing, the DB may commit partial work before the exception
propagates.

`ValidationException` thrown inside the closure also escapes and rolls back the transaction.
Livewire catches it natively and shows field errors; do not add a separate catch block for it.

---

### 3. `FulfillmentGroupDetail` -- `GET /fulfillment-groups/{group}`

Route name: `fulfillment-groups.show`

`mount(FulfillmentGroup $group)`:
- Abort 403 if `$group->tenant_id` not in `allowedTenantIds()`
- Store `$this->groupId = $group->id`

Load group with: `orders.lines.sku.stockItem`, `warehouse`, `tenant`, `outboundOrder`

**Page sections:**

1. **Header:** reference_no, tenant, warehouse, status badge, created at

2. **Recipient snapshot** (editable when status = `reserved`):
   - Display: all address fields read-only
   - Edit mode (`$editingRecipient` boolean): inline form for all address fields
   - `saveRecipient()` updates both FulfillmentGroup and linked OutboundOrder recipient fields
   - Same pattern as `SalesOrderDetail`

3. **Shipping info** (editable when status = `reserved`):
   - Fields: `courier`, `tracking_no`, `note`
   - Inline edit (`$editingShipping` boolean)
   - `saveShipping()` updates FulfillmentGroup (OutboundOrder shipping details filled on actual ship)

4. **Linked Outbound Order:**
   - Show outbound reference + status badge
   - "Go to outbound" button linking to `outbound.ship` (when status = pending)
   - Shipping is done from the outbound page, not from here

5. **Orders table:**
   - Columns: platform_order_id (or `#id`), recipient name, city, line count, fulfillment_status badge
   - Link each row to `sales.orders.show`

6. **Combined lines table** (aggregated from all non-cancelled lines across attached orders):
   - Group by `sku_id`, sum `quantity`
   - Columns: SKU code, Stock item code + name, Total qty

No "Ship" or "Cancel" button on this page. Those actions live on the OutboundOrder.
The group status updates automatically via `OutboundOrderObserver`.

---

## Routes to add in `routes/web.php`

```php
use App\Livewire\FulfillmentGroupCreate;
use App\Livewire\FulfillmentGroupDetail;
use App\Livewire\FulfillmentGroupIndex;

Route::get('/fulfillment-groups', FulfillmentGroupIndex::class)->name('fulfillment-groups.index');
Route::get('/fulfillment-groups/create', FulfillmentGroupCreate::class)->name('fulfillment-groups.create');
Route::get('/fulfillment-groups/{group}', FulfillmentGroupDetail::class)->name('fulfillment-groups.show');
```

---

## Nav

Add to `resources/views/components/layout/navigation.blade.php` as a plain link
(no dropdown), after Sales Orders:
```php
$fulfillmentActive = request()->routeIs('fulfillment-groups.*');
```
```html
<a href="{{ route('fulfillment-groups.index') }}"
   class="top-nav-btn {{ $fulfillmentActive ? 'is-active' : '' }}"
   wire:navigate>
    {{ __('common.nav_fulfillment_groups') }}
</a>
```

Add to `lang/en/common.php`: `'nav_fulfillment_groups' => 'Fulfillment'`
Add stub (value = key) to `lang/ja/common.php`, `lang/zh_TW/common.php`, `lang/zh_CN/common.php`.

---

## Lang file

Create `lang/en/fulfillment_groups.php` with all strings used in the three components.
Create stubs (value = key) for `lang/ja/`, `lang/zh_TW/`, `lang/zh_CN/`.

Minimum keys needed:
```php
'page_title'             => 'Fulfillment Groups',
'create_page_title'      => 'Create Fulfillment Group',
'detail_page_title'      => 'Fulfillment Group',
'group_created'          => 'Fulfillment group created.',
'order_no_longer_ready'  => 'Order #:id is no longer ready for fulfillment.',
'orders_must_share_key'  => 'All selected orders must share the same recipient address.',
'status_reserved'        => 'Reserved',
'status_shipped'         => 'Shipped',
'status_cancelled'       => 'Cancelled',
'col_reference_no'       => 'Reference',
'col_recipient'          => 'Recipient',
'col_orders'             => 'Orders',
'col_status'             => 'Status',
'col_shipped_at'         => 'Shipped at',
'col_actions'            => 'Actions',
'btn_create'             => 'Create group',
'btn_view'               => 'View',
'btn_go_to_outbound'     => 'Go to outbound',
'empty_state'            => 'No fulfillment groups found.',
'field_courier'          => 'Courier',
'field_tracking_no'      => 'Tracking no.',
'field_note'             => 'Note',
'section_orders'         => 'Sales Orders',
'section_lines'          => 'Combined lines',
'section_outbound'       => 'Linked outbound order',
'section_recipient'      => 'Recipient',
'section_shipping'       => 'Shipping info',
```

---

## Factory

`database/factories/FulfillmentGroupFactory.php`

```php
public function definition(): array
{
    return [
        'tenant_id'               => Tenant::factory(),
        'warehouse_id'            => Warehouse::factory(),
        'reference_no'            => 'FG-' . fake()->unique()->numerify('########'),
        'status'                  => FulfillmentGroup::STATUS_RESERVED,
        'ship_together_key'       => fake()->md5(),
        'recipient_name'          => fake()->name(),
        'recipient_country_code'  => 'JP',
        'recipient_city'          => fake()->city(),
        'recipient_address_line1' => fake()->streetAddress(),
        'created_by_user_id'      => null,
    ];
}
```

---

## Tests -- `tests/Feature/FulfillmentGroupTest.php`

Use `RefreshDatabase`. Follow the same helper pattern as `SalesOrderTest`.

**Important:** `reserveStock` requires an `InventoryBalance` row for the stock item in the
target warehouse. Your `salesSku()` helper must seed one, or create a dedicated
`fulfillmentSku()` helper that does:
```php
$balance = InventoryBalance::factory()->create([
    'tenant_id'    => $tenant->id,
    'warehouse_id' => $warehouse->id,
    'stock_item_id' => $stockItem->id,
    'on_hand_qty'  => 100,
    'reserved_qty' => 0,
    'available_qty' => 100,
]);
```

Required test cases:

| # | Test | What it asserts |
|---|---|---|
| 1 | `test_create_fulfillment_group_succeeds` | Group + pivot + OutboundOrder created; stock reserved; sales orders set to `in_group` |
| 2 | `test_create_group_generates_unique_reference_no` | `reference_no` matches `FG-` format and is unique |
| 3 | `test_create_group_snapshots_recipient_from_first_order` | Group `recipient_name` matches the sales order |
| 4 | `test_create_group_rejects_empty_order_selection` | Validation error |
| 5 | `test_create_group_rejects_orders_with_different_ship_together_keys` | Orders with mixed keys are rejected |
| 6 | `test_create_group_rejects_order_not_in_ready_status` | Order with `fulfillment_status = in_group` is rejected |
| 7 | `test_create_group_rejects_order_from_wrong_tenant` | Cross-tenant order is rejected |
| 8 | `test_outbound_ship_back_writes_group_and_sales_orders` | When linked OutboundOrder ships, group becomes `shipped`, sales orders become `completed/shipped` |
| 9 | `test_outbound_cancel_back_writes_group_and_releases_sales_orders` | When linked OutboundOrder cancels, group becomes `cancelled`, sales orders return to `ready` |
| 10 | `test_unlinked_outbound_ship_does_not_affect_groups` | Observer ignores OutboundOrders with no `fulfillment_group_id` |
| 11 | `test_tenant_user_only_sees_own_groups` | Tenant user cannot see other tenants' groups |
| 12 | `test_tenant_user_without_active_tenant_cannot_access_pages` | 403 on index and create |
| 13 | `test_fulfillment_group_routes_render` | All three routes return 200 for internal user |
| 14 | `test_create_group_preserves_distinct_outbound_lines_for_skus_sharing_stock_item` | SKU-A and SKU-B both map to the same stock_item; two sales order lines (one per SKU); group created; assert reserved qty = sum of both lines; assert outbound_order_lines has 2 rows with correct sku_id each |

---

## Constraints

- No Volt. No TypeScript. Class-based Livewire only.
- No delete. Groups are permanent records (cancelled state only).
- `DB::transaction()` for all multi-step writes.
- `lockForUpdate()` on sales orders before reserve to prevent double-grouping.
- Let `InvalidArgumentException` from InventoryService escape the transaction closure so Laravel rolls back automatically; catch it outside, flash an error, and never return a 500.
- All queries must scope to `allowedTenantIds()`.
- Copy access control pattern exactly as shown above.
- `mount()` must be the first public method in each component.
- Do not modify any existing migration, model constant, or test.
- Add `fulfillment_group_id` to `OutboundOrder::$fillable` and add the `fulfillmentGroup()` relation.
- Register `OutboundOrderObserver` in `AppServiceProvider::boot()`.
- Run `php artisan test` at the end and confirm all tests pass.
