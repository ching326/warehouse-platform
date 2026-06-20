# Marketplace Shipping Notice Export v1

## Goal

Complete the marketplace shipment notification export flow for Amazon and Rakuten.

This is different from courier CSV export:

- Courier CSV export sends order data to Yamato / Sagawa to create labels.
- Marketplace shipping notice export sends tracking / shipping confirmation data back to Amazon / Rakuten after labels and tracking numbers are ready.

Refer to the old system for behavior and format ideas:

- `C:\laragon\www\order-manage\amzorder\includes\manage_order.php`
  - Amazon shipment confirmation export in the `form_type = tracking_number`, `ship_type = amazon` branch.
- `C:\laragon\www\order-manage\amzorder\includes\export_ship_notice_rakuten.php`
  - Rakuten shipping notice CSV export.
- `C:\laragon\www\order-manage\amzorder\includes\update_ship_notice_rakuten_api.php`
  - Rakuten API payload reference only. Do not implement API submit in v1.

## Scope

Build CSV export only in v1:

- Amazon shipping confirmation TXT / TSV export
- Rakuten shipping notice CSV export
- Validation before export
- Downloadable export files
- Audit / batch records
- Sales Orders index buttons for selected orders

Do not implement:

- Amazon API shipment confirmation
- Rakuten API shipment confirmation
- automatic scheduled export
- partial shipment notice
- marketplace tracking upload import

API submission can be v2 using the same validation and payload builders.

## Current system context

The current system already has:

- `sales_orders`
  - `platform_order_id`
  - `shop_id`
  - `shipping_method_id`
  - legacy `shipping_method`
  - `tracking_no`
  - `order_status`
  - `fulfillment_status`
  - `shipped_at`
  - `courier_csv_exported_at`
- `sales_order_lines`
  - `platform_line_id`
  - `sku_id`
  - `quantity`
  - `line_status`
- `shops`
  - `platform`
  - `marketplace`
- `shipping_methods`
- `shipping_method_marketplace_mappings`
  - `platform`
  - `marketplace`
  - `carrier_code`
  - `carrier_name`
  - `service_code`
  - `service_name`

Use `shipping_method_marketplace_mappings` for Amazon / Rakuten carrier code mapping.
Do not hardcode old `local_shipping` mappings into the export service.

## Product rules

### Supported platforms

Only support:

- `amazon`
- `rakuten`

The selected orders must all belong to the same platform.

If the user selects mixed platforms, block the export and show a clear error.

### Export unit

Export selected orders only.

Do not export all filtered rows in v1.

The Sales Orders index already has selected IDs and selected-order actions. Add marketplace shipping notice export actions there.

### Suggested UI

In the selected-order action area, add a marketplace notice export menu:

- Marketplace Notice
  - Amazon
  - Rakuten

Only show/enable:

- Amazon option when selected orders are all Amazon
- Rakuten option when selected orders are all Rakuten

If easier for v1, always show both actions but validate on click and show errors.

Use the same flow as the existing courier export:

1. `SalesOrderIndex` Livewire method validates selected orders.
2. If re-export confirmation is not needed, the Livewire method calls the export service.
3. The service creates the file + batch.
4. The Livewire method returns `redirect()->route('marketplace-shipping-notice-batches.download', $batch)`.

Do not add separate POST validate/export controllers in v1.
Do not use `wire:navigate` for file downloads.

## Validation rules

Before export, validate all selected orders.

Hard-block the whole export if any selected order fails.
Do not silently skip invalid selected orders.

### General hard blocks

Block when:

- no selected orders
- selected orders are outside the current user's tenant scope
- selected orders span multiple tenants
- selected orders include mixed platforms
- selected platform is not the requested export platform
- order has no `platform_order_id`
- order has no `shipping_method_id`
- order has no marketplace mapping for its shipping method
- resolved marketplace mapping has an empty `carrier_code`
- order status is one of:
  - `on_hold`
  - `backorder`
  - `cancel_requested`
  - `cancelled`
- fulfillment status is one of:
  - `cancelled`
- order has no ready lines
- order has no `tracking_no` when the selected shipping method is trackable

### Tracking no rule

If `shippingMethod.is_trackable = true`, require `sales_orders.tracking_no`.

If `shippingMethod.is_trackable = false`, allow blank tracking no.

Fallback values:

- Do not invent fake tracking numbers for Amazon in v1.
- For Rakuten, old system used fallback text for Japan Post / Amazon warehouse shipping.
  In v1, prefer real mapping + real tracking data. If no tracking is required, leave the tracking number blank unless the mapping specifically defines a fallback later.

Marketplace notice export depends on `shipping_method_id`.
Legacy string-only `sales_orders.shipping_method` is not enough for this export, because marketplace carrier mapping and `is_trackable` are both method-level data.
Show a clear "missing shipping method" error for legacy-only orders.

### Marketplace mapping rule

Look up the mapping by:

- `shipping_method_id`
- platform = `amazon` or `rakuten`
- marketplace = order shop marketplace, or empty string fallback

Priority:

1. exact marketplace match, e.g. `JP`
2. empty marketplace `''` fallback

Block if no mapping exists.
Block if the resolved mapping has no `carrier_code`.

## File formats

### Amazon shipping confirmation export

Old system reference:

```text
TemplateType=OrderFulfillment	Version=2011.1102	Amazon shipment confirmation feed
order-id	order-item-id	quantity	ship-date	carrier-code	carrier-name	tracking-number	ship-method
```

Implement as tab-separated text, encoded as `SJIS-win`.

Filename:

```text
amazon-shipping-notice-YYYYMMDD-HHmmss.txt
```

Use Japan time for filename and ship date:

```php
now('Asia/Tokyo')
```

#### Amazon rows

Generate one row per ready sales order line.

Columns:

1. `order-id`
   - `sales_orders.platform_order_id`
2. `order-item-id`
   - `sales_order_lines.platform_line_id`
   - If missing, leave blank for v1 but add a warning test/note.
   - Do not block v1 solely because old/manual orders may not have this.
3. `quantity`
   - `sales_order_lines.quantity`
4. `ship-date`
   - ISO-8601 timestamp in Japan time, e.g. `2026-06-20T15:30:00+09:00`
5. `carrier-code`
   - `shipping_method_marketplace_mappings.carrier_code`
6. `carrier-name`
   - `shipping_method_marketplace_mappings.carrier_name`
7. `tracking-number`
   - `sales_orders.tracking_no`
8. `ship-method`
   - Prefer mapping `service_name`
   - fallback to `shipping_methods.name`

If Amazon requires only one row per order for your current workflow, keep line rows anyway.
Line-level export is safer because Amazon reports can contain multiple `order-item-id` rows for the same order.

### Rakuten shipping notice export

Old system reference:

```php
$headers = ['注文番号', '送付先ID', '発送明細ID', 'お荷物伝票番号', '配送会社', '発送日'];
```

Meaning:

```text
order_number, destination_id, shipment_detail_id, tracking_number, delivery_company, ship_date
```

Definitive Rakuten header order:

| Column | Header | Meaning | Source |
|---|---|---|---|
| 1 | 注文番号 | order_number | `sales_orders.platform_order_id` |
| 2 | 送付先ID | destination_id | blank in v1 |
| 3 | 発送明細ID | shipment_detail_id | blank in v1 |
| 4 | お荷物伝票番号 | tracking_number | `sales_orders.tracking_no` |
| 5 | 配送会社 | delivery_company | `shipping_method_marketplace_mappings.carrier_code` |
| 6 | 発送日 | ship_date | `YYYY-MM-DD` in Japan time |

If the old-system reference or the numbered list below displays as mojibake in a terminal, ignore the mojibake and use the definitive header table above.

Implement as CSV, encoded as `SJIS-win`, CRLF line endings.

Filename:

```text
rakuten-shipping-notice-YYYYMMDD-HHmmss.csv
```

Use Japan time for filename and ship date.

#### Rakuten rows

Generate one row per sales order.

Columns:

1. `注文番号`
   - `sales_orders.platform_order_id`
2. `送付先ID`
   - blank in v1
3. `発送明細ID`
   - blank in v1
4. `お荷物伝票番号`
   - `sales_orders.tracking_no`
5. `配送会社`
   - `shipping_method_marketplace_mappings.carrier_code`
6. `発送日`
   - `YYYY-MM-DD` in Japan time

Rakuten old system grouped one row per order and used the first/max tracking no and shipping method.
The new system has one `tracking_no` and one `shipping_method_id` on the sales order, so one row per order is correct for v1.

## Encoding and CSV writing

Do not convert individual fields before writing CSV/TSV.

Build rows in UTF-8 first, then convert the final assembled file content to `SJIS-win`.

Use CRLF line endings for exported marketplace files.

For Rakuten CSV:

- quote CSV fields safely
- if using `fputcsv`, write to a UTF-8 temp stream first
- normalize line endings to CRLF
- then convert the complete content to `SJIS-win`

For Amazon TSV:

- tabs separate columns
- escape tabs/newlines inside fields by replacing them with a single space
- normalize line endings to CRLF
- then convert to `SJIS-win`

## Audit / batch tracking

Add marketplace shipping notice audit tables.

### `marketplace_shipping_notice_batches`

```text
id
tenant_id nullable
platform              amazon / rakuten
marketplace           JP / US / etc, nullable or empty string
file_name
disk                  default local
path
order_count
line_count
exported_by_user_id
exported_at
created_at
updated_at
```

Migration notes:

- `tenant_id` should be nullable and `nullOnDelete()`, same as `courier_export_batches`.
- `exported_by_user_id` should be nullable and constrained to `users` with `nullOnDelete()`.
- Use `disk` + `path`, not `file_path`, so the download controller can mirror `CourierExportDownloadController`.

Rules:

- If all selected orders are one tenant, store that tenant id.
- Block mixed-tenant export in v1 to keep audit and download authorization simple.

### `marketplace_shipping_notice_batch_orders`

```text
id
marketplace_shipping_notice_batch_id
sales_order_id
platform_order_id
tracking_no
shipping_method_id nullable
exported_at
created_at
updated_at
```

Add indexes:

- `marketplace_shipping_notice_batch_id`
- `sales_order_id`
- `platform_order_id`

### Sales order timestamp

Add nullable timestamp to `sales_orders`:

```text
marketplace_shipping_notice_exported_at
```

This timestamp means the order was included in an Amazon/Rakuten shipping notice export.

When export succeeds:

- set `marketplace_shipping_notice_exported_at = now()` in UTC
- keep displaying dates in Japan time where user-facing

Do not overwrite `courier_csv_exported_at`.
Courier export and marketplace notice export are separate events.

## Re-export behavior

If any selected order already has `marketplace_shipping_notice_exported_at`, show a confirmation warning.

Behavior should match courier CSV re-export style:

- first click validates and warns
- user must confirm to continue
- confirmed export creates a new batch and updates `marketplace_shipping_notice_exported_at` again

Warning message:

```text
Some selected orders were already exported to marketplace shipping notice. Export again?
```

## Mark shipped behavior

Do not automatically mark orders as shipped in v1.

Reason:

- User may export file, upload to marketplace, and then still need to verify marketplace accepted it.
- Mark shipped should remain a separate explicit action.

Future option:

- Add a checkbox "Mark shipped after export" later if needed.

## Services / classes

Create service classes similar to courier export:

```text
app/Services/MarketplaceShippingNotice/
  MarketplaceShippingNoticeExportService.php
  MarketplaceShippingNoticeValidationResult.php
  AmazonShippingNoticeBuilder.php
  RakutenShippingNoticeBuilder.php
```

Responsibilities:

### `MarketplaceShippingNoticeExportService`

- validate selected orders
- enforce tenant scope
- enforce same platform
- load relationships:
  - `shop.tenant`
  - `shippingMethod.carrier`
  - `shippingMethod.marketplaceMappings`
  - `lines`
- resolve marketplace mapping
- build file content via platform builder
- store file under private storage
- create batch + pivot rows in a DB transaction
- update `sales_orders.marketplace_shipping_notice_exported_at`
- return batch

### `AmazonShippingNoticeBuilder`

- builds Amazon TSV content
- line-level rows
- `SJIS-win`
- CRLF

### `RakutenShippingNoticeBuilder`

- builds Rakuten CSV content
- order-level rows
- `SJIS-win`
- CRLF

## Routes

Add only one download route:

```php
Route::get('/marketplace-shipping-notice-batches/{batch}/download', MarketplaceShippingNoticeDownloadController::class)
    ->name('marketplace-shipping-notice-batches.download');
```

Do not add POST validate/export routes for v1.
Validation and export are driven by `SalesOrderIndex` Livewire methods, mirroring the existing courier selected-order export flow.

Download route must tenant-scope the batch.

Tenant user can only download batches belonging to their tenant.
Internal user can download all batches.

## Sales Orders index integration

Add Livewire methods to `SalesOrderIndex`:

```php
validateMarketplaceShippingNoticeExport(string $platform)
confirmMarketplaceShippingNoticeExport()
```

State:

```php
public ?string $pendingMarketplaceNoticePlatform = null;
public array $pendingMarketplaceNoticeOrderIds = [];
```

The flow should mirror the existing courier export confirmation flow.

## Mapping setup requirement

Shipping Method setup page already has marketplace mappings.

Make sure users can configure:

### Amazon mapping examples

For each shipping method:

```text
platform: amazon
marketplace: JP or empty fallback
carrier_code: Yamato / Sagawa / Japan Post / Other
carrier_name: Yamato / Sagawa / Japan Post / Other
service_name: Nekopos / Takkyubin / Hikyaku Takuhai / etc
```

Use actual Amazon-accepted carrier codes for your marketplace.
Do not assume Yamato/Sagawa names are accepted without checking.

### Rakuten mapping examples

For each shipping method:

```text
platform: rakuten
marketplace: JP or empty fallback
carrier_code: Rakuten delivery company code
carrier_name: optional display name
```

Old system used `local_shipping.carrier_code_rakuten`.
In new system, this belongs in `shipping_method_marketplace_mappings.carrier_code`.

## Language keys

Add English keys only; other locale files inherit English.

Suggested keys in `lang/en/sales_orders.php`:

```php
'marketplace_notice_export_no_selection' => 'Select orders to export marketplace shipping notice.',
'marketplace_notice_export_wrong_platform' => 'Only :platform orders can be exported.',
'marketplace_notice_export_mixed_platforms' => 'Selected orders contain multiple platforms.',
'marketplace_notice_export_mixed_tenants' => 'Selected orders contain multiple tenants.',
'marketplace_notice_export_blocked_status' => 'Some selected orders cannot be exported because they are on hold, backorder, cancel requested, or cancelled.',
'marketplace_notice_export_missing_tracking' => 'Some selected orders are missing tracking numbers.',
'marketplace_notice_export_missing_shipping_method' => 'Some selected orders are missing a shipping method.',
'marketplace_notice_export_missing_mapping' => 'Some selected orders are missing marketplace carrier mapping.',
'marketplace_notice_export_missing_carrier_code' => 'Some selected orders have marketplace carrier mappings without carrier codes.',
'marketplace_notice_export_confirm_reexport' => 'Some selected orders were already exported to marketplace shipping notice. Export again?',
'marketplace_notice_export_confirm_btn' => 'Export again',
'marketplace_notice_exported' => 'Marketplace shipping notice exported.',
'btn_marketplace_notice' => 'Marketplace Notice',
'btn_marketplace_notice_amazon' => 'Amazon',
'btn_marketplace_notice_rakuten' => 'Rakuten',
```

## Tests

Add feature tests.

### Validation

1. `test_marketplace_notice_requires_selection`
2. `test_amazon_notice_blocks_non_amazon_orders`
3. `test_rakuten_notice_blocks_non_rakuten_orders`
4. `test_marketplace_notice_blocks_mixed_platform_selection`
5. `test_marketplace_notice_blocks_mixed_tenant_selection`
6. `test_marketplace_notice_blocks_on_hold_backorder_cancel_requested_and_cancelled_orders`
7. `test_marketplace_notice_requires_tracking_for_trackable_methods`
8. `test_marketplace_notice_allows_blank_tracking_for_non_trackable_methods`
9. `test_marketplace_notice_requires_shipping_method_id`
10. `test_marketplace_notice_requires_marketplace_mapping`
11. `test_marketplace_notice_requires_mapping_carrier_code`
12. `test_marketplace_notice_uses_empty_marketplace_mapping_as_fallback`
13. `test_marketplace_notice_requires_reexport_confirmation`

### Amazon export

14. `test_amazon_shipping_notice_exports_tsv_with_required_headers`
15. `test_amazon_shipping_notice_exports_one_row_per_ready_line`
16. `test_amazon_shipping_notice_uses_platform_line_id_as_order_item_id`
17. `test_amazon_shipping_notice_encodes_as_sjis_win_with_crlf`
18. `test_amazon_shipping_notice_uses_japan_time_ship_date`

### Rakuten export

19. `test_rakuten_shipping_notice_exports_csv_with_required_headers`
20. `test_rakuten_shipping_notice_exports_one_row_per_order`
21. `test_rakuten_shipping_notice_uses_mapping_carrier_code`
22. `test_rakuten_shipping_notice_encodes_as_sjis_win_with_crlf`
23. `test_rakuten_shipping_notice_uses_japan_date`

### Audit / security

24. `test_marketplace_notice_export_creates_batch_and_batch_orders`
25. `test_marketplace_notice_batch_uses_disk_and_path_for_download`
26. `test_marketplace_notice_export_sets_sales_order_exported_at`
27. `test_tenant_user_cannot_export_other_tenant_orders`
28. `test_tenant_user_cannot_download_other_tenant_batch`
29. `test_internal_user_can_download_batch`
30. `test_marketplace_notice_export_does_not_mark_order_shipped`

### UI

31. `test_sales_order_index_shows_marketplace_notice_actions`
32. `test_marketplace_notice_reexport_warning_sets_pending_state`
33. `test_confirm_marketplace_notice_export_uses_pending_order_ids`

## Acceptance criteria

- User can select Amazon orders and export Amazon shipping confirmation file.
- User can select Rakuten orders and export Rakuten shipping notice file.
- Mixed platform exports are blocked.
- Missing tracking numbers are blocked for trackable shipping methods.
- Marketplace carrier mapping is required.
- Exported file can be downloaded.
- Batch history is stored.
- Sales order gets `marketplace_shipping_notice_exported_at`.
- Re-export requires confirmation.
- Export does not mark orders shipped automatically.
- Tenant users cannot export or download other tenant data.
- Existing courier CSV export tests remain green.
- Full test suite passes.
