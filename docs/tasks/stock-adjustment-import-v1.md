# Stock Adjustment Import v1

## Goal

Add a bulk stock adjustment import flow for warehouse/admin users.

This is a bulk version of the existing manual stock adjustment page. It must support only:

- Add qty
- Deduct qty

Do not implement "Set actual qty" in v1. Stock count / actual-quantity reconciliation is a different workflow and should be a later task.

## Existing Code To Reuse

- Single adjustment page: `StockAdjustmentCreate`
- Inventory write path: `InventoryService::adjustStock()`
- Existing reason lists:
  - Add: `found_stock`, `correction`, `return_to_stock`, `supplier_replacement`, `other`
  - Deduct: `lost_missing`, `package_damage`, `product_damage`, `write_off`, `correction`, `internal_use`, `sample_demo_units`, `marketing_giveaways`, `other`
- SKU import UI pattern:
  - upload
  - map columns
  - preview
  - confirm
  - saved mapping templates
  - default template per tenant

The import must not update `inventory_balances` directly. Every row must go through `InventoryService::adjustStock()` so movement audit data stays consistent.

## Entry Points

Add an Import button on:

- `/stock-adjustments/create`

The button opens:

- `/stock-adjustments/import`

The import page should follow the SKU import layout and behavior as closely as possible:

- same stepper style
- same drag/drop upload zone
- same mapping table style
- same saved template UI
- same preview/result structure

## Access Control

Use the same tenant-scope pattern as `StockAdjustmentCreate`.

- Internal users can select any active tenant.
- Tenant users can only import for their active tenant(s).
- Guests are never internal users.
- A tenant user must not be able to load, save, set default, delete, preview, or confirm another tenant's mapping/template/import.

## Upload Step

Required inputs:

- Tenant
- Warehouse
- Action: Add qty or Deduct qty
- Reason
- File

Rules:

- Action has no default value.
- Reason has no default value.
- Reason options depend on the selected action, same as the manual page.
- Warehouse dropdown must show active warehouses only.
- If the system has only one active warehouse, auto-select it using the existing shared behavior.
- If user has a saved default warehouse preference, follow existing stock adjustment behavior.
- Accept the same file types as SKU import: `.csv`, `.txt`, `.xlsx`, `.xls`.
- Use the same row cap as SKU import unless there is a strong reason to change it.
- Store the uploaded file in private/local storage during the flow, same as SKU import. Do not rely on temporary Livewire upload paths after the upload step.

## Mapping Step

Create a mapping helper similar to `SkuImportFields`.

Field options:

- Identifier
- Quantity
- Line note
- Reference no

Required mapped fields:

- Identifier
- Quantity

Identifier matching should support these values:

- `stock_items.code`
- `stock_items.tenant_item_code`
- `skus.sku`
- active `barcode_aliases.barcode`

Matching rules:

- Match only inside the selected tenant.
- Barcode matching must use the same normalized barcode behavior used elsewhere in the system.
- If an identifier matches no stock item, preview row is an error.
- If an identifier matches more than one stock item, preview row is an error.
- If multiple file rows resolve to the same stock item, block the import in v1. Do not merge rows silently.

No auto-detect is required beyond the existing simple SKU-import style. If default templates exist for the tenant, load the default template after file upload.

## Saved Templates

Create a new table:

`stock_adjustment_import_mappings`

Columns:

- `id`
- `tenant_id`
- `name`
- `mapping` json
- `is_default` boolean default false, indexed
- `created_by_user_id` nullable
- timestamps

Constraints:

- unique `(tenant_id, name)`
- tenant FK cascade delete
- user FK null on delete

Behavior:

- Load template
- Save template
- Set default template
- Delete template
- Only one default template per tenant
- When tenant is selected and a default template exists, auto-load it after upload/read-file

## Preview Step

Preview must show enough information for the user to catch mistakes before changing stock.

Recommended columns:

- Row no.
- Raw identifier
- Resolved stock item code
- Tenant item code
- Stock item name
- Current on-hand qty
- Current available qty
- Import qty
- Resulting on-hand qty
- Reason
- Line note / reference no
- Status / errors

Validation:

- Quantity must be a positive integer.
- Add qty produces a positive adjustment.
- Deduct qty produces a negative adjustment.
- Deduct qty must not make on-hand qty negative. This should be shown as a preview error and also rechecked during confirm.
- Deduct can reduce reserved stock's backing on-hand only if `InventoryService::adjustStock()` allows it. Do not bypass the service. If the service throws, show the row as failed.
- Rows with errors block confirm.

Important: preview is informational only. Confirm must re-read/re-evaluate the file and re-check current balances because stock may change between preview and confirm.

## Confirm Step

On confirm:

1. Re-read the stored import file.
2. Rebuild row mapping.
3. Re-resolve every identifier inside the selected tenant.
4. Re-check duplicate resolved stock items.
5. Re-check quantity and available/on-hand constraints.
6. If any row is invalid, do not partially apply the import.
7. If all rows are valid, apply all rows inside a DB transaction.
8. For each row, call:

```php
InventoryService::adjustStock(
    tenantId: $tenantId,
    warehouseId: $warehouseId,
    stockItemId: $stockItemId,
    quantityDelta: $signedQty,
    context: [
        'ref_type' => 'stock_adjustment_import',
        'ref_id' => (string) $run->id,
        'user_id' => Auth::id(),
        'note' => $movementNote,
    ],
);
```

Create an import run table so imported adjustments can be audited as one batch.

`stock_adjustment_import_runs`

Columns:

- `id`
- `tenant_id`
- `warehouse_id`
- `action` (`add` or `deduct`)
- `reason`
- `note` nullable
- `file_name` nullable
- `total_rows`
- `adjusted_rows`
- `failed_rows`
- `created_by_user_id` nullable
- `confirmed_at` nullable datetime
- timestamps

FK behavior:

- tenant constrained cascade delete
- warehouse constrained restrict delete
- user null on delete

The movement note should include:

- reason label
- optional global note
- optional line note
- optional reference no

## Result Step

Show:

- total rows
- adjusted rows
- failed rows
- link back to inventory
- link back to stock adjustment create page

Success toast should not include zero-value skipped text. For example, show "20 stock adjustments imported." instead of "20 imported, 0 skipped."

## UI Copy

Add new English lang keys only. Do not edit `lang/ja`, `lang/zh_TW`, or `lang/zh_CN` in this task.

Add a row to `docs/translation-backlog.md` noting that Stock Adjustment Import needs CJK translation later.

Suggested English labels:

- Import adjustments
- Upload adjustment file
- Map columns
- Preview adjustments
- Confirm import
- Identifier
- Quantity
- Line note
- Reference no.
- Add qty
- Deduct qty

## Non-Goals

Do not implement:

- Set actual qty
- Stock count reconciliation
- variance approval
- automatic warehouse/location guessing
- inventory location-level quantity changes
- scheduled imports
- import from Google Sheets or WeChat API

## Tests

Add focused tests. Do not run the full suite by default unless targeted tests suggest a broader issue.

Required tests:

1. Internal user can open stock adjustment import page.
2. Tenant user can only import for their own tenant.
3. Tenant user cannot load/save/delete/set-default another tenant's mapping template.
4. Upload step rejects missing tenant, warehouse, action, reason, or file.
5. Action dropdown has only Add qty and Deduct qty; Set actual qty is absent.
6. Reason options change based on Add vs Deduct.
7. Default template auto-loads after upload for the selected tenant.
8. Identifier resolves by stock item system code.
9. Identifier resolves by tenant item code.
10. Identifier resolves by SKU code.
11. Identifier resolves by active barcode alias.
12. Identifier from another tenant does not resolve.
13. Ambiguous identifier is blocked.
14. Duplicate resolved stock item rows are blocked.
15. Add import creates positive adjust movement and increases on-hand qty.
16. Deduct import creates negative adjust movement and decreases on-hand qty.
17. Deduct import is blocked if it would make on-hand qty negative.
18. Confirm revalidates after preview if stock changed before confirm.
19. Import movements use `ref_type = stock_adjustment_import` and `ref_id = run id`.
20. Import button appears on `/stock-adjustments/create`.

Recommended targeted commands:

```bash
php artisan test tests/Feature/StockAdjustmentTest.php
php artisan test tests/Feature/StockAdjustmentImportTest.php
vendor/bin/pint --dirty
```

Run Larastan only if this task introduces new service/model code that needs type verification.
