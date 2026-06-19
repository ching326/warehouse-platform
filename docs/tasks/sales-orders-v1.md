# Task: Sales Orders v1

## Stack

Laravel 13, Livewire 4 (class-based components, not Volt), Flux UI (`livewire/flux ^2.14`),
SQLite (dev), PHP 8.3. Plain Blade views only -- no TypeScript.

---

## What this task covers

Three pages and three Livewire components:

- `GET /sales-orders`           -- SalesOrderIndex: list, filter, search
- `GET /sales-orders/create`    -- SalesOrderCreate: manual create with lines
- `GET /sales-orders/{order}`   -- SalesOrderDetail: view, cancel, edit recipient, related orders

**Two new migrations** for `sales_orders` and `sales_order_lines`.
**No delete.** Cancel only. Cancelled orders remain for audit.
**Tenant-scoped.** All queries use `allowedTenantIds()` (same pattern as OutboundOrderCreate).

Phase 1 scope:
- Manual create only (source = 'manual'); CSV/API import is Phase 7.
- Lines require `sku_id`; all lines created with `line_status = 'ready'`.
- `ship_together_key` computed automatically on save via `SalesOrderObserver`.
- Detail page shows related orders (same key, ready to fulfill) as a label -- no fulfill action yet (Phase 2).
- No fulfillment group interaction in this phase.

---

## New migrations

### Migration 1: `xxxx_xx_xx_create_sales_orders_table.php`

```php
Schema::create('sales_orders', function (Blueprint $table) {
    $table->id();
    $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
    $table->foreignId('shop_id')->constrained('shops')->restrictOnDelete();
    $table->string('source')->default('manual');        // manual | csv | api
    $table->string('platform_order_id')->nullable();   // external order ID (optional for manual)
    $table->string('order_status')->default('pending'); // pending | on_hold | backorder | cancelled | completed
    $table->string('fulfillment_status')->default('unfulfilled'); // unfulfilled | ready | in_group | shipped | cancelled
    $table->string('recipient_name')->nullable();
    $table->string('recipient_phone')->nullable();
    $table->string('recipient_country_code', 2)->nullable();
    $table->string('recipient_postal_code')->nullable();
    $table->string('recipient_state')->nullable();
    $table->string('recipient_city')->nullable();
    $table->string('recipient_address_line1')->nullable();
    $table->string('recipient_address_line2')->nullable();
    $table->string('ship_together_key')->nullable();    // md5 hash of normalized recipient fields
    $table->text('note')->nullable();
    $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
    $table->timestamps();

    $table->index(['tenant_id', 'fulfillment_status']);
    $table->index(['tenant_id', 'order_status']);
    $table->index(['shop_id']);
    $table->index(['ship_together_key']);               // for same-address detection
    $table->index(['tenant_id', 'shop_id', 'platform_order_id']); // for dedup
});
```

### Migration 2: `xxxx_xx_xx_create_sales_order_lines_table.php`

```php
Schema::create('sales_order_lines', function (Blueprint $table) {
    $table->id();
    $table->foreignId('sales_order_id')->constrained()->cascadeOnDelete();
    $table->foreignId('sku_id')->constrained('skus')->restrictOnDelete();
    $table->unsignedInteger('quantity');
    $table->decimal('unit_price', 10, 2)->nullable();  // optional; used by CSV/API import
    $table->string('currency', 3)->nullable();          // ISO code, e.g. JPY; used by CSV/API import
    $table->string('line_status')->default('ready');    // ready | cancelled
    $table->string('note')->nullable();
    $table->timestamps();

    $table->index(['sales_order_id']);
    $table->index(['sku_id']);
});
```

`fulfillment_group_lines` is deferred until partial fulfillment or materialized pick summaries are
needed (Phase 3+). Phase 2 computes combined line summaries on the fly via
`fulfillment_group_orders -> sales_orders -> sales_order_lines` joins.

---

## Models

### `app/Models/SalesOrder.php`

```php
class SalesOrder extends Model
{
    use HasFactory, LogsActivity;

    public const ORDER_STATUS_PENDING   = 'pending';
    public const ORDER_STATUS_ON_HOLD   = 'on_hold';
    public const ORDER_STATUS_BACKORDER = 'backorder';
    public const ORDER_STATUS_CANCELLED = 'cancelled';
    public const ORDER_STATUS_COMPLETED = 'completed';

    public const FULFILLMENT_STATUS_UNFULFILLED = 'unfulfilled';
    public const FULFILLMENT_STATUS_READY       = 'ready';
    public const FULFILLMENT_STATUS_IN_GROUP    = 'in_group';
    public const FULFILLMENT_STATUS_SHIPPED     = 'shipped';
    public const FULFILLMENT_STATUS_CANCELLED   = 'cancelled';

    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_CSV    = 'csv';
    public const SOURCE_API    = 'api';

    protected $fillable = [
        'tenant_id', 'shop_id', 'source', 'platform_order_id',
        'order_status', 'fulfillment_status',
        'recipient_name', 'recipient_phone', 'recipient_country_code',
        'recipient_postal_code', 'recipient_state', 'recipient_city',
        'recipient_address_line1', 'recipient_address_line2',
        'ship_together_key', 'note', 'created_by_user_id',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('sales_order')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function tenant(): BelongsTo   { return $this->belongsTo(Tenant::class); }
    public function shop(): BelongsTo     { return $this->belongsTo(Shop::class); }
    public function createdBy(): BelongsTo { return $this->belongsTo(User::class, 'created_by_user_id'); }
    public function lines(): HasMany      { return $this->hasMany(SalesOrderLine::class)->orderBy('id'); }

    /**
     * Computes and sets ship_together_key on this model instance.
     * Call before save(). Observer calls this automatically via saving().
     * Key is null if recipient_address_line1 is blank (no address yet).
     */
    public function recalculateShipTogetherKey(): void
    {
        if (empty(trim((string) $this->recipient_address_line1))) {
            $this->ship_together_key = null;
            return;
        }

        $this->ship_together_key = md5(implode('|', [
            $this->tenant_id,
            $this->shop_id,
            strtolower(trim((string) $this->recipient_name)),
            strtolower(trim((string) $this->recipient_country_code)),
            strtolower(trim((string) $this->recipient_postal_code)),
            strtolower(trim((string) $this->recipient_state)),
            strtolower(trim((string) $this->recipient_city)),
            strtolower(trim((string) $this->recipient_address_line1)),
            strtolower(trim((string) $this->recipient_address_line2)),
        ]));
    }
}
```

### `app/Models/SalesOrderLine.php`

```php
class SalesOrderLine extends Model
{
    use HasFactory;

    public const STATUS_READY     = 'ready';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'sales_order_id', 'sku_id', 'quantity',
        'unit_price', 'currency', 'line_status', 'note',
    ];

    protected function casts(): array
    {
        return [
            'quantity'   => 'integer',
            'unit_price' => 'decimal:2',
        ];
    }

    public function salesOrder(): BelongsTo { return $this->belongsTo(SalesOrder::class); }
    public function sku(): BelongsTo        { return $this->belongsTo(Sku::class); }
}
```

### `app/Observers/SalesOrderObserver.php`

```php
class SalesOrderObserver
{
    public function saving(SalesOrder $order): void
    {
        $recipientFields = [
            'shop_id',
            'recipient_name', 'recipient_country_code', 'recipient_postal_code',
            'recipient_state', 'recipient_city',
            'recipient_address_line1', 'recipient_address_line2',
        ];

        // $order->exists is false for new models not yet persisted
        if (! $order->exists || $order->isDirty($recipientFields)) {
            $order->recalculateShipTogetherKey();
        }
    }
}
```

Register the observer in `app/Providers/AppServiceProvider.php`:

```php
public function boot(): void
{
    SalesOrder::observe(SalesOrderObserver::class);
    // ... other observers
}
```

---

## Livewire Components

### `app/Livewire/SalesOrderIndex.php`

Use the `WithPagination` trait.

Add `updated*()` hooks:

```php
public function updatedShopId(): void            { $this->resetPage(); }
public function updatedFulfillmentStatus(): void { $this->resetPage(); }
public function updatedOrderStatus(): void       { $this->resetPage(); }
public function updatedSearch(): void            { $this->resetPage(); }
```

#### Wire properties

```php
#[Url(as: 'shop', except: '')]
public string $shopId = '';

#[Url(as: 'fulfillment', except: '')]
public string $fulfillmentStatus = '';

#[Url(as: 'order_status', except: '')]
public string $orderStatus = '';

#[Url(as: 'q', except: '')]
public string $search = '';
```

#### `render()`

```php
public function render()
{
    $orders = SalesOrder::query()
        ->with(['shop.tenant'])
        ->whereIn('tenant_id', $this->allowedTenantIds())
        ->when($this->shopId !== '', fn ($q) => $q->where('shop_id', $this->shopId))
        ->when($this->fulfillmentStatus !== '', fn ($q) => $q->where('fulfillment_status', $this->fulfillmentStatus))
        ->when($this->orderStatus !== '', fn ($q) => $q->where('order_status', $this->orderStatus))
        ->when($this->search !== '', function ($q) {
            $like = '%' . $this->search . '%';
            $q->where(fn ($q) => $q
                ->where('platform_order_id', 'like', $like)
                ->orWhere('recipient_name', 'like', $like)
            );
        })
        ->orderByDesc('created_at')
        ->paginate(30);

    return view('livewire.sales-order-index', [
        'orders'             => $orders,
        'shops'              => $this->shopOptions(),
        'fulfillmentStatuses'=> $this->fulfillmentStatuses(),
        'orderStatuses'      => $this->orderStatuses(),
    ])->layout('inventory', [
        'title'    => __('sales_orders.index_page_title'),
        'subtitle' => __('sales_orders.index_page_subtitle'),
    ]);
}
```

#### Private helpers

```php
private function shopOptions(): Collection
{
    return Shop::whereIn('tenant_id', $this->allowedTenantIds())
        ->where('status', 'active')
        ->with('tenant:id,code')
        ->orderBy('name')
        ->get(['id', 'tenant_id', 'name', 'platform']);
}

private function fulfillmentStatuses(): array
{
    return [
        SalesOrder::FULFILLMENT_STATUS_UNFULFILLED => __('sales_orders.fulfillment_unfulfilled'),
        SalesOrder::FULFILLMENT_STATUS_READY       => __('sales_orders.fulfillment_ready'),
        SalesOrder::FULFILLMENT_STATUS_IN_GROUP    => __('sales_orders.fulfillment_in_group'),
        SalesOrder::FULFILLMENT_STATUS_SHIPPED     => __('sales_orders.fulfillment_shipped'),
        SalesOrder::FULFILLMENT_STATUS_CANCELLED   => __('sales_orders.fulfillment_cancelled'),
    ];
}

private function orderStatuses(): array
{
    return [
        SalesOrder::ORDER_STATUS_PENDING   => __('sales_orders.order_pending'),
        SalesOrder::ORDER_STATUS_ON_HOLD   => __('sales_orders.order_on_hold'),
        SalesOrder::ORDER_STATUS_BACKORDER => __('sales_orders.order_backorder'),
        SalesOrder::ORDER_STATUS_CANCELLED => __('sales_orders.order_cancelled'),
        SalesOrder::ORDER_STATUS_COMPLETED => __('sales_orders.order_completed'),
    ];
}

// TODO: remove unauthenticated fallback when auth is implemented
private function isInternalUser(): bool
{
    $user = Auth::user();
    return ! $user || $user->user_type === 'internal';
}

private function allowedTenantIds(): array
{
    if ($this->isInternalUser()) {
        return Tenant::pluck('id')->all();
    }
    return Auth::user()->tenantUsers()->where('status', 'active')->pluck('tenant_id')->all();
}
```

---

### `app/Livewire/SalesOrderCreate.php`

#### Wire properties

```php
#[Url(as: 'shop_id', except: '')]
public string $shopId = '';

public string $platformOrderId = '';
public string $note            = '';

// Recipient
public string $recipientName        = '';
public string $recipientPhone       = '';
public string $recipientCountryCode = '';
public string $recipientPostalCode  = '';
public string $recipientState       = '';
public string $recipientCity        = '';
public string $recipientAddressLine1 = '';
public string $recipientAddressLine2 = '';

public array $lines = [
    ['sku_id' => '', 'quantity' => '', 'note' => ''],
];
```

All scalar wire properties must be `string` type.

#### `mount()`

Guard tenant access. Internal users can access all tenants. Tenant users need at least one active
tenant link, otherwise return 403.

#### `updatedShopId()`

```php
public function updatedShopId(): void
{
    $this->lines = [['sku_id' => '', 'quantity' => '', 'note' => '']];
}
```

#### `addLine()` / `removeLine(int $index)`

```php
public function addLine(): void
{
    $this->lines[] = ['sku_id' => '', 'quantity' => '', 'note' => ''];
}

public function removeLine(int $index): void
{
    if (count($this->lines) <= 1) {
        return;
    }
    array_splice($this->lines, $index, 1);
    $this->lines = array_values($this->lines);
}
```

#### `save()`

```php
public function save()
{
    $shop = $this->validatedShop();

    $this->validateInput($shop);

    $order = SalesOrder::create([
        'tenant_id'          => $shop->tenant_id,
        'shop_id'            => $shop->id,
        'source'             => SalesOrder::SOURCE_MANUAL,
        'platform_order_id'  => $this->nullableString($this->platformOrderId),
        'order_status'       => SalesOrder::ORDER_STATUS_PENDING,
        'fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_UNFULFILLED,
        'recipient_name'         => $this->nullableString($this->recipientName),
        'recipient_phone'        => $this->nullableString($this->recipientPhone),
        'recipient_country_code' => $this->nullableString(strtoupper($this->recipientCountryCode)),
        'recipient_postal_code'  => $this->nullableString($this->recipientPostalCode),
        'recipient_state'        => $this->nullableString($this->recipientState),
        'recipient_city'         => $this->nullableString($this->recipientCity),
        'recipient_address_line1'=> $this->nullableString($this->recipientAddressLine1),
        'recipient_address_line2'=> $this->nullableString($this->recipientAddressLine2),
        'note'               => $this->nullableString($this->note),
        'created_by_user_id' => Auth::id(),
        // ship_together_key is set by SalesOrderObserver::saving()
    ]);

    foreach ($this->lines as $lineInput) {
        $order->lines()->create([
            'sku_id'      => (int) $lineInput['sku_id'],
            'quantity'    => (int) $lineInput['quantity'],
            'line_status' => SalesOrderLine::STATUS_READY,
            'note'        => $this->nullableString($lineInput['note'] ?? ''),
        ]);
    }

    session()->flash('status', __('sales_orders.order_created'));

    return redirect()->route('sales.orders.show', $order);
}
```

#### `validateInput(Shop $shop)`

```php
private function validateInput(Shop $shop): void
{
    validator([
        'platform_order_id'      => $this->platformOrderId,
        'note'                   => $this->note,
        'recipient_name'         => $this->recipientName,
        'recipient_phone'        => $this->recipientPhone,
        'recipient_country_code' => $this->recipientCountryCode,
        'recipient_postal_code'  => $this->recipientPostalCode,
        'recipient_state'        => $this->recipientState,
        'recipient_city'         => $this->recipientCity,
        'recipient_address_line1'=> $this->recipientAddressLine1,
        'recipient_address_line2'=> $this->recipientAddressLine2,
        'lines'                  => $this->lines,
    ], [
        'platform_order_id'      => [
            'nullable', 'string', 'max:100',
            Rule::unique('sales_orders', 'platform_order_id')
                ->where('tenant_id', $shop->tenant_id)
                ->where('shop_id', $shop->id),
        ],
        'note'                   => ['nullable', 'string', 'max:2000'],
        'recipient_name'         => ['nullable', 'string', 'max:255'],
        'recipient_phone'        => ['nullable', 'string', 'max:50'],
        'recipient_country_code' => ['nullable', 'string', 'regex:/^[A-Z]{2}$/'],
        'recipient_postal_code'  => ['nullable', 'string', 'max:20'],
        'recipient_state'        => ['nullable', 'string', 'max:100'],
        'recipient_city'         => ['nullable', 'string', 'max:100'],
        'recipient_address_line1'=> ['nullable', 'string', 'max:255'],
        'recipient_address_line2'=> ['nullable', 'string', 'max:255'],
        'lines'                  => ['required', 'array', 'min:1'],
        'lines.*.sku_id'         => [
            'required', 'integer',
            Rule::exists('skus', 'id')->where('tenant_id', $shop->tenant_id),
        ],
        'lines.*.quantity'       => ['required', 'integer', 'min:1'],
        'lines.*.note'           => ['nullable', 'string', 'max:500'],
    ])->validate();
}
```

`recipient_country_code` uses `'regex:/^[A-Z]{2}$/'` (same reason as WarehouseCreate: rejects
digits). `save()` normalizes it to uppercase before reaching validation.

`platform_order_id` uniqueness is scoped to `(tenant_id, shop_id)`. For manual orders with no
platform_order_id (null), the rule does not fire because of `nullable`.

#### `render()`

```php
public function render()
{
    return view('livewire.sales-order-create', [
        'shops'           => $this->shopOptions(),
        'skus'            => $this->skuOptions(),
        'showShopSelect'  => true,
    ])->layout('inventory', [
        'title'    => __('sales_orders.create_page_title'),
        'subtitle' => __('sales_orders.create_page_subtitle'),
    ]);
}
```

#### Private helpers

```php
private function validatedShop(): Shop
{
    if ($this->shopId === '') {
        throw ValidationException::withMessages(['shopId' => __('sales_orders.shop_required')]);
    }

    $shop = Shop::where('status', 'active')
        ->whereIn('tenant_id', $this->allowedTenantIds())
        ->find((int) $this->shopId);

    if (! $shop) {
        throw ValidationException::withMessages(['shopId' => __('sales_orders.invalid_shop')]);
    }

    return $shop;
}

private function shopOptions(): Collection
{
    return Shop::whereIn('tenant_id', $this->allowedTenantIds())
        ->where('status', 'active')
        ->with('tenant:id,code')
        ->orderBy('name')
        ->get(['id', 'tenant_id', 'platform', 'marketplace', 'code', 'name']);
}

private function skuOptions(): Collection
{
    if ($this->shopId === '') {
        return collect();
    }

    $shop = Shop::whereIn('tenant_id', $this->allowedTenantIds())
        ->where('status', 'active')
        ->find((int) $this->shopId);

    if (! $shop) {
        return collect();
    }


    return Sku::where('tenant_id', $shop->tenant_id)
        ->where(fn ($q) => $q
            ->where('sku_type', 'virtual_bundle')
            ->orWhereNotNull('stock_item_id')
        )
        ->with('stockItem:id,code,name')
        ->orderBy('sku')
        ->get(['id', 'tenant_id', 'sku', 'name', 'stock_item_id', 'sku_type']);
}

// TODO: remove unauthenticated fallback when auth is implemented
private function isInternalUser(): bool
{
    $user = Auth::user();
    return ! $user || $user->user_type === 'internal';
}

private function allowedTenantIds(): array
{
    if ($this->isInternalUser()) {
        return Tenant::pluck('id')->all();
    }
    return Auth::user()->tenantUsers()->where('status', 'active')->pluck('tenant_id')->all();
}

private function nullableString(?string $value): ?string
{
    $value = trim((string) $value);
    return $value === '' ? null : $value;
}
```

---

### `app/Livewire/SalesOrderDetail.php`

#### Wire properties

```php
public int $orderId = 0;  // set once in mount(); never bound to form

// Recipient editing
public bool $editingRecipient        = false;
public string $editRecipientName        = '';
public string $editRecipientPhone       = '';
public string $editRecipientCountryCode = '';
public string $editRecipientPostalCode  = '';
public string $editRecipientState       = '';
public string $editRecipientCity        = '';
public string $editRecipientAddressLine1 = '';
public string $editRecipientAddressLine2 = '';
```

`orderId` is `int` (not string) -- set once in `mount()`, read in `render()` and action methods.
All recipient edit properties are `string` for Livewire 4 hydration compatibility.

#### `mount(SalesOrder $order)`

Route model binding in `mount()` only -- NOT in action methods. Store the id.

```php
public function mount(SalesOrder $order): void
{
    if (! in_array($order->tenant_id, $this->allowedTenantIds())) {
        abort(403);
    }
    $this->orderId = $order->id;
}
```

#### `editRecipient()`

```php
public function editRecipient(): void
{
    $order = SalesOrder::whereIn('tenant_id', $this->allowedTenantIds())->findOrFail($this->orderId);

    $this->editRecipientName         = (string) $order->recipient_name;
    $this->editRecipientPhone        = (string) $order->recipient_phone;
    $this->editRecipientCountryCode  = (string) $order->recipient_country_code;
    $this->editRecipientPostalCode   = (string) $order->recipient_postal_code;
    $this->editRecipientState        = (string) $order->recipient_state;
    $this->editRecipientCity         = (string) $order->recipient_city;
    $this->editRecipientAddressLine1 = (string) $order->recipient_address_line1;
    $this->editRecipientAddressLine2 = (string) $order->recipient_address_line2;
    $this->editingRecipient = true;
}
```

#### `cancelEditRecipient()`

```php
public function cancelEditRecipient(): void
{
    $this->editingRecipient = false;
}
```

#### `saveRecipient()`

```php
public function saveRecipient(): void
{
    $order = SalesOrder::whereIn('tenant_id', $this->allowedTenantIds())->findOrFail($this->orderId);

    $this->editRecipientCountryCode = strtoupper(trim($this->editRecipientCountryCode));

    validator([
        'recipient_name'         => $this->editRecipientName,
        'recipient_phone'        => $this->editRecipientPhone,
        'recipient_country_code' => $this->editRecipientCountryCode,
        'recipient_postal_code'  => $this->editRecipientPostalCode,
        'recipient_state'        => $this->editRecipientState,
        'recipient_city'         => $this->editRecipientCity,
        'recipient_address_line1'=> $this->editRecipientAddressLine1,
        'recipient_address_line2'=> $this->editRecipientAddressLine2,
    ], [
        'recipient_name'         => ['nullable', 'string', 'max:255'],
        'recipient_phone'        => ['nullable', 'string', 'max:50'],
        'recipient_country_code' => ['nullable', 'string', 'regex:/^[A-Z]{2}$/'],
        'recipient_postal_code'  => ['nullable', 'string', 'max:20'],
        'recipient_state'        => ['nullable', 'string', 'max:100'],
        'recipient_city'         => ['nullable', 'string', 'max:100'],
        'recipient_address_line1'=> ['nullable', 'string', 'max:255'],
        'recipient_address_line2'=> ['nullable', 'string', 'max:255'],
    ])->validate();

    $order->update([
        'recipient_name'         => $this->nullableString($this->editRecipientName),
        'recipient_phone'        => $this->nullableString($this->editRecipientPhone),
        'recipient_country_code' => $this->nullableString($this->editRecipientCountryCode),
        'recipient_postal_code'  => $this->nullableString($this->editRecipientPostalCode),
        'recipient_state'        => $this->nullableString($this->editRecipientState),
        'recipient_city'         => $this->nullableString($this->editRecipientCity),
        'recipient_address_line1'=> $this->nullableString($this->editRecipientAddressLine1),
        'recipient_address_line2'=> $this->nullableString($this->editRecipientAddressLine2),
    ]);
    // SalesOrderObserver::saving() fires on update, recalculates ship_together_key automatically

    $this->editingRecipient = false;
}
```

#### `cancelOrder()`

```php
public function cancelOrder(): void
{
    $order = SalesOrder::whereIn('tenant_id', $this->allowedTenantIds())->findOrFail($this->orderId);

    if ($order->order_status === SalesOrder::ORDER_STATUS_CANCELLED) {
        return;
    }

    if (! in_array($order->fulfillment_status, [
        SalesOrder::FULFILLMENT_STATUS_UNFULFILLED,
        SalesOrder::FULFILLMENT_STATUS_READY,
    ])) {
        session()->flash('error', __('sales_orders.cannot_cancel_in_group'));
        return;
    }

    $order->update([
        'order_status'       => SalesOrder::ORDER_STATUS_CANCELLED,
        'fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_CANCELLED,
    ]);

    $order->lines()->update(['line_status' => SalesOrderLine::STATUS_CANCELLED]);
}
```

Cannot cancel an order that is `in_group`, `shipped`. Only `unfulfilled` or `ready` orders can
be cancelled via this action. Orders in an active fulfillment group must be cancelled at the
group level (Phase 2).

#### `render()`

```php
public function render()
{
    $order = SalesOrder::whereIn('tenant_id', $this->allowedTenantIds())
        ->with(['shop.tenant', 'lines.sku.stockItem', 'createdBy'])
        ->findOrFail($this->orderId);

    $relatedOrders = collect();
    if ($order->ship_together_key) {
        $relatedOrders = SalesOrder::whereIn('tenant_id', $this->allowedTenantIds())
            ->where('ship_together_key', $order->ship_together_key)
            ->where('id', '!=', $order->id)
            ->whereIn('fulfillment_status', [
                SalesOrder::FULFILLMENT_STATUS_UNFULFILLED,
                SalesOrder::FULFILLMENT_STATUS_READY,
            ])
            ->with('shop:id,name,platform')
            ->orderBy('created_at')
            ->get();
    }

    $activities = $order->activities()->with('causer')->latest()->get();

    return view('livewire.sales-order-detail', [
        'order'         => $order,
        'relatedOrders' => $relatedOrders,
        'activities'    => $activities,
    ])->layout('inventory', [
        'title'    => __('sales_orders.detail_page_title'),
        'subtitle' => $order->platform_order_id ?? "#{$order->id}",
    ]);
}
```

#### Private helpers

Same `isInternalUser()`, `allowedTenantIds()`, and `nullableString()` as SalesOrderCreate.

---

## Routes

Add to `routes/web.php`:

```php
use App\Livewire\SalesOrderCreate;
use App\Livewire\SalesOrderDetail;
use App\Livewire\SalesOrderIndex;

Route::get('/sales-orders', SalesOrderIndex::class)->name('sales.orders.index');
Route::get('/sales-orders/create', SalesOrderCreate::class)->name('sales.orders.create');
Route::get('/sales-orders/{order}', SalesOrderDetail::class)->name('sales.orders.show');
```

---

## Navigation

In `resources/views/components/layout/navigation.blade.php`, add a "Sales Orders" flat link
between Outbound and Setup. Also add `$salesActive` to the top PHP block:

```php
$salesActive = request()->routeIs('sales.*');
```

```blade
{{-- Sales Orders --}}
<a
    href="{{ route('sales.orders.index') }}"
    class="top-nav-btn {{ $salesActive ? 'is-active' : '' }}"
    wire:navigate
>
    {{ __('common.nav_sales_orders') }}
</a>
```

Add `nav_sales_orders` to all four lang files (`lang/en/common.php`, `lang/ja/common.php`,
`lang/zh_TW/common.php`, `lang/zh_CN/common.php`):

```php
'nav_sales_orders' => 'Sales Orders',
```

---

## Lang: `lang/en/sales_orders.php`

```php
<?php

return [
    // Pages
    'index_page_title'    => 'Sales Orders',
    'index_page_subtitle' => 'View and manage platform sales orders.',
    'create_page_title'   => 'Create Sales Order',
    'create_page_subtitle'=> 'Manually create a sales order.',
    'detail_page_title'   => 'Sales Order',

    // Fields
    'field_shop'                  => 'Shop',
    'field_platform_order_id'     => 'Platform order ID',
    'field_platform_order_id_hint'=> 'Optional. External reference from Amazon, Rakuten, etc.',
    'field_order_status'          => 'Order status',
    'field_fulfillment_status'    => 'Fulfillment status',
    'field_source'                => 'Source',
    'field_note'                  => 'Note',
    'field_recipient'             => 'Recipient',
    'field_recipient_name'        => 'Recipient name',
    'field_recipient_phone'       => 'Phone',
    'field_country_code'          => 'Country code',
    'field_postal_code'           => 'Postal code',
    'field_state'                 => 'State / Prefecture',
    'field_city'                  => 'City',
    'field_address_line1'         => 'Address line 1',
    'field_address_line2'         => 'Address line 2',
    'field_sku'                   => 'SKU',
    'field_quantity'              => 'Quantity',
    'field_unit_price'            => 'Unit price',
    'field_line_status'           => 'Line status',

    // Order statuses
    'order_pending'   => 'Pending',
    'order_on_hold'   => 'On hold',
    'order_backorder' => 'Backorder',
    'order_cancelled' => 'Cancelled',
    'order_completed' => 'Completed',

    // Fulfillment statuses
    'fulfillment_unfulfilled' => 'Unfulfilled',
    'fulfillment_ready'       => 'Ready',
    'fulfillment_in_group'    => 'In fulfillment',
    'fulfillment_shipped'     => 'Shipped',
    'fulfillment_cancelled'   => 'Cancelled',

    // Sources
    'source_manual' => 'Manual',
    'source_csv'    => 'CSV',
    'source_api'    => 'API',

    // Line statuses
    'line_ready'     => 'Ready',
    'line_cancelled' => 'Cancelled',

    // Table columns
    'col_shop'               => 'Shop',
    'col_platform_order_id'  => 'Platform order ID',
    'col_recipient'          => 'Recipient',
    'col_fulfillment_status' => 'Fulfillment',
    'col_order_status'       => 'Status',
    'col_created_at'         => 'Created',
    'col_actions'            => 'Actions',
    'col_sku'                => 'SKU / Stock Item',
    'col_qty'                => 'Qty',
    'col_line_status'        => 'Line status',

    // Feedback
    'order_created'          => 'Sales order created.',
    'recipient_updated'      => 'Recipient updated.',
    'order_cancelled_msg'    => 'Order cancelled.',
    'cannot_cancel_in_group' => 'Cannot cancel an order that is currently in a fulfillment group.',
    'empty_state'            => 'No sales orders match the current filters.',
    'no_lines'               => 'No lines.',

    // Buttons
    'btn_create_order'      => 'Create order',
    'btn_back_orders'       => 'Back to sales orders',
    'btn_cancel_order'      => 'Cancel order',
    'btn_edit_recipient'    => 'Edit recipient',
    'btn_save_recipient'    => 'Save recipient',
    'btn_cancel_edit'       => 'Cancel',
    'btn_add_line'          => 'Add line',
    'btn_remove_line'       => 'Remove',
    'btn_view_order'        => 'View',

    // Validation
    'shop_required'  => 'Please select a shop.',
    'invalid_shop'   => 'Invalid or inactive shop.',

    // Filter labels
    'all_shops'              => 'All shops',
    'all_fulfillment_status' => 'All fulfillment',
    'all_order_status'       => 'All statuses',
    'search_placeholder'     => 'Platform order ID, recipient name...',

    // Related orders
    'related_orders_label'   => 'Same recipient - can ship together',
    'related_orders_none'    => 'No other orders share this recipient address.',
];
```

Also create `lang/ja/sales_orders.php`, `lang/zh_TW/sales_orders.php`,
`lang/zh_CN/sales_orders.php` with identical content and a
`// TODO: translate this file. English values are placeholders.` comment.

---

## Blade Views

### `resources/views/livewire/sales-order-index.blade.php`

Structure:
1. Flash message at top
2. Page actions row: "Create order" button linking to `route('sales.orders.create')` with `wire:navigate`
3. Toolbar: shop filter select, fulfillment_status filter, order_status filter, search input
4. Table columns: Shop (name + platform badge), Platform Order ID, Recipient (name + city), Fulfillment status badge, Order status badge, Created at, Actions (View link)
5. Fulfillment status badge colours: unfulfilled = zinc, ready = blue, in_group = amber, shipped = green, cancelled = red
6. Order status badge colours: pending = zinc, on_hold = amber, backorder = orange, cancelled = red, completed = green
7. Empty state, pagination

### `resources/views/livewire/sales-order-create.blade.php`

Structure:
1. Panel 1 -- Order: Shop (select from `$shops`, shows tenant code prefix), Platform Order ID (optional, with hint)
2. Panel 2 -- Recipient: Name, Phone, Country code, Postal code, State, City, Address line 1, Address line 2
3. Lines panel: rows of SKU select (scoped to the selected shop tenant), Quantity, Note, Remove button; "Add line" button below table
4. Full-width panel: Note textarea
5. Sticky footer: back link (`route('sales.orders.index')`), submit button
6. Show validation errors below each field
7. Lines validation errors shown per row (e.g. `lines.0.sku_id`)
8. When `$shopId` is empty, SKU selects show placeholder "Select a shop first"

### `resources/views/livewire/sales-order-detail.blade.php`

Structure:
1. Flash messages (status / error) at top
2. Header row: Platform Order ID (or "#id"), shop + platform badge, created by + date
3. Status badges: order_status + fulfillment_status side by side
4. Related orders section (only shown when `$relatedOrders` is non-empty):
   - Label: "Same recipient - can ship together"
   - List of related order IDs / platform_order_ids with links
   - (No fulfill button yet -- Phase 2)
5. Recipient section:
   - Display mode: shows all address fields in read-only layout; "Edit recipient" button
   - Edit mode (when `$editingRecipient`): inline form with all address fields + "Save recipient" / "Cancel" buttons
6. Lines table: SKU code/name, Stock item code, Quantity, Line status badge, Note
7. Note section (order note)
8. Activity log section: list of logged changes (who, what, when)
9. Footer actions: "Cancel order" button (danger, shown when order_status = pending and
   fulfillment_status in [unfulfilled, ready]); "Back to orders" link

---

## Tests: `tests/Feature/SalesOrderTest.php`

```
test_create_sales_order_succeeds()
```
- Active tenant, active shop, active SKU
- Internal user creates order: shop, recipient_name 'Taro', address_line1 '1-1 Namba', platform_order_id null, one line
- Assert redirect to `sales.orders.show`
- Assert `SalesOrder` with `order_status = 'pending'`, `fulfillment_status = 'unfulfilled'`
- Assert one `SalesOrderLine` with `line_status = 'ready'`

```
test_create_sales_order_computes_ship_together_key()
```
- Create order with recipient address set
- Assert `ship_together_key` is not null
- Create second order with identical recipient (same tenant, shop, address)
- Assert both orders have identical `ship_together_key`

```
test_create_sales_order_key_null_when_no_address()
```
- Create order with no recipient address fields set
- Assert `ship_together_key` is null

```
test_create_sales_order_rejects_duplicate_platform_order_id()
```
- Order already exists: tenant A, shop A, platform_order_id 'AMZ-123'
- Try to create another with same tenant, shop, platform_order_id 'AMZ-123'
- Assert `assertHasErrors(['platform_order_id'])`

```
test_create_sales_order_allows_same_platform_order_id_for_different_shop()
```
- Two shops under same tenant, both have an order with platform_order_id 'AMZ-123'
- Assert both created successfully (unique is per shop)

```
test_create_sales_order_requires_sku_id()
```
- Submit with line `sku_id = ''`
- Assert `assertHasErrors(['lines.0.sku_id'])`

```
test_create_sales_order_requires_quantity()
```
- Submit with line `quantity = 0`
- Assert `assertHasErrors(['lines.0.quantity'])`

```
test_create_sales_order_rejects_sku_from_wrong_tenant()
```
- Shop belongs to tenant A; SKU belongs to tenant B
- Submit with that SKU
- Assert `assertHasErrors(['lines.0.sku_id'])`

```
test_cancel_sales_order_succeeds()
```
- Order with `order_status = 'pending'`, `fulfillment_status = 'ready'`, two lines
- Call `cancelOrder()`
- Assert `order_status = 'cancelled'`, `fulfillment_status = 'cancelled'`
- Assert all lines have `line_status = 'cancelled'`

```
test_cancel_sales_order_blocked_when_in_group()
```
- Order with `fulfillment_status = 'in_group'`
- Call `cancelOrder()`
- Assert order NOT cancelled; `assertHasErrors` or flash error set

```
test_update_recipient_recalculates_ship_together_key()
```
- Order has ship_together_key set for address A
- Call `saveRecipient()` with address B
- Assert `ship_together_key` changed to reflect address B

```
test_related_orders_shown_on_detail()
```
- Two orders with identical `ship_together_key`, both `fulfillment_status = 'ready'`
- Load detail for order 1
- Assert `$relatedOrders` contains order 2

```
test_related_orders_excludes_cancelled()
```
- Order 2 has same ship_together_key but `fulfillment_status = 'cancelled'`
- Load detail for order 1
- Assert `$relatedOrders` is empty

```
test_sales_order_routes_render()
```
- GET /sales-orders, /sales-orders/create both return 200
- Create an order; GET /sales-orders/{order} returns 200

---

## Implementation rules

- Run both migrations before implementing components
- `SalesOrderObserver` must be registered in `AppServiceProvider::boot()` before any tests run
- `order_status` and `fulfillment_status` use model constants (e.g. `SalesOrder::ORDER_STATUS_PENDING`),
  never bare strings
- Manual create always sets `order_status = ORDER_STATUS_PENDING`, `fulfillment_status = FULFILLMENT_STATUS_UNFULFILLED`, and `source = SOURCE_MANUAL`.
  Later fulfillment checks can promote `fulfillment_status` to `ready`.
- `ship_together_key` is null when `recipient_address_line1` is blank; do not compute a key for
  addressless orders
- `ship_together_key` must be recomputed whenever any recipient field or `shop_id` changes --
  the observer handles this; do not call `recalculateShipTogetherKey()` directly in Livewire
  `updated*()` hooks (observer is the single source of truth)
- `recipient_country_code` normalised to uppercase in `save()` and `saveRecipient()` before validation;
  use `'regex:/^[A-Z]{2}$/'` not `'size:2'`
- All wire properties must be `string` type except `public int $orderId = 0` (SalesOrderDetail)
  and `public bool $editingRecipient = false` (Livewire supports bool for non-hydrated toggles)
- `allowedTenantIds()` pattern (same as OutboundOrderCreate): internal users see all tenants;
  tenant users see only their active-linked tenants
- SKU options are scoped to the selected shop's tenant; return empty collection if no shop selected
- `platform_order_id` uniqueness: application-level only (`Rule::unique` in validateInput);
  no DB partial unique constraint in this migration
- Copy `isInternalUser()` with the `// TODO: remove unauthenticated fallback when auth is implemented` comment
- Use `Rule::unique(...)` and `Rule::exists(...)` not bare string syntax
- ASCII punctuation only in all strings
