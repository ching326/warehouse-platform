# Fulfillment Print History and Requeue Print v1

## Goal

Make courier label / CSV printing easier to recover from.

Users need two related workflows:

1. Requeue an already-printed but unshipped outbound order so it appears in Print Waiting again.
2. View and download previous courier CSV / Label10 address label exports from a dedicated Print History page.

Do not auto-requeue when shipping method changes. Not every shipping method edit means the old label is invalid, so this must stay a manual user action in v1.

## Existing Behavior

- `outbound_orders.courier_label_exported_at` means the outbound order was last exported for courier label / courier CSV.
- Print Waiting filters to orders where:
  - `courier_label_exported_at IS NULL`
  - `hold_status` is active / not on hold
  - status is not cancelled
- Export services already write history:
  - `courier_export_batches`
  - `courier_export_batch_orders`
- The download route already exists:
  - `GET /courier-export-batches/{batch}/download`
- Outbound detail now shows recent export history for that outbound order.

## Part A - Requeue Print Action

### Meaning

Requeue print means:

- Set `outbound_orders.courier_label_exported_at = null`
- Keep all `courier_export_batches` and `courier_export_batch_orders` history
- Do not delete files
- Do not change shipment status
- Do not change tracking number, shipping method, courier cost, or lines

After requeue, the order should appear in Print Waiting again.

If the order is on hold, requeue only clears the exported timestamp. The order still stays out of Print Waiting until the hold is released.

### Where to Add It

Add a `Requeue print` action in:

1. Fulfillment index action menu / row action area
2. Outbound order detail action section

Show it only when all are true:

- outbound order has `courier_label_exported_at !== null`
- outbound order status is not `shipped`
- outbound order status is not `cancelled`

For on-hold orders:

- Allow requeue print.
- It will not appear in Print Waiting until the hold is released, because Print Waiting excludes on-hold orders.
- This is okay; do not change hold behavior.

### Confirmation

Requeue should require confirmation:

Message:

> Requeue this order for printing? It will appear in Print Waiting again. Previous export files and history will stay available.

Button text:

- Cancel
- Requeue print

Use existing app toast / confirmation style if possible.

### Success Message

Use a success toast:

> Order requeued for printing.

For bulk action:

> :updated order(s) requeued for printing.

The bulk action should aggregate per-order service results into updated/skipped counts.

Do not show `0 skipped` when skipped is zero, matching the current bulk-message preference.

### Service / Logic

Add a small service or helper method rather than duplicating logic in two Livewire components.

Suggested class:

`app/Services/Fulfillment/RequeuePrintService.php`

Method:

```php
public function requeue(OutboundOrder $order, array $allowedTenantIds): bool
```

Rules:

- Re-query the outbound order inside a DB transaction.
- Scope by allowed tenant ids.
- Lock row with `lockForUpdate()`.
- Return false / throw a clear exception if:
  - order not found in allowed tenant scope
  - order is shipped
  - order is cancelled
  - `courier_label_exported_at` is already null
- Update only `courier_label_exported_at` to null.

No inventory changes.
No batch/history deletion.

### Activity Log

If existing outbound order activity logging is available, log:

- event: `courier_label_requeued`
- properties:
  - outbound_order_id
  - outbound_order_ref
  - previous_courier_label_exported_at
  - user_id

If outbound activity logging is not consistently used yet, skip logging rather than adding a new logging pattern.

## Part B - Print History Page

### Route

Add a new page:

`GET /fulfillment/print-history`

Route name:

`fulfillment.print-history`

Add the route inside the existing authenticated route group in `routes/web.php`, next to the other `/fulfillment/*` routes. The route only needs login-level middleware; internal-vs-tenant visibility is enforced by the component query scope below.

Livewire component:

`app/Livewire/FulfillmentPrintHistory.php`

View:

`resources/views/livewire/fulfillment-print-history.blade.php`

### Entry Point

Do not add this page to the main nav bar.

Add the entry inside the Fulfillment page export menu / courier menu, near:

- Export Yamato
- Export Sagawa
- Label10 (2x5)

Add:

- Print History

This should be a normal page link to:

`/fulfillment/print-history`

Use `wire:navigate` for this page link. Do not use `wire:navigate` on actual file download links inside the Print History page.

Use English key now. Add CJK translations later via `docs/translation-backlog.md`.

### Purpose

This page lists previous courier label / CSV export batches and lets the user download the file again.

It should show both:

- Yamato CSV
- Sagawa CSV
- Label10 (2x5) PDF

### Columns

Use a compact table.

Columns:

- Exported at
- Type
- Tenant
- Orders
- File
- Exported by
- Actions

Details:

- Exported at: show date and time in the app timezone for v1. Do not derive warehouse timezone from batch orders.
- Type:
  - Yamato CSV
  - Sagawa CSV
  - Label10 (2x5)
- Tenant: tenant code / name
- Orders: `order_count`
- File: `file_name`
- Exported by: user name or `-`
- Actions: `Download`

### Filters

Add filters:

- Tenant
- Type
- Date from
- Date to
- Search

Type filter values must use the stored `courier_export_batches.carrier` values:

- `yamato`
- `sagawa`
- `label10_2x5`

Display labels should come from the shared export-type label mapping. Do not use display strings such as `Label10 (2x5)` as query values.

Search should match:

- `file_name`
- batch id
- sales order / marketplace order id via `courier_export_batch_orders.platform_order_id`
- outbound ref by joining `outbound_orders` through `courier_export_batch_orders.outbound_order_id` and matching `outbound_orders.ref`

Important: do not assume `courier_export_batch_orders.platform_order_id` always contains the outbound ref. For platform orders it stores the sales order platform id; for manual no-sales-order outbounds it may store the outbound ref. Search must use `outbound_order_id` -> `outbound_orders.ref` for outbound ref matching.

Use `simplePaginate(30)`.

Default sort:

- `exported_at DESC`

### Tenant Scope

Same rule as existing download controller:

- Internal user can see all batches.
- Tenant user can only see batches where `courier_export_batches.tenant_id` is in their active tenant ids.
- Guest must not be treated as internal.

Do not list batches where tenant is outside allowed scope.

The existing download controller already protects downloads; keep that protection.

### Empty State

Text:

> No print history found.

## Part C - Download Behavior

Reuse existing route:

`route('courier-export-batches.download', $batch)`

Do not create a new download controller.

The page should not use `wire:navigate` for download links.

## Part D - Outbound Detail Link

The outbound detail export history section currently shows file links directly to download.

Keep it.

Optionally add a small link in the section header:

`View all print history`

Link to:

`/fulfillment/print-history?search={outbound ref}`

This link depends on the Print History search joining `outbound_orders` via `courier_export_batch_orders.outbound_order_id`.

This is optional for v1 if it complicates the route/query binding.

## Part E - Do Not Do

Do not:

- Auto-requeue when shipping method changes
- Delete or overwrite old export batches
- Add `address_label_printed_at`
- Add a new print-history table
- Rename `courier_label_exported_at`
- Change shipped/cancelled behavior
- Change Print Waiting filter logic except using the requeue action to clear `courier_label_exported_at`

## Part F - Language / Encoding

Follow the current language-file rule:

- Add new keys to `lang/en` only.
- Add a row to `docs/translation-backlog.md` for ja / zh_TW / zh_CN.
- Do not edit CJK language files in this task.
- Do not use PowerShell file rewrites for language files.

Suggested English keys:

- `fulfillment.print_history_link`
- `fulfillment.print_history_title`
- `fulfillment.print_history_subtitle`
- `fulfillment.print_history_empty`
- `fulfillment.print_history_download`
- `fulfillment.requeue_print`
- `fulfillment.requeue_print_confirm`
- `fulfillment.requeue_print_success`
- `fulfillment.batch_requeue_print_result`
- `fulfillment.batch_requeue_print_result_no_skips`

Do not add a second set of export-type labels if existing keys already exist:

- `outbound.courier_label_export_type_yamato`
- `outbound.courier_label_export_type_sagawa`
- `outbound.courier_label_export_type_label10`

Reuse the existing export-type label mapping from outbound detail, or extract it to a small shared helper if the Print History page needs the same mapping. Unknown `carrier` / export-type values should fall back to the raw value or `Unknown`.

## Part G - Tests

Add targeted tests.

### Requeue print

1. `test_requeue_print_clears_exported_at_for_reserved_order`
   - Create reserved outbound with `courier_label_exported_at`.
   - Call requeue action.
   - Assert `courier_label_exported_at` is null.
   - Assert export batch rows still exist.

2. `test_requeue_print_blocks_shipped_order`
   - Shipped order with exported timestamp.
   - Requeue action should not clear timestamp.
   - Error/skip message shown.

3. `test_requeue_print_blocks_cancelled_order`
   - Same as shipped.

4. `test_requeue_print_tenant_scope`
   - Tenant user cannot requeue another tenant's outbound order.

### Print history page

5. `test_print_history_lists_courier_and_label10_batches`
   - Create/export Yamato and Label10 batches.
   - Page shows type, file name, exported by, download link.

6. `test_print_history_filters_by_type`
   - Yamato filter shows Yamato only.
   - Label10 filter shows Label10 only.

7. `test_print_history_tenant_scope`
   - Tenant user sees own tenant batches only.

8. `test_print_history_search_matches_outbound_ref_or_file_name`
   - Search by outbound ref or file name.
   - The outbound ref assertion must cover an outbound that has linked sales orders, proving the search uses `outbound_orders.ref` and not only `platform_order_id`.

## Acceptance Criteria

- Printed unshipped outbound orders can be manually requeued.
- Requeued orders appear in Print Waiting again.
- Requeue does not delete old export history or files.
- Shipped/cancelled orders cannot be requeued.
- Print History page lists old Yamato / Sagawa / Label10 exports.
- Old CSV/PDF files can be downloaded again.
- Tenant scope is enforced.
- New language keys are English-only and logged in translation backlog.
