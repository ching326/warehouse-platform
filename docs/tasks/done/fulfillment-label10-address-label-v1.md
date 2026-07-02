# Fulfillment Label10 Address Label Export v1

## Goal

Add a `Label10 (2x5)` export action to the Fulfillment page so warehouse staff can print A4 address labels for selected outbound orders.

This is based on the old system's `amzorder/address_label` `LABEL 2 (2x5 OPTION)` flow, but implemented in the new system style and data model.

## References

Old system:

- `C:\laragon\www\order-manage\amzorder\address_label\label_common.php`
- `C:\laragon\www\order-manage\amzorder\address_label\label_format_2.php`
- `C:\laragon\www\order-manage\amzorder\includes\export_address_label.php`
- `C:\laragon\www\order-manage\amzorder\js\order.js`

New system:

- Existing SKU label PDF flow:
  - `app/Services/Labels/SkuLabelPdfService.php`
  - `app/Support/Labels/LabelLayout.php`
  - `config/label_layouts.php`
- Fulfillment export flow:
  - `app/Livewire/FulfillmentIndex.php`
  - `resources/views/livewire/fulfillment-index.blade.php`
  - `app/Services/Courier/CourierExportService.php`

## Decisions

### 1. Label10 counts as printed

Printing `Label10 (2x5)` must mark the selected outbound orders as printed/exported.

Use the same printed state currently used by courier CSV export:

- Current column: `outbound_orders.courier_csv_exported_at`
- Rename to: `outbound_orders.courier_label_exported_at`

After Label10 is generated, selected orders should disappear from `Print Waiting`, exactly like Yamato/Sagawa export.

### 2. Rename printed/exported column

`courier_csv_exported_at` is now too narrow because Label10 is not a courier CSV.

Rename it to:

```php
outbound_orders.courier_label_exported_at
```

Update all usages:

- Print Waiting filter
- printed / not printed filter
- courier export validation and re-export confirmation
- remap shipping invalidation
- hold blocking / printed warning
- consolidation guards
- tests
- activity log property names if present

Do not add a separate `address_label_printed_at` field in v1.

### 3. Do not group by same address

The old system grouped selected rows by same recipient/address/phone.

Do not repeat that logic in the new system. In the new system, consolidation/grouping is already handled before fulfillment export. Generate one label per selected outbound order.

### 4. Item code display source

The SKU/item code shown inside the label must follow the existing Fulfillment item code source setting.

Use the same source currently used by fulfillment export/display:

- SKU code
- stock item system code
- tenant item code
- both, if the existing setting supports it

Do not hardcode SKU.

### 5. Product name display

For item names inside the label:

1. Prefer `stock_items.short_name` when present.
2. Fallback to stock item display name/name.
3. Keep the final name on one row.
4. Apply a length limit/ellipsis so the label layout does not wrap or overflow.

Recommended helper behavior:

```php
labelName = Str::limit(short_name ?: stock_item_name, 36)
```

Adjust the exact number after PDF visual testing so it stays on one row.

### 6. Skip-used-cells

Support skip cells in v1.

When user clicks `Label10 (2x5)`, open a modal that lets the user select used cells before generating the PDF.

Requirements:

- Layout: 2 columns x 5 rows per page.
- Let user select cells for up to the first 3 pages.
- Max selectable cell positions: 30.
- Skip cells only affect the beginning of the output.
- After skipped cells are inserted, labels continue normally.

This mirrors the old system's `LABEL 2 (2x5 OPTION)` behavior, but the UI should follow the new system modal style.

## UI

### Fulfillment bulk export menu

Add a new action under the Fulfillment export menu:

```text
Label10 (2x5)
```

Placement:

- Same export menu area as Yamato / Sagawa.
- Only enabled when at least one outbound order is selected.

Click behavior:

1. User selects outbound orders.
2. User clicks `Label10 (2x5)`.
3. A modal opens for skip-used-cells selection.
4. User can select none, one, or multiple cells.
5. User clicks `Generate PDF`.
6. PDF downloads/opens.
7. Orders are marked printed through `courier_label_exported_at`.

### Skip cells modal

Modal content:

- Title: `Skip used cells`
- Short text: `Select cells that are already used. Labels will start after the selected cells.`
- Page sections:
  - Page 1: cells 1-10
  - Page 2: cells 11-20
  - Page 3: cells 21-30
- Buttons:
  - `Cancel`
  - `Generate PDF`

The modal should use the same general style as existing app modals/toasts.

## Validation

Reuse the same safety model as courier export unless stated otherwise.

Block Label10 export when:

- no selected outbound orders
- selected orders are outside the user's allowed tenants
- selected orders span multiple tenants
- outbound status is not exportable
- outbound is on hold
- outbound has no ready/leaf lines
- sales order under it is on hold / cancel requested / cancelled
- outbound is already shipped or cancelled

Already printed orders:

- If any selected outbound has `courier_label_exported_at`, show the existing re-export confirmation flow.
- Confirmation should allow re-export and update `courier_label_exported_at` to the new timestamp.

## Data Builder

Create a new service:

```php
App\Services\Labels\AddressLabelDataBuilder
```

Input:

- selected `OutboundOrder` collection

Output per outbound order:

```php
[
    'postal_code' => string,
    'address_line1' => string,
    'address_line2' => string,
    'recipient_name' => string,
    'recipient_phone' => string,
    'show_phone' => bool,
    'items_line' => string,
    'description_line' => string,
    'shipper_name' => string,
    'shipper_address' => string,
    'total_weight' => int|null,
]
```

### Recipient data

Use `OutboundOrder` recipient fields:

- `recipient_postal_code`
- `recipient_state`
- `recipient_city`
- `recipient_address_line1`
- `recipient_address_line2`
- `recipient_name`
- `recipient_phone`

Postal code:

- Japan 7-digit postal codes should display as `123-4567`.
- Preserve non-JP/unknown formats as entered.

### Shipper data

Use sender/shop/tenant data in this order:

1. Linked sales order shop sender fields if available.
2. Courier sender config fallback:
   - `config('courier.sender.name')`
   - `config('courier.sender.address1')`
   - `config('courier.sender.address2')`

If no shop data exists, fallback to tenant name/code where appropriate.

### Items line

Build from outbound leaf lines.

Rules:

- Ignore lines with qty <= 0.
- Display quantity before code, same style as old system:
  - `SKU-A`
  - `2 x SKU-A`
  - `2 x SKU-A, SKU-B`
- If multiple SKUs exist, add a short marker at the beginning.
- If any qty > 1, add a short marker at the beginning.
- If consolidated outbound contains more than one sales order, add a short marker at the beginning.

Marker text can be English-only in v1 if no locale key exists yet, but prefer lang keys.

Suggested:

```text
Consolidated
Multiple
Qty
```

Keep the final line compact. It must not overflow the label.

### Description/name line

Use short product names:

- one line only
- prefer `stock_items.short_name`
- fallback to stock item display name/name
- length limited

For multiple SKUs, either:

- show the first short name only, or
- show compact joined names if it still fits

Prefer the first short name in v1 for layout stability.

### Weight

Show total weight in grams.

Source:

1. `OutboundOrder.package_weight_g` if set.
2. Otherwise calculate from leaf lines:
   - stock item weight in grams x qty
   - convert kg/g if needed using existing stock item weight fields
3. If unknown, leave blank or show `-`.

Do not block printing for missing weight.

## PDF Service

Create:

```php
App\Services\Labels\AddressLabelPdfService
```

Do not put address label logic into `SkuLabelPdfService`.

Reason:

- SKU label service is for product/barcode labels.
- Address labels include recipient, address, shipper, item summary, and weight.

Shared foundation:

- Reuse `TCPDF`
- Reuse `LabelLayout`
- Add a layout to `config/label_layouts.php`

Suggested layout key:

```php
address_label_10_a4
```

Layout:

- A4 portrait
- 2 columns
- 5 rows
- 10 cells per page
- fill order: row first
- supports skip: true

Use old system sizing as the starting point:

- page: A4 210 x 297 mm
- margin: 4 mm or 18 mm depending test output
- old `label_format_2.php` uses 18 mm margin
- old `label_format_2_margin_4mm.php` uses 4 mm margin

Pick the one that visually matches the desired label paper. If uncertain, start with 4 mm because it gives more usable space.

## Controller / Download Flow

Add a dedicated download route, similar to courier export download style.

Options:

### Option A - Store batch file

Create a batch record/file like courier export:

- stores PDF under local disk
- records file name, path, tenant, user, exported_at, order count
- downloads via a GET route

Pros:

- audit-friendly
- re-download possible
- consistent with courier export batches

### Option B - Stream PDF directly

Generate and stream PDF directly from Livewire/controller.

Pros:

- simpler

Recommendation: Option A if easy to mirror existing courier export flow. Option B acceptable for v1 if the implementation remains simple and audit activity still records the event.

## Activity Log / Audit

When PDF is generated:

- update `courier_label_exported_at`
- activity event on each related sales order:

```text
courier_label_exported
```

Properties:

- `type`: `label10_2x5`
- `file_name`
- `outbound_order_id`
- `outbound_order_ref`
- `re_export`
- `skip_cells`

## Tests

Add focused tests.

### Data / validation

1. Label10 blocks no selection.
2. Label10 blocks mixed tenant selection.
3. Label10 blocks held outbound orders.
4. Label10 blocks shipped/cancelled outbound orders.
5. Label10 blocks outbound orders with no ready/leaf lines.
6. Already printed orders require confirmation.
7. Confirmed re-export succeeds and updates `courier_label_exported_at`.

### PDF / layout

8. Service generates a PDF string for one outbound order.
9. PDF includes recipient name/address.
10. PDF includes compact item code using Fulfillment item code source setting.
11. PDF prefers stock item short name over full name.
12. Long names are limited to one row.
13. Weight is shown when package weight exists.
14. Missing weight does not block PDF generation.
15. Skip cells insert blank cells before labels.
16. Skip cells support up to first 3 pages / 30 cells only.

### Printed state

17. Label10 export sets `courier_label_exported_at`.
18. Print Waiting excludes Label10-exported orders.
19. Printed filter includes Label10-exported orders.
20. Remap shipping invalidates `courier_label_exported_at` when shipping method changes.

### Migration rename

21. Migration renames `courier_csv_exported_at` to `courier_label_exported_at`.
22. Existing printed timestamps survive the rename.

## Non-goals

- Do not recreate old address grouping by same recipient/address/phone.
- Do not add `address_label_printed_at`.
- Do not build Label1/Label3/Label4 in v1.
- Do not add QR/barcode to address label in v1.
- Do not change courier CSV export format.
- Do not change actual shipping/mark-shipped behavior.

## Implementation Notes

- Be careful when renaming `courier_csv_exported_at`; search all references.
- Use tenant-scoped outbound order loading, same as courier export.
- Keep PDF rendering deterministic for tests.
- Avoid hardcoded Japanese strings in Blade/PHP; add English lang keys first unless a translation is explicitly requested.
- Do not use PowerShell rewrite commands on CJK lang files.

