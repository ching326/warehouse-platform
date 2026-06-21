# Sales Order Tracking Number Import v1

## Goal

Add a tracking-number import workflow to the Sales Orders index:

- User opens it from `http://127.0.0.1:8000/sales-orders`
- User can drag and drop a courier CSV file into a modal
- System auto-detects whether the file is Yamato or Sagawa
- System parses order numbers and tracking numbers
- System previews matched / unmatched rows before writing
- Confirm import updates `sales_orders.tracking_no`
- Import is tenant-scoped and must not update another tenant's orders

This is the missing step after courier CSV export:

1. Export Yamato / Sagawa CSV from WMS
2. Upload to courier system and print labels
3. Download/export tracking-number result file from courier system
4. Import that file back into WMS
5. WMS stores tracking numbers for marketplace shipping notice export and packing scan flow

## Old System Reference

Read these files while implementing:

- `C:\laragon\www\order-manage\amzorder\includes\import_yamato.php`
- `C:\laragon\www\order-manage\amzorder\includes\import_sagawa.php`

Important old-system parsing behavior:

### Yamato

Old import reads CSV rows with:

- Column A / index `0`: order field
- Column D / index `3`: tracking number

Old code:

```php
$orderField = normalizeImportedValue((string) ($data[0] ?? '')); // Column A
$trackingNumber = normalizeImportedValue((string) ($data[3] ?? '')); // Column D
```

### Sagawa

Old import reads CSV rows with:

- Column A / index `0`: tracking number
- Column B / index `1`: order field

Old code:

```php
$tracking_number = normalizeImportedValue((string) ($data[0] ?? '')); // Column A
$orderField = normalizeImportedValue((string) ($data[1] ?? '')); // Column B
```

## Current System Context

Relevant current fields:

- `sales_orders.platform_order_id`
- `sales_orders.tracking_no`
- `sales_orders.tenant_id`
- `sales_orders.shop_id`
- `sales_orders.shipping_method`
- `sales_orders.shipping_method_id`
- `sales_orders.courier_csv_exported_at`

Relevant existing UI:

- `resources/views/livewire/sales-order-index.blade.php`
- The Import menu already has a disabled `Tracking Numbers` option:
  - `__('sales_orders.import_tracking_numbers')`

Relevant existing Livewire component:

- `app/Livewire/SalesOrderIndex.php`
- It already has `updateTrackingNo()` and manual inline tracking save logic.
- Reuse tenant-scope logic from the index. Do not invent a new unsafe tenant scope helper.

## UX

### Entry Point

On the Sales Orders index:

- In the existing `Import` menu, replace the disabled `Tracking Numbers` item with a clickable button.
- Clicking it opens a modal.
- Do not navigate away from `/sales-orders`.

Suggested menu label:

- `Import tracking no.`

### Modal

Modal title:

- `Import Tracking Numbers`

Modal contents:

1. Drag-and-drop upload area
   - Accept `.csv` and `.txt`
   - Also allow click-to-select file
   - Show selected file name
   - Show upload progress / loading state

2. Auto-detected courier display
   - Before parsing: `Courier: Auto detect`
   - After parsing: `Courier: Yamato` or `Courier: Sagawa`

3. Preview button
   - `Preview import`

4. Preview summary
   - Total rows read
   - Matched orders
   - Updated orders
   - Unmatched rows
   - Skipped rows
   - Duplicate/conflicting rows

5. Preview table
   - Row
   - Courier
   - Order ID
   - Tracking no.
   - Current tracking no.
   - Result
   - Notes

6. Confirm button
   - `Import tracking numbers`
   - Disabled until preview has at least one matched row and no hard errors.

7. Cancel / Close button
   - Closes modal and resets upload state.

### Drag and Drop

Use Livewire file upload with Alpine event helpers, similar to `sales-order-import.blade.php`.

The drop zone should behave like:

- Drag file over -> highlight
- Drop file -> set Livewire file field
- Click zone -> open file picker

Do not require a separate page.

## File Detection

Create a parser service:

- `app/Services/Courier/TrackingImport/TrackingImportParser.php`

The parser should return a value object / array like:

```php
[
    'courier' => 'yamato'|'sagawa'|'unknown',
    'rows' => [
        [
            'row' => 1,
            'order_field' => '...',
            'order_tokens' => ['...'],
            'tracking_no' => '...',
            'status' => 'parsed'|'skipped'|'error',
            'note' => null,
        ],
    ],
]
```

Auto-detect courier by header first, then by row shape:

### Yamato detection

Treat as Yamato when any of these are true:

- Header contains Japanese column names equivalent to:
  - `注文番号`
  - `伝票番号`
- A data row has a plausible order value in column A and a plausible tracking number in column D.

Yamato parser:

- Order field: column A / index `0`
- Tracking no.: column D / index `3`

### Sagawa detection

Treat as Sagawa when any of these are true:

- Header contains Japanese column names equivalent to:
  - `お問い合せ送り状No.`
  - order/customer management field
- A data row has a plausible tracking number in column A and a plausible order value in column B.

Sagawa parser:

- Tracking no.: column A / index `0`
- Order field: column B / index `1`

### Ambiguous / unknown files

If both formats appear possible:

- Prefer header-based detection.
- If still ambiguous, show a hard error:
  - `Cannot detect courier file type. Please upload a Yamato or Sagawa tracking file.`

Do not silently guess if detection confidence is low.

## Encoding And CSV Handling

Courier files may be Shift-JIS / CP932 / UTF-8.

Parser must:

- Read raw bytes
- Detect/try encodings in this order:
  - UTF-8
  - SJIS-win / CP932
  - Shift-JIS
- Convert to UTF-8 before parsing
- Normalize line endings
- Support comma-delimited CSV
- If comma parsing produces only one column, retry tab-delimited parsing

Use PHP CSV parsing, not manual `explode(',')`, because quoted fields may contain commas.

## Normalization

Implement a shared normalizer equivalent to old system behavior.

### normalizeImportedValue()

Rules:

- Trim whitespace
- Convert full-width numbers/letters to half-width where safe:
  - use `mb_convert_kana($value, 'asKV', 'UTF-8')`
- Strip Excel text wrappers:
  - `="1234567890"` -> `1234567890`
- Strip surrounding quotes
- Strip leading apostrophe used by Excel:
  - `'1234567890` -> `1234567890`

### splitOrderTokens()

Order field may contain more than one order ID.

Support splitting on:

- whitespace
- comma
- pipe `|`
- slash `/`
- Japanese punctuation
- newline

Also keep the original full value as a token.

Important: the new system should match against `sales_orders.platform_order_id`, not the internal database id.

### Matching Rules

For each parsed row:

1. Normalize order tokens.
2. Try exact match:
   - `sales_orders.platform_order_id = token`
3. Try suffix match for courier shortened order values:
   - `RIGHT(platform_order_id, 15) = token`
   - This mirrors the old system's `RIGHT(order_id, 15)` fallback.
4. Match only orders whose `tenant_id` is in the current user's allowed tenant ids.
5. If more than one order matches one token:
   - mark row as ambiguous
   - do not update
6. If no order matches:
   - mark row as unmatched
   - do not update

Do not match by internal `sales_orders.id` unless the file explicitly contains a WMS-specific ID format in a future task. V1 should use platform order number only.

## Preview Rules

Preview must not write to DB.

Statuses:

- `Matched` - exactly one order found and tracking number is present
- `Already same` - order already has exactly the same tracking number
- `Will update` - order has no tracking number or a different tracking number
- `Unmatched` - no order found
- `Ambiguous` - more than one order found
- `Missing tracking no.` - row has order but no tracking number
- `Missing order ID` - row has tracking but no order field
- `Skipped` - blank/header/invalid row

Allow import when:

- There is at least one `Will update` or `Already same` row.

Hard block import when:

- File type is unknown
- File cannot be parsed
- All rows are invalid/unmatched

Ambiguous and unmatched rows should not block importing matched rows, but they must be shown clearly in the preview.

## Confirm Import Rules

On confirm:

- Re-parse from stored preview data or locked temporary file state.
- Do not trust client-submitted parsed row IDs.
- Re-check tenant scope and matching.
- Update only matched orders.
- Do not update unmatched / ambiguous / missing tracking rows.
- If a row says `Already same`, count it as skipped/no-op.
- If order has different existing tracking no., overwrite it with the imported value.

When overwriting a different existing tracking number:

- Count as updated
- Add note in result:
  - `Tracking number replaced`

Use one DB transaction for all updates.

## Activity Log

For each updated order, write an activity log entry:

- log name: `sales_order`
- event: `tracking_imported`
- caused by current user
- properties:
  - courier
  - old_tracking_no
  - new_tracking_no
  - source_file_name
  - row_no

Do not log full uploaded file contents.

## Optional Import Batch Table

V1 can work without a batch table, but adding one is useful for audit.

Recommended tables:

### courier_tracking_import_batches

Columns:

- id
- tenant_id nullable
- courier string
- source_file_name string
- disk string default `local`
- path string nullable
- total_rows unsigned int default 0
- matched_rows unsigned int default 0
- updated_orders unsigned int default 0
- unmatched_rows unsigned int default 0
- skipped_rows unsigned int default 0
- imported_by_user_id nullable FK users
- imported_at timestamp nullable
- timestamps

### courier_tracking_import_batch_rows

Columns:

- id
- batch_id FK
- sales_order_id nullable FK
- platform_order_id nullable string
- row_no unsigned int
- courier string
- order_field nullable string
- tracking_no nullable string
- status string
- note nullable text
- timestamps

If implementing batch tables in V1:

- Store preview/confirm result rows there.
- Show latest import batch summaries later in a future task.

If not implementing batch tables in V1:

- Keep it in scope as a follow-up, but activity log is still required.

## Sales Orders UI Changes

File:

- `resources/views/livewire/sales-order-index.blade.php`

Change the Import menu:

- Current disabled `Tracking Numbers` option becomes a real button.
- Clicking opens modal.

Suggested Livewire state on `SalesOrderIndex`:

```php
public bool $showTrackingImportModal = false;
public ?TemporaryUploadedFile $trackingImportFile = null;
public bool $trackingImportParsed = false;
public array $trackingImportRows = [];
public array $trackingImportSummary = [];
```

Add `WithFileUploads` to `SalesOrderIndex` if implementing modal inside the same component.

Alternative acceptable implementation:

- Create child component `CourierTrackingImportModal`
- Include it on the Sales Orders index
- Emit/dispatch event to refresh index after import

Choose whichever keeps `SalesOrderIndex` maintainable. Since `SalesOrderIndex` is already large, a child component is preferred if implementation stays clean.

## Language Keys

Add to `lang/en/sales_orders.php` or a dedicated courier tracking lang file:

- `import_tracking_numbers`
- `tracking_import_title`
- `tracking_import_hint`
- `tracking_import_drop_file`
- `tracking_import_preview`
- `tracking_import_confirm`
- `tracking_import_detected_courier`
- `tracking_import_unknown_courier`
- `tracking_import_summary`
- `tracking_import_result_matched`
- `tracking_import_result_will_update`
- `tracking_import_result_already_same`
- `tracking_import_result_unmatched`
- `tracking_import_result_ambiguous`
- `tracking_import_result_missing_tracking`
- `tracking_import_result_missing_order`
- `tracking_import_success`
- `tracking_import_no_rows`
- `tracking_import_no_matched_orders`

Other locale files can remain fallback stubs unless existing pattern requires keys.

## Permissions

Use the same tenant visibility rules as Sales Orders index:

- Internal user can import tracking for allowed/all tenants.
- Tenant user can import tracking only for their own active tenant orders.
- A tenant user uploading a file that contains another tenant's order IDs must see those rows as unmatched/not allowed, and those orders must not be updated.

Do not treat guest as internal.

## Tests

Add tests, probably in a new file:

- `tests/Feature/CourierTrackingImportTest.php`

Required tests:

1. `test_yamato_tracking_file_is_detected_and_previewed`
   - Yamato-style CSV:
     - col A = platform_order_id
     - col D = tracking_no
   - Assert detected courier = yamato.

2. `test_sagawa_tracking_file_is_detected_and_previewed`
   - Sagawa-style CSV:
     - col A = tracking_no
     - col B = platform_order_id
   - Assert detected courier = sagawa.

3. `test_confirm_import_updates_sales_order_tracking_no`
   - Matched order gets `tracking_no`.

4. `test_import_matches_by_platform_order_id_not_internal_id`
   - File token equals platform order id.
   - Internal DB id should not be needed.

5. `test_import_matches_by_last_15_chars_when_courier_file_contains_shortened_order_id`
   - platform_order_id longer than 15 chars.
   - file contains last 15 only.

6. `test_import_does_not_update_other_tenant_order`
   - Tenant user uploads file containing another tenant's platform_order_id.
   - Other order remains unchanged.

7. `test_unmatched_rows_do_not_block_matched_rows`
   - One matched row, one unmatched row.
   - Confirm imports matched row only.

8. `test_ambiguous_suffix_match_is_not_updated`
   - Two orders share same last 15 chars.
   - Row is ambiguous and neither order updates.

9. `test_already_same_tracking_number_is_counted_as_noop`
   - Existing tracking number equals imported tracking.

10. `test_different_existing_tracking_number_is_overwritten_and_logged`
    - Assert old/new tracking in activity properties.

11. `test_unknown_file_type_shows_clear_error`
    - File does not match Yamato/Sagawa.

12. `test_shift_jis_file_is_parsed`
    - Build a small CP932/SJIS-win encoded file.
    - Assert parser reads it.

13. `test_sales_order_index_has_tracking_import_button`
    - Import menu no longer shows disabled tracking item.

14. `test_drag_drop_upload_markup_exists`
    - Assert drop zone text / file input present in modal/component.

15. `test_parser_normalizes_excel_wrapped_tracking_numbers`
    - `="123456789012"` becomes `123456789012`.

Run:

```bash
php artisan test --filter=CourierTrackingImportTest
php artisan test
```

## Acceptance Criteria

- Sales Orders import menu has a working `Import tracking no.` option.
- Modal supports drag/drop and file picker upload.
- Courier type auto-detects Yamato vs Sagawa.
- Yamato parser uses col A order + col D tracking.
- Sagawa parser uses col A tracking + col B order.
- Parser supports Shift-JIS / CP932 and UTF-8.
- Preview shows matched/unmatched/ambiguous rows before confirm.
- Confirm updates `sales_orders.tracking_no`.
- Confirm does not update out-of-scope tenant orders.
- Activity log records changed tracking numbers.
- Full test suite passes.

## Out of Scope

- Pack/scan verification screen.
- Auto mark shipped.
- Marketplace shipping notice export.
- Courier API integration.
- Multi-package tracking numbers per single order.
- Changing courier CSV export format.

