# Task: Fulfillment Groups v2 -- Bug Fixes

## Pre-conditions

Fulfillment Groups v1 (commit bafb8f1) must be deployed.
Sales Orders Phase 1 must be complete.

---

## Bugs to fix

### [P1] Virtual bundle sales order lines are silently skipped

**File:** `app/Livewire/FulfillmentGroupCreate.php`, method `aggregateLines()`

**Problem:**
`aggregateLines()` skips any sales order line whose SKU has no `stock_item_id`:

```php
if (! $sku || ! $sku->stock_item_id) {
    continue;  // virtual_bundle SKUs are dropped here
}
```

A sales order can contain a `virtual_bundle` SKU (allowed by `SalesOrderCreate`).
When that order is grouped, the bundle lines produce no outbound lines and no stock
reservation. The group is created successfully, the sales order moves to `in_group`,
but the linked `OutboundOrder` is missing the bundle stock. This is a silent data error.

**Fix:**
In `aggregateLines()`, when a SKU has `sku_type = virtual_bundle`, expand its
`bundleComponents` into the aggregation maps instead of skipping it.
If a bundle has no components, throw `ValidationException` so the transaction rolls back.

`SkuBundleComponent` fields used:
- `component_stock_item_id` -- the physical stock item to reserve
- `quantity` -- units per bundle unit (multiply by line quantity)
- `tenant_id` -- must match the group tenant

**Updated `aggregateLines()` signature and logic:**

```php
/**
 * @return array{0: array<string,array{sku_id:int,stock_item_id:int,qty:int,parent_sku_id:int|null}>, 1: array<int,int>}
 */
private function aggregateLines(Collection $orders, int $tenantId): array
{
    $bySkuAndItem = [];
    $byStockItem  = [];

    foreach ($orders as $order) {
        foreach ($order->lines as $line) {
            if ($line->line_status !== SalesOrderLine::STATUS_READY) {
                continue;
            }

            $sku = $line->sku;
            if (! $sku) {
                continue;
            }

            if ($sku->sku_type === 'virtual_bundle') {
                $components = $sku->bundleComponents;

                if ($components->isEmpty()) {
                    throw ValidationException::withMessages([
                        'selectedOrderIds' => __('fulfillment_groups.bundle_no_components', ['sku' => $sku->sku]),
                    ]);
                }

                foreach ($components as $component) {
                    if ($component->tenant_id !== $tenantId) {
                        throw ValidationException::withMessages([
                            'selectedOrderIds' => __('fulfillment_groups.bundle_invalid_tenant', ['sku' => $sku->sku]),
                        ]);
                    }

                    $componentQty = $line->quantity * $component->quantity;
                    $sid = $component->component_stock_item_id;
                    // Use "bundle:{bundle_sku_id}:{component_stock_item_id}" as key so
                    // the parent SKU id is preserved for the outbound line.
                    $key = 'bundle:'.$sku->id.':'.$sid;
                    $bySkuAndItem[$key] ??= [
                        'sku_id'          => $sku->id,
                        'stock_item_id'   => $sid,
                        'qty'             => 0,
                        'parent_sku_id'   => null,
                    ];
                    $bySkuAndItem[$key]['qty'] += $componentQty;
                    $byStockItem[$sid] = ($byStockItem[$sid] ?? 0) + $componentQty;
                }

                continue;
            }

            if (! $sku->stock_item_id) {
                // Non-bundle SKU with no stock item: not shippable, skip.
                // These are informational SKUs (services, fees, etc.).
                continue;
            }

            $key = $sku->id.':'.$sku->stock_item_id;
            $bySkuAndItem[$key] ??= ['sku_id' => $sku->id, 'stock_item_id' => $sku->stock_item_id, 'qty' => 0, 'parent_sku_id' => null];
            $bySkuAndItem[$key]['qty'] += $line->quantity;
            $byStockItem[$sku->stock_item_id] = ($byStockItem[$sku->stock_item_id] ?? 0) + $line->quantity;
        }
    }

    return [$bySkuAndItem, $byStockItem];
}
```

Update the call site in `save()` to pass `$tenantId`:

```php
[$bySkuAndItem, $byStockItem] = $this->aggregateLines($orders, $tenantId);
```

The outbound line creation loop already works unchanged -- each entry in `$bySkuAndItem`
produces one outbound line with `sku_id` and `stock_item_id`.

**Note on outbound line display:**
Bundle component outbound lines use the bundle SKU's `sku_id` (same as `OutboundOrderCreate`).
The outbound ship page already handles this pattern.

**Add to `lang/en/fulfillment_groups.php`:**

```php
'bundle_no_components'  => 'SKU :sku is a virtual bundle with no components configured.',
'bundle_invalid_tenant' => 'SKU :sku has bundle components belonging to a different tenant.',
```

Add stubs (value = key) to `lang/ja/`, `lang/zh_TW/`, `lang/zh_CN/`.

Also update `with('lines.sku')` in the `save()` transaction to eager-load bundle components:

```php
->with('lines.sku.bundleComponents')
->lockForUpdate()
->get();
```

---

### [P1] Tenant data leak in read queries

**File:** `app/Livewire/FulfillmentGroupCreate.php`, methods `shipKeyOptions()` and
`eligibleOrders()`

**Problem:**
Both methods filter by `$this->tenantId` without verifying it is in `allowedTenantIds()`.
A tenant user can tamper Livewire state (e.g. via browser devtools or a crafted request)
to set `tenantId` to a different tenant's ID. The read queries then return that tenant's
ship-together keys and ready orders in the UI, before any write-path validation catches it.

```php
// Both methods do this -- no allowedTenantIds() check:
SalesOrder::query()->where('tenant_id', (int) $this->tenantId)
```

**Fix:**
Guard both methods with an early return if the selected tenant is not in `allowedTenantIds()`.
The write path (`validatedTenantId()`) already rejects invalid tenants -- this fix closes
the read-path leak.

```php
private function shipKeyOptions(): Collection
{
    if ($this->tenantId === '' || ! in_array((int) $this->tenantId, $this->allowedTenantIds(), true)) {
        return collect();
    }

    return SalesOrder::query()
        ->where('tenant_id', (int) $this->tenantId)
        ->where('fulfillment_status', SalesOrder::FULFILLMENT_STATUS_READY)
        ->whereNotNull('ship_together_key')
        ->selectRaw('ship_together_key, min(recipient_name) as recipient_name, min(recipient_city) as recipient_city, count(*) as order_count')
        ->groupBy('ship_together_key')
        ->orderBy('recipient_name')
        ->get();
}

private function eligibleOrders(): Collection
{
    if ($this->tenantId === '' || ! in_array((int) $this->tenantId, $this->allowedTenantIds(), true)) {
        return collect();
    }

    return SalesOrder::query()
        ->where('tenant_id', (int) $this->tenantId)
        ->where('fulfillment_status', SalesOrder::FULFILLMENT_STATUS_READY)
        ->where('ship_together_key', $this->shipKey)
        ->withCount('lines')
        ->orderBy('created_at')
        ->get();
}
```

The guard condition is identical in both methods: `$this->tenantId === '' || ! in_array(...)`.
Internal users always pass (their `allowedTenantIds()` contains all tenant IDs).
Tenant users only see data for their own allowed tenants.

---

## Tests to add in `tests/Feature/FulfillmentGroupTest.php`

Add the following cases to the existing test class. Do not modify existing tests.

| # | Test | What it asserts |
|---|---|---|
| 15 | `test_create_group_expands_virtual_bundle_into_outbound_lines` | Order has a virtual_bundle SKU with 2 components; group created; outbound has 2 component lines; stock reserved for each component |
| 16 | `test_create_group_rejects_bundle_with_no_components` | Order has a virtual_bundle SKU with no components; group creation fails with `selectedOrderIds` error; no group or outbound created |
| 17 | `test_tenant_user_cannot_see_other_tenant_ship_keys` | Tenant user sets `tenantId` to another tenant's ID; `shipKeyOptions()` returns empty; no data leaked |
| 18 | `test_tenant_user_cannot_see_other_tenant_eligible_orders` | Tenant user sets `tenantId` and `shipKey` to another tenant's values; `eligibleOrders()` returns empty via render; no data leaked |

**Test 15 setup pattern:**

```php
public function test_create_group_expands_virtual_bundle_into_outbound_lines(): void
{
    [$tenant, $warehouse, $shop] = [...];  // create tenant/warehouse/shop

    // Two physical stock items
    $stockItemA = StockItem::factory()->for($tenant)->create();
    $stockItemB = StockItem::factory()->for($tenant)->create();

    // Virtual bundle SKU
    $bundleSku = Sku::factory()->for($tenant)->for($shop)->create(['sku_type' => 'virtual_bundle']);

    // Seed stock for both components
    app(InventoryService::class)->adjustStock($tenant->id, $warehouse->id, $stockItemA->id, 50);
    app(InventoryService::class)->adjustStock($tenant->id, $warehouse->id, $stockItemB->id, 50);

    // Bundle components: 2x stockItemA + 3x stockItemB per bundle unit
    SkuBundleComponent::factory()->create([
        'bundle_sku_id'            => $bundleSku->id,
        'tenant_id'                => $tenant->id,
        'component_stock_item_id'  => $stockItemA->id,
        'quantity'                 => 2,
    ]);
    SkuBundleComponent::factory()->create([
        'bundle_sku_id'            => $bundleSku->id,
        'tenant_id'                => $tenant->id,
        'component_stock_item_id'  => $stockItemB->id,
        'quantity'                 => 3,
    ]);

    // Sales order with 4 units of the bundle
    $order = $this->readySalesOrder($tenant, $shop, $bundleSku, 4, 'SO-BUNDLE');

    $this->createGroup($tenant, $warehouse, $order->ship_together_key, [$order])
        ->assertRedirect();

    $outbound = FulfillmentGroup::firstOrFail()->outboundOrder()->with('lines')->firstOrFail();

    $this->assertSame(2, $outbound->lines->count());  // one per component
    // 4 units * 2 per bundle = 8 reserved for component A
    $this->assertSame(8, $this->balance($tenant, $warehouse, $stockItemA)->reserved_qty);
    // 4 units * 3 per bundle = 12 reserved for component B
    $this->assertSame(12, $this->balance($tenant, $warehouse, $stockItemB)->reserved_qty);
}
```

**Tests 17-18 pattern** (checking render output, not save):

```php
public function test_tenant_user_cannot_see_other_tenant_ship_keys(): void
{
    [$ownTenant, $user] = $this->tenantUser();
    [$otherTenant, , $otherShop, $otherSku] = $this->skuWithStock(10);
    $this->readySalesOrder($otherTenant, $otherShop, $otherSku, 1, 'SO-LEAK');

    Livewire::actingAs($user)
        ->test(FulfillmentGroupCreate::class)
        ->set('tenantId', (string) $otherTenant->id)  // tampered
        ->assertSet('tenantId', (string) $otherTenant->id)
        ->call('render')
        ->assertViewHas('shipKeyOptions', fn ($opts) => $opts->isEmpty());
}
```

---

## Constraints

- Do not modify any existing migration, model constant, or passing test.
- Do not extract `createVirtualBundleLines` from `OutboundOrderCreate` into a shared
  trait or service yet -- duplication is acceptable in v2; refactor is a separate task.
- `aggregateLines()` must pass `$tenantId` explicitly (not read from `$this->tenantId`
  inside the method) because it runs inside the transaction closure where `$this->tenantId`
  may differ from the validated `$tenantId`.
- Run `php artisan test` at the end and confirm all tests pass.
