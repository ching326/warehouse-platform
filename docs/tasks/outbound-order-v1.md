# Task: Outbound Order v1

## Stack

Laravel 13, Livewire 4 (class-based components, not Volt), Flux UI (`livewire/flux ^2.14`),
SQLite (dev), PHP 8.3. Plain Blade views only -- no TypeScript.

---

## What this task covers

Three pages and three Livewire components:

- `GET /outbound` -- OutboundOrderIndex: list, filter, navigate to ship page, cancel action
- `GET /outbound/create` -- OutboundOrderCreate: create order, reserve stock immediately
- `GET /outbound/{order}/ship` -- OutboundOrderShip: enter courier/tracking info, confirm shipment

**Stock is reserved immediately on order creation (pending status).**
**Shipping confirmation is a separate page, not an inline button on the index.**
**Cancel releases reserved stock and is done from the index.**

---

## SKU types and bundle expansion

Outbound supports all three SKU types. The handling differs:

| sku_type | stock_item_id | Behaviour |
|---|---|---|
| single | set | Reserve and ship own stock_item_id |
| physical_bundle | set | Reserve and ship own stock_item_id |
| virtual_bundle | null | Expand bundleComponents, reserve/ship each component_stock_item_id |

For virtual_bundle, use `$sku->bundleComponents` (HasMany SkuBundleComponent via `bundle_sku_id`).
Each `SkuBundleComponent` has: `bundle_sku_id`, `component_stock_item_id`, `quantity`, `tenant_id`.

A virtual bundle with user qty=N reserves:
- component A stock_item: qty = N * componentA.quantity
- component B stock_item: qty = N * componentB.quantity

If a virtual bundle has no components configured, reject it with a validation error.

---

## Parent/child line design for virtual bundles

`outbound_order_lines` has a self-referential `parent_line_id` (nullable FK, cascadeOnDelete).

**For single/physical_bundle lines:**
- `parent_line_id = null`
- `stock_item_id = sku->stock_item_id`
- `qty = user input`

**For virtual_bundle lines:**
- One parent line: `parent_line_id = null`, `stock_item_id = null`, `qty = user_qty`
- N child lines (one per component): `parent_line_id = parent.id`, `stock_item_id = component.component_stock_item_id`, `qty = user_qty * component.quantity`
- Child lines keep `sku_id` as the parent bundle SKU; `stock_item_id` is the component's stock item.
  Display child lines using `stockItem->code` / `stockItem->name`, not the bundle SKU code.

All `InventoryService` calls (reserve, ship, release) operate only on lines where
`stock_item_id IS NOT NULL`. Never call the service on parent bundle lines.

---

## Status machine

```
pending (stock reserved) -> shipped
pending (stock reserved) -> cancelled
```

- `pending`: order created, stock reserved. Can navigate to ship page or cancel.
- `shipped`: shipment confirmed, stock deducted. Terminal.
- `cancelled`: order cancelled, reserved stock returned. Terminal.

`pending` means stock is already reserved. The UI must make this clear.

---

## Database

### Migration: `2026_XX_XX_000017_create_outbound_orders_table.php`

```php
Schema::create('outbound_orders', function (Blueprint $table) {
    $table->id();
    $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
    $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
    $table->string('ref')->nullable();
    $table->string('status');
    $table->date('expected_ship_at')->nullable();
    $table->text('note')->nullable();

    // Recipient / delivery address (filled at create time)
    $table->string('recipient_name')->nullable();
    $table->string('recipient_phone')->nullable();
    $table->string('recipient_country_code', 2)->nullable();
    $table->string('recipient_postal_code')->nullable();
    $table->string('recipient_state')->nullable();
    $table->string('recipient_city')->nullable();
    $table->string('recipient_address_line1')->nullable();
    $table->string('recipient_address_line2')->nullable();

    // Shipping method (planned at create, confirmed at ship)
    $table->string('shipping_method')->nullable();

    // Shipment details (filled at ship time)
    $table->string('courier')->nullable();
    $table->string('tracking_no')->nullable();
    $table->unsignedSmallInteger('package_count')->nullable();
    $table->unsignedInteger('package_weight_g')->nullable();
    $table->text('ship_note')->nullable();

    // Metadata
    $table->timestamp('shipped_at')->nullable();
    $table->foreignId('shipped_by_user_id')->nullable()->constrained('users')->nullOnDelete();
    $table->timestamp('cancelled_at')->nullable();
    $table->foreignId('cancelled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
    $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
    $table->timestamps();

    $table->index(['tenant_id', 'status']);
    $table->index(['warehouse_id']);
    $table->index(['created_at']);
});
```

### Migration: `2026_XX_XX_000018_create_outbound_order_lines_table.php`

```php
Schema::create('outbound_order_lines', function (Blueprint $table) {
    $table->id();
    $table->foreignId('outbound_order_id')->constrained()->cascadeOnDelete();
    $table->foreignId('parent_line_id')
        ->nullable()
        ->constrained('outbound_order_lines')
        ->cascadeOnDelete();
    $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
    $table->foreignId('sku_id')->constrained('skus')->restrictOnDelete();
    $table->foreignId('stock_item_id')
        ->nullable()
        ->constrained('stock_items')
        ->restrictOnDelete();
    $table->unsignedInteger('qty');
    $table->foreignId('inventory_movement_id')
        ->nullable()
        ->constrained('inventory_movements')
        ->nullOnDelete();
    $table->text('note')->nullable();
    $table->timestamps();

    $table->index(['outbound_order_id']);
    $table->index(['parent_line_id']);
    $table->index(['tenant_id', 'stock_item_id']);
    $table->index(['inventory_movement_id']);
});
```

`stock_item_id` is nullable to allow virtual bundle parent lines.
`inventory_movement_id` stores the ship movement when shipped (set on child/leaf lines only).

---

## Models

### `app/Models/OutboundOrder.php`

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OutboundOrder extends Model
{
    use HasFactory;

    public const STATUS_PENDING   = 'pending';
    public const STATUS_SHIPPED   = 'shipped';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'tenant_id', 'warehouse_id', 'ref', 'status',
        'expected_ship_at', 'note',
        'recipient_name', 'recipient_phone',
        'recipient_country_code', 'recipient_postal_code',
        'recipient_state', 'recipient_city',
        'recipient_address_line1', 'recipient_address_line2',
        'shipping_method',
        'courier', 'tracking_no',
        'package_count', 'package_weight_g', 'ship_note',
        'shipped_at', 'shipped_by_user_id',
        'cancelled_at', 'cancelled_by_user_id',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'expected_ship_at' => 'date',
            'shipped_at'       => 'datetime',
            'cancelled_at'     => 'datetime',
            'package_count'    => 'integer',
            'package_weight_g' => 'integer',
        ];
    }

    public function tenant(): BelongsTo { return $this->belongsTo(Tenant::class); }
    public function warehouse(): BelongsTo { return $this->belongsTo(Warehouse::class); }
    public function createdBy(): BelongsTo { return $this->belongsTo(User::class, 'created_by_user_id'); }
    public function shippedBy(): BelongsTo { return $this->belongsTo(User::class, 'shipped_by_user_id'); }
    public function cancelledBy(): BelongsTo { return $this->belongsTo(User::class, 'cancelled_by_user_id'); }

    // All lines including bundle parent and component children
    public function lines(): HasMany
    {
        return $this->hasMany(OutboundOrderLine::class)->orderBy('id');
    }

    // Only top-level lines (bundle parents + non-bundle lines). Use for display.
    public function parentLines(): HasMany
    {
        return $this->hasMany(OutboundOrderLine::class)
            ->whereNull('parent_line_id')
            ->orderBy('id');
    }

    // Only leaf lines (stock_item_id not null). Use for service calls.
    public function leafLines(): HasMany
    {
        return $this->hasMany(OutboundOrderLine::class)
            ->whereNotNull('stock_item_id');
    }
}
```

### `app/Models/OutboundOrderLine.php`

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OutboundOrderLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'outbound_order_id', 'parent_line_id', 'tenant_id',
        'sku_id', 'stock_item_id', 'qty',
        'inventory_movement_id', 'note',
    ];

    protected function casts(): array
    {
        return ['qty' => 'integer'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(OutboundOrder::class, 'outbound_order_id');
    }
    public function parentLine(): BelongsTo
    {
        return $this->belongsTo(OutboundOrderLine::class, 'parent_line_id');
    }
    public function childLines(): HasMany
    {
        return $this->hasMany(OutboundOrderLine::class, 'parent_line_id');
    }
    public function tenant(): BelongsTo { return $this->belongsTo(Tenant::class); }
    public function sku(): BelongsTo { return $this->belongsTo(Sku::class); }
    public function stockItem(): BelongsTo { return $this->belongsTo(StockItem::class); }
    public function inventoryMovement(): BelongsTo
    {
        return $this->belongsTo(InventoryMovement::class);
    }
}
```

---

## Livewire Components

### `app/Livewire/OutboundOrderCreate.php`

#### Wire properties

```php
#[Url(as: 'tenant_id', except: '')]
public string $tenantId = '';

#[Url(as: 'warehouse_id', except: '')]
public string $warehouseId = '';

public string $ref             = '';
public string $expectedShipAt  = '';
public string $note            = '';
public string $skuSearch       = '';

// Recipient
public string $recipientName         = '';
public string $recipientPhone        = '';
public string $recipientCountryCode  = '';
public string $recipientPostalCode   = '';
public string $recipientState        = '';
public string $recipientCity         = '';
public string $recipientAddressLine1 = '';
public string $recipientAddressLine2 = '';

// Shipping
public string $shippingMethod = '';

public array $lines = [
    ['sku_id' => '', 'qty' => '', 'note' => ''],
];
```

All Livewire wire properties must be declared as `string` type.
Do NOT use `int`, `int|string`, or union types for wire properties.

#### `mount()`

If `!isInternalUser() && $tenantId === ''`, auto-set `$tenantId` to first active tenant of auth user.
Only set if still empty (preserves URL pre-fills).

#### `updatedTenantId()`

Reset `$warehouseId = ''` and `$lines` to one empty row.

#### `addLine()` / `removeLine(int $index)`

`removeLine` does nothing if `count($this->lines) <= 1`.
Re-index after splice: `$this->lines = array_values($this->lines)`.

#### `save()`

```php
public function save()
{
    $tenantId = $this->validatedTenantId();
    $this->validateInput($tenantId);

    DB::transaction(function () use ($tenantId) {
        $order = OutboundOrder::create([
            'tenant_id'               => $tenantId,
            'warehouse_id'            => (int) $this->warehouseId,
            'ref'                     => $this->nullableString($this->ref),
            'status'                  => OutboundOrder::STATUS_PENDING,
            'expected_ship_at'        => $this->nullableString($this->expectedShipAt),
            'note'                    => $this->nullableString($this->note),
            'recipient_name'          => $this->nullableString($this->recipientName),
            'recipient_phone'         => $this->nullableString($this->recipientPhone),
            'recipient_country_code'  => $this->nullableString(strtoupper($this->recipientCountryCode)),
            'recipient_postal_code'   => $this->nullableString($this->recipientPostalCode),
            'recipient_state'         => $this->nullableString($this->recipientState),
            'recipient_city'          => $this->nullableString($this->recipientCity),
            'recipient_address_line1' => $this->nullableString($this->recipientAddressLine1),
            'recipient_address_line2' => $this->nullableString($this->recipientAddressLine2),
            'shipping_method'         => $this->nullableString($this->shippingMethod),
            'created_by_user_id'      => Auth::id(),
        ]);

        foreach ($this->lines as $index => $lineInput) {
            $sku = Sku::query()
                ->where('tenant_id', $tenantId)
                ->with('bundleComponents')
                ->findOrFail($lineInput['sku_id']);

            $userQty = (int) $lineInput['qty'];
            $lineNote = $this->nullableString($lineInput['note'] ?? '');

            if ($sku->sku_type === 'virtual_bundle') {
                $components = $sku->bundleComponents;

                if ($components->isEmpty()) {
                    throw ValidationException::withMessages([
                        "lines.{$index}.sku_id" => __('outbound.bundle_no_components'),
                    ]);
                }

                // Verify all bundle component definitions belong to the same tenant
                foreach ($components as $component) {
                    if ($component->tenant_id !== $tenantId) {
                        throw ValidationException::withMessages([
                            "lines.{$index}.sku_id" => __('outbound.bundle_invalid_tenant'),
                        ]);
                    }
                }

                // Verify the component stock items also belong to the same tenant
                $componentStockItemIds = $components->pluck('component_stock_item_id')->all();
                $invalidCount = StockItem::query()
                    ->whereIn('id', $componentStockItemIds)
                    ->where('tenant_id', '!=', $tenantId)
                    ->count();

                if ($invalidCount > 0) {
                    throw ValidationException::withMessages([
                        "lines.{$index}.sku_id" => __('outbound.bundle_invalid_tenant'),
                    ]);
                }

                // Parent line (display only, no stock_item_id)
                $parentLine = $order->lines()->create([
                    'tenant_id'    => $tenantId,
                    'sku_id'       => $sku->id,
                    'stock_item_id'=> null,
                    'qty'          => $userQty,
                    'note'         => $lineNote,
                ]);

                // Component child lines
                foreach ($components as $component) {
                    $componentQty = $userQty * $component->quantity;

                    try {
                        app(InventoryService::class)->reserveStock(
                            tenantId:    $tenantId,
                            warehouseId: (int) $this->warehouseId,
                            stockItemId: $component->component_stock_item_id,
                            quantity:    $componentQty,
                            context:     [
                                'ref_type' => 'outbound_order',
                                'ref_id'   => (string) $order->id,
                                'user_id'  => Auth::id(),
                            ],
                        );
                    } catch (InvalidArgumentException $e) {
                        throw ValidationException::withMessages([
                            "lines.{$index}.qty" => $e->getMessage(),
                        ]);
                    }

                    $order->lines()->create([
                        'parent_line_id' => $parentLine->id,
                        'tenant_id'      => $tenantId,
                        'sku_id'         => $sku->id,
                        'stock_item_id'  => $component->component_stock_item_id,
                        'qty'            => $componentQty,
                    ]);
                }
            } else {
                // single or physical_bundle
                if ($sku->stock_item_id === null) {
                    throw ValidationException::withMessages([
                        "lines.{$index}.sku_id" => __('outbound.sku_not_shippable'),
                    ]);
                }

                try {
                    app(InventoryService::class)->reserveStock(
                        tenantId:    $tenantId,
                        warehouseId: (int) $this->warehouseId,
                        stockItemId: $sku->stock_item_id,
                        quantity:    $userQty,
                        context:     [
                            'ref_type' => 'outbound_order',
                            'ref_id'   => (string) $order->id,
                            'user_id'  => Auth::id(),
                        ],
                    );
                } catch (InvalidArgumentException $e) {
                    throw ValidationException::withMessages([
                        "lines.{$index}.qty" => $e->getMessage(),
                    ]);
                }

                $order->lines()->create([
                    'tenant_id'     => $tenantId,
                    'sku_id'        => $sku->id,
                    'stock_item_id' => $sku->stock_item_id,
                    'qty'           => $userQty,
                    'note'          => $lineNote,
                ]);
            }
        }
    });

    session()->flash('status', __('outbound.order_created'));

    return redirect()->route('outbound.index');
}
```

The outer `DB::transaction()` wraps all service calls + model creates.
Do NOT add extra transactions around individual `reserveStock()` calls inside the loop.

#### `validateInput(int $tenantId)`

```php
private function validateInput(int $tenantId): void
{
    validator($this->formData(), [
        'tenant_id'              => ['required', 'integer'],
        'warehouse_id'           => ['required', 'integer', Rule::exists('warehouses', 'id')],
        'ref'                    => ['nullable', 'string', 'max:255'],
        'expected_ship_at'       => ['nullable', 'date'],
        'note'                   => ['nullable', 'string', 'max:1000'],
        'recipient_name'         => ['nullable', 'string', 'max:255'],
        'recipient_phone'        => ['nullable', 'string', 'max:50'],
        'recipient_country_code' => ['nullable', 'string', 'size:2'],
        'recipient_postal_code'  => ['nullable', 'string', 'max:20'],
        'recipient_state'        => ['nullable', 'string', 'max:100'],
        'recipient_city'         => ['nullable', 'string', 'max:100'],
        'recipient_address_line1'=> ['nullable', 'string', 'max:255'],
        'recipient_address_line2'=> ['nullable', 'string', 'max:255'],
        'shipping_method'        => ['nullable', 'string', 'max:100'],
        'lines'                  => [
            'required', 'array', 'min:1',
            function ($attribute, $value, $fail) {
                $ids = collect($value)->pluck('sku_id')->filter()->values();
                if ($ids->count() !== $ids->unique()->count()) {
                    $fail(__('outbound.duplicate_skus'));
                }
            },
        ],
        'lines.*.sku_id' => ['required', 'integer', Rule::exists('skus', 'id')->where('tenant_id', $tenantId)],
        'lines.*.qty'    => ['required', 'integer', 'min:1'],
        'lines.*.note'   => ['nullable', 'string', 'max:500'],
    ])->validate();
}
```

#### `render()`

```php
public function render()
{
    return view('livewire.outbound-order-create', [
        'tenants'         => $this->tenantOptions(),
        'warehouses'      => $this->warehouseOptions(),
        'skus'            => $this->skuOptions(),
        'showTenantSelect'=> $this->isInternalUser(),
        'currentTenant'   => $this->currentTenant(),
    ])->layout('inventory', [
        'title'    => __('outbound.create_page_title'),
        'subtitle' => __('outbound.create_page_subtitle'),
    ]);
}
```

SKU options include ALL sku_types (single, physical_bundle, virtual_bundle) -- do NOT filter out
virtual_bundle. Filter out only SKUs where `sku_type != 'virtual_bundle' AND stock_item_id IS NULL`
(i.e. non-bundle SKUs that have no stock item). The correct filter:

```php
->where(fn ($q) => $q
    ->where('sku_type', 'virtual_bundle')
    ->orWhereNotNull('stock_item_id')
)
```

#### Private helpers

Copy verbatim from InboundOrderCreate:
- `isInternalUser()` -- include `// TODO: remove unauthenticated fallback when auth is implemented`
- `allowedTenantIds()`, `validatedTenantId()`, `activeTenantIds()`, `nullableString()`
- `tenantOptions()`, `warehouseOptions()`, `currentTenant()`
- `formData()` -- must include all fields including recipient fields

---

### `app/Livewire/OutboundOrderIndex.php`

#### Wire properties

```php
#[Url(as: 'tenant_id', except: '')]
public string $tenantId = '';

#[Url(as: 'warehouse_id', except: '')]
public string $warehouseId = '';

#[Url(as: 'status', except: '')]
public string $statusFilter = '';
```

#### `visibleTenantIds()` memoization

Use identical pattern to InboundOrderIndex (two private properties:
`$visibleTenantIdsResolved` bool and `$visibleTenantIdsCache` array).

#### `cancel(int $orderId)`

```php
public function cancel(int $orderId): void
{
    $order = OutboundOrder::with('leafLines')
        ->whereIn('tenant_id', $this->visibleTenantIds())
        ->findOrFail($orderId);

    if ($order->status !== OutboundOrder::STATUS_PENDING) {
        return;
    }

    DB::transaction(function () use ($order) {
        foreach ($order->leafLines as $line) {
            app(InventoryService::class)->releaseReserve(
                tenantId:    $order->tenant_id,
                warehouseId: $order->warehouse_id,
                stockItemId: $line->stock_item_id,
                quantity:    $line->qty,
                context:     [
                    'ref_type' => 'outbound_order',
                    'ref_id'   => (string) $order->id,
                    'user_id'  => Auth::id(),
                ],
            );
        }

        $order->status               = OutboundOrder::STATUS_CANCELLED;
        $order->cancelled_at         = now();
        $order->cancelled_by_user_id = Auth::id();
        $order->save();
    });

    session()->flash('status', __('outbound.order_cancelled'));
}
```

Cancel uses `$order->leafLines` (whereNotNull stock_item_id) to call releaseReserve.
Do NOT call releaseReserve on virtual bundle parent lines (stock_item_id = null).

#### Status helpers

```php
public function statusLabel(string $status): string
{
    return match ($status) {
        OutboundOrder::STATUS_PENDING   => __('outbound.status_pending'),
        OutboundOrder::STATUS_SHIPPED   => __('outbound.status_shipped'),
        OutboundOrder::STATUS_CANCELLED => __('outbound.status_cancelled'),
        default                         => $status,
    };
}

public function statusColor(string $status): string
{
    return match ($status) {
        OutboundOrder::STATUS_PENDING   => 'warning',
        OutboundOrder::STATUS_SHIPPED   => 'success',
        OutboundOrder::STATUS_CANCELLED => 'danger',
        default                         => 'zinc',
    };
}
```

#### `render()`

```php
public function render()
{
    $orders = OutboundOrder::query()
        ->whereIn('tenant_id', $this->visibleTenantIds())
        ->when($this->tenantId !== '', fn ($q) => $q->where('tenant_id', (int) $this->tenantId))
        ->when($this->warehouseId !== '', fn ($q) => $q->where('warehouse_id', (int) $this->warehouseId))
        ->when($this->statusFilter !== '', fn ($q) => $q->where('status', $this->statusFilter))
        ->with([
            'tenant:id,code,name',
            'warehouse:id,code,name',
            'parentLines.sku:id,sku,sku_type',
        ])
        ->orderByDesc('created_at')
        ->paginate(30);

    return view('livewire.outbound-order-index', [
        'orders'     => $orders,
        'tenants'    => Tenant::query()
            ->whereIn('id', $this->visibleTenantIds())
            ->orderBy('name')
            ->get(['id', 'name']),
        'warehouses' => Warehouse::query()->orderBy('name')->get(['id', 'code', 'name']),
        'statuses'   => [
            OutboundOrder::STATUS_PENDING   => __('outbound.status_pending'),
            OutboundOrder::STATUS_SHIPPED   => __('outbound.status_shipped'),
            OutboundOrder::STATUS_CANCELLED => __('outbound.status_cancelled'),
        ],
    ])->layout('inventory', [
        'title'    => __('outbound.page_title'),
        'subtitle' => __('outbound.page_subtitle'),
    ]);
}
```

Index actions:
- `pending`: "Ship" link button navigating to `route('outbound.ship', $order)` with `wire:navigate`;
  "Cancel" button with `wire:confirm="{{ __('outbound.confirm_cancel') }}"` calling `wire:click="cancel({{ $order->id }})"`
- `shipped` or `cancelled`: no action buttons

---

### `app/Livewire/OutboundOrderShip.php`

#### Wire properties

```php
public int $orderId = 0;   // set once in mount(), read in save() and render()

public string $courier        = '';
public string $shippingMethod = '';
public string $trackingNo     = '';
public string $packageCount   = '';
public string $packageWeightG = '';
public string $shipNote       = '';
```

All wire properties must be `string` type. Exception: `$orderId` holds the route model PK (integer);
it is set once in `mount()` and never bound to a form field, so `int` is safe.

#### `mount(OutboundOrder $order)`

Route model binding via `{order}` parameter.

```php
public function mount(OutboundOrder $order): void
{
    if (! in_array($order->tenant_id, $this->visibleTenantIds(), true)) {
        abort(403);
    }

    $this->orderId = $order->id;

    if ($order->status !== OutboundOrder::STATUS_PENDING) {
        session()->flash('error', __('outbound.already_processed'));
        $this->redirectRoute('outbound.index', navigate: true);
        return;
    }

    // Pre-fill shipping method if set at create time
    $this->shippingMethod = $order->shipping_method ?? '';
}
```

#### `save()`

```php
public function save()
{
    $order = OutboundOrder::whereIn('tenant_id', $this->visibleTenantIds())
        ->findOrFail($this->orderId);

    if ($order->status !== OutboundOrder::STATUS_PENDING) {
        session()->flash('error', __('outbound.already_processed'));
        return redirect()->route('outbound.index');
    }

    $this->validateInput();

    try {
        DB::transaction(function () use ($order) {
            $order->load('leafLines');

            foreach ($order->leafLines as $line) {
                $movement = app(InventoryService::class)->shipReservedStock(
                    tenantId:    $order->tenant_id,
                    warehouseId: $order->warehouse_id,
                    stockItemId: $line->stock_item_id,
                    quantity:    $line->qty,
                    context:     [
                        'ref_type' => 'outbound_order',
                        'ref_id'   => (string) $order->id,
                        'user_id'  => Auth::id(),
                    ],
                );

                $line->inventory_movement_id = $movement->id;
                $line->save();
            }

            $order->status             = OutboundOrder::STATUS_SHIPPED;
            $order->shipped_at         = now();
            $order->shipped_by_user_id = Auth::id();
            $order->shipping_method    = $this->nullableString($this->shippingMethod);
            $order->courier            = $this->nullableString($this->courier);
            $order->tracking_no        = $this->nullableString($this->trackingNo);
            $order->package_count      = $this->packageCount !== '' ? (int) $this->packageCount : null;
            $order->package_weight_g   = $this->packageWeightG !== '' ? (int) $this->packageWeightG : null;
            $order->ship_note          = $this->nullableString($this->shipNote);
            $order->save();
        });
    } catch (InvalidArgumentException $e) {
        session()->flash('error', $e->getMessage());
        return;
    }

    session()->flash('status', __('outbound.order_shipped'));

    return redirect()->route('outbound.index');
}
```

#### `validateInput()`

```php
private function validateInput(): void
{
    validator([
        'courier'        => $this->courier,
        'shipping_method'=> $this->shippingMethod,
        'tracking_no'    => $this->trackingNo,
        'package_count'  => $this->packageCount,
        'package_weight_g'=> $this->packageWeightG,
        'ship_note'      => $this->shipNote,
    ], [
        'courier'         => ['nullable', 'string', 'max:100'],
        'shipping_method' => ['nullable', 'string', 'max:100'],
        'tracking_no'     => ['nullable', 'string', 'max:255'],
        'package_count'   => ['nullable', 'integer', 'min:1'],
        'package_weight_g'=> ['nullable', 'integer', 'min:1'],
        'ship_note'       => ['nullable', 'string', 'max:1000'],
    ])->validate();
}
```

#### `render()`

```php
public function render()
{
    $order = OutboundOrder::whereIn('tenant_id', $this->visibleTenantIds())
        ->with([
            'tenant:id,code,name',
            'warehouse:id,code,name',
            'parentLines.sku:id,sku,sku_type,name',
            'parentLines.childLines.stockItem:id,code,name',
            'parentLines.stockItem:id,code,name',
        ])
        ->findOrFail($this->orderId);

    return view('livewire.outbound-order-ship', [
        'order' => $order,
    ])->layout('inventory', [
        'title'    => __('outbound.ship_page_title'),
        'subtitle' => __('outbound.ship_page_subtitle'),
    ]);
}
```

#### Private helpers

```php
// TODO: remove unauthenticated fallback when auth is implemented
private function isInternalUser(): bool
{
    $user = Auth::user();
    return ! $user || $user->user_type === 'internal';
}

private function visibleTenantIds(): array
{
    // Same memoized pattern as OutboundOrderIndex
}

private function nullableString(?string $value): ?string
{
    $value = trim((string) $value);
    return $value === '' ? null : $value;
}
```

---

## Routes

Add to `routes/web.php`:

```php
use App\Livewire\OutboundOrderCreate;
use App\Livewire\OutboundOrderIndex;
use App\Livewire\OutboundOrderShip;

Route::get('/outbound', OutboundOrderIndex::class)->name('outbound.index');
Route::get('/outbound/create', OutboundOrderCreate::class)->name('outbound.create');
Route::get('/outbound/{order}/ship', OutboundOrderShip::class)->name('outbound.ship');
```

---

## Navigation

In `resources/views/components/layout/navigation.blade.php`, add "Outbound" as a top-level link:

```blade
<a
    href="{{ route('outbound.index') }}"
    class="top-nav-btn {{ request()->routeIs('outbound.*') ? 'is-active' : '' }}"
    wire:navigate
>
    {{ __('common.nav_outbound') }}
</a>
```

Add to all four lang files (`lang/en/common.php`, `lang/ja/common.php`, `lang/zh_TW/common.php`,
`lang/zh_CN/common.php`) after `'nav_inbound'`:

```php
'nav_outbound' => 'Outbound',
```

---

## Lang: `lang/en/outbound.php`

```php
<?php

return [
    'page_title'             => 'Outbound Orders',
    'page_subtitle'          => 'Pending orders have stock reserved. Ship to confirm dispatch.',
    'create_page_title'      => 'Create Outbound Order',
    'create_page_subtitle'   => 'Reserve stock for a planned shipment. Stock is reserved immediately on submission.',
    'ship_page_title'        => 'Ship Order',
    'ship_page_subtitle'     => 'Confirm shipment details. Stock will be deducted on submit.',
    'section_order'          => 'Order Details',
    'section_order_hint'     => 'Choose the tenant, warehouse, and optional planned ship date.',
    'section_recipient'      => 'Recipient',
    'section_recipient_hint' => 'Optional delivery address. Can be added now or later.',
    'section_lines'          => 'Items',
    'section_lines_hint'     => 'Add one line per SKU. All SKU types are supported. Stock is reserved on submission.',
    'section_shipment'       => 'Shipment Details',
    'section_shipment_hint'  => 'Courier, tracking, and package information.',
    'section_order_summary'  => 'Order Summary',
    'field_tenant'           => 'Tenant',
    'field_warehouse'        => 'Warehouse',
    'field_ref'              => 'Reference',
    'field_ref_hint'         => 'Optional order or customer reference number.',
    'field_expected_ship_at' => 'Expected ship date',
    'field_note'             => 'Note',
    'field_sku'              => 'SKU',
    'field_qty'              => 'Quantity',
    'field_line_note'        => 'Line note',
    'field_recipient_name'   => 'Recipient name',
    'field_recipient_phone'  => 'Recipient phone',
    'field_country_code'     => 'Country code',
    'field_postal_code'      => 'Postal code',
    'field_state'            => 'State / Prefecture',
    'field_city'             => 'City',
    'field_address_line1'    => 'Address line 1',
    'field_address_line2'    => 'Address line 2',
    'field_shipping_method'  => 'Shipping method',
    'field_courier'          => 'Courier',
    'field_tracking_no'      => 'Tracking number',
    'field_package_count'    => 'Number of packages',
    'field_package_weight_g' => 'Total weight (g)',
    'field_ship_note'        => 'Ship note',
    'btn_create'             => 'Create outbound order',
    'btn_back'               => 'Back to outbound orders',
    'btn_cancel'             => 'Cancel',
    'btn_submit'             => 'Create outbound order',
    'btn_ship'               => 'Ship',
    'btn_submit_ship'        => 'Confirm shipment',
    'btn_cancel_order'       => 'Cancel order',
    'btn_add_line'           => 'Add line',
    'btn_remove_line'        => 'Remove',
    'order_created'          => 'Outbound order created. Stock has been reserved.',
    'order_shipped'          => 'Order shipped. Stock has been deducted.',
    'order_cancelled'        => 'Outbound order cancelled. Reserved stock has been returned.',
    'already_processed'      => 'This order has already been shipped or cancelled.',
    'confirm_cancel'         => 'Cancel this outbound order? Reserved stock will be returned to available.',
    'duplicate_skus'         => 'Each SKU may only appear once per outbound order.',
    'sku_not_shippable'      => 'This SKU has no stock item linked.',
    'bundle_no_components'   => 'This virtual bundle has no components configured and cannot be shipped.',
    'bundle_invalid_tenant'  => 'One or more bundle components do not belong to the selected tenant.',
    'col_ref'                => 'Ref',
    'col_tenant_warehouse'   => 'Tenant / Warehouse',
    'col_expected_ship_at'   => 'Expected ship',
    'col_lines'              => 'Lines',
    'col_status'             => 'Status',
    'col_actions'            => 'Actions',
    'status_pending'         => 'Pending (reserved)',
    'status_shipped'         => 'Shipped',
    'status_cancelled'       => 'Cancelled',
    'empty_state'            => 'No outbound orders match the current filters.',
    'all_statuses'           => 'All statuses',
    'select_tenant'          => 'Select tenant',
    'no_active_tenant'       => 'No active tenant',
    'search_skus_label'      => 'Search SKUs',
    'select_sku'             => 'Select SKU',
    'bundle_components_label'=> 'Components',
];
```

Also create `lang/ja/outbound.php`, `lang/zh_TW/outbound.php`, `lang/zh_CN/outbound.php`
with identical content and a `// TODO: translate this file. English values are placeholders.` comment.

---

## Blade Views

### `resources/views/livewire/outbound-order-create.blade.php`

Two-tab or two-panel layout:

**Panel 1 -- Order details**: tenant (if internal), warehouse, ref, expected_ship_at, note, shipping_method

**Panel 2 -- Recipient** (collapsible or separate section): recipient_name, recipient_phone,
recipient_country_code, recipient_postal_code, recipient_state, recipient_city,
recipient_address_line1, recipient_address_line2

**Panel 3 -- Lines**: dynamic list, each line shows SKU select (with skuSearch text input filtering
`$skus`), qty input, note input, remove button. "Add line" button below.
The SKU select must include virtual bundles -- do NOT filter them out.

**Sticky footer**: back link to `route('outbound.index')`, submit button.

Show validation errors with `<p class="form-error">` below each field.
Show `session('status')` flash if present.

### `resources/views/livewire/outbound-order-index.blade.php`

1. Flash messages (session 'status' and session 'error') at top
2. "Create outbound order" link button with `wire:navigate`
3. Toolbar: tenant filter, warehouse filter, status filter
4. Table: Ref, Tenant / Warehouse, Expected ship, Lines (SKU codes, show virtual bundle parent
   lines only), Status badge, Actions
5. Status label for pending should show "Pending (reserved)" to clarify stock is held
6. Actions for `pending`: "Ship" link with `wire:navigate` to `route('outbound.ship', $order->id)`;
   "Cancel" button with `wire:confirm="{{ __('outbound.confirm_cancel') }}"` and `wire:click="cancel({{ $order->id }})"`
7. No actions for `shipped` or `cancelled`
8. Empty state, pagination

### `resources/views/livewire/outbound-order-ship.blade.php`

1. Flash messages at top (session 'error')
2. Order summary section: show tenant, warehouse, ref, recipient name/address, lines with
   quantities (group component lines under their bundle parent)
3. Shipment details form: shipping_method, courier, tracking_no, package_count, package_weight_g, ship_note
4. Sticky footer: back link to `route('outbound.index')`, "Confirm shipment" submit button
5. Validation errors below each field

---

## Tests: `tests/Feature/OutboundOrderTest.php`

Use `RefreshDatabase`, `Livewire::actingAs()`. Helper method `skuWithStock()` should first create
Tenant, Warehouse, StockItem, Shop, and Sku, then call `app(InventoryService::class)->adjustStock()`
to seed the on_hand balance, and return the created models.

```
test_create_single_sku_order_reserves_stock()
```
- Seed on_hand = 20, create order qty = 5
- Assert `STATUS_PENDING`, `InventoryBalance::reserved_qty = 5`, `available_qty = 15`
- Assert 1 reserve movement (movement_type = TYPE_RESERVE)

```
test_create_virtual_bundle_order_reserves_components()
```
- Create virtual bundle SKU with 2 components (StockItem A qty=1, StockItem B qty=2)
- Seed StockItem A on_hand = 10, StockItem B on_hand = 20
- Create outbound order with virtual bundle qty = 3
- Assert parent line created (stock_item_id = null)
- Assert 2 child lines: StockItem A qty = 3, StockItem B qty = 6
- Assert InventoryBalance for A: reserved = 3; for B: reserved = 6

```
test_create_fails_for_bundle_with_no_components()
```
- Virtual bundle SKU with no SkuBundleComponents
- Assert `assertHasErrors(['lines.0.sku_id'])`

```
test_create_fails_when_insufficient_stock()
```
- on_hand = 3, try to reserve qty = 10
- Assert `assertHasErrors(['lines.0.qty'])`
- Assert no OutboundOrder created

```
test_create_rejects_duplicate_skus()
```
- Two lines with same sku_id
- Assert `assertHasErrors(['lines'])`

```
test_ship_deducts_reserved_stock()
```
- Seed on_hand = 10, create order qty = 4 (available becomes 6, reserved = 4)
- Submit ship form with courier = 'Yamato', tracking_no = 'YM123'
- Assert redirect to `outbound.index`
- Assert `STATUS_SHIPPED`, `shipped_at` not null, `courier = 'Yamato'`, `tracking_no = 'YM123'`
- Assert InventoryBalance: `on_hand = 6`, `reserved = 0`, `available = 6`
- Assert leaf line `inventory_movement_id` is not null
- Assert movement has `movement_type = TYPE_SHIP`

```
test_ship_virtual_bundle_deducts_all_components()
```
- Virtual bundle with 2 components, qty = 2
- Reserve at create: A qty=2, B qty=4
- Ship the order
- Assert InventoryBalance for A: on_hand decreased by 2, reserved = 0
- Assert InventoryBalance for B: on_hand decreased by 4, reserved = 0

```
test_cancel_releases_reserved_stock()
```
- Seed on_hand = 10, create order qty = 4
- Call `cancel()` from OutboundOrderIndex
- Assert `STATUS_CANCELLED`
- Assert InventoryBalance: `reserved = 0`, `available = 10` (fully restored)

```
test_cancel_is_blocked_for_shipped_order()
```
- Ship an order (status = shipped), call `cancel()`, assert status still `shipped`

```
test_ship_page_is_blocked_for_non_pending_order()
```
- Cancelled order, visit ship page
- Assert redirect to `outbound.index` (not a 200 with form)

```
test_tenant_user_only_sees_own_outbound_orders()
```
- Two tenants, tenant user sees own ref, not other tenant's

```
test_outbound_routes_render()
```
- GET /outbound, /outbound/create both return 200
- GET /outbound/{pending_order}/ship returns 200

---

## Implementation rules

- Migration sequential suffixes: 000017 for outbound_orders, 000018 for outbound_order_lines
- All wire properties must be `string` type (no int, no union types)
- All service calls use `app(InventoryService::class)` not `new InventoryService()`
- Only leaf lines (stock_item_id not null) are passed to InventoryService
- Never call service on virtual bundle parent lines (stock_item_id = null)
- `Sku::bundleComponents()` already exists in the Sku model as
  `hasMany(SkuBundleComponent::class, 'bundle_sku_id')`. Do NOT redefine it.
  Each `SkuBundleComponent` has `bundle_sku_id`, `component_stock_item_id`, `quantity`, `tenant_id`.
- SKU filter in skuOptions() must include virtual bundles -- filter is:
  `where('sku_type', 'virtual_bundle') OR whereNotNull('stock_item_id')`
- Outer `DB::transaction()` covers entire create/ship/cancel loop; no nested transactions
- ASCII punctuation only -- no Unicode dashes, curly quotes, ellipsis, or garbled multi-byte chars
- Copy `isInternalUser()` with `// TODO: remove unauthenticated fallback when auth is implemented`
- `package_count` and `package_weight_g` are set to null when the input string is empty
- `recipient_country_code` is uppercased with `strtoupper()` before saving
- Courier, tracking, and package fields are stored at order level for MVP.
  Multi-package tracking (individual tracking numbers per package) requires a separate
  `outbound_packages` table -- that is out of scope for v1.
