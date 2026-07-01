# SKU Label Printing v1

## Goal

Let an internal user print barcode labels for SKUs from the SKUs page. A per-row
**Print** button opens a dedicated print page where the user chooses what to encode
(SKU code, FNSKU, or primary barcode), how many copies, the label layout, and which
sheet cells to skip. Output is an exact-size **PDF** suitable for label printers and
pre-cut label sheets.

## Background

The old system has two relevant modules:

- `order-manage/label` - FNSKU label generator. Code 128 barcode + code text +
  description line, rendered to PDF with exact paper sizes:
  - **62 x 29 mm thermal** (Brother QL-800): paper `62mm x 29mm`, one label per page,
    barcode height 15mm, text 10pt.
  - **40-up A4**: A4 portrait, 4 columns x 10 rows, each cell `52.5mm x 29.7mm`,
    4mm padding, barcode height 8mm, code 8pt, description 7pt. (The old code nudged
    rows down with hardcoded "fudge" offsets to fight printer drift; we replace that
    with real top-margin and gutter parameters.)
- `order-manage/amzorder/address_label` - grid label printer (TCPDF) that positions
  cells by `pageMargin + col * cellWidth + cellMargin` and treats an empty entry as a
  blank cell. This is the mechanism behind printing onto partially used sheets.

This task brings both ideas into the new system as one clean, data-driven feature.

## Decisions

- **PDF, exact size.** Use **TCPDF**, pinned `tecnickcom/tcpdf:^6.7`. Precise mm
  positioning, native Code 128 (`write1DBarcode`), native parametric grid + skipping.
  (picqer stays only for the on-screen pick summary SVG; TCPDF owns PDF labels.)
- **Layouts are data, not code.** A label layout is a definition (paper size, columns,
  rows, margins, gutters, cell size, barcode height, font sizes, fill order). v1 stores
  these in a config file; adding a layout (e.g. 3x8 A4) is a new entry, not new code.
  This is the seam for future user-configurable sizes/margins.
- **Print button has no dropdown.** Clicking Print on a SKU row opens the print page
  seeded with that SKU. All choices (content, qty, layout, name line, skip cells) are
  made on the print page. There is **no default content type** - the user picks.
- **Content resolves to one deterministic value, with no fallback.** Options are built
  per SKU: `SKU` (`sku.sku`), `FNSKU` (`sku.platform_label_code`), and one option per
  stock-item barcode **type** that has a primary active alias - since `is_primary` is
  per-`barcode_type`, there is no single "primary barcode", so the user picks the type
  (see section 3).

  There is NO priority chain and NO substitution. If the chosen value is absent, that
  option is simply **disabled** in the UI - the user chooses something else. This is
  intentionally different from the pick summary, which merges and ranks all scannable
  aliases for matching; the label prints exactly the one value selected.
- **Quantity: fill once, apply to all.** A single qty input plus an "Apply to all"
  button sets the quantity for every label entry on the page.
- **Cell skipping.** For sheet layouts (more than one cell per page), the print page
  shows a clickable grid; cells the user marks as already-used are left blank on the
  first page so labels start at the first free cell. Ignored for single-label layouts.
- **PDF is streamed from a plain controller, not from Livewire.** The Livewire page
  handles the interactive choices and validation; generation happens on a normal GET
  download route (see section 5). This avoids Livewire file-download fragility.
- **Internal user only** (`authorizeInternalUser()`), tenant-scoped explicitly (do not
  rely on implicit route-model binding for isolation).

## Explicit Non-Goals (v1)

- No user-facing layout/margin editor yet (config only; DB-backed editor is future).
- No 3x8 / 24-up layout yet (the model must support it; the entry is added later).
- No saving of label batches or print history.
- No tenant-initiated printing (internal staff only).
- No QR codes; Code 128 only.
- No change to barcode alias data or scan matching.
- No fallback/auto-selection of a barcode when the chosen content has no value.

## 1. Dependency

```bash
composer require tecnickcom/tcpdf:^6.7
```

## 2. Label layout model (data-driven)

New file: `config/label_layouts.php`. Each layout keyed by slug; distances in mm.

```php
return [
    '40up_a4' => [
        'name' => '40 per A4 sheet (52.5 x 29.7 mm)',
        'paper' => 'A4',
        'orientation' => 'P',
        'page_width' => 210,
        'page_height' => 297,
        'cols' => 4,
        'rows' => 10,
        'margin_top' => 0,
        'margin_left' => 0,
        'gutter_x' => 0,
        'gutter_y' => 0,
        'cell_width' => 52.5,
        'cell_height' => 29.7,
        'cell_padding' => 4,
        'barcode_height' => 8,
        'code_font_pt' => 8,
        'name_font_pt' => 7,
        'fill' => 'row',          // 'row' or 'column'
        'supports_skip' => true,
    ],

    '62x29_thermal' => [
        'name' => '62 x 29 mm thermal (1 per page)',
        'paper' => [62, 29],
        'orientation' => 'L',
        'page_width' => 62,
        'page_height' => 29,
        'cols' => 1,
        'rows' => 1,
        'margin_top' => 0,
        'margin_left' => 0,
        'gutter_x' => 0,
        'gutter_y' => 0,
        'cell_width' => 62,
        'cell_height' => 29,
        'cell_padding' => 4,
        'barcode_height' => 15,
        'code_font_pt' => 10,
        'name_font_pt' => 10,
        'fill' => 'row',
        'supports_skip' => false,
    ],
];
```

Future: a `label_layouts` table with the same fields (plus `tenant_id`) and a settings
screen. The generator reads a layout as a plain definition array, so the source does
not matter.

Value object: `app/Support/Labels/LabelLayout.php`

- `cellsPerPage(): int` - `cols * rows`
- `cellOrigin(int $cellIndex): array` - `[x, y]` top-left of the cell on its page,
  computed from margins, gutters, cell size, and `fill` order (the generalized version
  of the old `pageMargin + col * cellWidth + margin` math).
- `supportsSkip(): bool`

## 3. Content resolution

`is_primary` is enforced **per `barcode_type`**, not per stock item
(`BarcodeAliasService::unsetOtherPrimaryAliases(..., $barcodeType, ...)`). So a stock
item can simultaneously have a primary `jan`, a primary `supplier_label`, etc. There is
therefore no single "primary barcode" - the user must choose a barcode **type**.

Content options are built **per SKU** (no default; options that have no value are simply
not offered):

- **SKU** -> `$sku->sku` (always present).
- **FNSKU** -> `$sku->platform_label_code`; offered only when non-empty.
- **Barcode by type** -> one option per stock-item barcode type that has a primary active
  alias, labelled e.g. "Barcode (JAN)", "Barcode (Supplier label)". The encoded value is
  that type's primary active alias.

`content` values are: `'sku'`, `'fnsku'`, or `'barcode:{type}'` (e.g. `barcode:jan`).

Add deterministic accessors and reuse them everywhere this is resolved:

```php
// StockItem
// barcode types that have a primary active alias, for building the options
public function primaryBarcodeTypes(): array;            // e.g. ['jan', 'supplier_label']
// the primary active alias of a given type (exactly one, by the per-type rule), else null
public function primaryBarcodeAliasOfType(string $barcodeType): ?BarcodeAlias;
```

Both accessors MUST filter explicitly: `model_type = stock_item`, `is_active = true`,
`is_primary = true`, and a valid `barcode_type`. The relation already scopes
`model_type`, but spell it out so an inactive or non-primary alias can never be picked.

No priority chain, no "first active" fallback, no merge with SKU aliases: a type is
offered only if it has a primary active alias, and selecting it prints exactly that
alias. `code_text` (the printed human-readable line) equals the encoded value. The
optional name line is the stock item localized display name.

## 4. PDF generator service

New file: `app/Services/Labels/SkuLabelPdfService.php`

```php
/**
 * @param  array<int, array{value:string, code_text:string, name:?string}>  $labels
 *         already expanded by quantity and in print order
 * @param  array<int, int>  $skipCells  zero-based cell indices to leave blank (page 1)
 */
public function render(string $layoutKey, array $labels, array $skipCells = []): string; // PDF bytes
```

Behavior:

1. Resolve the layout from `config('label_layouts')`; throw on unknown key.
2. For sheet layouts, prepend the skipped cell indices as blanks on page 1 so real
   labels land in free cells. For single-label layouts (`supports_skip = false`),
   ignore `skipCells` entirely.
3. Create TCPDF with the layout paper size/orientation, zero auto margins, no
   header/footer. NOTE: for `62x29_thermal`, `paper => [62, 29]` plus `orientation => L`
   can swap width/height depending on TCPDF's handling of an explicit format array vs
   orientation. Lock the known-good combo during implementation and assert it with a
   render test (see section 9) - the produced page must measure 62mm wide x 29mm tall.
4. Per filled cell: compute `[x, y]` via `LabelLayout::cellOrigin()`, draw Code 128 with
   `write1DBarcode($value, 'C128', x, y, width, barcode_height)`, then the code text,
   then the optional name line, respecting `cell_padding`, centered.
5. New page when the page's cells are full.
6. Return `$pdf->Output('', 'S')`.

Keep per-cell drawing in one private method so new layouts need only their config entry
plus the shared `cellOrigin` math.

## 5. Print page + download route

### Print page (Livewire, interactive choices only)

`app/Livewire/SkuLabelPrint.php` + `resources/views/livewire/sku-label-print.blade.php`
Route: `GET /skus/{sku}/label` -> name `skus.label`.

- `mount(Sku $sku)`: call `authorizeInternalUser()`, then **re-scope the bound SKU to
  the allowed tenant(s) and abort(404) if it does not belong** - do not trust implicit
  route-model binding for isolation.
- State: `layoutKey`; `entries` (seeded with the clicked SKU; each
  `{ sku_id, content, qty }`, `content` empty until chosen); `applyQty`; `includeName`;
  `skipCells`.
- Per entry the user picks **content** (SKU / FNSKU / one option per available barcode
  type, no default; options built per section 3) and **qty** (>= 1).
- Controls: "Apply to all" sets every entry qty to `applyQty`; layout selector; when the
  layout `supportsSkip()`, a clickable `cols x rows` grid toggling `skipCells` (hidden
  otherwise); optional "Add SKU" to append entries (keeps the batch shape for future
  bulk select).
- **Generate**: validate; store the **unresolved choices only** in the session under a
  one-shot key - `{ layoutKey, entries: [{ sku_id, content, qty }], skipCells,
  includeName }` (no resolved values, so the controller has the `sku_id`s to re-scope);
  then `return redirect()->route('skus.label.download')`.

Validation: `layoutKey` known; every entry has a chosen `content` whose value is present
for that SKU; `qty >= 1`; `skipCells` within `0 .. cellsPerPage - 1`.

### Download route (plain controller, streams PDF)

`GET /skus/labels/download` -> name `skus.label.download`, internal-auth.

- Controller reads AND removes the one-shot payload atomically with
  `session()->pull($key)` at the very start (so it cannot leak past one request); if
  missing/expired, flash `skus.label_session_expired` and redirect back.
- **Re-load each `sku_id` scoped to the allowed tenant(s)** (abort/redirect if any does
  not belong - do not trust the session blindly), **re-resolve each `content` to its
  value** (section 3), then **expand by `qty`** into the ordered label list.
- Generate via `SkuLabelPdfService`, then return a **normal streamed response** (not
  `streamDownload`, which forces an attachment) served **inline** so it opens in the
  browser PDF viewer / print dialog:
  - `Content-Type: application/pdf`
  - `Content-Disposition: inline; filename="sku-labels-{sku}-{Ymd}.pdf"`
    (single seed SKU) or `inline; filename="sku-labels-{Ymd}.pdf"` (batch).
  (The payload was already removed by `session()->pull()` above, so nothing leaks even
  though code after `return response(...)` would not run.)

## 6. Print entry point on the SKUs page

`resources/views/livewire/skus-index.blade.php` - per-row actions cell
(`sku-row-actions`, around line 330): add a **Print** button immediately before the
existing edit/manage control:

```blade
<flux:button type="button" size="sm" variant="subtle"
    href="{{ route('skus.label', $sku) }}" wire:navigate>
    {{ __('skus.btn_print_label') }}
</flux:button>
```

`wire:navigate` is correct here: it opens the config page, not the PDF.

## 7. Routes

```php
// Static segments first (matches the existing /skus/create, /skus/import pattern)
Route::get('/skus/labels/download', [SkuLabelController::class, 'download'])->name('skus.label.download');
// Then dynamic SKU routes
Route::get('/skus/{sku}/label', SkuLabelPrint::class)->name('skus.label');
```

Declare the static `/skus/labels/download` route before the dynamic `/skus/{sku}/...`
routes - it does not collide today, but static-first matches the existing pattern and
avoids surprises if a generic `/skus/{sku}` is ever added. Tenant isolation is enforced
in the component/controller (above), not by route binding.

## 8. Lang keys

Add to `lang/en/skus.php` (plus ja, zh_TW, zh_CN), ASCII punctuation only:

- `btn_print_label`, `label_print_title`, `label_content`, `label_content_sku`,
  `label_content_fnsku`, `label_content_barcode_type` (e.g. "Barcode (:type)"),
  `label_qty`, `label_apply_all`, `label_layout`, `label_include_name`,
  `label_skip_cells_hint`, `label_generate`, `label_no_content_selected`,
  `label_value_missing`, `label_add_sku`, `label_session_expired`
  (e.g. "Label print session expired. Please generate again.").

## 9. Tests

`tests/Unit/`:

- `LabelLayout::cellOrigin()` returns expected `[x, y]` for the first cell, a mid-sheet
  cell, and respects `fill` order (row vs column).
- `cellsPerPage()` equals `cols * rows`.
- `SkuLabelPdfService` ignores `skipCells` for the thermal (single-cell) layout.
- `SkuLabelPdfService` renders the `62x29_thermal` page at 62mm x 29mm (locks the
  paper-size/orientation combo).

`tests/Feature/` (`SkuLabelPrintTest`):

1. Content options built per SKU: `FNSKU` offered only when `platform_label_code`
   present; one `barcode:{type}` option per stock-item type that has a primary active
   alias (a stock item with primary `jan` and primary `supplier_label` offers both);
   selecting a `barcode:{type}` prints that type's primary alias; with no value present,
   generation is rejected (no fallback substitution).
2. "Apply to all" sets qty across all entries.
3. Generate with content = `sku` redirects to the download route, and the download route
   returns `Content-Type: application/pdf` with `Content-Disposition: inline` and a
   non-empty body whose first bytes are `%PDF`.
3a. Missing/expired session payload redirects back with the `label_session_expired`
    message instead of erroring.
4. Download filename is `sku-labels-{sku}-{Ymd}.pdf`.
5. Quantity expansion: qty 10 produces 10 labels (assert via the label list passed to
   `SkuLabelPdfService` using a spy/fake).
6. Skip cells: marking cells N leaves them blank and shifts labels to the next free
   cells (assert through the service input).
7. Validation: generate fails when an entry has no content chosen.
8. Tenant isolation: cannot open the print page or hit the download route for another
   tenant's SKU (both the bound SKU and the session payload are re-scoped).

## 10. Verification

```
composer require tecnickcom/tcpdf:^6.7
vendor/bin/pint app config tests
php vendor/bin/phpstan analyse --memory-limit=512M
php artisan test
```

PHPStan must stay at zero new errors against the baseline.

## Notes / future

- **3x8 A4 (24-up)** is just another `config/label_layouts.php` entry - no code change.
- **User-configurable sizes/margins**: move layouts to a `label_layouts` table (same
  fields + `tenant_id`) plus a settings screen; the generator already reads a layout as
  a plain definition, so only the source changes.
- **Bulk printing**: the print page is built as a batch, so a future "Print labels"
  action on the SKUs selection toolbar can seed multiple SKUs into one sheet.
- The label content is an explicit user choice (SKU, FNSKU, or a specific
  `barcode:{type}`), with no hidden fallback or priority logic. Because `is_primary` is
  per `barcode_type`, there is intentionally no single "primary barcode" - do not build
  one. This also differs from the pick summary's merged/ranked matching list on purpose.
- The old 40-up vertical "fudge" offsets are dropped; correct output comes from real
  `margin_top` / `gutter_y`, which is also what makes layouts user-editable later.
