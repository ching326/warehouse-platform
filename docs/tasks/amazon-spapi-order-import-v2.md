# Task: Amazon SP-API Order Import v2

## Goal

Build Amazon SP-API order import on top of `Amazon SP-API Shop Settings v1`.

This task lets an internal user pull Amazon orders directly from SP-API for one Amazon shop, preview the result, and import valid new orders into the existing `sales_orders` and `sales_order_lines` tables.

The API import must behave consistently with the existing Amazon Order Report import:

- Multiple Amazon lines with the same Amazon order ID become one WMS sales order.
- Existing orders are treated as already imported and skipped.
- Missing SKUs are shown clearly with an Add SKU action.
- Buyer cancellation requests become `order_status = cancel_requested`.
- Shipping method mapping reuses the existing Amazon report mapping logic.

## Pre-conditions

This task assumes these are already deployed:

- Amazon SP-API Shop Settings v1
- Sales Orders v3 / v4 / v5
- Amazon Order Report import
- Shipping methods and rates
- Courier export flow

Do not build this until each Amazon shop can save and test an SP-API connection.

## Important Product Decision

This is **manual API import first**, not fully automated scheduled sync.

Access model for v2: **internal users only**.

Reason: this page uses internally configured Amazon credentials and can fetch recipient PII from Amazon. Tenant-facing API import can be added later after a dedicated tenant permission/audit UX is designed.

V2 should provide an internal UI where an operator can:

1. Select an Amazon shop.
2. Choose an import window.
3. Fetch orders from SP-API.
4. Review a preview.
5. Confirm import.

Scheduled sync can be a later task after the manual flow is stable.

`sync_enabled` does **not** block this manual API import page. In this product design, `sync_enabled` controls future scheduled sync only. An internal operator may still run a one-off manual import for a shop whose scheduled sync is disabled.

## API Scope

### In scope

- Fetch Amazon orders for one shop using the saved SP-API connection.
- Fetch order line items.
- Fetch recipient/shipping address data when the account has the required PII permissions.
- Preview new orders, duplicates, missing SKU rows, cancel-requested rows, and API errors.
- Import only valid new orders.
- Skip duplicates, including orders that were already marked duplicate during preview.
- Store import run history.
- Tests with HTTP fake / mocked SP-API client.

### Out of scope

- Scheduled background sync
- Webhook / notifications
- Auto-update every existing order field
- Auto-create SKUs
- Auto-create fulfillment groups
- Auto-export courier CSV
- Amazon shipment notification upload
- Financial settlement import

## API Version Strategy

Use a small internal client abstraction so the WMS is not tightly coupled to one Amazon endpoint shape.

Suggested service:

```text
App\Services\Amazon\AmazonSpapiOrdersClient
```

Preferred current behavior:

- Use the current Orders API endpoint available to the implementation environment.
- If using the newer `searchOrders` operation, use `includedData` where supported to reduce extra calls.
- If using Orders v0, use `getOrders` plus order-item retrieval as needed.

Do not spread raw Amazon response parsing inside Livewire components. Keep it in a service/mapper so it can be adjusted if Amazon endpoint versions change.

Reference facts from Amazon docs:

- An LWA access token is sent in `x-amz-access-token`.
- `CreatedAfter` or `LastUpdatedAfter` is required for order search.
- PII access requires the correct restricted role / authorization. For the shipping address and buyer/recipient contact data needed by this WMS, assume the implementation must use a Restricted Data Token (RDT) for restricted Orders resources.
- Use official docs for exact endpoint path and parameters during implementation.

## PII / Address Requirement

For WMS shipping, recipient name, address, and phone are operationally required.

V2 must not silently import shippable orders without recipient address data.

Rules:

- Implement the RDT flow as the primary path for fetching shipping address / buyer or recipient contact data.
- If the Amazon API returns recipient/shipping address data after RDT authorization, import normally.
- If Amazon denies RDT creation or denies PII/address fields, fail the fetch/preview with a clear message:
  - "Amazon PII permission is missing. Order address and phone cannot be imported."
- If an individual order has no address because it is not yet actionable, for example Amazon `Pending`, skip that order with an API warning instead of failing the whole preview.
- Do not import addressless orders by default.
- Do not store partial orders with blank recipient fields unless a future explicit "non-PII import mode" is designed.

Note: selecting "No, I will not delegate access to PII to another developer's application" in Amazon does not by itself mean this private app cannot access PII. The app still needs the correct Amazon restricted role / approval for order shipping address data.

## Data Model

### Add columns to `amazon_spapi_connections`

Add sync cursor and API status fields:

```text
last_orders_imported_at        nullable datetime
last_orders_import_window_from nullable datetime
last_orders_import_window_to   nullable datetime
last_orders_import_status      nullable string -- success / failed
last_orders_import_error       nullable text
```

These are informational. The source of truth for each import run is the import-run table below.

### Create `amazon_spapi_import_runs`

```text
amazon_spapi_import_runs
------------------------
id
tenant_id
shop_id
amazon_spapi_connection_id
triggered_by_user_id nullable
mode                 -- manual / scheduled
status               -- previewed / importing / completed / failed
window_type          -- last_updated / created
window_from
window_to
api_order_count
api_line_count
new_order_count
new_line_count
duplicate_order_count
missing_sku_count
cancel_requested_count
imported_order_count
skipped_order_count
error_message
started_at
completed_at
created_at
updated_at

index: tenant_id + shop_id + created_at
index: status
index: amazon_spapi_connection_id
```

Do not store raw full Amazon API payloads in v2 unless needed for debugging. If storing raw payloads later, redact or encrypt PII.

## Models

Create:

```text
App\Models\AmazonSpapiImportRun
```

Relationships:

```php
AmazonSpapiImportRun belongsTo Tenant
AmazonSpapiImportRun belongsTo Shop
AmazonSpapiImportRun belongsTo AmazonSpapiConnection
AmazonSpapiImportRun belongsTo User as triggeredBy
```

Add to `AmazonSpapiConnection`:

```php
public function importRuns(): HasMany
```

Activity log:

- It is OK to log import-run summary fields.
- Do not log raw Amazon API payloads.
- Do not log access tokens, refresh tokens, or RDTs.

## Route / UI

Add route:

```text
GET /sales-orders/import/amazon-api
name: sales.orders.import.amazon-api
Livewire component: AmazonSpapiOrderImport
```

Route ordering:

- Place `/sales-orders/import/amazon-api` before `/sales-orders/{order}`.

Access control:

- Internal users can access the page.
- Tenant users receive 403, even for their own tenant's shops, in v2.
- Do not expose this route in tenant-facing navigation yet.

Add an entry point from the existing sales order import page:

- Existing: Generic CSV / XLSX
- Existing: Amazon Order Report TXT
- New: Amazon API Import

## UI Flow

### Step 1: Select shop and window

Fields:

- Amazon shop
- Window type:
  - Last updated, recommended
  - Created
- Date/time from
- Date/time to
- Use default window button:
  - From: last successful import time minus 10 minutes
  - To: now minus 2 minutes

Default behavior:

- Window type = `last_updated`
- `to` must be at least 2 minutes before now to avoid Amazon's latest-data delay rule.
- Maximum manual window: 7 days by default.
- Allow internal admin to choose up to 30 days with confirmation.

Buttons:

- Fetch Preview
- Reset

### Step 2: Preview

Show summary cards:

- API orders found
- New orders
- Already imported
- Missing SKU
- Cancel requested
- Skipped / not actionable

Preview table columns:

- Row / order number
- Status
  - Ready
  - Already imported
  - Missing SKU
  - Cancel requested
  - Not actionable
  - API warning
- Amazon order ID
- SKU
- Qty
- Recipient
- Address
- Shipping method
- Notes
- Action

For missing SKU:

- Show an Add SKU button.
- Open `/skus/create` in a new tab.
- Pre-fill if possible:
  - `tenant_id`
  - `shop_id`
  - `sku`
  - `name` from Amazon item title
  - `platform_sku`

For duplicates:

- Label: `Already imported`
- Do not validate SKU or quantity for duplicate rows.
- Confirm import must skip them even if the DB changes after preview.

### Step 3: Confirm import

Confirm import:

- Imports only valid new orders.
- Skips duplicates marked during preview.
- Re-checks DB duplicates at confirm time.
- Runs inside one DB transaction.
- Creates one `amazon_spapi_import_runs` row.

If the preview has missing SKU rows:

- Do not import anything.
- User should add SKUs and fetch preview again.

## Import Window Rules

Use `LastUpdatedAfter` / `LastUpdatedBefore` by default.

Why:

- Amazon can update older orders.
- Cancellation requests and status changes are updates.
- Last-updated sync reduces missed changes compared with created-date-only sync.

Cursor rule:

- On successful import, update `last_orders_imported_at` and last window fields on the connection.
- For next default window, use:

```text
from = last_orders_import_window_to - 10 minutes
to = now - 2 minutes
```

The 10-minute overlap is intentional. Duplicates are skipped, so overlap is safer than missing updates.

## Mapping Rules

Create a mapper service:

```text
App\Services\Amazon\AmazonOrderMapper
```

Do not duplicate mapping rules in Livewire.

The mapper should convert Amazon API orders/items into the same normalized row shape used by the existing Amazon Order Report parser where possible.

Normalized row fields:

```text
source = api
tenant_id
shop_id
platform_order_id
platform_ordered_at
latest_ship_at
order_status
shipping_method
shipping_method_id
recipient_name
recipient_phone
recipient_country_code
recipient_postal_code
recipient_state
recipient_city
recipient_address_line1
recipient_address_line2
sku
sku_id
quantity
platform_line_id
platform_product_name
unit_price
currency
line_note
order_note
is_duplicate
sku_not_found
errors
```

Do not store additional hidden PII in `parsedRows` beyond what the preview UI needs to display. Livewire public properties are serialized into the browser snapshot; this is acceptable for an internal preview that shows recipient data, but keep the payload minimal and do not include raw Amazon payloads or tokens.

### Order mapping

Amazon order field mapping:

```text
AmazonOrderId       -> sales_orders.platform_order_id
PurchaseDate        -> sales_orders.platform_ordered_at
LatestShipDate      -> sales_orders.latest_ship_at
OrderStatus         -> used for order_status mapping
ShippingAddress     -> recipient fields
BuyerInfo / phone   -> recipient_phone if available
ShipmentServiceLevelCategory / ShipServiceLevel -> shipping method mapping
```

Status mapping:

```text
Amazon order status Pending       -> skip as not actionable
buyer requested cancellation true -> cancel_requested
Amazon order status Canceled      -> cancelled
Amazon order status Cancelled     -> cancelled
Amazon order status Unshipped     -> pending
Amazon order status PartiallyShipped -> pending
Amazon order status Shipped       -> skip as not actionable / already outside WMS flow
otherwise                         -> skip with API warning
```

If Amazon order is already shipped by Amazon / seller before import:

- Do not auto-mark as WMS shipped in v2.
- Import it as pending only if it still needs warehouse action.
- If it does not need WMS action, skip it with an API warning.
- Exact rule can be conservative: only import statuses that represent unshipped orders.

Important: Amazon `Pending` does not mean WMS actionable pending. Amazon Pending normally means payment is not authorized yet; address, buyer info, item price, and sometimes item details may be unavailable. Skip Amazon Pending orders in preview. They should reappear in a later last-updated window once Amazon moves them to Unshipped.

### Line mapping

Amazon item field mapping:

```text
OrderItemId      -> sales_order_lines.platform_line_id
SellerSKU        -> skus.sku lookup within selected shop
Title            -> sales_order_lines.platform_product_name
QuantityOrdered  -> sales_order_lines.quantity
ItemPrice.Amount -> unit_price calculation
ItemPrice.CurrencyCode -> currency
```

Amazon `OrderItem.ItemPrice.Amount` is normally the line total for the ordered quantity, not a per-unit price. Store:

```text
unit_price = ItemPrice.Amount / QuantityOrdered
```

If Amazon omits ItemPrice for pending/fresh orders, leave `unit_price` and `currency` null. Missing price is not an import error.

If one Amazon order has multiple items:

- Create one `sales_orders` row.
- Create multiple `sales_order_lines` rows.
- Group by `AmazonOrderId`.

## SKU Matching

Use existing rule:

- Match Amazon SellerSKU to `skus.sku`.
- Scope lookup by:
  - `tenant_id`
  - `shop_id`
  - `status = active`
- SKU is importable if:
  - `sku_type = virtual_bundle`, or
  - `stock_item_id is not null`

Do not match by stock item code.
Do not auto-create stock items in this API import.
Do not auto-create SKUs in v2.

## Duplicate Handling

Amazon often returns old orders in API windows.

Rules:

- If `(tenant_id, shop_id, platform_order_id)` already exists, mark as `Already imported`.
- Duplicate orders are not errors.
- Confirm import must skip orders marked duplicate during preview.
- Confirm import must also re-check DB duplicates to handle races.
- If all fetched orders are duplicates, show "No new orders to import" and do not fail.

Do not re-import a preview duplicate just because the DB changes between preview and confirm.

### Duplicate plus cancel request precedence

A duplicate existing order can also carry a new Amazon buyer cancellation request.

Precedence:

1. If the order already exists and Amazon does **not** report a new cancellation request, classify it as `Already imported` and skip it.
2. If the order already exists and Amazon reports buyer cancellation requested, classify it as an `Existing order update: cancel requested` preview row, not a plain duplicate.
3. On confirm, run the safe existing-order update path described below.

This is new API-import behavior. The current Amazon report import only inserts new orders and skips duplicates; do not assume it already handles existing-order updates.

## Existing Order Updates

V2 should be conservative.

For existing orders:

- Do not rewrite recipient address.
- Do not rewrite lines.
- Do not rewrite shipping method.
- Do not rewrite notes.

Allowed safe update:

- If Amazon reports buyer cancellation requested and the existing WMS order is not shipped/completed/cancelled/in a fulfillment group:
  - set `order_status = cancel_requested`
  - record this in the import run summary

If this feels too large during implementation, defer existing-order updates entirely and document it clearly in the UI. But do not silently overwrite existing order data.

## Shared Import Persistence

The existing `SalesOrderImport` Livewire component already contains important insert logic:

- group rows by `platform_order_id`
- skip preview duplicates
- re-check DB duplicates at confirm
- create the order and lines in one transaction
- preserve the "preview duplicate remains skipped" hardening

Do not copy/paste that persistence logic into the Amazon API import component.

Extract shared persistence into a service, for example:

```text
App\Services\SalesOrders\SalesOrderImporter
```

Responsibilities:

- Accept normalized rows from CSV, Amazon report, or Amazon API.
- Validate there are no non-duplicate errors.
- Group rows into orders.
- Skip preview duplicates.
- Re-check DB duplicates before insert.
- Create `sales_orders` and `sales_order_lines` in a transaction.
- Return counts: imported orders, imported lines, skipped duplicates.

Then update the existing `SalesOrderImport` component to call this service, so report import and API import share the same insert/dedup behavior.

Amazon API import can add its existing-order cancel-request update branch before or after the shared insert step, but it should not fork the base order creation path.

## API Client Details

Create service:

```text
App\Services\Amazon\AmazonSpapiOrdersClient
```

Responsibilities:

- Get LWA access token using `AmazonSpapiTokenService`
- Require the saved connection status to be `connected` before fetching preview.
- Build request URL from connection endpoint
- Fetch orders using selected window
- Handle pagination / NextToken
- Fetch order items if the chosen API operation does not include line items
- Return normalized DTOs or raw API arrays to the mapper

Do not put HTTP calls in Livewire.

Use Laravel HTTP client.

Test with `Http::fake()`.

### Rate limits / pagination

Rules:

- Follow `NextToken` until exhausted or max page/order cap reached.
- Manual import cap: 500 API orders per preview.
- If cap is hit, show warning and ask user to use a smaller window.
- Respect rate limit retry headers when available.
- On 429, retry with backoff a small number of times, then fail gracefully.
- Send `window_from` and `window_to` to Amazon as ISO-8601 UTC strings with offset, for example `2026-06-20T01:23:45Z`.

## PII / RDT Handling

Create:

```text
App\Services\Amazon\AmazonRestrictedDataTokenService
```

For v2, implement this service. Address and phone data are required for warehouse shipping, so the RDT path is part of the core flow rather than a later optional enhancement.

Rules:

- RDT is short-lived and must not be stored in DB.
- RDT must not be logged.
- RDT is used only as request token for restricted operations.
- If RDT request fails due to missing role/permission, fail the preview with a clear message and import nothing.

If a future Orders API operation returns the required address fields without an explicit RDT request but still requires restricted roles, keep the same error behavior when Amazon denies address/buyer fields. Do not import addressless orders silently.

## Data Safety / Transactions

Preview:

- Does not write sales orders.
- May create an `amazon_spapi_import_runs` row with `status = previewed`, or may wait until confirm. Pick one and keep tests consistent.
- Stores/imports the previewed normalized rows for confirm. Confirm must not re-fetch from Amazon, because API data can change between preview and confirm and the user must import exactly what was reviewed.

Confirm:

- Must run inside `DB::transaction()`.
- Must create/import all valid new orders atomically.
- Must apply allowed existing-order cancel-request updates inside the same transaction as the new-order inserts.
- Must update import run status and counts.
- Must catch duplicate-key race and ask user to preview again.
- Must re-check DB duplicates, but must not re-call Amazon.

If any non-duplicate row has validation errors:

- Import nothing.

## UI Copy

Add lang keys. Suggested:

```php
amazon_spapi_import.page_title
amazon_spapi_import.page_subtitle
amazon_spapi_import.shop
amazon_spapi_import.window_type
amazon_spapi_import.window_last_updated
amazon_spapi_import.window_created
amazon_spapi_import.from
amazon_spapi_import.to
amazon_spapi_import.btn_fetch_preview
amazon_spapi_import.btn_confirm_import
amazon_spapi_import.btn_reset
amazon_spapi_import.summary_api_orders
amazon_spapi_import.summary_new_orders
amazon_spapi_import.summary_duplicates
amazon_spapi_import.summary_missing_sku
amazon_spapi_import.summary_cancel_requested
amazon_spapi_import.summary_skipped
amazon_spapi_import.status_ready
amazon_spapi_import.status_duplicate
amazon_spapi_import.status_missing_sku
amazon_spapi_import.status_cancel_requested
amazon_spapi_import.status_not_actionable
amazon_spapi_import.status_api_warning
amazon_spapi_import.no_connection
amazon_spapi_import.connection_not_ready
amazon_spapi_import.pii_missing
amazon_spapi_import.window_too_large
amazon_spapi_import.no_new_orders
amazon_spapi_import.import_succeeded
amazon_spapi_import.api_error
```

## Tests

Add feature tests for the Livewire page and services.

Suggested tests:

1. Internal user can open Amazon API import page.
2. Tenant user gets 403 on Amazon API import page, even for their own tenant's shop.
3. Non-Amazon shop cannot be used.
4. Amazon shop without SP-API connection shows no-connection error.
5. Connection with `status = not_tested` or `status = failed` cannot fetch preview.
6. Connection with `sync_enabled = false` can still run manual fetch preview when status is connected.
7. Fetch preview requests/uses RDT for address and buyer/recipient contact data.
8. RDT creation failure due to missing role blocks preview and imports nothing.
9. Amazon Pending order is skipped as not actionable and does not trigger PII/address failure.
10. Global PII denied response blocks preview and imports nothing.
11. Preview groups multiple Amazon items with the same Amazon order ID.
12. Preview marks existing non-cancelled orders as Already imported, not error.
13. Existing duplicate with new Amazon cancel request is classified as an existing-order update, not plain duplicate.
14. Confirm skips preview duplicates even if DB changes later.
15. Confirm re-checks DB duplicates before writing.
16. Confirm imports previewed normalized rows and does not re-fetch from Amazon.
17. Missing SKU blocks confirm and shows Add SKU link.
18. SKU lookup is scoped to selected shop.
19. Buyer cancellation request maps to `cancel_requested`.
20. Cancel-requested existing order is updated only when safe.
21. Existing shipped/completed/cancelled/in-group order is not overwritten by API update.
22. API import creates `source = api`.
23. API import sets platform line id and product name.
24. API import divides Amazon line-total ItemPrice by quantity for `unit_price`.
25. API import allows null unit price/currency when Amazon omits price for imported non-pending orders.
26. API import sets shipping method id and legacy carrier when mapping is clear.
27. Unknown shipping method imports as null method, not error.
28. Legacy region/marketplace mismatch from an older saved connection blocks or warns before API call.
29. Window `to` must be at least 2 minutes before now.
30. Window larger than allowed range is rejected.
31. API client formats window datetimes as UTC ISO-8601 strings ending in `Z`.
32. Pagination with NextToken imports all pages within cap.
33. 429 retry/backoff is handled with HTTP fake.
34. Manual import cap shows warning and stops before writing.
35. Import run counts are saved correctly.
36. Existing Amazon report import still passes after extracting shared `SalesOrderImporter`.
37. Full test suite remains green.

## Acceptance Criteria

- Internal user can fetch Amazon API order preview for an Amazon shop with a saved connection.
- Tenant users cannot access Amazon API import v2.
- Missing SP-API connection prevents import with a clear message.
- Missing PII/address permission prevents import with a clear message.
- RDT is used for restricted address/contact access and is never stored or logged.
- Amazon Pending orders are skipped as not actionable and do not block the whole preview.
- New valid Amazon orders import into `sales_orders` / `sales_order_lines`.
- Existing orders are skipped as already imported.
- Existing orders with a new Amazon buyer cancellation request are safely updated when allowed.
- Missing SKU rows block import and show Add SKU action.
- Multiple line items for one Amazon order become one sales order with multiple lines.
- Cancel-requested Amazon orders are marked `cancel_requested`.
- Imported orders use `source = api`.
- No raw access token, refresh token, or RDT is stored or logged.
- Tests use faked HTTP/client responses, not real Amazon API calls.
- Manual import is allowed even when scheduled sync is disabled, as long as the saved connection status is connected.

## Future Task: Amazon SP-API Scheduled Sync v3

After manual API import is stable, create a separate task for:

- Scheduled sync per shop
- Queue jobs
- Retry and alerting
- Cursor management
- Import run dashboard
- Syncing safe status updates for existing orders
- Amazon shipment notification upload after courier tracking is available
