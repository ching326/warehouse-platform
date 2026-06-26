# Task: SKU CSV/Excel Import with Field Mapping v1

## Stack

Laravel 13, Livewire 4 (class-based, not Volt), Flux UI (`livewire/flux ^2.14`), PHP 8.3.
`maatwebsite/excel ^3.1` is already installed (reads CSV and xlsx). SQLite dev/test. Plain Blade.

ASCII punctuation only in code, comments, and this doc. No em-dashes, smart quotes, or unicode arrows.
CJK in translation VALUES is fine.

---

## Goal

Let a user bulk-import SKUs from a CSV or Excel file they upload. The user uploads first, then maps
their own column headers to our fields (the mapping UI is built from the file's real headers), and can
save that mapping as a reusable named template. Insert-only vs upsert is chosen on the preview step.

This is a new pattern. The existing `SalesOrderImport` uses fixed required headers; this feature is
user-driven column mapping and must not reuse that fixed-header path.

---

## Flow (upload first, then map)

1. **Upload step.** Internal users pick a tenant; tenant users are fixed to their tenant. Optional
   shop select. Upload `.csv` / `.xlsx` / `.xls`. We read the header row plus the first ~5 data rows
   for preview. Reject empty files and files over the row cap (see decisions).
2. **Map step.** For each importable field, show a dropdown populated from THIS file's headers, plus an
   "ignore" option. Auto-guess pre-fills the mapping (see Auto-guess). The user can load a saved
   template or adjust manually. Show a live preview of the sample rows as they would import.
3. **Preview + options step.** Validate every row. Show a preview table with per-row status and errors.
   The user chooses:
   - **insert-only** (skip rows whose SKU already exists) or **upsert** (update existing),
   - **skip-invalid** (import only valid rows) or **cancel**,
   - optionally **save the current mapping** as a named template.
   Then confirm.
4. **Result step.** Show counts: created / updated / skipped / failed. Offer a downloadable error
   report (row number, column, message).

---

## Locked decisions

1. **Insert vs upsert:** chosen by the user on the preview step. Upsert matches on `sku` within the
   tenant (and shop, when a shop is set). Insert-only counts existing matches as "skipped".
2. **Stock item (v1):** single SKUs only.
   - If a `stock_item_code` column is mapped and matches an existing stock item in the tenant, link to
     it (and, on upsert, update that stock item from the mapped stock-item columns, mirroring SkuEdit).
   - Otherwise create a new stock item from the mapped stock-item columns, auto-coded via the existing
     `nextStockItemCode` pattern.
   - Virtual and physical bundles are OUT of scope (components do not fit a flat row).
3. **Sync vs queued:** synchronous, capped at ~2000 data rows, processed in chunks inside a DB
   transaction. Over the cap is rejected with a "split your file" message. Queued/async is a future
   enhancement.
4. **Invalid rows:** validate all rows up front, preview with per-row errors, user chooses skip-invalid
   or cancel. Provide a downloadable CSV error report.

---

## Field catalog (single source of truth)

`App\Support\SkuImport\SkuImportField` (value object) and `App\Support\SkuImport\SkuImportFields::all()`
returning the importable fields. Each field defines:

- `key` (internal field name, e.g. `sku`, `name`, `stock_item_code`)
- `label` (translation key, reuse `skus.*` where possible)
- `target` (`sku` or `stock_item`)
- `required` (bool; only `sku` and `name` are required)
- `rule` (Laravel validation rule fragment)
- `cast` (`string` | `decimal` | `bool` | `enum`)
- `aliases` (array of common header strings for auto-guess)

This one catalog drives the mapping dropdowns, the row validation, and the auto-guess. Adding a field
later is a single entry here.

Fields to include (from `Sku` and `StockItem` fillable):

- SKU target: `sku` (required, unique per tenant+shop), `name` (required), `name_ja`, `name_zh_tw`,
  `name_zh_cn`, `platform_sku`, `platform_product_id`, `platform_variant_id`, `platform_variant_name`,
  `platform_label_code`, `status` (enum), `note`. (`sku_type` is fixed to `single` in v1; do not import.)
- Stock item target: `stock_item_code` (link key; not written), `short_name`, `brand`, `model_number`,
  `variation_code`, `color`, `size`, `barcode`, `barcode_type` (enum), `product_type` (slug, validate
  against `product_types`), `is_dangerous_goods` (bool), `requires_expiry_tracking` (bool),
  `requires_lot_tracking` (bool), `weight_value` (decimal), `weight_unit`, `length_value`,
  `width_value`, `height_value` (decimal), `dimension_unit`, `description`, `note`, `handling_note`,
  `status` (enum). Stock-item localized names: `si_name_ja`, `si_name_zh_tw`, `si_name_zh_cn`.

`default_packaging_material_id` and `default_shipping_method_id` are FK-by-id today; for import accept
an optional `default_packaging_code` / `default_shipping_method_code` resolved to ids, or omit from v1.
Recommend omit from v1 to keep scope tight.

### Auto-guess

For each field, match file headers (trim + lowercase, ignore spaces/underscores) against: the field
`key`, its translated `label` in all four locales, and its `aliases`. First match wins. Leave unmatched
fields on "ignore".

---

## Saved mapping templates

Migration `create_sku_import_mappings_table`:

- `id`, `tenant_id` (fk, indexed), `name` (string), `mapping` (json: field key => file header string or
  null), `created_by_user_id` (nullable fk), timestamps. Unique (`tenant_id`, `name`).

Model `App\Models\SkuImportMapping` (tenant-scoped, fillable, optional LogsActivity). Store the mapping
by header NAME (not column index) so it re-applies to any future file with the same headers. Load /
save / delete on the map step, scoped to the selected tenant.

---

## Shared writer (refactor to avoid duplication)

Extract the create/upsert logic into `App\Services\SkuImport\SkuWriter`:

```
upsert(int $tenantId, ?int $shopId, array $skuData, array $stockItemData, bool $allowUpdate): SkuWriteResult
```

It resolves/creates the stock item (per decision 2), creates or updates the SKU, and returns whether it
created, updated, or skipped. Reuse the existing `nextStockItemCode` logic and nullable helpers.

`SkuCreate` (and ideally `SkuEdit`) should be refactored to call `SkuWriter` so the form and the
importer share one set of rules and tenant scoping. Minimum bar: the importer uses `SkuWriter`. The
form refactor is recommended in this task but can be a fast-follow if it risks scope.

---

## Reader

`App\Services\SkuImport\SkuImportReader` wrapping Laravel Excel:

- `headers(string $path): array` -- first row as trimmed strings (use `HeadingRowImport` or read row 0).
- `rows(string $path, ?int $limit = null): array` -- data rows as positional arrays.
- Handle CSV delimiter detection and BOM stripping (see the existing BOM handling in
  `SalesOrderImport`). Support `.csv`, `.xlsx`, `.xls`.

Map field -> header -> column index once, then build each row's sku/stock-item payload by index.

---

## Validation

For each row, build the sku and stock-item payloads from the mapping and validate with the catalog
rules plus:

- `sku` required and unique per tenant (+shop). In upsert mode an existing match is allowed (update);
  in insert-only mode it is skipped, not an error.
- duplicate `sku` WITHIN the uploaded file is an error.
- enum fields (`status`, `barcode_type`, `product_type`) validated against allowed values.
- decimals non-negative.

Collect errors keyed by row number for the preview and the downloadable report.

---

## Auth and tenant scope

Internal users (`user_type === 'internal'`) may import for any tenant; tenant users are limited to
`activeTenantIds()`. The selected shop must belong to the tenant. All template queries are tenant-scoped.

---

## Files

- route `GET /skus/import` -> `SkuImport`
- `app/Livewire/SkuImport.php` + `resources/views/livewire/sku-import.blade.php`
- `app/Support/SkuImport/SkuImportField.php`, `SkuImportFields.php`
- `app/Services/SkuImport/SkuImportReader.php`
- `app/Services/SkuImport/SkuWriter.php` (+ `SkuWriteResult`)
- migration `..._create_sku_import_mappings_table.php`
- `app/Models/SkuImportMapping.php`
- lang keys in `lang/{en,ja,zh_TW,zh_CN}/skus.php` (or a new `sku_import.php`)
- "Import" button on `resources/views/livewire/skus-index.blade.php`
- tests

---

## Tests

- reader parses CSV and xlsx headers and rows; handles BOM and delimiter
- auto-guess maps known headers (including a Japanese-header sample)
- mapping resolves field -> column correctly; "ignore" leaves field unset
- insert-only skips existing SKUs; upsert updates them
- new stock item created when no `stock_item_code`; linked when code matches
- invalid rows reported and excluded under skip-invalid; valid rows imported
- duplicate-sku-within-file flagged
- row cap enforced
- tenant scope enforced (tenant user cannot import for another tenant)
- saved template: create, load, apply

---

## Verification

```
php artisan migrate
vendor/bin/pint --dirty
php vendor/bin/phpstan analyse --memory-limit=512M
php artisan test
```

---

## Out of scope (v1)

- virtual / physical bundle components
- queued / async import for very large files
- product image import
- default packaging / shipping method by code (unless trivial)
