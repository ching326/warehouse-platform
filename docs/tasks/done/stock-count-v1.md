# Stock Count v1

## Naming Decision

Use **Stock Count** as the page/module name.

Reason:

- It is clearer for tenants and warehouse staff than "Stocktake".
- It describes the action directly: count physical stock and enter the actual quantity.
- "Stocktake" can still appear in helper text later if needed, but the nav/page title should be "Stock Count".

Internal names should use `stock_count`.

## Goal

Add a dedicated Stock Count page for setting actual counted quantity.

This is different from Stock Adjustment:

- Stock Adjustment: user enters how much to add or deduct.
- Stock Count: user enters the actual counted on-hand quantity, and the system calculates the adjustment delta.

Examples:

- Current on-hand = 10, counted qty = 13 -> system creates +3 adjustment.
- Current on-hand = 10, counted qty = 7 -> system creates -3 adjustment.
- Current on-hand = 10, counted qty = 10 -> no movement, but record the count.

## Scope

V1 must support:

- Manual one-line stock count form for daily small corrections.
- Import flow for large stock counts after warehouse count.
- Actual quantity only.
- Tenant and warehouse selection.
- Stock item lookup by system code, tenant item code, SKU, or barcode.
- Preview before posting.
- Audit record of each stock count batch.
- Inventory changes through `InventoryService::adjustStock()` only.

## Non-Goals

Do not implement in v1:

- Add qty / Deduct qty import. That belongs to Stock Adjustment Import.
- Location-level counting.
- Cycle count scheduling.
- Count assignment to staff.
- Approval workflow.
- Variance tolerance approval.
- Frozen inventory snapshot / warehouse lock.
- Blind count mode.
- Mobile camera scan count.

## Navigation / Routes

Add a new Inventory nav item:

- Stock Count

Routes:

- `GET /inventory/stock-counts`
- `GET /inventory/stock-counts/create`
- `GET /inventory/stock-counts/import`
- `GET /inventory/stock-counts/{stockCountRun}`

If route naming is preferred:

- `stock-counts.index`
- `stock-counts.create`
- `stock-counts.import`
- `stock-counts.show`

## Page Structure

### Index Page

Show recent stock count runs.

Columns:

- Count no. or run ID
- Tenant
- Warehouse
- Source: Manual / Import
- Total lines
- Adjusted lines
- No-change lines
- Failed lines
- Created by
- Created at
- Posted at

Actions:

- New Stock Count
- Import Stock Count

### Manual Create Page

Inputs:

- Tenant
- Warehouse
- Stock item async/search picker
- Counted qty
- Note

Rules:

- Counted qty is required, integer, min 0.
- Stock item is required.
- Tenant is required for internal users.
- Warehouse is required.
- Warehouse dropdown shows active warehouses only.
- If there is only one active warehouse, auto-select it using the existing shared behavior.
- Stock item picker must be tenant-scoped.

When a stock item is selected, show current balance:

- On-hand qty
- Reserved qty
- Hold qty
- Damaged qty
- Available qty

Before saving, show a simple preview:

- Current on-hand
- Counted qty
- Delta

On save:

- Create a stock count run with source `manual`.
- Create one stock count line.
- If delta is 0, do not call `InventoryService::adjustStock()`.
- If delta is not 0, call `InventoryService::adjustStock()` with that delta.
- Redirect to the stock count run detail page.

### Import Page

Follow SKU import's interface and flow:

- Upload
- Map columns
- Preview
- Confirm
- Result
- Saved templates
- Default template per tenant

Do not invent a separate import UI style.

Upload fields:

- Tenant
- Warehouse
- File

File types:

- `.csv`
- `.txt`
- `.xlsx`
- `.xls`

Required mapped fields:

- Identifier
- Counted qty

Optional mapped fields:

- Line note
- Reference no.

Identifier may match:

- `stock_items.code`
- `stock_items.tenant_item_code`
- `skus.sku`
- active `barcode_aliases.barcode`

Matching rules:

- Match only within selected tenant.
- Barcode matching must use normalized barcode behavior.
- No match = row error.
- Multiple matches = row error.
- Duplicate stock item in the same import = row error. Do not merge silently in v1.

Row cap:

- Use the same row cap as SKU import unless there is a strong reason to lower it.

Stored file:

- Store uploaded file in private/local storage during the flow.
- Do not rely on Livewire temporary file path after the upload step.

## Data Model

### `stock_count_runs`

Columns:

- `id`
- `tenant_id`
- `warehouse_id`
- `source` enum/string: `manual`, `import`
- `file_name` nullable
- `total_lines` integer default 0
- `adjusted_lines` integer default 0
- `no_change_lines` integer default 0
- `failed_lines` integer default 0
- `note` nullable text
- `created_by_user_id` nullable
- `posted_at` nullable datetime
- timestamps

FKs:

- tenant constrained cascade delete
- warehouse constrained restrict delete
- created_by_user_id constrained users null on delete

### `stock_count_lines`

Columns:

- `id`
- `stock_count_run_id`
- `tenant_id`
- `warehouse_id`
- `stock_item_id`
- `identifier_raw` nullable string
- `counted_qty` integer
- `previous_on_hand_qty` integer
- `delta_qty` integer
- `movement_id` nullable FK to `inventory_movements`
- `line_note` nullable text
- `reference_no` nullable string
- `status` enum/string: `adjusted`, `no_change`, `failed`
- `error_message` nullable text
- timestamps

FKs:

- stock_count_run constrained cascade delete
- tenant constrained cascade delete
- warehouse constrained restrict delete
- stock_item constrained restrict delete
- movement_id constrained inventory_movements null on delete

Index suggestions:

- `(tenant_id, warehouse_id, stock_item_id)`
- `(stock_count_run_id, status)`

### `stock_count_import_mappings`

Same pattern as SKU import mappings.

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

## Posting Logic

Create a service, for example:

- `StockCountPostingService`

The service should handle both manual and import posting.

For each valid line:

1. Lock or re-load the inventory balance through `InventoryService::adjustStock()`.
2. Read current on-hand qty immediately before posting.
3. Calculate:

```php
$deltaQty = $countedQty - $currentOnHandQty;
```

4. If `$deltaQty === 0`, save the line as `no_change` and do not create an inventory movement.
5. If `$deltaQty !== 0`, call:

```php
InventoryService::adjustStock(
    tenantId: $tenantId,
    warehouseId: $warehouseId,
    stockItemId: $stockItemId,
    quantityDelta: $deltaQty,
    context: [
        'ref_type' => 'stock_count',
        'ref_id' => (string) $run->id,
        'user_id' => Auth::id(),
        'note' => $movementNote,
    ],
);
```

6. Save the created movement ID on the stock count line.

Important:

- Do not write `inventory_balances` directly.
- Do not create a new inventory movement type in v1 unless there is already a clear need.
- Use `InventoryMovement::TYPE_ADJUST` through `adjustStock()`.

## Reserved / Hold / Damaged Constraint

Existing `InventoryService::adjustStock()` recalculates:

```php
available = on_hand - reserved - hold - damaged
```

and blocks negative available qty.

Therefore, Stock Count cannot post a counted qty lower than:

```php
reserved_qty + hold_qty + damaged_qty
```

In preview, show this as a row error:

> Counted quantity is lower than reserved/hold/damaged stock.

Confirm must re-check this because balances may change between preview and confirm.

## Preview Rules

Preview should show:

- row no.
- raw identifier
- resolved stock item code
- tenant item code
- stock item name
- current on-hand qty
- reserved qty
- hold qty
- damaged qty
- counted qty
- delta qty
- status / errors

Rows with errors must block confirm.

Preview is not final. Confirm must re-read and revalidate the file.

## Detail Page

Show one stock count run.

Header:

- Tenant
- Warehouse
- Source
- Created by
- Posted at
- Totals

Lines table:

- Stock item
- Tenant item code
- Previous on-hand
- Counted qty
- Delta
- Movement link / movement ID
- Status
- Note
- Reference no.

## Access Control

Follow current tenant-scope patterns.

- Internal users can access all tenants.
- Tenant users can only access their active tenant(s).
- Tenant users cannot view/import/post another tenant's stock count.
- Route-model binding must be re-scoped in component `mount()` or controller logic.
- Do not treat guest users as internal.

## UI Copy

Add English lang keys only during implementation.

Do not edit CJK lang files in this task. Add a row to:

- `docs/translation-backlog.md`

Suggested labels:

- Stock Count
- New Stock Count
- Import Stock Count
- Counted qty
- Current on-hand
- Delta
- No change
- Posted
- Counted quantity is lower than reserved/hold/damaged stock.

## Tests

Add focused tests.

Required:

1. Internal user can open stock count pages.
2. Tenant user can only see/post own tenant stock counts.
3. Manual stock count with counted qty greater than current on-hand creates positive adjust movement.
4. Manual stock count with counted qty lower than current on-hand creates negative adjust movement.
5. Manual stock count with same qty creates a no-change line and no movement.
6. Manual stock count blocks counted qty lower than reserved + hold + damaged.
7. Import page follows upload/map/preview/confirm flow.
8. Import resolves identifier by stock item system code.
9. Import resolves identifier by tenant item code.
10. Import resolves identifier by SKU code.
11. Import resolves identifier by active barcode alias.
12. Import blocks cross-tenant identifier.
13. Import blocks ambiguous identifier.
14. Import blocks duplicate stock item rows.
15. Import preview shows delta qty.
16. Import confirm revalidates if balance changed after preview.
17. Import creates one stock count run and lines.
18. Import movements use `ref_type = stock_count` and `ref_id = run id`.
19. Import mapping template can be saved, loaded, deleted, and set as default within tenant scope.
20. "Set actual qty" does not appear on Stock Adjustment Import.

Recommended targeted commands:

```bash
php artisan test tests/Feature/StockCountTest.php
php artisan test tests/Feature/StockCountImportTest.php
vendor/bin/pint --dirty
```

Do not run the full suite by default unless targeted tests indicate a broader issue.
