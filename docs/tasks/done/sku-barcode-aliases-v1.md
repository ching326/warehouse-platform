# SKU / Stock Item Barcode Aliases v1

## Goal

Allow one SKU / stock item to be scanned by multiple barcode values.

The pack station currently matches scans against:

- `skus.barcode`
- `stock_items.barcode`
- `skus.sku`

In real warehouse work, the same sellable item may have several valid scan codes:

- manufacturer JAN / EAN / UPC
- Amazon FNSKU
- platform label code
- internal warehouse label
- supplier label
- old barcode from previous packaging

This task adds barcode aliases so pack check can accept all known valid scan codes without forcing staff to relabel every product immediately.

## Scope

Build:

1. `barcode_aliases` table.
2. Eloquent model and relationships.
3. Basic CRUD/editing from SKU detail / SKU edit surface.
4. Pack scan matching against aliases.
5. Tenant-safe validation and tests.

Do not build:

- barcode coverage report
- bulk import/export of aliases
- barcode label printing
- camera scanning
- GS1 parser
- automatic Amazon FNSKU sync

## Data Model

Create table:

```text
barcode_aliases
-----------------------------
id
tenant_id
model_type        sku / stock_item
model_id
barcode
normalized_barcode
barcode_type      jan / ean / upc / fnsku / platform_label / internal_label / supplier_label / other / unknown
label             nullable, human note e.g. "Old package barcode"
is_active         boolean default true
created_at
updated_at
```

Indexes:

```text
unique: tenant_id + normalized_barcode
index: tenant_id + model_type + model_id
index: tenant_id + normalized_barcode + is_active
```

Why tenant-wide unique:

- During pack scan, one barcode must resolve to one tenant item only.
- If the same barcode points to two different items in one tenant, scan matching becomes ambiguous and unsafe.
- Cross-tenant duplicates are OK.

Use `model_type` string values, not PHP class names:

```php
sku
stock_item
```

Do not add a DB foreign key to `model_id` because it is polymorphic. Enforce ownership in application validation.

## Model

Create:

```text
app/Models/BarcodeAlias.php
```

Constants:

```php
public const MODEL_TYPE_SKU = 'sku';
public const MODEL_TYPE_STOCK_ITEM = 'stock_item';
```

Fillable:

- `tenant_id`
- `model_type`
- `model_id`
- `barcode`
- `normalized_barcode`
- `barcode_type`
- `label`
- `is_active`

Casts:

- `is_active` boolean

Relationships:

- `tenant()`

Add relationships:

```php
Sku::barcodeAliases()
StockItem::barcodeAliases()
```

Each relationship should constrain:

```php
where('model_type', 'sku')
whereColumn('model_id', 'skus.id')
```

or use a normal `hasMany()` with explicit foreign key plus `where('model_type', ...)`.

## Barcode Normalization

Add a small reusable helper/service, or extend `FulfillmentPackService::normalizeProductBarcode()`.

For v1:

- trim
- remove spaces
- remove hyphens
- uppercase

Examples:

```text
" 49-0123 4567894 " -> "4901234567894"
"x00abc123" -> "X00ABC123"
```

Important:

- Keep `barcode` as the original user-entered value.
- Store normalized value in `normalized_barcode`.
- Pack scan should compare normalized scan input against normalized stored values.

## CRUD / UI

Add barcode alias management to SKU area.

Preferred v1 placement:

- SKU detail/edit page if available
- otherwise add an "Aliases" action/section on SKU index/detail surfaces

Minimum UI:

For each SKU:

- show aliases attached to the SKU
- show aliases attached to its stock item
- add alias
- deactivate alias

Fields:

- barcode
- barcode type dropdown
- label/note
- active toggle
- target:
  - this SKU
  - linked stock item

Rules:

- tenant user can only manage own tenant aliases
- internal user can manage allowed tenant aliases
- cannot create alias for a SKU/stock item outside allowed tenant scope
- cannot create duplicate normalized barcode within the same tenant
- blank barcode rejected

Do not hard-delete aliases in v1. Use `is_active = false`.

## Pack Scan Matching

Update:

```text
app/Services/Fulfillment/FulfillmentPackService.php
```

Current `lineMatchesScan()` should also match active barcode aliases.

Matching candidates for a normal SKU line:

1. `sku.barcode`
2. `stock_item.barcode`
3. `sku.sku`
4. active aliases for that SKU
5. active aliases for the linked stock item

Matching candidates for virtual bundle component line:

1. component stock item barcode
2. active aliases for that component stock item

Do not match inactive aliases.

Important performance note:

Avoid N+1 queries when checking aliases.

Update `packLines()` / `loadMissing()` to include:

```php
orders.lines.sku.barcodeAliases
orders.lines.sku.stockItem.barcodeAliases
orders.lines.sku.bundleComponents.componentStockItem.barcodeAliases
```

Then `lineMatchesScan()` can check already-loaded aliases.

## Ambiguity Rule

The tenant-wide unique index prevents most ambiguity.

Still, pack line matching can find multiple lines if two required lines share the same stock item or alias. Existing pack logic already prefers a matching line with remaining quantity before over-scan. Keep that behavior.

Do not change the shared-stock matching logic.

## Existing Barcode Columns

Keep existing columns:

- `skus.barcode`
- `stock_items.barcode`

They remain the primary/default barcode fields.

`barcode_aliases` is for extra scan codes only.

Do not migrate existing barcode values into aliases in v1.

## Activity Log

If using activity log on `BarcodeAlias`, log safe fields:

- barcode
- barcode_type
- label
- is_active

No secrets involved.

## Tests

Add targeted tests.

Required:

1. can create active SKU barcode alias
2. can create active stock item barcode alias
3. duplicate normalized barcode in same tenant is rejected
4. same normalized barcode across different tenants is allowed
5. tenant user cannot create alias for another tenant SKU
6. inactive alias does not match pack scan
7. SKU alias matches pack scan
8. stock item alias matches pack scan
9. virtual bundle component stock item alias matches pack scan
10. alias scan records original scanned barcode and normalized barcode in `fulfillment_pack_scans`
11. existing direct `sku.barcode` scan still works
12. existing direct `stock_items.barcode` scan still works
13. existing `skus.sku` scan still works

Run targeted SKU / fulfillment pack tests only.

Do not rerun the full suite by default unless a broad regression concern appears.

## Acceptance Criteria

- Staff can scan an alias barcode during pack check.
- Aliases are tenant-safe.
- Duplicate aliases within one tenant are blocked.
- Inactive aliases are ignored.
- Existing barcode behavior still works.
- No inventory / fulfillment status behavior changes.
- Targeted tests pass.

