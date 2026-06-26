# Barcode Alias Canonical Model v1

## Goal

Make `barcode_aliases` the canonical home for all scannable barcodes.

After this change:

- Users manage barcodes in one barcode UI.
- `stock_items.barcode` and `skus.barcode` become legacy fallback fields, not the source of truth.
- Product-level barcodes are stored as stock-item aliases.
- Platform label codes such as Amazon FNSKU are stored as SKU aliases.
- `skus.platform_label_code` stays as a denormalized searchable/display mirror, synced from barcode aliases.
- Pack scan and search logic should eventually rely on `barcode_aliases`, with legacy fallback during migration.

This is the single task spec for the barcode redesign.

## Why

The current system has multiple barcode homes:

- `stock_items.barcode`
- `stock_items.barcode_type`
- `skus.barcode`
- `skus.platform_label_code`
- `barcode_aliases`

That creates confusing rules:

- Users may not know whether a barcode belongs to SKU or stock item.
- Scan matching has to check multiple columns and aliases.
- FNSKU sync becomes bidirectional/confusing.
- It is easy to accidentally duplicate or conflict with the same physical barcode.

The cleaner model is:

> Every scannable code is a barcode alias. Existing columns are legacy mirrors/fallbacks only.

## Canonical Rules

### Product Barcodes

Physical product barcodes should attach to the stock item.

Examples:

- JAN
- EAN
- UPC
- GTIN
- Old product barcode
- Supplier barcode
- Internal warehouse label

Store as:

```text
barcode_aliases.model_type = stock_item
barcode_aliases.model_id = stock_items.id
```

### Platform Label Barcodes

Platform label codes such as Amazon FNSKU should attach to the SKU.

Reason:

- FNSKU identifies a platform listing, not only the shared physical product.
- Two SKUs can share one stock item but have different FNSKUs.
- During pack scan, the system may need to match the exact SKU line, not only the stock item.

Store as:

```text
barcode_aliases.model_type = sku
barcode_aliases.model_id = skus.id
barcode_aliases.barcode_type = platform_label
barcode_aliases.source = amazon/import/manual
```

### Platform SKU Is Not a Barcode

Do not include `platform_sku` in this redesign.

`platform_sku` is the seller/listing SKU used by Amazon/Rakuten/Shopify. It remains a normal SKU column.

## Schema Direction

Use `barcode_aliases` as the canonical table.

Recommended fields:

- `tenant_id`
- `model_type`
- `model_id`
- `barcode`
- `normalized_barcode`
- `barcode_type`
- `source`
- `label`
- `is_primary`
- `is_active`

Use these classification fields:

- `barcode_type` = what kind of barcode it is
- `source` = where it came from
- `is_primary` = whether it is the primary barcode for that model

Important:

- `source` is not a user-facing choice; the system sets it.
- `source != manual` is enough to identify system/import/platform-managed records when needed.

Suggested `barcode_type` values:

- `jan`
- `ean`
- `upc`
- `gtin`
- `platform_label`
- `internal`
- `supplier`
- `carton`
- `other`

Suggested `source` values:

- `manual`
- `import`
- `amazon`
- `rakuten`
- `shopify`
- `system`

## Keep `skus.platform_label_code` As A Mirror

Do not drop `skus.platform_label_code` in v1.

It is currently used for:

- Display in inventory/SKU/order create pages
- Search with simple `LIKE`
- Existing index/search performance

Instead, make it a synced mirror:

```text
canonical value: barcode_aliases where model_type = sku and barcode_type = platform_label
mirror value: skus.platform_label_code
```

Projection rule:

- If SKU has one active primary `platform_label` alias, mirror that alias's `barcode`.
- If SKU has one active `platform_label` alias and none is primary, mirror that alias's `barcode`.
- If SKU has multiple active `platform_label` aliases, mirror the primary one.
- If no active `platform_label` alias exists, set `skus.platform_label_code = null`.

Important:

- Do not let users edit `platform_label_code` directly after this migration.
- The barcode alias is canonical.
- `platform_label_code` is read-only projection/search cache.

## Legacy Columns

Do not remove these columns in v1:

- `stock_items.barcode`
- `stock_items.barcode_type`
- `skus.barcode`
- `skus.platform_label_code`

But stop using them as write targets, except for the platform-label mirror.

### Phase 1 Behavior

- New UI writes barcodes to `barcode_aliases`.
- Pack scan checks `barcode_aliases` first.
- Legacy columns remain as fallback reads.
- Existing pages still display current data safely.

### Phase 2 Behavior

Run a data migration/backfill:

- Move `stock_items.barcode` into `barcode_aliases` as stock-item primary aliases.
- Move `skus.barcode` into `barcode_aliases` as SKU or stock-item aliases according to existing meaning.
- Move `skus.platform_label_code` into `barcode_aliases` as SKU `platform_label` aliases.
- Keep `skus.platform_label_code` populated as a mirror after migration.

### Phase 3 Behavior

After the app is stable:

- Stop reading `stock_items.barcode` and `skus.barcode` in scan paths.
- Consider hiding old columns from UI.
- Consider dropping old columns only in a later cleanup, not in v1.

## UI Requirements

### User-Facing Barcode UI

Users should see:

- `Primary barcode`
- `Additional barcodes`

Users should not see:

- `model_type`
- `model_id`
- `Apply to SKU`
- `Apply to stock item`
- `source`

The system decides where the alias belongs.

### SKU Create / Edit

For normal single / physical bundle SKUs:

- Product barcode section writes product barcodes to the linked stock item.
- FNSKU/platform label barcode section writes `platform_label` aliases to the SKU.
- `platform_label_code` field should be hidden or read-only.

For virtual bundle SKUs:

- No stock-item product barcode section if there is no linked stock item.
- Platform label alias can be supported later if needed.

### SKU Import

Import behavior:

- Product barcode column should create/update a stock-item alias.
- Platform label code column should create/update a SKU `platform_label` alias.
- `skus.platform_label_code` mirror must be updated from the alias.

Do not write product barcodes directly to `stock_items.barcode` in new import code.

## Scan Matching Requirements

Pack scan should eventually resolve barcodes through `barcode_aliases`.

Recommended lookup order in v1:

1. Active `barcode_aliases.normalized_barcode`
2. Legacy `stock_items.barcode`
3. Legacy `skus.barcode`
4. Legacy `skus.platform_label_code`

During matching:

- Prefer exact SKU alias match when the scanned barcode is SKU-level.
- Prefer matching lines with remaining quantity > 0.
- If one barcode matches multiple different stock items/SKUs and cannot be resolved from the current pack lines, show an ambiguous barcode error.
- Do not increment scanned quantity on ambiguous or wrong barcode.

After Phase 2 backfill and confidence:

- Remove legacy lookup from the hot path.

## Conflict Rules

Normalize every barcode before validation.

Within the same tenant:

- A normalized barcode should not point to two different physical products.
- A normalized barcode should not point to two different SKUs unless explicitly allowed for a known platform case.

For v1:

- Keep tenant-wide uniqueness for active aliases if possible.
- If the same normalized barcode already belongs to the same model, treat it as duplicate-on-same-product, not cross-product conflict.
- If the same normalized barcode belongs to a different stock item/SKU, block save/import.

Suggested error:

> This barcode is already used by another product. Please check the barcode or remove it from the other product first.

For same model duplicate:

> This barcode is already added to this product.

## Primary Alias Rules

Each model should have at most one primary active barcode per useful group.

Recommended:

- One primary product barcode per stock item.
- One primary `platform_label` barcode per SKU.

Implementation options:

- Application-level transaction that unsets other primary aliases before setting one.
- Later DB-level partial/functional unique indexes for production safety.

Do not rely only on UI state.

## Services / Code Structure

Create or update a service such as:

```text
BarcodeAliasService
```

Responsibilities:

- Normalize barcode.
- Create/update aliases.
- Enforce tenant scope.
- Enforce conflict rules.
- Set primary alias.
- Sync `skus.platform_label_code` mirror from SKU `platform_label` aliases.

Deprecate/remove the old platform-label sync service once this service owns the flow.

## Tenant Scope

Every alias operation must be tenant-scoped.

Rules:

- Tenant users can only manage aliases for their active tenant.
- Internal users can manage records within allowed/internal scope.
- Never trust `tenant_id`, `model_type`, `model_id`, `sku_id`, or `stock_item_id` from browser state without re-querying under allowed tenant scope.
- Guest users are not internal users.

## Tests

Add/update tests for:

1. Product barcode creates stock-item alias.
2. Additional product barcode creates stock-item alias.
3. FNSKU/platform label creates SKU `platform_label` alias.
4. `skus.platform_label_code` mirrors SKU `platform_label` alias.
5. Clearing/removing platform label alias clears `skus.platform_label_code`.
6. Product barcode import writes alias, not direct `stock_items.barcode`.
7. Platform label import writes SKU alias and mirror column.
8. Pack scan matches stock-item alias.
9. Pack scan matches SKU platform-label alias.
10. Pack scan prefers SKU alias when two SKUs share one stock item.
11. Pack scan prefers matching line with remaining quantity before over-scan.
12. Same barcode on same model is treated as duplicate-on-same-product.
13. Same barcode on another product is blocked.
14. Tenant user cannot create/update/delete another tenant's alias.
15. Internal user can manage aliases within expected scope.
16. Legacy `stock_items.barcode` still works as fallback before migration.
17. Legacy `skus.platform_label_code` still works as fallback before migration.
18. Backfill migration creates aliases from legacy columns.
19. Backfill is idempotent.
20. Existing search/display using `platform_label_code` still works after alias update.

Run targeted barcode, SKU, SKU import, and pack-scan tests. Do not run the full suite by default unless needed.

## Out of Scope

- Serial number tracking.
- IMEI tracking.
- Unit-level inventory.
- Barcode printing.
- Dropping legacy barcode columns.
- Camera scanning UI.
- Bulk alias import beyond current SKU import fields.

## Future: Serial / IMEI Tracking

Some products, such as phones, have unit-specific identifiers:

- IMEI
- serial number
- device ID

These should not be normal product barcodes.

Future model idea:

```text
inventory_units
- tenant_id
- stock_item_id
- serial_no
- imei
- warehouse_id
- status
- inbound_order_id
- outbound_order_id
- sales_order_id
```

This is separate from product-level barcode aliases.
