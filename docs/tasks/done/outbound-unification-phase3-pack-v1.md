# Task: Outbound Unification Phase 3 - Decouple Pack from Sales-Order Lines

Parent plan: docs/tasks/outbound-unification-v1.md. Done: Phase 0 (048f221), Phase 1 (1829ea7),
Phase 2 (a2f543b). This is Phase 3: make the pack/scan flow derive its required line quantities
from outbound_order_lines instead of sales-order lines, WITHOUT changing scan identity, matching
behavior, progress, or the group-based pack entry. After this, pack no longer depends on
SalesOrderLine, which is the prerequisite for packing manual (no-sales-order) outbounds later.

## Stack
- Laravel 13, Livewire 4, PHP 8.3, SQLite (dev). ASCII punctuation only. Dev dummy data.

## Background (current behavior to preserve exactly)
app/Services/Fulfillment/FulfillmentPackService::packLines(FulfillmentGroup $group) builds the
"required lines" by iterating $group->orders -> $order->lines (SalesOrderLine, line_status READY),
expanding virtual bundles into components. Each required line has: key, sku (for barcode match),
sku_id, stock_item, stock_item_id, required_qty. Two shapes:
- Single SKU line:    key "sku:{sku_id}:stock:{stock_item_id}", sku_id = the SKU id,
                      sku = the SKU, stock_item = the SKU's stock item.
- Bundle component:   key "component:{component_stock_item_id}", sku_id = NULL,
                      sku = the BUNDLE sku (so its barcode can also match), stock_item = component.
Scan progress (acceptedScanQuantitiesByLine) groups FulfillmentPackScan by (sku_id, stock_item_id),
and the FulfillmentGroupPack component RECORDS scans with those same sku_id/stock_item_id. So the
(sku_id, stock_item_id) identity of each required line is load-bearing and MUST NOT change
(component lines keep sku_id = NULL).

## Outbound line structure (the new source) - already populated in Phase 2
attachAndReserve / createOutboundLines build outbound_order_lines, already aggregated across all
member orders and bundle-expanded:
- Single SKU:   one line, parent_line_id NULL, sku_id = SKU, stock_item_id = stock item, qty.
- Virtual bundle: a parent line (parent_line_id NULL, sku_id = bundle SKU, stock_item_id NULL) plus
  child lines (parent_line_id = parent, sku_id = bundle SKU, stock_item_id = component, qty).
OutboundOrder::leafLines() already returns lines with stock_item_id NOT NULL (i.e. single-SKU lines
and bundle child lines; bundle parent lines are excluded).

## Change: packLines reads outbound leaf lines, preserving identity
File: app/Services/Fulfillment/FulfillmentPackService.php

Rewrite packLines(FulfillmentGroup $group) to build required lines from
$group->outboundOrder->leafLines (eager load sku + stockItem + their barcodeAliases, and for child
lines the parent line's sku for barcode matching). Map each leaf line:
- parent_line_id IS NULL (single SKU):
  key = "sku:{sku_id}:stock:{stock_item_id}", sku_id = line.sku_id, sku = line.sku,
  stock_item = line.stockItem, required_qty += line.qty.
- parent_line_id IS NOT NULL (bundle component):
  key = "component:{stock_item_id}", sku_id = NULL (preserve!), stock_item = line.stockItem,
  sku = the PARENT line's sku (bundle sku, for barcode matching), required_qty += line.qty.
Aggregate by key (sum qty) so the same stock item across multiple bundle parents collapses to one
"component:" line, exactly as today. Drop the SalesOrderLine import and the orders/lines loop.

Net result: identical required-line set (same keys, same sku_id/stock_item_id, same sku for
matching, same required_qty) as the current sales-order-derived version - just sourced from the
outbound order. lineMatchesScan, packLinesWithProgress, acceptedScanQuantitiesByLine,
lineIsStrictOnly, allLinesComplete stay unchanged.

## Keep unchanged this phase
- Pack ENTRY stays group-based: findGroupForTrackingNo and all callers
  (FulfillmentGroupPack, FulfillmentPackStart, FulfillmentPackScanIndex) keep taking a
  FulfillmentGroup. Packing a manual (no-group) outbound is a LATER phase (UI merge).
- Scan RECORDING is unchanged: FulfillmentPackScan still keyed by fulfillment_group_id and the same
  (sku_id, stock_item_id) per line (component lines still record sku_id = NULL).
- No change to courier export, tracking import, hold, or any UI.
- No change to inventory math or statuses.

## Edge cases
- A group whose outbound order has no leaf lines -> packLines returns [] (same as an empty group
  today). allLinesComplete already guards lines !== [].
- A stock item that is BOTH a standalone SKU and a bundle component in the same parcel stays two
  separate required lines ("sku:..." with sku_id set, and "component:..." with sku_id NULL) - the
  parent_line_id distinction preserves this.

## Tests (tests/Feature)
- Pack lines for a single-SKU group: same keys/sku_id/stock_item_id/required_qty as before
  (assert against the outbound-derived output).
- Pack lines for a virtual-bundle group: component required line has sku_id NULL,
  stock_item_id = component, required_qty = order_qty * component_qty, and the bundle SKU barcode
  still matches it (lineMatchesScan).
- A full scan-to-complete flow still works end to end (accept scans -> allLinesComplete true ->
  ship), proving scan recording/progress identity is unchanged.
- All existing FulfillmentPackTest / FulfillmentGroupTest pack tests pass unchanged.

## Do Not Do In This Task
- Do NOT change FulfillmentPackScan keying or the recording side.
- Do NOT generalize pack entry to OutboundOrder / manual outbounds yet (UI phase).
- Do NOT touch courier export, tracking import, hold gate, or UI.
- Do NOT alter outbound line creation (Phase 2 owns that).

## Acceptance Criteria
- FulfillmentPackService no longer references SalesOrderLine or $group->orders->lines; required
  lines come from $group->outboundOrder->leafLines.
- Required-line identity (keys, sku_id/stock_item_id, sku-for-matching, required_qty) is byte-for-
  byte equivalent to the previous behavior; all pack tests pass unchanged.
- Full suite green; no behavior change for users.
