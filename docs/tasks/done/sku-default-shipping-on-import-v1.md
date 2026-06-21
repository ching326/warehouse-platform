# Task: Apply SKU Default Shipping Method on Order Import v1

## Stack

Laravel 13, Livewire 4 (class-based, not Volt), Flux UI, SQLite (dev), PHP 8.3. Plain Blade.
Use plain ASCII punctuation only in code, Blade, and lang files.

---

## Goal

When sales orders are created by **import** (CSV/report and Amazon API), automatically set each
order's shipping method from the order's SKUs' `default_shipping_method_id`, so staff do not set
shipping method per order.

Multi-line orders are resolved by a **configurable priority ranking** between shipping methods
(a more-capable method wins, because the whole consolidated parcel must ship by a method that can
carry the bulkiest item).

This builds on `skus.default_shipping_method_id` (already added) and the shipping method setup.

---

## Decisions (locked)

1. **SKU default always wins over the platform-mapped method.** On import, if the order's SKUs
   have a default shipping method, it overrides whatever the platform service level mapped.
2. **Resolution = highest `selection_priority` wins. Higher number wins.**
3. **Tie = leave blank.** If two or more distinct methods share the highest priority among the
   order's SKU defaults, set no shipping method (null) and let staff set it manually. (This also
   discards the platform-mapped method for that order, per Decision 1.)
4. **Cross-carrier** is handled by the single global priority number (you control the ranking).
5. **Manual create is out of scope.** `SalesOrderCreate` keeps its plain manual shipping-method
   dropdown. Do NOT add auto-loading there in v1 (the override-tracking complexity is not worth it
   on a single-order form where the user is present).

---

## Part 1: `selection_priority` on shipping methods

### Migration

Add to `shipping_methods` (separate from `sort_order`, which is display order only):

```php
Schema::table('shipping_methods', function (Blueprint $table) {
    $table->unsignedSmallInteger('selection_priority')->default(0)->after('sort_order');
});
```

**Seed required.** Backfill priorities for the existing canonical methods in the same migration.
This is not optional: if every method stays at `0`, multi-SKU orders with different defaults all
tie and shipping is left blank too often, defeating the feature.

Use the **real method codes** (verified in the `sort_order` backfill migration), higher = more
capable = wins. Suggested starting numbers (the team confirms/adjusts, especially where Yu-Pack
sits):

```text
yamato_tqb        (Takkyubin)        : 40
sagawa_thb        (Hikyaku/Takuhai)  : 40
japan_post_yupack (Yu-Pack)          : 40
yamato_compact    (Compact)          : 30
yamato_nekopos    (Nekopos)          : 20
other                                : 10
```

Use a data backfill in the migration keyed by method `code` (skip codes that do not exist), the
same defensive style as the existing `sort_order` backfill migration. Note: methods sharing the
same number (for example the three box couriers above) will tie against each other, which by the
locked decision means shipping is left blank for a multi-SKU order mixing them. Assign distinct
numbers if you want a winner among box couriers.

### Model

`App\Models\ShippingMethod`:

- add `selection_priority` to `$fillable`.
- cast `'selection_priority' => 'integer'`.

### Setup UI

Make `selection_priority` editable on **both** the create and edit flows. Their internal
structures differ, so do not assume editing the shared form is enough - spell out each touch
point so execution does not add the field to create only:

- `resources/views/livewire/shipping-method-form.blade.php` (shared form): add the number field
  bound to the property.
- `App\Livewire\ShippingMethodCreate`:
  - add the public property (for example `public string $selectionPriority = '0';`).
  - include it in `validationData()` and in `methodPayload()`.
  - reset/initialize it in `mount()` if needed.
- `App\Livewire\ShippingMethodEdit`: this component does **not** share `methodPayload()` /
  `validationData()` with create. Add the property, hydrate it in its own `mount()` from the
  existing method, and persist it in its own `save()`.

Field details:

- label "Selection priority", helper text: "Higher wins when an order has SKUs with different
  default shipping methods."
- validation: `['nullable', 'integer', 'min:0', 'max:65535']` (treat blank as 0).

(Optional, not required: mirror the existing inline `sort_order` editor on `ShippingMethodIndex`
for priority too. The create/edit field is sufficient for v1.)

---

## Part 2: Resolver service

Create `App\Services\SalesOrders\SkuDefaultShippingMethodResolver` (or a static resolver method;
keep it unit-testable).

Pass tenant context and only load SKUs scoped to that tenant (defense in depth - do not trust
the row ids alone, consistent with the inline-edit hardening pattern elsewhere):

```php
/**
 * @param array<int> $skuIds  resolved SKU ids for one order's lines
 * @return array{status: 'winner'|'tie'|'none', shipping_method_id: ?int, shipping_method: ?string}
 */
public function resolve(int $tenantId, array $skuIds): array
```

Return an **explicit result object/array with a `status`**, not a nullable array. This is the key
fix: "tie" and "no SKU default" must be distinguishable because they behave differently
(tie -> clear shipping; none -> keep platform method). Do not collapse both into `null`.

Logic:

1. Load SKUs `where tenant_id = $tenantId and id in ($skuIds)`; collect their non-null
   `default_shipping_method_id` values.
2. Load those shipping methods that are `status = active` (ignore inactive defaults), with their
   `selection_priority` and `carrier.code`.
3. If none remain -> return `['status' => 'none', 'shipping_method_id' => null, 'shipping_method' => null]`
   (caller keeps the platform method, if any).
4. Find the maximum `selection_priority`.
5. If exactly one distinct method has that maximum -> return
   `['status' => 'winner', 'shipping_method_id' => $method->id, 'shipping_method' => $method->carrier?->code]`.
6. If two or more distinct methods share that maximum -> return
   `['status' => 'tie', 'shipping_method_id' => null, 'shipping_method' => null]`
   (caller clears the order's shipping method).

A single method appearing on multiple lines is **not** a tie - that is one distinct method
(a `winner`). Keep one query for the methods; do not N+1 per SKU.

Note on `shipping_method` (legacy column): `sales_orders.shipping_method` stores the carrier code
(for example `yamato`). Populate it from the resolved method's `carrier.code`, consistent with how
the importer currently sets it.

---

## Part 3: Wire into the shared importer

Single integration point: `App\Services\SalesOrders\SalesOrderImporter::import()`. It already
groups rows by `platform_order_id` and creates each order from `$first['shipping_method_id']` /
`$first['shipping_method']` (around lines 54-64). Both CSV (`SalesOrderImport`) and Amazon
(`AmazonOrderMapper`) flow through this, so wiring here covers both.

Per order group, before `SalesOrder::create([...])`:

1. Collect the group's distinct non-null `sku_id`s from its rows.
2. Call `resolve($shop->tenant_id, $skuIds)`.
3. Decide the order's shipping method by `status`:
   - `winner` -> use the resolved `shipping_method_id` / `shipping_method` (overrides the platform
     `$first` values - Decision 1, SKU default wins).
   - `tie` -> set `shipping_method_id = null`, `shipping_method = null` (clear, staff sets it).
   - `none` (no SKU default) -> keep the existing `$first['shipping_method_id']` /
     `$first['shipping_method']` (platform fallback, current behavior).

### Platform fallback differs by import source

Be explicit that the `none` fallback is only meaningful where the source actually maps a shipping
method:

- **Amazon report CSV** (`importFormat = amazon_report`) and **Amazon API** map a method from the
  `ship-service-level` / service-level category, so `$first` may carry a platform method that
  `none` falls back to.
- **Generic CSV** (`importFormat = generic`) has **no** platform shipping mapping. So for generic
  imports, `none` means the order's shipping method stays **null** unless a SKU default resolves
  to a `winner`. This is expected, not a bug.

Do not change the line insert logic, dedup, or the cancel-request handling. Only the order-level
shipping method resolution is added.

---

## Out of scope

- `SalesOrderCreate` (manual) - unchanged, manual dropdown only.
- Outbound order shipping method - unchanged.
- Re-resolving shipping method on existing orders - this only applies at import time.

---

## Language keys

`lang/en/shipping.php`: `field_selection_priority`, `selection_priority_hint`.
Follow the existing locale-stub pattern for `ja`/`zh_*`.

---

## Tests

`tests/Feature/SalesOrderImportTest.php` and/or `tests/Feature/AmazonSpapiOrderImportTest.php`
(import paths), plus a focused resolver unit test.

Resolver (assert the `status` field, not just the id):

1. Single SKU with an active default -> `status = winner` with that method.
2. Multiple SKUs whose defaults resolve to one highest-priority method -> `winner` with it.
3. The same method on multiple lines is one distinct method -> `winner`, not a tie.
4. Two distinct methods tie at the highest priority -> `status = tie`.
5. No SKU has a default -> `status = none`.
6. A SKU whose default is inactive is ignored; if it was the only default -> `status = none`.
7. Higher `selection_priority` number wins (assert direction).
8. Cross-carrier: a Yamato default and a Sagawa default with different priorities -> higher wins;
   equal -> `tie`.
9. A SKU id from another tenant passed in is ignored (resolver is scoped by `$tenantId`).

Import wiring:

10. Importing an order whose SKUs have a single highest-priority default sets that shipping method,
    overriding the platform-mapped method (Decision 1).
11. Importing an order whose SKU defaults tie leaves `shipping_method_id` null.
12. Amazon import order whose SKUs have no defaults keeps the platform-mapped shipping method.
13. Generic CSV order with no SKU defaults leaves shipping method null (no platform fallback).
14. Manual `SalesOrderCreate` is unaffected (no auto-set).

Setup:

12. `selection_priority` is editable and validated on the shipping method form.

Run:

```bash
php artisan test tests/Feature/SalesOrderImportTest.php tests/Feature/AmazonSpapiOrderImportTest.php tests/Feature/ShippingMethodTest.php
php artisan test
```

If `php` is not on PATH (Laragon):

```bash
C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan test
```

---

## Acceptance Criteria

- Shipping methods have an editable `selection_priority` (higher wins).
- Imported orders (CSV and Amazon) get their shipping method from the order's SKUs' defaults,
  overriding the platform-mapped method when defaults exist.
- Multi-line orders resolve to the single highest-priority default; ties leave shipping blank.
- Orders whose SKUs have no default keep the platform-mapped method.
- Inactive default methods are ignored in resolution.
- Manual create is unchanged.
- Tenant scope and existing import behavior (dedup, cancel handling, lines) are preserved.
- Tests pass.

---

## Future Phases

- Optional inline `selection_priority` editing on the shipping method index (mirror `sort_order`).
- Optional "suggested method" hint on manual `SalesOrderCreate` (display only, no auto-set).
- Optional re-resolve action for existing orders missing a shipping method.
