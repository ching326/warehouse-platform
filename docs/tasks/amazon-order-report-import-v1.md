# Task: Amazon Order Report Import v1

## Goal

Allow staff to import Amazon Order Report TXT files into Sales Orders.

Amazon reports are tab-separated text files, usually encoded as CP932 / SJIS-win for Japanese
seller accounts. One row represents one Amazon order item. Multiple rows can share the same
`order-id`; those rows must become one `sales_orders` record with multiple `sales_order_lines`.

This task extends the existing Sales Order import flow. Do not replace the existing generic
CSV/XLSX importer.

Reference files:

- Old importer: `C:\laragon\www\order-manage\amzorder\includes\import_order_amazon.php`
- Sample report: `C:\Users\diamo\Desktop\69a8c8df33c1d_21553784720020517.txt`

The sample report has been checked:

- Encoding: CP932
- Format: TXT / TSV
- Rows: 12
- Orders: 12
- Unique SKUs: 8
- `ship-country`: JP
- `is-buyer-requested-cancellation`: false for all sample rows

Even though the sample has one row per order, real Amazon reports can contain multiple rows with
the same `order-id`. The implementation must group by `order-id`.

---

## Current state

Existing `SalesOrderImport` supports a generic import file with headers like:

- `platform_order_id`
- `sku`
- `quantity`
- recipient fields

Amazon Order Report headers are different, for example:

- `order-id`
- `order-item-id`
- `purchase-date`
- `sku`
- `product-name`
- `quantity-purchased`
- `currency`
- `item-price`
- `ship-service-level`
- `recipient-name`
- `ship-address-1`
- `ship-address-2`
- `ship-address-3`
- `ship-city`
- `ship-state`
- `ship-postal-code`
- `ship-country`
- `ship-phone-number`
- `latest-ship-date`
- `shipment-status`
- `is-buyer-requested-cancellation`

Therefore, add an Amazon-specific parser instead of forcing Amazon files into the generic CSV
header format.

---

## Data Model Changes

### 1. Add sales order date fields

Create a new migration for `sales_orders`:

```php
Schema::table('sales_orders', function (Blueprint $table) {
    $table->timestamp('platform_ordered_at')->nullable()->after('platform_order_id');
    $table->timestamp('latest_ship_at')->nullable()->after('platform_ordered_at');
});
```

Update `App\Models\SalesOrder`:

- Add to `$fillable`:
  - `platform_ordered_at`
  - `latest_ship_at`
- Add to `casts()`:
  - `platform_ordered_at` => `datetime`
  - `latest_ship_at` => `datetime`

### 2. Add sales order line platform fields

Create a new migration for `sales_order_lines`:

```php
Schema::table('sales_order_lines', function (Blueprint $table) {
    $table->string('platform_line_id')->nullable()->after('sales_order_id');
    $table->string('platform_product_name')->nullable()->after('platform_line_id');

    $table->index(['platform_line_id']);
});
```

Update `App\Models\SalesOrderLine`:

- Add to `$fillable`:
  - `platform_line_id`
  - `platform_product_name`

### 3. Add cancel-requested order status

Update `App\Models\SalesOrder`:

```php
public const ORDER_STATUS_CANCEL_REQUESTED = 'cancel_requested';
public const SOURCE_AMAZON_REPORT = 'amazon_report';
```

Add language label:

```php
'order_status_cancel_requested' => 'Cancel requested',
'source_amazon_report' => 'Amazon report',
```

Use `cancel_requested` only when Amazon says `is-buyer-requested-cancellation = true`.

Do not write cancel reason to `note`. Amazon reports often do not include a useful reason, and it
is not important for this workflow.

---

## Import UI

Update existing `GET /sales-orders/import` page.

Add an import format selector:

```php
public string $importFormat = 'generic';
```

Options:

```php
private function importFormatOptions(): array
{
    return [
        'generic' => __('sales_orders.import_format_generic'),
        'amazon_report' => __('sales_orders.import_format_amazon_report'),
    ];
}
```

Language:

```php
'import_format' => 'Import format',
'import_format_generic' => 'Generic CSV / XLSX',
'import_format_amazon_report' => 'Amazon Order Report TXT',
```

When `importFormat = amazon_report`:

- File input should accept `.txt`.
- The selected shop must be an active Amazon shop:
  - `shops.platform = amazon`
  - shop must be in `allowedTenantIds()`
- Parse using the Amazon TSV parser below.

When `importFormat = generic`:

- Existing behavior should continue to work.
- Existing generic import tests should still pass.

Reset preview when `importFormat`, `shopId`, or `file` changes.

---

## Amazon Field Mapping

One Amazon report row maps to one `sales_order_lines` row.

Rows with the same `order-id` map to the same `sales_orders` record.

### Order-level mapping

| Amazon column | New system field | Notes |
|---|---|---|
| `order-id` | `sales_orders.platform_order_id` | group key |
| `purchase-date` | `sales_orders.platform_ordered_at` | parse ISO datetime |
| `latest-ship-date` | `sales_orders.latest_ship_at` | parse ISO datetime |
| `ship-service-level` | `sales_orders.shipping_method` | use normalized mapping below |
| `recipient-name` | `recipient_name` | first row of group |
| `ship-phone-number` or `buyer-phone-number` | `recipient_phone` | prefer ship phone |
| `ship-country` | `recipient_country_code` | normalize to 2-letter code |
| `ship-postal-code` | `recipient_postal_code` | |
| `ship-state` | `recipient_state` | |
| `ship-city` | `recipient_city` | |
| `ship-address-1` | `recipient_address_line1` | |
| `ship-address-2` + `ship-address-3` | `recipient_address_line2` | join with space, trim |
| `is-buyer-requested-cancellation` | `order_status` | true => cancel_requested |

Set:

```php
'source' => SalesOrder::SOURCE_AMAZON_REPORT,
'fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_UNFULFILLED,
```

Order status:

- If `is-buyer-requested-cancellation = true`: `order_status = cancel_requested`
- Otherwise: `order_status = pending`

Do not write cancel reason to `note`.

### Line-level mapping

| Amazon column | New system field | Notes |
|---|---|---|
| `order-item-id` | `sales_order_lines.platform_line_id` | useful for audit |
| `sku` | resolve to `skus.id` | scoped by selected shop |
| `product-name` | `sales_order_lines.platform_product_name` | original Amazon item title |
| `quantity-purchased` | `sales_order_lines.quantity` | integer >= 1 |
| `currency` or `order-currency-code` | `sales_order_lines.currency` | usually JPY |
| `item-price` or `item-price-amount` | `sales_order_lines.unit_price` | divide by qty if Amazon value is total line amount |

Line status:

- If order is cancel requested: `line_status = ready` is acceptable because the order itself is
  blocked by status. Do not mark lines cancelled automatically.
- Otherwise: `line_status = ready`

---

## Shipping Method Mapping

Amazon `ship-service-level` does not directly equal your courier choice.

For v1:

```php
private function normalizeAmazonShippingMethod(string $shipServiceLevel): ?string
{
    return match (strtolower(trim($shipServiceLevel))) {
        'yamato' => 'yamato',
        'sagawa' => 'sagawa',
        'japan_post', 'japan post' => 'japan_post',
        default => null,
    };
}
```

The sample uses `Standard`, so it should import as `shipping_method = null`.

Staff can later set Yamato/Sagawa manually on the Sales Orders index.

---

## Cancel Requested Rules

Add `cancel_requested` to all status label/color/status-option helpers:

- index filters
- detail header badges
- status management helpers
- any lang keys used by status display

Rules:

- `cancel_requested` orders cannot be marked ready.
- `cancel_requested` orders cannot be fulfilled.
- `cancel_requested` orders cannot be added to fulfillment groups.
- `cancel_requested` orders cannot be exported to courier CSV files.
- `on_hold` orders also cannot be exported to courier CSV files.

Implementation notes:

- `SalesOrderDetail::markReady()` and `SalesOrderIndex::bulkMarkReady()` should continue to only
  allow `order_status = pending`. Add explicit tests for `cancel_requested` so the guard cannot
  regress.
- `FulfillmentGroupCreate` must only list/select orders where:
  - `fulfillment_status = ready`
  - `order_status = pending`
- `FulfillmentGroupCreate::save()` must re-check the selected orders inside the transaction before
  reserving stock. If any selected order is no longer `pending + ready`, stop and roll back.

Courier export validation must hard-block orders where:

```php
order_status in ['on_hold', 'cancel_requested', 'cancelled']
```

The user cannot override this with a confirmation popup.

---

## Duplicate Handling

Use the existing unique behavior:

`(tenant_id, shop_id, platform_order_id)` must be unique.

During parse preview:

- If an imported `order-id` already exists for the selected shop, mark that order group as error.
- Do not silently skip duplicate orders.

During confirm/import:

- Re-check duplicate `order-id` values before writing.
- If any duplicate now exists, block the whole import with an error.
- Do not partially import.

If two rows in the same uploaded file share `order-id`, that is not a duplicate error. That is a
multi-line Amazon order and must become one order with multiple lines.

---

## Cross-row Consistency

For rows sharing the same `order-id`, all order-level fields must match.

Check at least:

- `purchase-date`
- `latest-ship-date`
- `ship-service-level`
- `recipient-name`
- `ship-phone-number`
- `buyer-phone-number`
- `ship-country`
- `ship-postal-code`
- `ship-state`
- `ship-city`
- `ship-address-1`
- `ship-address-2`
- `ship-address-3`
- `is-buyer-requested-cancellation`

If any differ across rows of the same `order-id`, mark all rows in that order group with:

```php
__('sales_orders.import_conflicting_order_fields', ['id' => $orderId])
```

This prevents one Amazon order from accidentally importing mixed recipient/order data.

---

## Encoding and TXT Parsing

Do not rely on Laravel Excel for Amazon TXT reports. Parse Amazon report files manually.

Implement helpers inside `SalesOrderImport` or a small service class, for example:

```php
private function detectFileEncoding(string $path): string
{
    $header = file_get_contents($path, false, null, 0, 4);

    foreach ([
        "\xEF\xBB\xBF" => 'UTF-8',
        "\xFF\xFE" => 'UTF-16LE',
        "\xFE\xFF" => 'UTF-16BE',
    ] as $bom => $encoding) {
        if (strncmp($header, $bom, strlen($bom)) === 0) {
            return $encoding;
        }
    }

    $sample = file_get_contents($path, false, null, 0, 4096);

    return mb_detect_encoding($sample, ['UTF-8', 'SJIS-win', 'CP932', 'EUC-JP'], true) ?: 'CP932';
}
```

Then:

```php
$content = file_get_contents($this->file->getRealPath());
$content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
$content = mb_convert_encoding($content, 'UTF-8', $this->detectFileEncoding($this->file->getRealPath()));
$lines = preg_split("/\r\n|\n|\r/", $content);
```

Parse each row with tab delimiter:

```php
$columns = str_getcsv($line, "\t", '"', '');
```

Header names should be lowercased and trimmed.

Required Amazon headers:

```php
[
    'order-id',
    'order-item-id',
    'purchase-date',
    'sku',
    'product-name',
    'quantity-purchased',
    'recipient-name',
    'ship-address-1',
    'ship-state',
    'ship-postal-code',
    'ship-country',
    'shipment-status',
    'is-buyer-requested-cancellation',
]
```

Optional but supported:

```php
[
    'buyer-phone-number',
    'ship-phone-number',
    'ship-address-2',
    'ship-address-3',
    'ship-city',
    'currency',
    'order-currency-code',
    'item-price',
    'item-price-amount',
    'latest-ship-date',
    'ship-service-level',
]
```

Missing required headers should raise a file validation error.

Carry true text-file line number in `__row`, same as generic import.

---

## SKU Resolution

Resolve Amazon `sku` against `skus.sku`.

Scope:

```php
Sku::query()
    ->where('tenant_id', $shop->tenant_id)
    ->where('shop_id', $shop->id)
    ->where('status', 'active')
    ->where(fn ($q) => $q
        ->where('sku_type', 'virtual_bundle')
        ->orWhereNotNull('stock_item_id'))
```

Do not resolve by `stock_items.code`.

If SKU is unknown, mark row error and block import.

---

## Import Flow

### Parse step

When `importFormat = amazon_report`, parse Amazon rows into the same `$parsedRows` shape used by
generic import, with extra fields:

```php
[
    'row' => 2,
    'platform_order_id' => '250-0761480-9900668',
    'platform_line_id' => '14491063060805',
    'sku' => 'IJL-B0876RMKCC-20250909',
    'sku_id' => 123,
    'quantity' => 1,
    'unit_price' => '195.00',
    'currency' => 'JPY',
    'platform_product_name' => 'TIANQIU ...',
    'recipient_name' => '久田一美',
    'recipient_phone' => '09082844430',
    'recipient_country_code' => 'JP',
    'recipient_postal_code' => '770-0805',
    'recipient_state' => '徳島県',
    'recipient_city' => '',
    'recipient_address_line1' => '徳島市下助任町5-2-44',
    'recipient_address_line2' => '',
    'platform_ordered_at' => '2026-03-03T03:51:43+00:00',
    'latest_ship_at' => '2026-03-05T14:59:59+00:00',
    'shipping_method' => null,
    'cancel_requested' => false,
    'errors' => [],
]
```

Preview should still show:

- row number
- OK/error
- order id
- sku
- qty
- errors

It is acceptable to add more preview columns later, but not required for v1.

### Confirm/import step

Group by `platform_order_id`.

For each group:

- create one `SalesOrder`
- create one `SalesOrderLine` per Amazon row

Use one database transaction.

If any row has an error, import nothing.

If duplicate is detected during confirm, import nothing.

---

## Detail / Index Display

No major UI change required for v1.

But make sure:

- `cancel_requested` appears as a badge in Sales Order index/detail.
- `source_amazon_report` displays properly.
- Amazon imported orders still appear in the existing Sales Orders index.
- Platform order id remains clickable.

---

## Courier Export Guard

Courier export is planned separately in `docs/tasks/courier-export-flow-v1.md`. In this task:

- If courier export code already exists in the current branch, update its validation.
- If courier export code does not exist yet, do not implement courier export here. Instead, update
  `docs/tasks/courier-export-flow-v1.md` so the future implementation includes this guard.

The courier export guard must block:

- `order_status = on_hold`
- `order_status = cancel_requested`
- `order_status = cancelled`

This block is not confirmable.

Re-export confirmation is still only for orders already exported via courier CSV.

---

## Language Keys

Add to `lang/en/sales_orders.php`:

```php
'import_format' => 'Import format',
'import_format_generic' => 'Generic CSV / XLSX',
'import_format_amazon_report' => 'Amazon Order Report TXT',
'import_amazon_missing_headers' => 'Missing Amazon report column(s): :headers.',
'import_amazon_only' => 'Amazon Order Report import requires an active Amazon shop.',
'import_amazon_bad_date' => 'Invalid Amazon date.',
'order_status_cancel_requested' => 'Cancel requested',
'source_amazon_report' => 'Amazon report',
```

For `lang/ja`, `lang/zh_TW`, `lang/zh_CN`, add stubs if the project currently uses stub keys;
otherwise follow the current locale convention.

---

## Tests

Add or extend `tests/Feature/SalesOrderImportTest.php`.

Required tests:

1. `test_amazon_report_import_creates_orders`
   - Upload Amazon TXT with one order.
   - Assert one sales order and one line created.
   - Assert source = `amazon_report`.

2. `test_amazon_report_groups_multiple_lines_with_same_order_id`
   - Two rows with same `order-id`, different `order-item-id` / SKU.
   - Assert one `SalesOrder`, two `SalesOrderLine`.

3. `test_amazon_report_resolves_sku_by_selected_amazon_shop`
   - Same SKU exists in another shop.
   - Selected shop SKU is used.

4. `test_amazon_report_rejects_unknown_sku`
   - Unknown Amazon `sku` marks row error and imports nothing.

5. `test_amazon_report_rejects_duplicate_existing_order`
   - Existing `(tenant_id, shop_id, platform_order_id)`.
   - Parse marks error.
   - Confirm imports nothing.

6. `test_amazon_report_rechecks_duplicate_during_confirm`
   - Parse OK.
   - Insert same order before confirm.
   - Confirm blocks all import.

7. `test_amazon_report_cancel_requested_sets_cancel_requested_status`
   - `is-buyer-requested-cancellation = true`
   - Assert `order_status = cancel_requested`.
   - Assert order note does not need cancel reason.

8. `test_cancel_requested_order_cannot_be_marked_ready`
   - Create/import a cancel-requested order.
   - Calling detail `markReady()` must not change it to ready.
   - Calling index `bulkMarkReady()` must not change it to ready.

9. `test_cancel_requested_order_is_not_available_for_fulfillment_group`
   - Even if lines are ready, the order must not appear in `FulfillmentGroupCreate` candidates.
   - If selected/tampered manually, save must reject it and reserve no stock.

10. `test_fulfillment_group_only_accepts_pending_ready_orders`
    - A ready pending order can be selected.
    - A ready on_hold/cancel_requested/cancelled order is excluded/rejected.

11. `test_amazon_report_imports_cp932_japanese_text`
    - Use CP932-encoded content with Japanese recipient/address/product name.
    - Assert imported text is readable UTF-8.

12. `test_amazon_report_rejects_missing_required_headers`
    - Missing `order-id` or `sku` etc. raises file error.

13. `test_amazon_report_rejects_conflicting_order_fields_for_same_order_id`
    - Same `order-id`, different recipient/address/cancel flag.
    - Both rows error.

14. `test_amazon_report_sets_platform_line_id_and_product_name`
    - Assert `sales_order_lines.platform_line_id` and `platform_product_name`.

15. `test_amazon_report_sets_unit_price_and_currency`
    - If item price is total line price, assert unit price = item price / qty.

16. `test_amazon_report_standard_shipping_imports_shipping_method_as_null`
    - `ship-service-level = Standard` => `shipping_method = null`.

17. `test_amazon_report_requires_amazon_shop`
    - Selected shop platform not `amazon`.
    - Parse has shop validation error.

18. Courier export guard test
    - Add only if courier export code already exists in the current branch.
    - Assert `on_hold`, `cancel_requested`, and `cancelled` orders are blocked and this block is not
      confirmable.

Run:

```bash
php artisan test
```

If global PHP is unavailable:

```bash
C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan test
```

---

## Constraints

- Do not remove or break existing generic import.
- Do not use `stock_items.code` for import lookup.
- Do not silently skip duplicate Amazon orders.
- Do not partially import files.
- Do not mark cancel-requested orders as cancelled automatically.
- Do not write Amazon cancel reason to note.
- Do not assume Amazon TXT is UTF-8.
- Do not use Laravel Excel for Amazon TXT parsing; parse TSV manually after encoding conversion.
- Do not implement the full courier export module in this task if it is not already present.
- Do not allow `on_hold`, `cancel_requested`, or `cancelled` orders to export courier CSV when
  courier export is implemented.
- Keep all tenant/shop scoping server-side.

---

## Follow-up

- Amazon API order sync.
- Amazon shipment confirmation upload / API feed.
- Better mapping from Amazon `ship-service-level` to local courier rules per shop.
- Import batch history table for marketplace imports.
- Store raw import file for audit.
- Per-row import error download.
