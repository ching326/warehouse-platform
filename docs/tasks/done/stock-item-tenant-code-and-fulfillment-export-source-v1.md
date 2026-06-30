# Stock Item Tenant Code and Fulfillment Export Source v1

## Goal

Allow tenants to store and use their own stock item code without replacing the system-generated `stock_items.code`.

The system must keep three concepts separate:

- `stock_items.id`: internal database identity and foreign-key target.
- `stock_items.code`: system-generated stable human-readable code.
- `stock_items.tenant_item_code`: tenant-provided human-readable item code.

Also add a tenant-level fulfillment export setting so one tenant can export shipping labels / fulfillment files using tenant item codes, while another tenant continues exporting SKU codes.

## Non-goals

- Do not replace `stock_items.code`.
- Do not use tenant item code as a barcode.
- Do not let a user UI display preference change export file output.
- Do not rewrite inventory relationships. Inventory, movements, inbound/outbound lines, barcode aliases, and audit references continue to use ids / existing FKs.

## Data Model

### stock_items

Add nullable string:

```php
$table->string('tenant_item_code')->nullable()->after('code');
```

Meaning:

- Tenant-defined stock item code.
- Optional.
- Unique within a tenant when filled.
- Different tenants may use the same tenant item code.

Validation should enforce uniqueness by tenant:

```php
Rule::unique('stock_items', 'tenant_item_code')
    ->where('tenant_id', $tenantId)
    ->ignore($stockItemId)
```

Empty string should be saved as `null`.

Do not add a global unique index on `tenant_item_code`.

### tenants

Add a tenant-level setting:

```php
$table->string('fulfillment_item_code_source')
    ->default('sku')
    ->after('stock_item_name_base_locale');
```

Allowed values:

- `sku`: export the sales/order SKU code.
- `tenant_item_code`: export `stock_items.tenant_item_code`, with fallback.
- `stock_item_code`: export system `stock_items.code`.

Default: `sku`.

This setting controls fulfillment-related exports only. It must not be affected by the user's UI display preference.

## Display Rules

Add helper methods instead of repeating display logic in Blade files.

Suggested helpers:

```php
StockItem::preferredDisplayCode(bool $showTenantCode): string
StockItem::secondaryDisplayCode(bool $showTenantCode): ?string
```

Rules:

When `showTenantCode = false`:

- Primary: `stock_items.code`
- Secondary: none

When `showTenantCode = true`:

- If `tenant_item_code` exists:
  - Primary: `tenant_item_code`
  - Secondary: `stock_items.code`
- If `tenant_item_code` is empty:
  - Primary: `stock_items.code`
  - Secondary: none

Do not hide the system code when a tenant item code exists; show it as smaller secondary text so warehouse/support can still identify the system record.

## User UI Preference

Add a user preference:

```php
preferences.show_tenant_item_code = true|false
```

This is a UI-only preference.

It affects table/card display only. It must not affect:

- CSV exports.
- Courier exports.
- Marketplace shipping notices.
- Shipping labels.
- Import matching.
- Inventory movements.
- Audit logs.

### SKU page

On `/skus`, add a checkbox near the existing view controls:

Label:

- EN: `Tenant code`
- JA: `取引先商品コード`
- zh_TW: `租戶商品編號`
- zh_CN: `租户商品编号`

Behavior:

- Checked: show tenant item code as primary and system stock item code as secondary.
- Unchecked: show system stock item code only.
- Save preference immediately when changed.
- Show success toast only when preference is saved.

### Inventory page

Apply the same display preference to stock item code display on `/inventory`.

If a separate toggle is added there, it must read/write the same user preference.

## Forms

### SKU create / edit

Add `tenant_item_code` to the stock item section.

Validation:

- nullable string
- max 255
- unique within tenant when filled

When linking to an existing stock item, the linked stock item's tenant item code should display, but editing rules should stay consistent with the existing stock-item editing behavior.

### Stock item-related UI

Any stock item create/edit surfaces should include the field if they exist.

Label:

- EN: `Tenant item code`
- JA: `取引先商品コード`
- zh_TW: `租戶商品編號`
- zh_CN: `租户商品编号`

## SKU Import

Add import mapping field:

```text
Tenant item code
```

Import behavior:

- Save mapped value to `stock_items.tenant_item_code`.
- Empty string becomes null.
- Validate uniqueness within tenant.

When matching an existing stock item during import:

1. If a system stock item code is mapped, match by `stock_items.code`.
2. If tenant item code is mapped, match by `tenant_id + tenant_item_code`.
3. If both are present and point to different stock items, block the row with a clear error.
4. Existing barcode alias matching remains separate and must not be confused with tenant item code.

Suggested error:

```text
Stock item code and tenant item code refer to different products.
```

## Search

Include `stock_items.tenant_item_code` in:

- SKU global search.
- Inventory global search.
- Any stock-item searchable picker where users expect to find a product by code.

Search must remain tenant-scoped.

## Fulfillment Export Source

Add a small setting on Tenant setup pages:

Field label:

- EN: `Fulfillment item code source`
- JA: `出荷用商品コード`
- zh_TW: `出貨商品編號來源`
- zh_CN: `出货商品编号来源`

Options:

- `SKU`
- `Tenant item code`
- `System stock item code`

This setting controls item code output for fulfillment-facing exports where item code is included, such as:

- Shipping label export if item code is included.
- Courier / warehouse handoff files if item code is included.
- Future packing slips / pick documents if they need item code output.

It does not change screen display.

### Export resolver

Create a single resolver/service method so exports do not duplicate fallback logic:

```php
FulfillmentItemCodeResolver::resolve(Tenant $tenant, Sku $sku, ?StockItem $stockItem): string
```

Rules:

```php
match ($tenant->fulfillment_item_code_source) {
    'tenant_item_code' => $stockItem?->tenant_item_code ?: $sku->sku,
    'stock_item_code' => $stockItem?->code ?: $sku->sku,
    default => $sku->sku,
};
```

Notes:

- For virtual bundles / lines without a stock item, fallback to SKU.
- Never return an empty string; fallback to SKU.
- Keep this resolver separate from UI display helpers.

## Tests

Add or update tests for:

1. Tenant item code can be saved on stock item create/edit.
2. Empty tenant item code saves as null.
3. Tenant item code must be unique within the same tenant.
4. Same tenant item code is allowed across different tenants.
5. SKU page display toggle shows tenant item code first and system code second.
6. SKU page display toggle off shows system code only.
7. User preference persists and is loaded on next visit.
8. Inventory page uses the same display preference.
9. SKU import maps tenant item code.
10. SKU import matches existing stock item by tenant item code.
11. SKU import blocks when system stock item code and tenant item code point to different stock items.
12. SKU / inventory search finds stock items by tenant item code.
13. Tenant setup can save `fulfillment_item_code_source`.
14. Fulfillment item code resolver returns SKU by default.
15. Resolver returns tenant item code when configured and present.
16. Resolver falls back to SKU when tenant item code is configured but missing.
17. Resolver returns system stock item code when configured.
18. User UI preference does not affect export resolver output.

Run targeted SKU, inventory, tenant setup, and export/resolver tests. Do not run the full suite unless a targeted failure suggests a wider regression.

## Acceptance Criteria

- `stock_items.code` remains unchanged and continues to be generated by the system.
- Tenant item code can be stored and displayed.
- Users can choose whether to show tenant item code in UI and save it as a personal preference.
- Fulfillment exports use tenant-level `fulfillment_item_code_source`, not user UI preference.
- Existing imports/exports continue to work when tenant item code is empty.
- No barcode logic is moved into tenant item code.
- Tests cover tenant scoping, uniqueness, import matching, display preference, and export resolver fallback.
