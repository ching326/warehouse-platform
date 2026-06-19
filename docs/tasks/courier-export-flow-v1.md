# Task: Courier Export Flow v1 -- Yamato / Sagawa CSV

## Goal

Build the first courier export flow for Sales Orders.

Staff should be able to:

1. Select Sales Orders on the Sales Orders index.
2. Export selected Yamato or Sagawa CSV.
3. Prevent exporting orders with the wrong shipping method.
4. Warn before re-exporting an order that was already exported.
5. Download a carrier-ready Shift-JIS CSV.
6. Mark exported orders with `courier_csv_exported_at`.
7. Keep an audit record of who exported which orders, when, with which carrier, and which file.

This task only covers CSV export. Do not implement tracking import or pack-scan verification here.

---

## Reference from old system

Use the old system only as a reference for carrier mapping and behavior. Do not copy the old code
directly.

Reference files:

- `C:\laragon\www\order-manage\amzorder\includes\export_yamato.php`
- `C:\laragon\www\order-manage\amzorder\includes\export_sagawa.php`

Important behavior from the old files:

- Output CSV is encoded as `Shift-JIS`.
- Yamato filename pattern: `yamato_YYYYMMDD_HHMM.csv`.
- Sagawa filename pattern: `sagawa_YYYYMMDD_HHMM.csv`.
- Yamato ship date format is `YYYY/MM/DD`.
- Sagawa ship date format is `YYYYMMDD`.
- After export, old system updates `print_date`; in the new system this maps to
  `sales_orders.courier_csv_exported_at`.
- Old system logs export events; in the new system use an explicit export batch/history table plus
  activity log if helpful.
- Old system validates selected orders against carrier method:
  - Yamato export accepts Yamato/YMT-style orders only.
  - Sagawa export accepts Sagawa/SGW-style orders only.
- Old system groups same-address orders into one CSV row. For v1, do not implement automatic merge
  by address unless fulfillment groups already exist in the selected orders. Export one row per
  Sales Order. Ship-together/group export can be a follow-up.
- Old system appends consolidation notes like `同梱(...)`; do not implement this in v1.
- Old system has address splitting logic. Reuse the idea, but implement it as clean Laravel helper
  methods with tests.

---

## Pre-conditions

These should already exist before this task starts:

- `sales_orders.shipping_method`
- `sales_orders.tracking_no`
- `sales_orders.courier_csv_exported_at`
- Sales Orders index has selected rows.
- Sales Orders index can edit `shipping_method`.
- Sales Orders export v4 exists and must not be broken.

If `shipping_method`, `tracking_no`, or `courier_csv_exported_at` are missing, stop and implement
`docs/tasks/sales-order-index-shipping-ui-v1.md` first.

---

## Data model

Add a new table: `courier_export_batches`.

```php
Schema::create('courier_export_batches', function (Blueprint $table) {
    $table->id();
    $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
    $table->string('carrier'); // yamato / sagawa
    $table->string('file_name');
    $table->string('disk')->default('local');
    $table->string('path');
    $table->unsignedInteger('order_count')->default(0);
    $table->foreignId('exported_by_user_id')->nullable()->constrained('users')->nullOnDelete();
    $table->timestamp('exported_at');
    $table->timestamps();

    $table->index(['carrier', 'exported_at']);
    $table->index(['tenant_id', 'exported_at']);
});
```

Add a pivot/history table: `courier_export_batch_orders`.

```php
Schema::create('courier_export_batch_orders', function (Blueprint $table) {
    $table->id();
    $table->foreignId('courier_export_batch_id')->constrained()->cascadeOnDelete();
    $table->foreignId('sales_order_id')->constrained()->cascadeOnDelete();
    $table->string('platform_order_id')->nullable();
    $table->string('carrier');
    $table->timestamp('exported_at');
    $table->timestamps();

    $table->unique(['courier_export_batch_id', 'sales_order_id'], 'ceb_orders_batch_order_unique');
    $table->index(['sales_order_id', 'exported_at']);
});
```

Create models:

- `CourierExportBatch`
- `CourierExportBatchOrder`

Relationships:

- `CourierExportBatch belongsTo Tenant`
- `CourierExportBatch belongsTo User as exportedBy`
- `CourierExportBatch hasMany CourierExportBatchOrder`
- `CourierExportBatch belongsToMany SalesOrder through courier_export_batch_orders`
- `SalesOrder hasMany CourierExportBatchOrder`

Do not add a hard unique constraint that prevents re-export. Re-export is allowed only after a user
confirms the warning.

---

## Carrier constants

Add constants somewhere small and boring, either on `SalesOrder` or a new enum-like class.

Recommended:

```php
class CourierCarrier
{
    public const YAMATO = 'yamato';
    public const SAGAWA = 'sagawa';
}
```

Shipping method values should match the UI task:

- `yamato`
- `sagawa`
- `japan_post`
- `other`
- `null`

For v1:

- Yamato CSV accepts only `shipping_method = yamato`.
- Sagawa CSV accepts only `shipping_method = sagawa`.

---

## Routes

Add routes before `/sales-orders/{order}` wildcard routes:

```php
Route::post('/sales-orders/courier-export/validate', CourierExportValidateController::class)
    ->name('sales.orders.courier-export.validate');

Route::post('/sales-orders/courier-export', CourierExportController::class)
    ->name('sales.orders.courier-export');

Route::get('/courier-export-batches/{batch}/download', CourierExportDownloadController::class)
    ->name('courier-export-batches.download');
```

Notes:

- `validate` returns JSON for the frontend/Livewire confirm step.
- `export` creates the CSV, records the batch, marks orders exported, and redirects/downloads.
- `download` lets staff re-download a previous export file.

If simpler for Livewire, the validate/export actions may be methods on `SalesOrderIndex` instead
of controllers, but the actual CSV generation should live in a service class, not the Blade file.

---

## Service classes

Create:

- `app/Services/Courier/CourierExportService.php`
- `app/Services/Courier/YamatoCsvBuilder.php`
- `app/Services/Courier/SagawaCsvBuilder.php`
- `app/Services/Courier/JapaneseAddressSplitter.php`

### CourierExportService responsibilities

Input:

```php
public function validateExport(array $salesOrderIds, string $carrier, array $allowedTenantIds): CourierExportValidationResult
```

and:

```php
public function export(array $salesOrderIds, string $carrier, array $allowedTenantIds, ?User $user, bool $confirmedReExport = false): CourierExportBatch
```

Responsibilities:

- Normalize ids.
- Scope all queries by `allowedTenantIds`.
- Reject empty selection.
- Load orders with:
  - `shop.tenant`
  - `lines.sku`
- Only include ready lines:
  - `sales_order_lines.line_status = ready`
- Validate all selected orders exist within allowed tenants.
- Reject orders with no ready lines.
- Reject orders whose `shipping_method` does not match the selected carrier.
- Detect already exported orders where `courier_csv_exported_at` is not null.
- If already exported orders exist and `confirmedReExport = false`, return/throw a validation
  result requiring user confirmation.
- If confirmed, allow re-export.
- Generate CSV through the correct builder.
- Store the CSV under `storage/app/courier_exports/{carrier}/YYYY/MM/`.
- Create `courier_export_batches` and `courier_export_batch_orders`.
- Update `sales_orders.courier_csv_exported_at = now()` for exported orders.
- Use `DB::transaction()` for database writes after CSV content is successfully built.

Important: do not update `tracking_no` here. Tracking comes from the courier system later.

---

## Validation result

Create a simple value object or return array with:

```php
[
    'ok' => true/false,
    'requires_confirmation' => true/false,
    'valid_order_ids' => [...],
    'missing_order_ids' => [...],
    'wrong_carrier_order_ids' => [...],
    'already_exported_order_ids' => [...],
    'no_ready_lines_order_ids' => [...],
    'message' => '...',
]
```

Rules:

- If `wrong_carrier_order_ids` is not empty, block export. User cannot override this.
- If `no_ready_lines_order_ids` is not empty, block export. User cannot override this.
- If `already_exported_order_ids` is not empty, require confirm before export.
- If only already-exported issue exists and user confirms, export may proceed.

This directly supports the UX:

- Wrong carrier: "These orders are Yamato/Sagawa mismatch. Fix shipping method first."
- Re-export: "Some orders were already exported. Export again?"

---

## Sales Orders index UI

Add buttons to selected-actions bar:

- Export Yamato CSV
- Export Sagawa CSV

Recommended UX:

1. User selects rows.
2. User clicks `Export Yamato CSV` or `Export Sagawa CSV`.
3. Livewire calls validation service.
4. If validation blocks, show error badge/list and do not export.
5. If validation requires confirmation, show a confirmation prompt.
6. If confirmed, submit export request.
7. Browser downloads CSV or redirects to a download URL.
8. Page refreshes enough to show `Printed: YYYY-MM-DD` under Created.

Do not use `wire:navigate` for file downloads.

Implementation option:

- For v1, it is acceptable for Livewire to call the service and then return a file download
  response through a controller route.
- Keep the UX simple. A browser `confirm()` via `wire:confirm` is acceptable for re-export
  confirmation if a modal is too much.

---

## CSV encoding

Carrier CSV files must be Shift-JIS encoded.

Use a helper:

```php
private function sjis(string $value): string
{
    return mb_convert_encoding($value, 'SJIS-win', 'UTF-8');
}
```

Use `SJIS-win` instead of plain `Shift-JIS` to better handle Japanese Windows carrier systems.

Build CSV using `fputcsv()` into a temp stream, converting each field to SJIS-win before writing.

Do not use Laravel Excel for these carrier CSV files unless it can be proven to preserve the exact
encoding and header shape. Plain `fputcsv()` is easier to reason about for carrier imports.

---

## Address handling

Create `JapaneseAddressSplitter`.

Input:

- `recipient_state`
- `recipient_city`
- `recipient_address_line1`
- `recipient_address_line2`

Output:

```php
[
    'address1' => '...',
    'address2' => '...',
    'address3' => '...',
]
```

Rules inspired by old export files:

- Normalize Japanese width with `mb_convert_kana`.
- Prefer not to lose any address text.
- If splitting fails, fall back to:
  - address1 = state + city + address_line1
  - address2 = address_line2
  - address3 = empty
- Keep tests for:
  - normal Tokyo/Kanagawa address
  - long address with building name
  - address line 2 present
  - reconstruction check: address1 + address2 + address3 should contain the original key parts

Do not over-optimize address splitting in v1. Make it safe and testable first.

---

## Yamato CSV

Create `YamatoCsvBuilder`.

Reference: `export_yamato.php`.

Header should follow the old Yamato header:

```text
注文番号, 配送方法, クール区分, 伝票番号, 出荷予定日, お届け予定日, 配達時間帯, お届け先コード, お届け先電話番号, お届け先電話番号枝番, お届け先郵便番号, お届け先住所, お届け先アパートマンション名, 会社, 部門, お届け先名, お届け先名(ｶﾅ), 敬称, ご依頼主コード, ご依頼主電話番号, ご依頼主電話番号枝番, ご依頼主郵便番号, ご依頼主住所, ご依頼主アパートマンション, ご依頼主名, ご依頼主名(ｶﾅ), 品名コード１, 品名１, 品名コード２, 品名２, 荷扱い１, 荷扱い２, 記事, ｺﾚｸﾄ代金引換額（税込), 内消費税額等, 止置き, 営業所コード, 発行枚数, 個数口表示フラグ, 請求先顧客コード, 請求先分類コード, 運賃管理番号
```

Minimal v1 mapping:

- 注文番号: `platform_order_id`
- 配送方法: empty
- クール区分: empty
- 伝票番号: empty
- 出荷予定日: today in `YYYY/MM/DD`
- お届け予定日: empty
- 配達時間帯: empty
- お届け先電話番号: `recipient_phone`
- お届け先郵便番号: `recipient_postal_code`
- お届け先住所: address1
- お届け先アパートマンション名: address2
- 会社: address3 or empty
- お届け先名: `recipient_name`
- ご依頼主電話番号: sender phone constant/config
- ご依頼主郵便番号: sender postal code constant/config
- ご依頼主住所: sender address1 constant/config
- ご依頼主アパートマンション: sender address2 constant/config
- ご依頼主名: shop name
- 品名１: first item short name / sku name, max 25 chars
- 品名２: second item short name / sku name, max 25 chars
- 記事: item summary marker, e.g. `多種`, `複数`, or concise SKU summary
- 請求先顧客コード / 運賃管理番号: leave empty for v1 unless config exists

Item name rules:

- Use SKU name if available, else SKU code.
- For multiple ready lines, put first two short item names into 品名１/品名２.
- If there are more than two item types, keep 品名１/品名２ empty or short, and use 記事 with a
  compact summary.
- Do not exceed carrier field lengths. Use `mb_substr`.

---

## Sagawa CSV

Create `SagawaCsvBuilder`.

Reference: `export_sagawa.php`.

Header should follow the old Sagawa header array. Keep the same column count/order.

Minimal v1 mapping from old indices:

- お届け先電話番号: `recipient_phone`
- お届け先郵便番号: `recipient_postal_code`
- お届け先住所１: address1
- お届け先住所２: address2
- お届け先住所３: address3
- お届け先名称１: `recipient_name`
- お客様管理番号: last 15 chars of `platform_order_id` or full value if <= 15
- ご依頼主電話番号: sender phone constant/config
- ご依頼主郵便番号: sender postal code constant/config
- ご依頼主住所１: sender address1 constant/config
- ご依頼主住所２: sender address2 constant/config
- ご依頼主名称１: shop name or configured sender name
- 品名１..品名５: item summary fields, each max 16 chars
- 出荷日: today in `YYYYMMDD`
- お問い合せ送り状No.: empty

As with Yamato, keep the full Sagawa header shape, but fill unsupported fields as empty strings.

---

## Sender / shipper config

Do not hardcode old sender details directly inside CSV builders.

For v1, add config file:

`config/courier.php`

```php
return [
    'sender' => [
        'phone' => env('COURIER_SENDER_PHONE', '0455507090'),
        'postal_code' => env('COURIER_SENDER_POSTAL_CODE', '240-0065'),
        'address1' => env('COURIER_SENDER_ADDRESS1', '神奈川県横浜市保土ケ谷区和田2-6-8'),
        'address2' => env('COURIER_SENDER_ADDRESS2', '返品先の住所は異なる'),
        'name' => env('COURIER_SENDER_NAME', null),
    ],
];
```

Builder should use:

- configured sender name if present
- otherwise shop name

---

## Audit / activity log

After successful export:

- Create `courier_export_batches`
- Create `courier_export_batch_orders`
- Update `sales_orders.courier_csv_exported_at`
- Add an activity log entry for each exported order:
  - log name: `sales_order`
  - event: `courier_exported`
  - subject: SalesOrder
  - causer: current user
  - properties:
    - carrier
    - batch_id
    - file_name
    - re_export: true/false

If activitylog usage becomes awkward, keep the explicit batch tables as the source of truth and
defer per-order activitylog to a follow-up. Do not block v1 on activitylog polish.

---

## Re-export behavior

If any selected order has `courier_csv_exported_at IS NOT NULL`:

- Validation should return `requires_confirmation = true`.
- UI should show a warning:
  - "Some selected orders were already exported. Export again?"
- User must explicitly confirm.
- If confirmed, export all valid orders and record a new batch.
- Do not clear the old batch history.

This prevents accidental double shipping while still allowing recovery if a courier file was lost.

---

## Wrong carrier prevention

If user clicks Yamato export:

- Every selected order must have `shipping_method = yamato`.
- If not, block export and show the platform order IDs that do not match.

If user clicks Sagawa export:

- Every selected order must have `shipping_method = sagawa`.
- If not, block export and show the platform order IDs that do not match.

This is a hard block. Do not allow confirm override.

---

## Tests

Add `tests/Feature/CourierExportTest.php`.

Required tests:

1. `test_yamato_export_generates_shift_jis_csv_and_marks_orders_exported`
   - Create Yamato order with ready line.
   - Export.
   - Assert batch created.
   - Assert `courier_csv_exported_at` set.
   - Assert file exists.
   - Assert decoded CSV contains platform order id, phone, postal code, recipient name.

2. `test_sagawa_export_generates_shift_jis_csv_and_marks_orders_exported`
   - Same as Yamato, for Sagawa.

3. `test_yamato_export_blocks_sagawa_orders`
   - Selected order has `shipping_method = sagawa`.
   - Yamato export validation blocks.
   - No batch created.
   - `courier_csv_exported_at` remains null.

4. `test_sagawa_export_blocks_yamato_orders`
   - Mirror of above.

5. `test_export_requires_confirmation_for_already_exported_orders`
   - Set `courier_csv_exported_at`.
   - Validate export.
   - Assert `requires_confirmation = true`.
   - Export without confirmation does not create new batch.

6. `test_confirmed_re_export_creates_new_batch`
   - Already exported order.
   - Export with confirmation.
   - Assert new batch created.

7. `test_export_scopes_orders_by_tenant`
   - Tenant user includes another tenant's order id.
   - Other tenant order is ignored or blocked.
   - No leak in CSV.

8. `test_export_blocks_orders_without_ready_lines`
   - Order has only cancelled lines.
   - Export blocked.

9. `test_address_splitter_preserves_address_parts`
   - Unit-ish test for address splitter.

10. `test_sales_order_index_shows_export_buttons_for_selected_orders`
    - Selected rows show Yamato/Sagawa export buttons.

11. `test_export_updates_activity_or_batch_history`
    - Assert batch order row exists with platform order id, carrier, exported_at.

Run:

```bash
php artisan test
```

If `php` is not globally available:

```bash
C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan test
```

---

## Constraints

- No Volt.
- No TypeScript.
- Class-based Livewire only.
- Do not break existing Sales Orders export v4.
- Do not implement tracking import in this task.
- Do not implement pack scan in this task.
- Do not auto-merge same-address orders in v1.
- Do not export orders with mismatched shipping method.
- Do not silently re-export already exported orders; require explicit confirmation.
- Do not use `wire:navigate` for file downloads.
- CSV must be Shift-JIS/SJIS-win encoded.
- Keep all tenant scoping server-side. UI filtering is not security.

---

## Follow-up

- Tracking number import from courier system.
- Pack scan verification by tracking number and SKU barcode.
- Ship-together export using fulfillment groups.
- Courier export detail/history page.
- Configurable sender profiles per warehouse or per tenant.
- Yamato/Sagawa exact field length validation based on official carrier specs.
