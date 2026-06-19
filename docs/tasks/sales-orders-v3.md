# Task: Sales Orders v3 -- CSV / Spreadsheet Import

## Pre-conditions

These must be deployed first:
- Sales Orders v1 (commit a16e848)
- Sales Order tenant hardening (commit ca606e6)
- Sales Orders v2 status management (commit ab18280)

`maatwebsite/excel ^3.1` is already in `composer.json` (currently unused). This task is its
first consumer.

Do not modify existing models, constants, migrations, or passing tests.

---

## Goal

Let an operator bulk-create sales orders by uploading a CSV or XLSX file (a marketplace
order export). Rows are grouped into orders, SKUs are matched by code within the selected
shop, and the whole file is validated before anything is written.

This produces orders with `source = SalesOrder::SOURCE_CSV` (the constant already exists and
is currently unused). All other behavior matches manual create: `order_status = pending`,
`fulfillment_status = unfulfilled`, `ship_together_key` recomputed by `SalesOrderObserver`.

---

## Key data facts (verified against the codebase)

- SKU unique key is `(tenant_id, shop_id, sku)`. SKU code lookup MUST be scoped by both
  `tenant_id` and the selected `shop_id`.
- `platform_order_id` is unique per `(tenant_id, shop_id)` (enforced in manual create).
  Import must reject a row whose `platform_order_id` already exists for that shop, and must
  reject duplicate order ids that collide across different recipients within the file.
- A SKU is importable if `sku_type = virtual_bundle` OR `stock_item_id` is not null
  (same filter as `SalesOrderCreate::skuOptions()`). Match only `status = active` SKUs.
- `SalesOrderLine` fillable: `sku_id`, `quantity`, `line_status`, `note` (plus price fields
  not used here).

---

## CSV format

One row per order line. Rows that share the same `platform_order_id` become one order.

Required header row (exact column names, case-insensitive, trimmed):

| Column | Required | Maps to | Notes |
|---|---|---|---|
| `platform_order_id` | yes | `sales_orders.platform_order_id` | groups rows into orders |
| `sku` | yes | resolved to `sku_id` via shop-scoped lookup | the SKU code, not the numeric id |
| `quantity` | yes | `sales_order_lines.quantity` | integer >= 1; fractional (1.5, 1.0) rejected |
| `line_note` | no | `sales_order_lines.note` | per-line note |
| `recipient_name` | no | `sales_orders.recipient_name` | taken from the first row of each order group |
| `recipient_phone` | no | `sales_orders.recipient_phone` | first row of group |
| `recipient_country_code` | no | `sales_orders.recipient_country_code` | 2-letter, uppercased |
| `recipient_postal_code` | no | `sales_orders.recipient_postal_code` | first row of group |
| `recipient_state` | no | `sales_orders.recipient_state` | first row of group |
| `recipient_city` | no | `sales_orders.recipient_city` | first row of group |
| `recipient_address_line1` | no | `sales_orders.recipient_address_line1` | first row of group |
| `recipient_address_line2` | no | `sales_orders.recipient_address_line2` | first row of group |
| `order_note` | no | `sales_orders.note` | first row of group |

Recipient and order-level fields are read from the FIRST row of each order group. Later rows
of the same order only contribute their line (sku + quantity + line_note). This matches how
marketplace exports repeat header data on every line row.

**IMPORTANT: Cross-row consistency check** -- all rows with the same `platform_order_id` MUST
have identical recipient/order-level fields:
- `recipient_name`, `recipient_phone`, `recipient_country_code`, `recipient_postal_code`
- `recipient_state`, `recipient_city`, `recipient_address_line1`, `recipient_address_line2`
- `order_note`

If any field differs across rows of the same order, mark all rows in that group with an error:
`import_conflicting_order_fields`. Example: one row says recipient=Chan/Tokyo, another says
Lee/Osaka for the same platform_order_id. This is rejected to prevent silent misdirection.

---

## UX flow (three steps)

1. **Upload step:** select shop, upload file. On submit, parse + validate the whole file.
   Nothing is written yet.
2. **Preview step:** show a validation report -- a per-row table with status (OK / error)
   and the resolved order grouping summary (N orders, M lines). If any row has an error,
   the Confirm button is disabled. The operator fixes the file and re-uploads.
3. **Confirm step:** on Confirm, import all orders in one `DB::transaction()`.

Import is all-or-nothing. A file with any invalid row imports nothing. This avoids
half-imported batches that are hard to reconcile.

---

## Component: `SalesOrderImport` -- `GET /sales-orders/import`

Route name: `sales.orders.import`

Class-based Livewire, `use WithFileUploads`.

### Public properties

```php
use Livewire\WithFileUploads;

#[Url(as: 'shop_id', except: '')]
public string $shopId = '';

public ?\Livewire\Features\SupportFileUploads\TemporaryUploadedFile $file = null;

// Populated after parse(); each entry is one validated row.
// ['row' => int, 'platform_order_id' => string, 'sku' => string, 'quantity' => int,
//  'sku_id' => int|null, 'errors' => string[], ...recipient fields]
public array $parsedRows = [];

public bool $parsed = false;     // true once a file has been parsed
public bool $hasErrors = false;  // true if any parsed row has errors
```

### `mount()`

Call `authorizeTenantAccess()` (same helper as the other components -- copy it verbatim).

### `updatedShopId()` / `updatedFile()`

Reset the preview when shop or file changes:

```php
public function updatedShopId(): void
{
    $this->resetPreview();
}

public function updatedFile(): void
{
    $this->resetPreview();
}

private function resetPreview(): void
{
    $this->parsedRows = [];
    $this->parsed = false;
    $this->hasErrors = false;
}
```

### `parse()` -- validate the upload, populate the preview

```php
public function parse(): void
{
    $shop = $this->validatedShop();

    $this->validate([
        'file' => ['required', 'file', 'mimes:csv,txt,xlsx', 'max:5120'], // 5 MB
    ]);

    $rows = $this->readRows();  // see "Parsing" below

    if ($rows === []) {
        session()->flash('error', __('sales_orders.import_empty_file'));
        return;
    }

    // Build a shop-scoped SKU code -> id map (active, importable only).
    $skuMap = Sku::query()
        ->where('tenant_id', $shop->tenant_id)
        ->where('shop_id', $shop->id)
        ->where('status', 'active')
        ->where(fn ($q) => $q->where('sku_type', 'virtual_bundle')->orWhereNotNull('stock_item_id'))
        ->pluck('id', 'sku');  // ['SKU-CODE' => 123]

    // platform_order_ids already used in this shop.
    $existingOrderIds = SalesOrder::query()
        ->where('tenant_id', $shop->tenant_id)
        ->where('shop_id', $shop->id)
        ->whereNotNull('platform_order_id')
        ->pluck('platform_order_id')
        ->all();

    $parsed = [];
    $hasErrors = false;

    foreach ($rows as $raw) {
        // readRows() carries the true spreadsheet row number in '__row' so the
        // preview table matches the operator's file even when blank rows are skipped.
        $rowNo = (int) $raw['__row'];
        $errors = [];

        $orderId  = trim((string) ($raw['platform_order_id'] ?? ''));
        $skuCode  = trim((string) ($raw['sku'] ?? ''));
        $quantityRaw = trim((string) ($raw['quantity'] ?? ''));

        // Strict quantity validation: must be a positive integer, no fractional parts.
        $quantity = null;
        if ($quantityRaw === '') {
            $errors[] = __('sales_orders.import_missing_quantity');
        } else {
            // Reject fractional values like '1.5', '1.0' (both are invalid).
            // Accept only integer strings like '1', '2', etc.
            if (! preg_match('/^[1-9]\d*$/', $quantityRaw)) {
                $errors[] = __('sales_orders.import_bad_quantity');
            } else {
                $quantity = (int) $quantityRaw;
            }
        }

        if ($orderId === '') {
            $errors[] = __('sales_orders.import_missing_order_id');
        }
        if ($skuCode === '') {
            $errors[] = __('sales_orders.import_missing_sku');
        } elseif (! $skuMap->has($skuCode)) {
            $errors[] = __('sales_orders.import_unknown_sku', ['sku' => $skuCode]);
        }
        // No separate "< 1" check is needed: the regex above already rejects 0 and
        // negatives, so a non-null $quantity is always >= 1 here.
        if ($orderId !== '' && in_array($orderId, $existingOrderIds, true)) {
            $errors[] = __('sales_orders.import_duplicate_order', ['id' => $orderId]);
        }

        $parsed[] = [
            'row'                     => $rowNo,
            'platform_order_id'       => $orderId,
            'sku'                     => $skuCode,
            'sku_id'                  => $skuMap->get($skuCode),
            'quantity'                => $quantity ?? 0,
            'line_note'               => trim((string) ($raw['line_note'] ?? '')),
            'recipient_name'          => trim((string) ($raw['recipient_name'] ?? '')),
            'recipient_phone'         => trim((string) ($raw['recipient_phone'] ?? '')),
            'recipient_country_code'  => strtoupper(trim((string) ($raw['recipient_country_code'] ?? ''))),
            'recipient_postal_code'   => trim((string) ($raw['recipient_postal_code'] ?? '')),
            'recipient_state'         => trim((string) ($raw['recipient_state'] ?? '')),
            'recipient_city'          => trim((string) ($raw['recipient_city'] ?? '')),
            'recipient_address_line1' => trim((string) ($raw['recipient_address_line1'] ?? '')),
            'recipient_address_line2' => trim((string) ($raw['recipient_address_line2'] ?? '')),
            'order_note'              => trim((string) ($raw['order_note'] ?? '')),
            'errors'                  => $errors,
        ];

        if ($errors !== []) {
            $hasErrors = true;
        }
    }

    // Validate country code format
    foreach ($parsed as $idx => $p) {
        if ($p['recipient_country_code'] !== '' && ! preg_match('/^[A-Z]{2}$/', $p['recipient_country_code'])) {
            $parsed[$idx]['errors'][] = __('sales_orders.import_bad_country');
            $hasErrors = true;
        }
    }

    // Cross-row validation: same platform_order_id must have consistent recipient/order fields.
    // Group by order id and check all rows in each group match on these fields.
    $orderIdGroups = [];
    foreach ($parsed as $idx => $p) {
        $oid = $p['platform_order_id'];
        if ($oid !== '') {
            $orderIdGroups[$oid][] = $idx;
        }
    }

    $orderFieldKeys = [
        'recipient_name', 'recipient_phone', 'recipient_country_code', 'recipient_postal_code',
        'recipient_state', 'recipient_city', 'recipient_address_line1', 'recipient_address_line2',
        'order_note',
    ];

    foreach ($orderIdGroups as $oid => $indices) {
        if (count($indices) <= 1) {
            continue; // only one row for this order, nothing to compare
        }

        $first = $parsed[$indices[0]];
        $hasConflict = false;

        foreach ($indices as $idx) {
            $row = $parsed[$idx];
            foreach ($orderFieldKeys as $key) {
                if ($row[$key] !== $first[$key]) {
                    $hasConflict = true;
                    break; // one error per row is enough
                }
            }

            if ($hasConflict) {
                break;
            }
        }

        if ($hasConflict) {
            foreach ($indices as $idx) {
                $parsed[$idx]['errors'][] = __('sales_orders.import_conflicting_order_fields', [
                    'id' => $oid,
                ]);
            }

            $hasErrors = true;
        }
    }

    $this->parsedRows = $parsed;
    $this->parsed = true;
    $this->hasErrors = $hasErrors;
}
```

### Parsing helper `readRows()`

Use Maatwebsite Excel to read the temp file into rows keyed by header. Normalize headers to
lowercase + trimmed so column order and casing do not matter.

```php
use Maatwebsite\Excel\Facades\Excel;

private function readRows(): array
{
    // Excel::toArray returns [sheet => [rowIndex => [colIndex => value]]].
    $sheets = Excel::toArray(new class {}, $this->file->getRealPath());
    $sheet  = $sheets[0] ?? [];

    if (count($sheet) < 2) {
        return []; // header only or empty
    }

    $header = array_map(fn ($h) => strtolower(trim((string) $h)), $sheet[0]);

    $requiredHeaders = ['platform_order_id', 'sku', 'quantity'];
    $missingHeaders = array_values(array_diff($requiredHeaders, $header));

    if ($missingHeaders !== []) {
        throw ValidationException::withMessages([
            'file' => __('sales_orders.import_missing_headers', [
                'headers' => implode(', ', $missingHeaders),
            ]),
        ]);
    }

    $rows = [];

    // $index is the offset within $sheet: 0 is the header, so the first data row
    // is index 1, which is spreadsheet row 2. We carry the true row number in
    // '__row' so skipped blank rows do not shift the numbers shown in the preview.
    foreach ($sheet as $index => $line) {
        if ($index === 0) {
            continue; // header
        }

        // Skip fully blank lines
        if (collect($line)->every(fn ($v) => trim((string) $v) === '')) {
            continue;
        }

        $values = array_slice(array_pad($line, count($header), null), 0, count($header));
        $row = array_combine($header, $values);
        $row['__row'] = $index + 1; // 0-based index -> 1-based spreadsheet row

        $rows[] = $row;
    }

    return $rows;
}
```

Note: `'__row'` is an internal key, not a CSV column. The required-header check uses
`array_diff` against the real header, so `__row` never collides with a user column, and
the row-level loop only reads the named columns plus `__row`.

### `import()` -- write the orders

```php
public function import()
{
    $shop = $this->validatedShop();

    if (! $this->parsed || $this->parsedRows === []) {
        session()->flash('error', __('sales_orders.import_nothing_to_import'));
        return;
    }

    if ($this->hasErrors) {
        session()->flash('error', __('sales_orders.import_has_errors'));
        return;
    }

    // Group rows by platform_order_id, preserving first-seen order.
    $groups = [];
    foreach ($this->parsedRows as $p) {
        if ($p['platform_order_id'] !== '') {
            $groups[$p['platform_order_id']][] = $p;
        }
    }

    // Re-check for duplicates BEFORE writing. Between parse() and import(), another
    // user may have imported the same order ids. If so, reject the whole batch with a
    // clear message instead of letting a later insert surface as a raw DB error.
    //
    // NOTE ON THE RESIDUAL RACE: there is currently no DB-level unique index on
    // (tenant_id, shop_id, platform_order_id) -- uniqueness is enforced in application
    // code only. This re-check shrinks but does not fully close the window: two truly
    // concurrent imports could both pass the check and both insert. The complete fix is
    // a unique index, which is out of scope here (this task must not modify migrations).
    // It is tracked as a follow-up; see "Follow-up" at the end of this spec.
    $platformOrderIds = array_keys($groups);
    $recheck = SalesOrder::query()
        ->where('tenant_id', $shop->tenant_id)
        ->where('shop_id', $shop->id)
        ->whereIn('platform_order_id', $platformOrderIds)
        ->pluck('platform_order_id')
        ->all();

    if ($recheck !== []) {
        session()->flash('error', __('sales_orders.import_duplicate_during_confirm', [
            'ids' => implode(', ', $recheck),
        ]));
        return;
    }

    $orderCount = 0;

    DB::transaction(function () use ($shop, $groups, &$orderCount) {
        foreach ($groups as $platformOrderId => $rows) {
            $first = $rows[0];

            $order = SalesOrder::create([
                'tenant_id'               => $shop->tenant_id,
                'shop_id'                 => $shop->id,
                'source'                  => SalesOrder::SOURCE_CSV,
                'platform_order_id'       => $platformOrderId,
                'order_status'            => SalesOrder::ORDER_STATUS_PENDING,
                'fulfillment_status'      => SalesOrder::FULFILLMENT_STATUS_UNFULFILLED,
                'recipient_name'          => $this->nullableString($first['recipient_name']),
                'recipient_phone'         => $this->nullableString($first['recipient_phone']),
                'recipient_country_code'  => $this->nullableString($first['recipient_country_code']),
                'recipient_postal_code'   => $this->nullableString($first['recipient_postal_code']),
                'recipient_state'         => $this->nullableString($first['recipient_state']),
                'recipient_city'          => $this->nullableString($first['recipient_city']),
                'recipient_address_line1' => $this->nullableString($first['recipient_address_line1']),
                'recipient_address_line2' => $this->nullableString($first['recipient_address_line2']),
                'note'                    => $this->nullableString($first['order_note']),
                'created_by_user_id'      => Auth::id(),
            ]);

            foreach ($rows as $line) {
                $order->lines()->create([
                    'sku_id'      => $line['sku_id'],
                    'quantity'    => $line['quantity'],
                    'line_status' => SalesOrderLine::STATUS_READY,
                    'note'        => $this->nullableString($line['line_note']),
                ]);
            }

            $orderCount++;
        }
    });

    $this->resetPreview();
    $this->reset('file');

    session()->flash('status', __('sales_orders.import_succeeded', [
        'orders' => $orderCount,
    ]));

    return redirect()->route('sales.orders.index');
}
```

### Shared helpers

`validatedShop()` -- copy the exact method from `SalesOrderCreate` (shop must be active and
in `allowedTenantIds()`; throw `ValidationException` on `shopId` otherwise).

Also copy verbatim from the other components: `isInternalUser()`, `allowedTenantIds()`,
`authorizeTenantAccess()`, `nullableString()`.

`shopOptions()` -- copy from `SalesOrderCreate` for the shop dropdown.

Required imports for the component (in addition to whatever the copied helpers need):

```php
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\Sku;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException; // thrown by readRows() and validatedShop()
use Livewire\Attributes\Url;
use Livewire\WithFileUploads;
use Maatwebsite\Excel\Facades\Excel;
```

---

## Route

Add to `routes/web.php`, next to the other sales order routes:

```php
use App\Livewire\SalesOrderImport;

Route::get('/sales-orders/import', SalesOrderImport::class)->name('sales.orders.import');
```

Register this route BEFORE the `/sales-orders/{order}` show route if one exists with a
wildcard, so `import` is not captured as an order id. (Check the current order of routes;
literal segments should be declared before wildcard `{order}` routes.)

---

## Index link

On `SalesOrderIndex` blade, add an "Import CSV" button next to the existing "New order"
button, linking to `route('sales.orders.import')` with `wire:navigate`.

---

## Lang keys to add in `lang/en/sales_orders.php`

```php
// Import
'import_page_title'      => 'Import Sales Orders',
'import_page_subtitle'   => 'Upload a CSV or XLSX file to create orders in bulk.',
'import_btn'             => 'Import CSV',
'import_parse_btn'       => 'Validate file',
'import_confirm_btn'     => 'Confirm import',
'import_file_label'      => 'Order file (CSV or XLSX, max 5 MB)',
'import_empty_file'      => 'The file has no data rows.',
'import_nothing_to_import' => 'Validate a file before importing.',
'import_has_errors'      => 'Fix the highlighted rows before importing.',
'import_missing_order_id' => 'Missing platform_order_id.',
'import_missing_sku'     => 'Missing sku.',
'import_missing_quantity' => 'Missing quantity.',
'import_missing_headers' => 'Missing required column(s): :headers.',
'import_unknown_sku'     => 'SKU :sku not found in this shop.',
'import_bad_quantity'    => 'Quantity must be a positive integer (1, 2, 3...), not fractional (1.5, 1.0).',
'import_bad_country'     => 'Country code must be two uppercase letters.',
'import_duplicate_order' => 'Order :id already exists for this shop.',
'import_conflicting_order_fields' => 'Order :id has conflicting recipient/order fields across rows.',
'import_duplicate_during_confirm' => 'Orders :ids were imported by another user during validation. Please re-upload and try again.',
'import_succeeded'       => ':orders order(s) imported.',
'import_summary'         => ':orders order(s), :lines line(s) ready to import.',
'import_col_row'         => 'Row',
'import_col_status'      => 'Status',
'import_col_order'       => 'Order',
'import_col_sku'         => 'SKU',
'import_col_qty'         => 'Qty',
'import_col_errors'      => 'Errors',
'import_row_ok'          => 'OK',
```

Add stubs (value = key) to `lang/ja/`, `lang/zh_TW/`, `lang/zh_CN/`.

---

## Blade view `resources/views/livewire/sales-order-import.blade.php`

- Shop select (reuse the manual create markup).
- File input bound `wire:model="file"`.
- "Validate file" button -> `wire:click="parse"`.
- Render the `file` validation error explicitly (for example `@error('file') ... @enderror`).
  The missing-required-headers failure from `readRows()` surfaces on the `file` key, not as a
  row-level error, so it must be shown here or the operator sees nothing happen.
- After `$parsed`: a summary line (order count, line count) and a per-row table:
  - Row number (use the row's `row` value, which is the true spreadsheet line), Status badge
    (OK green / Error red), order id, sku, qty, joined errors.
- "Confirm import" button -> `wire:click="import"`, `:disabled="$hasErrors"`.
- Flash `status` and `error` banners (reuse existing banner partials).

Compute the summary in the view from `$parsedRows`:
```php
$orderCount = collect($parsedRows)->pluck('platform_order_id')->filter()->unique()->count();
$lineCount  = count($parsedRows);
```

---

## Tests -- `tests/Feature/SalesOrderImportTest.php`

Use `RefreshDatabase`. Build CSV content as a string and wrap it in
`Illuminate\Http\Testing\File::createWithContent('orders.csv', $csv)`; set it with
`->set('file', $file)`. Follow the helper style of `SalesOrderTest`.

| # | Test | What it asserts |
|---|---|---|
| 1 | `test_import_creates_orders_grouped_by_platform_order_id` | 3 rows, 2 share an order id -> 2 orders, 3 lines; source = csv |
| 2 | `test_import_resolves_sku_by_code_scoped_to_shop` | sku code maps to the shop's sku_id; line has correct sku_id |
| 3 | `test_import_recipient_taken_from_first_row_of_group` | order recipient matches first row; later rows ignored for header |
| 4 | `test_parse_flags_unknown_sku` | sku code not in shop -> row has error; hasErrors true |
| 5 | `test_parse_flags_duplicate_existing_order_id` | order id already in shop -> row error |
| 6 | `test_parse_flags_bad_quantity` | quantity 0 or negative -> row error |
| 7 | `test_parse_flags_bad_quantity_fractional` | quantity 1.5 or 1.0 (float-like string) -> row error |
| 8 | `test_parse_flags_bad_country_code` | recipient_country_code = 'JPN' -> row error |
| 9 | `test_parse_flags_conflicting_order_fields` | same order id with different recipient_name in row 1 vs row 2 -> both rows marked error |
| 10 | `test_import_blocked_when_any_row_has_errors` | file with one bad row -> import writes nothing |
| 11 | `test_import_sets_source_csv_and_pending_unfulfilled` | created orders have source=csv, order_status=pending, fulfillment_status=unfulfilled |
| 12 | `test_import_recomputes_ship_together_key` | order with address has non-null ship_together_key after import (observer ran) |
| 13 | `test_import_rejects_sku_from_another_shop` | sku exists for a different shop, not the selected one -> unknown sku error |
| 14 | `test_tenant_user_cannot_import_for_other_tenant_shop` | tampered shopId for another tenant -> validatedShop throws, no orders created |
| 15 | `test_tenant_user_without_active_tenant_cannot_access_import` | 403 on the import route |
| 16 | `test_import_skips_fully_blank_rows` | trailing blank line in CSV is ignored, not counted as an error |
| 17 | `test_import_rejects_duplicate_order_id_inserted_by_concurrent_user` | after parse(), another user imports order; confirm re-checks and rejects with flash error |
| 18 | `test_parse_flags_missing_required_headers` | missing platform_order_id / sku / quantity header -> file validation error |
| 19 | `test_import_ignores_extra_columns` | row has more columns than the header -> extra values are ignored, import still works |
| 20 | `test_parse_row_number_matches_sheet_with_interleaved_blank` | a blank line sits between two data rows; the error reported on the row after the blank shows the true spreadsheet row number, not a shifted one |

---

## Constraints

- No Volt. No TypeScript. Class-based Livewire only.
- Import is all-or-nothing inside a single `DB::transaction()`.
- SKU lookup MUST be scoped by `tenant_id` AND `shop_id` AND `status = active`.
- Use `SalesOrder::SOURCE_CSV` for the `source` column.
- Do not set `ship_together_key` manually; `SalesOrderObserver` computes it on create.
- Copy the access-control helpers (`isInternalUser`, `allowedTenantIds`,
  `authorizeTenantAccess`) verbatim from the existing sales order components.
- `validatedShop()` must reject shops outside `allowedTenantIds()`.
- Validate the upload with `mimes:csv,txt,xlsx` and `max:5120` (5 MB).
- Header matching is case-insensitive and trimmed; column order does not matter.
- Missing required headers must raise a file validation error before row-level validation.
- Extra columns beyond the header must be ignored safely.
- Declare the literal `/sales-orders/import` route before any wildcard `{order}` route.
- Preserve the true spreadsheet row number in the preview: blank rows are skipped but must
  not shift the reported row numbers (carried via `__row` in `readRows()`).
- Run `php artisan test` at the end and confirm all tests pass.

---

## Follow-up (out of scope for this task)

- Add a DB unique index on `sales_orders (tenant_id, shop_id, platform_order_id)` (ignoring
  null `platform_order_id`) so duplicate order ids are rejected at the database level. This
  closes the residual race in `import()` described above, where two concurrent imports can
  both pass the application-level re-check. It is deferred because this task must not modify
  migrations; it should be its own migration plus a regression test that two parallel imports
  of the same order id leave exactly one order.
