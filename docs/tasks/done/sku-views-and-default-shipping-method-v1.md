# Task: SKU Views + Default Shipping Method v1

## Stack

Laravel 13, Livewire 4 (class-based components, not Volt), Flux UI (`livewire/flux ^2.14`),
SQLite (dev), PHP 8.3. Plain Blade views only. No TypeScript.

Use plain ASCII punctuation only in code, Blade, and lang files. No em-dashes, smart quotes,
or arrow glyphs.

---

## Goal

Two related changes on the SKUs area:

1. Add a **default shipping method** per SKU (DB + model + form + display).
2. Add **switchable table views** to `/skus`: keep the current rich/grouped view and add three
   flat, one-field-per-column views. Let each user **save which view is their default**.

---

## Part 1: Default Shipping Method on SKU

### Current state (verified)

- `skus.default_packaging_material_id` already exists (nullable FK to `packaging_materials`,
  relation `defaultPackagingMaterial()`).
- `skus.default_shipping_method_id` does **not** exist. This task adds it.
- `shipping_methods` is global (not tenant-scoped); it has an `ordered()` scope and a `status`
  column. `App\Models\ShippingMethod` is the dropdown source.

### Migration

Add a nullable FK to `skus`, after `default_packaging_material_id`:

```php
Schema::table('skus', function (Blueprint $table) {
    $table->foreignId('default_shipping_method_id')
        ->nullable()
        ->after('default_packaging_material_id')
        ->constrained('shipping_methods')
        ->restrictOnDelete();
});
```

No data backfill needed (nullable, defaults to null).

Date this migration after `2026_06_20_000004_create_shipping_methods_and_rates_tables.php`,
because it depends on the `shipping_methods` table.

Use `restrictOnDelete()`, not `nullOnDelete()`. Shipping methods are a deactivate-only catalog
in this app; hard delete is blocked by the model. The FK should preserve SKU history/defaults and
stay consistent with that no-hard-delete rule.

### Model

`App\Models\Sku`:

- add `default_shipping_method_id` to `$fillable`.
- add relation:

```php
public function defaultShippingMethod(): BelongsTo
{
    return $this->belongsTo(ShippingMethod::class, 'default_shipping_method_id');
}
```

### Form (SKU create)

The SKU form lives in `App\Livewire\SkuCreate` (route `/skus/create`). Add a **Default shipping
method** dropdown next to the existing default packaging field:

- options: active shipping methods via `ShippingMethod::query()->where('shipping_methods.status', 'active')->ordered()->get()`
  (qualify columns; `ordered()` left-joins `carriers`). Label as `name` plus carrier name.
- nullable (blank = no default).
- validation: `['nullable', Rule::exists('shipping_methods', 'id')->where('status', 'active')]`
  for create.

There is no SKU edit page in v1. Do not add one for this task. Existing SKUs can update this field
from the `/skus` Logistics inline-edit view in Part 3.

For Logistics inline edit, use a slightly different rule:

- the dropdown should show active methods plus the SKU's currently saved method, even if that
  saved method is now inactive.
- updating to a different method must require an active method.
- keeping the same inactive method is allowed so old SKUs are not forced to clear their saved
  value just because a shipping method was later deactivated.

### Consumption of the default (scope note)

v1 stores, edits, and displays the default shipping method only. **Do not** auto-apply it to
sales orders or outbound orders in v1: orders are multi-line (multiple SKUs), so "which SKU's
default wins" is ambiguous and needs a separate product decision. Record this as a future phase;
do not invent an ambiguous rule now.

---

## Part 2: Switchable SKU Table Views

### Views

Four views total. Keep the current one; add three flat views.

1. **Detailed (current)** - the existing grouped/rich layout. Unchanged. This is the initial
   fallback default.

2. **Catalog (flat)** - one field per column:
   - SKU (`sku`)
   - Name (`name`)
   - Stock item code (`stockItem.code`)
   - Stock short name (`stockItem.short_name`)
   - Shop (`shop.code`)
   - Type (`sku_type`)
   - Status (`status`)

3. **Marketplace IDs (flat)** - one field per column:
   - SKU (`sku`)
   - Seller SKU (`platform_sku`)
   - ASIN (`platform_product_id`)
   - FNSKU (`platform_label_code`)
   - Variant (`platform_variant_name`)
   - Shop (`shop.code`)

   ASIN -> `platform_product_id` and FNSKU -> `platform_label_code` is the agreed mapping.

4. **Logistics (inline editable)** - see Part 3.

For flat columns, show `-` for null values (except in the Logistics view, which renders inputs).

### View switcher UI

Add a compact button group at the top of `/skus` (like the reference screenshot):

```text
Detailed | Catalog | Marketplace | Logistics      [Set as default]
```

- the active view button is visually marked active.
- `Set as default` saves the current view as the user default (Part 4).

### Component shape

Keep one component (`SkusIndex`) and one Blade view. Add:

```php
#[Url(as: 'view')]
public string $view = 'detailed';
```

Validate `view` against an allowed set (`detailed`, `catalog`, `marketplace`, `logistics`);
fall back to `detailed` if unknown.

Important: do not let the default property value (`detailed`) prevent user preferences from
loading. In `mount()`, check the real request query string, not only `$this->view`:

1. if `request()->query('view')` exists and is valid, use it.
2. else if `Auth::user()?->preference('skus_view')` is valid, use that.
3. else use `detailed`.

This keeps shareable URLs working while allowing the saved preference to apply when no `?view=`
query parameter was actually supplied.

Render the flat views with a shared column loop driven by a per-view column config. Keep the
existing `detailed` layout as its own branch (do not try to force it into the flat loop).
Filters, search, tenant scope, and pagination must work identically across all views.

The empty-state colspan must be based on the current view's actual column count. Do not leave a
hardcoded colspan from the old table.

Eager load the fields needed by all views:

- `tenant:id,code,name`
- `shop:id,tenant_id,code,name,platform,marketplace`
- `stockItem:id,tenant_id,code,name,short_name,barcode,product_type,weight_value,weight_unit,length_value,width_value,height_value,dimension_unit`
- `bundleComponents.componentStockItem:id,tenant_id,code,name`
- `defaultPackagingMaterial:id,code,name,type`
- `defaultShippingMethod:id,carrier_id,code,name,status`
- `defaultShippingMethod.carrier:id,code,name`

---

## Part 3: Logistics View (inline edit)

Columns:

| Column | Source | Editable as | Writes to |
|---|---|---|---|
| SKU | `sku` | read-only | - |
| Short name | `stockItem.short_name` | text input | the linked `stock_item` |
| Weight | `stockItem.weight_value` (+ `weight_unit` label) | number input | the linked `stock_item` |
| Length | `stockItem.length_value` (+ `dimension_unit` label) | number input | the linked `stock_item` |
| Width | `stockItem.width_value` | number input | the linked `stock_item` |
| Height | `stockItem.height_value` | number input | the linked `stock_item` |
| Default packaging | `sku.default_packaging_material_id` | dropdown | the `sku` |
| Default shipping method | `sku.default_shipping_method_id` | dropdown | the `sku` |

### Rendering rule

- Physical fields (short name, weight, L, W, H) and the two dropdowns are **always rendered as
  editable inputs/dropdowns**, pre-filled with the current value (empty input when null). This
  satisfies "fill in the nulls" and also lets staff correct existing values, without the awkward
  text-then-input toggle.
- Weight and dimension units (`g`, `cm`) are shown as static labels beside the inputs. v1 edits
  the numeric value only; do not make units editable here.

### Important: physical fields write to the shared stock item

`short_name`, `weight_value`, and the dimension values live on `stock_items`, not `skus`. Editing
them from this view updates the linked `stock_item`, which is shared by every SKU pointing at the
same stock item. This is correct (physical attributes belong to the product), but:

- After saving, other rows for the same stock item should reflect the new value on next render.
- For SKUs with **no** `stock_item_id` (for example `sku_type = virtual_bundle`), the physical
  fields have no target: render them disabled / `-` and do not attempt to save them. The two
  SKU-level dropdowns (packaging, shipping) remain editable for those SKUs.

### Save pattern

Mirror the existing inline-save pattern used for tracking numbers on the sales order index
(draft state + save on `blur` / `change`). Each editable field saves on `blur` (text/number) or
`change` (dropdowns) via a Livewire method.

Save rules:

- Re-scope every save: load the SKU (and its stock item) through the existing allowed-tenant
  scope and abort if out of scope. Do not trust the row id alone.
- Validation:
  - `short_name`: `nullable|string|max:255`
  - `weight_value`, `length_value`, `width_value`, `height_value`: `nullable|numeric|min:0`
  - `default_packaging_material_id`: `nullable|exists:packaging_materials,id`
  - `default_shipping_method_id`: nullable; allow active methods, or the SKU's existing saved
    inactive method if the value is unchanged.
- Empty input saves as `null` (clear), not `0`.
- Wrap stock-item field writes and SKU field writes so a single row edit is consistent.

---

## Part 4: Save Default View Per User

### Storage

There is no user-preferences storage yet. Add a JSON preferences column to `users`:

```php
Schema::table('users', function (Blueprint $table) {
    $table->json('preferences')->nullable()->after('remember_token');
});
```

`App\Models\User`:

- add `preferences` to the existing `#[Fillable([...])]` attribute. The helper below uses
  `$this->update(['preferences' => $prefs])`, so this column must be mass assignable. Alternatively,
  the helper may assign `$this->preferences = $prefs; $this->save();`, but do not leave it as a
  blocked mass-assignment write.
- cast `'preferences' => 'array'`.
- helpers:

```php
public function preference(string $key, mixed $default = null): mixed
{
    return data_get($this->preferences, $key, $default);
}

public function setPreference(string $key, mixed $value): void
{
    $prefs = $this->preferences ?? [];
    data_set($prefs, $key, $value);
    $this->update(['preferences' => $prefs]);
}
```

### Behavior

- `Set as default` on `/skus` calls `Auth::user()->setPreference('skus_view', $this->view)`.
  Only show/enable this button for an authenticated user. If there is no authenticated user,
  the page should simply use `detailed` and no preference write should be attempted.
- On `mount()`, resolve the active view in this order:
  1. valid `?view=` query parameter (shareable links win), else
  2. the user's saved `skus_view` preference, else
  3. `detailed`.
- Per-user, so internal and tenant users each keep their own default. A missing/guest user just
  gets `detailed`.

---

## Tenant scope and permissions

- Listing and all inline saves use the existing SKU tenant-scope helper. Internal users see all
  tenants; tenant users see only their own active tenant SKUs. Do not treat a guest as internal
  (use `$user?->user_type === 'internal'`).
- Inline edits must verify the SKU and its stock item are within the allowed tenant before
  writing.

---

## Language keys

Add to `lang/en/skus.php`:

- view labels: `view_detailed`, `view_catalog`, `view_marketplace`, `view_logistics`
- `btn_set_default_view`, `default_view_saved`
- column headers for the flat views: `col_name`, `col_short_name`, `col_seller_sku`, `col_asin`,
  `col_fnsku`, `col_variant`, `col_weight`, `col_length`, `col_width`, `col_height`,
  `col_default_packaging`, `col_default_shipping_method`, `col_type`, `col_status`
- form: `field_default_shipping_method`
- inline-save success/validation messages as needed

For `zh_TW`, `zh_CN`, `ja`, follow the existing locale pattern: empty `return [];` stubs relying
on `fallback_locale = en` if that is what the other files do. Do not copy English values into
every locale.

---

## Tests

Add/update `tests/Feature/SkuManagementTest.php` (and a focused view test file if cleaner).

Default shipping method:

1. Migration adds `default_shipping_method_id`; SKU can store and read it via the relation.
2. SKU create form renders the default shipping method dropdown.
3. Saving a SKU with a default shipping method persists the FK.
4. Invalid `default_shipping_method_id` is rejected.
5. Create form rejects an inactive shipping method.
6. Logistics view shows a SKU's currently saved inactive shipping method but does not allow
   changing another SKU to that inactive method.

Views:

7. `view=catalog` renders the catalog columns and does not render the grouped platform-ids column.
8. `view=marketplace` renders SKU/Seller SKU/ASIN/FNSKU/Variant/Shop.
9. `view=logistics` renders editable inputs for short name/weight/L/W/H and dropdowns for
   packaging and shipping method.
10. Unknown `view` value falls back to `detailed`.
11. Empty-state colspan matches the current view's column count.

Logistics inline edit:

12. Editing weight/L/W/H/short name saves to the linked `stock_item`.
13. Editing default packaging and default shipping method saves to the `sku`.
14. A `virtual_bundle` SKU (no stock item) does not allow editing physical fields, but still
    allows editing the two SKU-level dropdowns.
15. Negative weight/dimension is rejected.
16. Empty input clears the field to null (not 0).
17. A tenant user cannot inline-edit another tenant's SKU or stock item.

Default view persistence:

18. `Set as default` stores `skus_view` in the user's preferences.
19. On load with no `?view=`, the saved preference is used; with `?view=`, the query parameter
    wins; with neither, `detailed` is used.
20. The saved preference still applies when the component property default is `detailed`; test this
    through a plain `/skus` request with no `view` query string.

Guard:

21. Guest users are not treated as internal by the SKU tenant-scope helper.

Run:

```bash
php artisan test tests/Feature/SkuManagementTest.php
php artisan test
```

If `php` is not on PATH (Laragon):

```bash
C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan test tests/Feature/SkuManagementTest.php
```

---

## Acceptance Criteria

- SKUs have a `default_shipping_method_id`, editable on the SKU form and in the Logistics view.
- `/skus` keeps the current Detailed view and adds Catalog, Marketplace, and Logistics views.
- Flat views show one field per column with no combined columns.
- The Logistics view edits physical fields (short name, weight, L, W, H) on the linked stock item
  and the two defaults on the SKU, with tenant scope enforced and validation applied.
- Virtual-bundle SKUs handle the no-stock-item case gracefully.
- Each user can set and persist their default view; URL `?view=` overrides for shared links.
- Tenant scope is enforced everywhere. Tests pass.

---

## Future Phases

- **Order defaulting**: optionally pre-fill a sales/outbound order's shipping method from a SKU's
  default. Needs a product decision for multi-line orders.
- **Editable units**: allow editing weight/dimension units in the Logistics view.
- **Bulk fill**: select multiple SKUs and set packaging/shipping/dimensions in one action.
- **Stock metrics view**: a stock-quantity-per-SKU view that joins `inventory_balances` (belongs
  closer to the Inventory page; join-heavy, separate task).
