# Task: Sales Orders v4 -- CSV / Spreadsheet Export

## Scope note

v1 added manual create, v2 added status management, v3 added CSV/XLSX import. v4 is the
symmetric counterpart: **export**. It lets an operator download the sales orders currently
shown on the index (same filters) as a CSV or XLSX file. The file round-trips: the first 13
columns are exactly the v3 import header, so an exported file can be re-imported without
editing.

If you intended a different v4 (API ingestion, returns/RMA, an activity-log timeline), stop
and say so. This spec assumes export.

---

## Pre-conditions

These must be deployed first:
- Sales Orders v1 (commit a16e848)
- Sales Order tenant hardening (commit ca606e6)
- Sales Orders v2 status management (commit ab18280)
- Sales Orders v3 import (commit 2db16ba)

`maatwebsite/excel ^3.1` is already in `composer.json` and is used by v3. This task is its
second consumer.

Do not modify existing models, constants, migrations, or passing tests.

---

## Goal

Add an "Export" action to the sales order index that downloads the orders matching the
current filters (shop, fulfillment status, order status, search) as a CSV or XLSX file.

- One row per order line (same shape as the v3 import), order-level fields repeated on every
  line row of the same order.
- The first 13 columns are byte-for-byte the v3 import header, in the same order, so the
  output round-trips through `SalesOrderImport`.
- Extra read-only columns (status, source, timestamps) are appended AFTER the import columns.
  v3 import already ignores extra trailing columns, so a re-import skips them cleanly.
- Tenant scoping is enforced: a user can only ever export orders for tenants in
  `allowedTenantIds()`. This holds even with no filter and even if `shop` is tampered.

---

## Key data facts (verified against the codebase)

- `SalesOrderIndex` already exposes the filter set: `shopId`, `fulfillmentStatus`,
  `orderStatus`, `search`, all scoped by `allowedTenantIds()`. The export MUST apply the
  identical filter logic so "what you see is what you export". See
  `app/Livewire/SalesOrderIndex.php` `render()` (lines 137-151).
- The search filter matches `platform_order_id` OR `recipient_name` with a `like`.
- `Sku` has a `sku` string column (the code). Export the code, never the numeric id, so the
  file round-trips.
- `SalesOrderLine` belongs to `SalesOrder` (`sales_order_id`) and to `Sku` (`sku_id`), and
  has `quantity`, `line_status`, `note`.
- `SalesOrder` order-level fields: `platform_order_id`, the eight `recipient_*` fields,
  `note`, plus read-only `order_status`, `fulfillment_status`, `source`, `created_at`.

---

## Export column format

One row per order line. Header row first. Columns in this exact order:

| # | Column | Source | Round-trips? |
|---|---|---|---|
| 1 | `platform_order_id` | `sales_orders.platform_order_id` | yes (import col) |
| 2 | `sku` | `skus.sku` (the code) | yes (import col) |
| 3 | `quantity` | `sales_order_lines.quantity` (integer) | yes (import col) |
| 4 | `line_note` | `sales_order_lines.note` | yes (import col) |
| 5 | `recipient_name` | `sales_orders.recipient_name` | yes (import col) |
| 6 | `recipient_phone` | `sales_orders.recipient_phone` | yes (import col) |
| 7 | `recipient_country_code` | `sales_orders.recipient_country_code` | yes (import col) |
| 8 | `recipient_postal_code` | `sales_orders.recipient_postal_code` | yes (import col) |
| 9 | `recipient_state` | `sales_orders.recipient_state` | yes (import col) |
| 10 | `recipient_city` | `sales_orders.recipient_city` | yes (import col) |
| 11 | `recipient_address_line1` | `sales_orders.recipient_address_line1` | yes (import col) |
| 12 | `recipient_address_line2` | `sales_orders.recipient_address_line2` | yes (import col) |
| 13 | `order_note` | `sales_orders.note` | yes (import col) |
| 14 | `order_status` | `sales_orders.order_status` | no (read-only) |
| 15 | `fulfillment_status` | `sales_orders.fulfillment_status` | no (read-only) |
| 16 | `source` | `sales_orders.source` | no (read-only) |
| 17 | `created_at` | `sales_orders.created_at` (ISO 8601) | no (read-only) |

Columns 1 to 13 MUST match the v3 import header exactly (same names, same order). The
read-only columns 14 to 17 are appended so a re-import (which ignores extra trailing columns)
still works. Null DB values export as an empty string, never the literal `null`.

Driving model is `SalesOrderLine`: each line becomes one row, with its parent order's fields
repeated. Orders with no lines produce no rows (in practice every order has at least one line;
this edge case is acceptable and need not be special-cased).

---

## UX flow

On the sales order index, add an "Export" control next to the existing filters / "Import CSV"
button. It is a plain link (anchor), not a Livewire action, so the browser handles the file
download directly. The link carries the current filter values as query-string params and a
format param.

```
GET /sales-orders/export?shop=3&fulfillment=ready&order_status=&q=chan&format=csv
```

Provide two links (or one link plus a small format toggle): `format=csv` and `format=xlsx`.
The simplest robust UI is two buttons: "Export CSV" and "Export XLSX", each linking to the
export route with the current filters plus the matching `format`.

The filename is `sales-orders-{Ymd-His}.{ext}`, e.g. `sales-orders-20260619-194530.csv`.

An export with zero matching orders still returns a valid file containing only the header row
(not an error).

---

## Route

A binary download is a classic controller responsibility, not a Livewire full-page component.
Add an invokable controller and a GET route, declared BEFORE the wildcard `{order}` route.

`routes/web.php` (next to the other sales order routes):

```php
use App\Http\Controllers\SalesOrderExportController;

Route::get('/sales-orders/export', SalesOrderExportController::class)->name('sales.orders.export');
```

Confirm final order is: `index`, `create`, `import`, `export`, then `{order}` (literal
segments before the wildcard so `export` is not captured as an order id).

---

## Controller: `app/Http/Controllers/SalesOrderExportController.php`

Invokable. Reads the same filter params as the index, scopes by `allowedTenantIds()`, and
returns a Maatwebsite download.

```php
<?php

namespace App\Http\Controllers;

use App\Exports\SalesOrdersExport;
use App\Models\Shop;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SalesOrderExportController extends Controller
{
    public function __invoke(Request $request): BinaryFileResponse
    {
        $allowedTenantIds = $this->allowedTenantIds();

        if (! $this->isInternalUser() && $allowedTenantIds === []) {
            abort(403);
        }

        // Resolve and harden the shop filter. A tampered or out-of-scope shop id must
        // make the export EMPTY -- never drop the filter, because dropping it would
        // export the whole tenant while the index (same tampered URL) shows zero rows.
        // Match the index: an unknown shop yields no results, not a wider result.
        $shopId = trim((string) $request->query('shop', ''));
        $shopFilterAllowed = true;

        if ($shopId !== '') {
            $shopFilterAllowed = Shop::query()
                ->whereIn('tenant_id', $allowedTenantIds)
                ->whereKey((int) $shopId)
                ->exists();
        }

        $filters = [
            'allowed_tenant_ids'  => $allowedTenantIds,
            'shop_id'             => $shopId,
            'shop_filter_allowed' => $shopFilterAllowed,
            'fulfillment'         => trim((string) $request->query('fulfillment', '')),
            'order_status'        => trim((string) $request->query('order_status', '')),
            'search'              => trim((string) $request->query('q', '')),
        ];

        $format = $request->query('format') === 'xlsx' ? 'xlsx' : 'csv';
        $writer = $format === 'xlsx' ? ExcelFormat::XLSX : ExcelFormat::CSV;
        $filename = 'sales-orders-'.now()->format('Ymd-His').'.'.$format;

        return Excel::download(new SalesOrdersExport($filters), $filename, $writer);
    }

    // TODO: remove unauthenticated fallback when auth is implemented
    private function isInternalUser(): bool
    {
        $user = Auth::user();

        return ! $user || $user->user_type === 'internal';
    }

    private function allowedTenantIds(): array
    {
        if ($this->isInternalUser()) {
            return Tenant::query()->pluck('id')->all();
        }

        return Auth::user()
            ->tenantUsers()
            ->where('status', 'active')
            ->pluck('tenant_id')
            ->all();
    }
}
```

Note: the access-control helpers are copied verbatim to match the house style used by the
Livewire components (v2/v3 also duplicate them). Extracting a shared trait is listed under
Follow-up.

---

## Export class: `app/Exports/SalesOrdersExport.php`

`FromQuery` for memory safety (chunked by Maatwebsite), `WithHeadings`, `WithMapping`.

```php
<?php

namespace App\Exports;

use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class SalesOrdersExport implements FromQuery, WithHeadings, WithMapping
{
    /**
     * @param array{allowed_tenant_ids: array<int,int>, shop_id: string,
     *              shop_filter_allowed: bool, fulfillment: string,
     *              order_status: string, search: string} $filters
     */
    public function __construct(private array $filters) {}

    public function query(): Builder
    {
        $f = $this->filters;

        // Drive off lines so each line is one row; eager-load the parent order and sku.
        return SalesOrderLine::query()
            ->with(['sku:id,sku', 'salesOrder'])
            // A tampered / out-of-scope shop id forces an empty result, matching the index.
            ->when(! $f['shop_filter_allowed'], fn ($q) => $q->whereRaw('1 = 0'))
            ->whereHas('salesOrder', function (Builder $query) use ($f) {
                $query
                    ->whereIn('tenant_id', $f['allowed_tenant_ids'])
                    ->when($f['shop_id'] !== '', fn ($q) => $q->where('shop_id', (int) $f['shop_id']))
                    ->when($f['fulfillment'] !== '', fn ($q) => $q->where('fulfillment_status', $f['fulfillment']))
                    ->when($f['order_status'] !== '', fn ($q) => $q->where('order_status', $f['order_status']))
                    ->when($f['search'] !== '', function ($q) use ($f) {
                        $like = '%'.$f['search'].'%';
                        $q->where(fn ($inner) => $inner
                            ->where('platform_order_id', 'like', $like)
                            ->orWhere('recipient_name', 'like', $like));
                    });
            })
            // Mirror the index ordering (newest order first) so the export row order
            // matches what the operator sees on screen, then keep each order's lines
            // grouped and in a stable order.
            ->orderByDesc(
                SalesOrder::select('created_at')
                    ->whereColumn('sales_orders.id', 'sales_order_lines.sales_order_id')
            )
            ->orderByDesc('sales_order_id')
            ->orderBy('id');
    }

    public function headings(): array
    {
        return [
            'platform_order_id',
            'sku',
            'quantity',
            'line_note',
            'recipient_name',
            'recipient_phone',
            'recipient_country_code',
            'recipient_postal_code',
            'recipient_state',
            'recipient_city',
            'recipient_address_line1',
            'recipient_address_line2',
            'order_note',
            'order_status',
            'fulfillment_status',
            'source',
            'created_at',
        ];
    }

    /**
     * @param SalesOrderLine $line
     */
    public function map($line): array
    {
        $order = $line->salesOrder;

        return [
            (string) ($order->platform_order_id ?? ''),
            (string) ($line->sku->sku ?? ''),
            (int) $line->quantity,
            (string) ($line->note ?? ''),
            (string) ($order->recipient_name ?? ''),
            (string) ($order->recipient_phone ?? ''),
            (string) ($order->recipient_country_code ?? ''),
            (string) ($order->recipient_postal_code ?? ''),
            (string) ($order->recipient_state ?? ''),
            (string) ($order->recipient_city ?? ''),
            (string) ($order->recipient_address_line1 ?? ''),
            (string) ($order->recipient_address_line2 ?? ''),
            (string) ($order->note ?? ''),
            (string) $order->order_status,
            (string) $order->fulfillment_status,
            (string) $order->source,
            optional($order->created_at)->toIso8601String() ?? '',
        ];
    }
}
```

The heading list (rows 1 to 13) MUST stay identical to the v3 import header. If you change
one, change both, or the round-trip breaks. Consider adding a test that asserts the first 13
headings here equal `SalesOrderImport`'s expected header (see test 9).

---

## Index changes

On `resources/views/livewire/sales-order-index.blade.php`, add export links near the existing
"Import CSV" button. Carry the current filters in the query string so the export matches the
on-screen result.

```blade
<flux:button
    as="a"
    href="{{ route('sales.orders.export', [
        'shop'         => $shopId ?: null,
        'fulfillment'  => $fulfillmentStatus ?: null,
        'order_status' => $orderStatus ?: null,
        'q'            => $search ?: null,
        'format'       => 'csv',
    ]) }}"
    variant="ghost"
>
    {{ __('sales_orders.export_csv_btn') }}
</flux:button>

<flux:button
    as="a"
    href="{{ route('sales.orders.export', [
        'shop'         => $shopId ?: null,
        'fulfillment'  => $fulfillmentStatus ?: null,
        'order_status' => $orderStatus ?: null,
        'q'            => $search ?: null,
        'format'       => 'xlsx',
    ]) }}"
    variant="ghost"
>
    {{ __('sales_orders.export_xlsx_btn') }}
</flux:button>
```

Use a plain anchor (or `flux:button as="a"`), NOT `wire:click` and NOT `wire:navigate` --
the response is a file download, so it must be a normal browser navigation.

---

## Lang keys to add in `lang/en/sales_orders.php`

```php
// Export
'export_csv_btn'  => 'Export CSV',
'export_xlsx_btn' => 'Export XLSX',
```

Add keys to `lang/en/sales_orders.php` only. The `lang/ja/`, `lang/zh_TW/`, and `lang/zh_CN/`
`sales_orders.php` files currently inherit English wholesale via `return require ...` of the
en file -- do NOT split them into per-key stubs in this task.

---

## Tests -- `tests/Feature/SalesOrderExportTest.php`

Use `RefreshDatabase`. Split the two concerns:

- **Download mechanics** (filename, format, that a download happened, 403/scoping at the route
  level): use Maatwebsite's fake. Call `Excel::fake();` before hitting the route, then
  `Excel::assertDownloaded($exactFilename)`. Assert an EXACT filename, not a wildcard --
  Maatwebsite's fake matches the string and wildcards are not reliable. Because the filename
  embeds `now()`, freeze the clock first with
  `Carbon::setTestNow(Carbon::parse('2026-06-19 19:45:30'))` so the expected name is
  deterministic: `sales-orders-20260619-194530.csv` (and `.xlsx` for the xlsx test). Reset with
  `Carbon::setTestNow()` in `tearDown` (or rely on the framework reset between tests). The fake
  intercepts the download, so the file is never actually written.
- **Row content** (column order, one row per line, repeated order fields, null-to-empty,
  sku-code-not-id, integer quantity, filter results, the round-trip): do NOT rely on the fake.
  Instantiate `SalesOrdersExport` directly with a filters array and assert on
  `$export->headings()` and `$export->query()->get()->map(fn ($line) => $export->map($line))`.
  This tests the real query and mapping without parsing file bytes.

For the round-trip test (16), build the CSV bytes from the export's headings + mapped rows
(join with commas, CRLF-safe), wrap in `Illuminate\Http\Testing\File::createWithContent(...)`,
and feed it to `SalesOrderImport` against a different shop.

| # | Test | What it asserts |
|---|---|---|
| 1 | `test_export_downloads_csv_with_expected_filename` | with the clock frozen to 2026-06-19 19:45:30, `format=csv` downloads exactly `sales-orders-20260619-194530.csv` (`Excel::assertDownloaded` with the exact name) |
| 2 | `test_export_downloads_xlsx_when_format_xlsx` | same frozen clock, `format=xlsx` downloads exactly `sales-orders-20260619-194530.xlsx` |
| 3 | `test_export_defaults_to_csv_for_unknown_format` | `format=bogus` (or missing) downloads a `.csv` file |
| 4 | `test_export_has_one_row_per_line_with_order_fields_repeated` | order with 2 lines -> 2 rows; both carry the same `platform_order_id` and recipient |
| 5 | `test_export_respects_shop_filter` | two shops with orders; `shop=` filter yields only that shop's lines |
| 6 | `test_export_respects_fulfillment_status_filter` | only `fulfillment=ready` lines exported |
| 7 | `test_export_respects_order_status_filter` | only matching `order_status` lines exported |
| 8 | `test_export_respects_search_filter` | `q=` matches `platform_order_id` or `recipient_name` only |
| 9 | `test_export_first_thirteen_headings_match_import_header` | `SalesOrdersExport::headings()` first 13 entries equal the v3 import header exactly |
| 10 | `test_export_sku_column_is_code_and_quantity_is_integer` | row `sku` equals the SKU code (not id); `quantity` is an int |
| 11 | `test_export_null_fields_render_as_empty_string` | order with null `recipient_phone` exports `''`, never `'null'` |
| 12 | `test_export_empty_result_returns_header_only_file` | filters match nothing -> file with header row, no data rows, no error |
| 13 | `test_tenant_user_only_exports_own_tenant_orders` | tenant user with no filter never sees another tenant's lines |
| 14 | `test_tenant_user_tampered_shop_exports_empty_result_not_leaked` | tenant user passes another tenant's `shop` id -> export is EMPTY (header only), NOT the user's whole tenant; matches the index showing zero rows for the same tampered URL |
| 15 | `test_tenant_user_without_active_tenant_gets_403` | tenant user with no active tenant -> 403 on the export route |
| 16 | `test_exported_file_round_trips_through_import` | export a shop's orders, create matching active SKU codes in a DIFFERENT shop, then import the same bytes into that shop -> orders recreated with matching line counts and recipients |

Test 16 is the headline guarantee. Because `platform_order_id` is unique per
`(tenant_id, shop_id)`, re-importing into the SAME shop is correctly rejected as duplicate;
the round-trip test must import into a different shop (or different tenant) to prove the file
is consumable by `SalesOrderImport` unedited. That target shop must have active SKUs with the
same `sku` codes as the exported source rows, because `SalesOrderImport` resolves SKUs within
the selected target shop; without matching SKU codes, the import should correctly fail as
"unknown SKU".

---

## Constraints

- No Volt. No TypeScript. Invokable controller + a Maatwebsite export class only.
- Export columns 1 to 13 MUST be byte-identical to the v3 import header, in order.
- Read-only columns (14 to 17) are appended AFTER the import columns so re-import ignores them.
- The query MUST be scoped by `allowedTenantIds()`. An out-of-scope / tampered `shop` id forces
  an EMPTY result (not a dropped filter), matching how the index renders zero rows for the same
  URL. Dropping the filter would export the whole tenant and is a correctness bug, not a leak.
- Null DB values export as empty string, never the literal `null`.
- Use `FromQuery` (chunked) for memory safety; do not load all orders into memory at once.
- The index export control is a plain anchor / link download, not a `wire:click` action and
  not `wire:navigate`.
- Filename is `sales-orders-{Ymd-His}.{ext}`.
- Declare `/sales-orders/export` before any wildcard `{order}` route.
- Run `php artisan test` at the end and confirm all tests pass.

---

## Follow-up (out of scope for this task)

- Extract the duplicated access-control helpers (`isInternalUser`, `allowedTenantIds`) shared
  by `SalesOrderIndex`, `SalesOrderCreate`, `SalesOrderImport`, and this controller into a
  single trait. Deferred to keep this task focused and to avoid touching the other components.
- Optional: a "selected only" export that exports just the index's checked `selectedIds`
  (mirrors `bulkMarkReady`), once there is product demand for it.
