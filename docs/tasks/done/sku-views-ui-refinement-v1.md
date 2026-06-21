# Task: SKU Views UI Refinement v1

## Context

This is a follow-up to:

`docs/tasks/sku-views-and-default-shipping-method-v1.md`

The first implementation added SKU views and default shipping method support. This task only refines the `/skus` view layouts and the default-view control.

Do not change the database schema unless absolutely necessary. Do not remove the existing default shipping method feature.

---

## Goal

Make the SKU table views cleaner and more operational:

- Logistics view should show SKU and stock item separately, with tighter dimension columns.
- Marketplace view should only show the marketplace IDs that matter now.
- Catalog view should focus on product/catalog fields, not stock/shop linkage.
- Replace the `Set as default` button with a checkbox-style control.

---

## Part A: Logistics View Changes

Route example:

`/skus?view=logistics`

### Columns

Update the Logistics view columns to:

1. SKU
2. Stock item
3. Short name
4. Weight (g)
5. L (cm)
6. W (cm)
7. H (cm)
8. Packaging
9. Shipping method

### SKU column

- Show only `sku.sku`.
- Do not show `sku.name` in this column.
- Do not show stock item code inside this column anymore.

### Stock item column

Add a separate column immediately after SKU.

Display:

- `stockItem.code`
- `stockItem.name`

If no stock item, show `-` or the existing virtual-bundle/missing-stock-item display, but keep it compact.

### Weight and dimension columns

- Make Weight/L/W/H columns narrower than text columns.
- Column labels should include the unit:
  - `Weight (g)`
  - `L (cm)`
  - `W (cm)`
  - `H (cm)`
- Do not show `g` / `cm` inside each table cell anymore.
- The table cell should contain only the input.
- Keep numeric editing behavior the same.

### Packaging / Shipping method columns

Rename column headers:

- `Default packaging` -> `Packaging`
- `Default shipping method` -> `Shipping method`

Dropdown behavior:

- If the SKU value is null, keep the dropdown blank.
- Do not auto-select a default option just because the list has values.
- Keep the existing save behavior.
- Keep inactive saved method handling from the previous task.

### Column count

Update the Logistics empty-state colspan to match the new 9-column layout.

---

## Part B: Marketplace View Changes

Route example:

`/skus?view=marketplace`

Current Marketplace view includes too many columns.

Remove these columns:

- Seller SKU (`platform_sku`)
- Variant (`platform_variant_name`)

Keep only:

1. SKU (`sku`)
2. ASIN (`platform_product_id`)
3. FNSKU (`platform_label_code`)
4. Shop (`shop.code`)

For null values, keep showing `-`.

Update empty-state colspan for Marketplace view to 4.

---

## Part C: Catalog View Changes

Route example:

`/skus?view=catalog`

Current Catalog view still includes stock/shop linkage fields. Simplify it.

Remove these columns:

- Stock item code
- Stock short name
- Shop

Catalog view should show:

1. SKU (`sku`)
2. Name (`sku.name`)
3. Brand (`stockItem.brand`)
4. Variation code (`stockItem.variation_code`)
5. Barcode (`stockItem.barcode`)
6. Type (`sku_type`)
7. Status (`status`)

For null values, show `-`.

Update eager loading if needed:

- `stockItem.brand`
- `stockItem.variation_code`
- `stockItem.barcode`

Update empty-state colspan for Catalog view to 7.

---

## Part D: Set Default View Control

Replace the current `Set as default` button style with a checkbox-style control.

### UI

Instead of a button, show a compact checkbox near the view switcher:

```text
[ ] Use this view as my default
```

When the current view is already the user's saved default, the checkbox should be checked.

### Behavior

- Checking the checkbox saves the current view as `skus_view` preference.
- Unchecking the checkbox clears only the `skus_view` preference, so future visits fall back to `detailed` unless `?view=` is supplied.
- The checkbox should only show for authenticated users.
- Do not show it for guests.

### Query string precedence remains unchanged

- `?view=` still wins over saved preference.
- The checkbox state should reflect whether the current view equals the saved preference.
- Example: if saved default is `catalog` but URL is `?view=logistics`, the checkbox should be unchecked until the user checks it.

### Suggested method names

You can implement with methods like:

```php
public bool $currentViewIsDefault = false;

public function updatedCurrentViewIsDefault(bool $checked): void
{
    if ($checked) {
        Auth::user()?->setPreference('skus_view', $this->view);
        return;
    }

    Auth::user()?->forgetPreference('skus_view');
}
```

If adding `forgetPreference()` to `User`, make it generic and safe:

```php
public function forgetPreference(string $key): void
{
    $prefs = $this->preferences ?? [];
    data_forget($prefs, $key);
    $this->update(['preferences' => $prefs === [] ? null : $prefs]);
}
```

If you do not add `forgetPreference()`, implement equivalent safe clearing in `SkusIndex`.

---

## Part E: Tests

Add/update SKU tests.

1. Logistics view SKU column does not show SKU name inside the SKU cell.
2. Logistics view has a separate Stock item column next to SKU.
3. Logistics view headers show `Weight (g)`, `L (cm)`, `W (cm)`, `H (cm)`.
4. Logistics view cells do not render separate `g` / `cm` unit labels beside every input.
5. Logistics view uses `Packaging` and `Shipping method` headers, not `Default packaging` / `Default shipping method`.
6. Logistics view keeps dropdown blank when SKU has null packaging/shipping method.
7. Marketplace view does not render Seller SKU or Variant columns.
8. Marketplace view renders only SKU, ASIN, FNSKU, Shop columns.
9. Catalog view does not render Stock item, Short name, or Shop columns.
10. Catalog view renders Brand, Variation code, Barcode, Type, Status.
11. Empty-state colspan is correct for logistics, marketplace, and catalog views.
12. Default-view checkbox saves the current view as user preference.
13. Unchecking the default-view checkbox clears `skus_view` preference.
14. If URL `?view=` differs from saved preference, checkbox is unchecked until user saves current view.

Run:

```bash
php artisan test tests/Feature/SkuManagementTest.php
php artisan test
```

If `php` is not on PATH:

```bash
C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan test tests/Feature/SkuManagementTest.php
```

---

## Acceptance Criteria

- Logistics view has separate SKU and Stock item columns.
- SKU column no longer shows SKU name.
- Weight/dimension unit labels are in headers only.
- Weight/L/W/H columns are visually narrower.
- Packaging and Shipping method headers no longer say Default.
- Marketplace view removes Seller SKU and Variant.
- Catalog view removes stock/shop linkage fields and adds Brand, Variation code, Barcode.
- Default view saving uses a checkbox-style control, not a normal action button.
- Tests pass.
