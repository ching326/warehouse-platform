# Task: Outbound Unification Phase 7 - Decouple Tracking Import to OutboundOrder

Parent plan: docs/tasks/outbound-unification-v1.md. Done: Phase 0 (048f221), 1 (1829ea7),
2 (a2f543b), 3 (ff62422), 4 (8d02813), 5 (497e4ee), 6 courier export (519f46a). This phase moves the
tracking-number import to match parcels by OutboundOrder reference and write the tracking onto the
OutboundOrder (plus the places still read today). It is the last backend decoupling in the courier
domain; after it the UI merge can switch entry to OutboundOrder. ENTRY stays group-based this phase
(the same Import modal); only the matching + write targets change.

## Stack
- Laravel 13, Livewire 4, PHP 8.3, SQLite (dev). Tenant scoping unchanged. ASCII punctuation only.

## Background (current behavior)
app/Services/Courier/TrackingImport/TrackingImportService::importFulfillmentGroups parses a
Yamato/Sagawa CSV/TXT, and for each ready row:
- findGroups(tokens): matches FulfillmentGroup by reference_no (exact, else 15-char suffix).
- requires exactly one unambiguous group per row.
- writes the tracking number to fulfillment_group_orders.tracking_no and the member
  sales_orders.tracking_no, and logs a sales_order activity per member.
Note: outbound.ref == the group reference_no (set at group creation), and markShipped currently
reads tracking from fulfillment_group_orders, courier export now reads outbound.tracking_no, and
marketplace notice reads sales_orders.tracking_no. So tracking lives in 3 places today.

## Change: match and write via OutboundOrder
File: app/Services/Courier/TrackingImport/TrackingImportService.php
- Replace findGroups() with findOutboundOrders(tokens, allowedTenantIds): match OutboundOrder by
  ref (exact, else 15-char suffix via substr(ref, -15)), status != cancelled, tenant in
  allowedTenantIds. Return at most the matched set; keep the existing "exactly one unambiguous
  match per row" rule (count === 1).
- For the matched OutboundOrder (lockForUpdate), with its salesOrders (pivot) and
  fulfillmentGroup.groupOrders eager-loaded:
  - oldTrackingNo = outbound->tracking_no (fallback to first linked sales order tracking_no).
  - if normalized new == old, skip (unchanged).
  - write newTrackingNo to:
    - outbound->tracking_no (the parcel - primary),
    - the linked sales_orders.tracking_no (outbound->salesOrders),
    - fulfillment_group_orders.tracking_no for the linked group (back-compat: markShipped still
      reads it; remove this write in the teardown phase when markShipped reads the outbound).
  - activity log: one 'tracking_imported' sales_order activity per linked sales order, as today,
    using the outbound ref (and keep fulfillment_group_reference_no when a group exists). For a
    manual parcel with no sales orders, log a single activity on the OutboundOrder instead (or skip
    if there is no clean subject - document the choice).

## Out of scope (later phases)
- Outbound-based ENTRY / manual-parcel tracking import surfaced in the UI (UI merge phase). This
  phase only changes matching + write targets; the modal still imports "fulfillment groups".
- Unifying the tracking store to a single source on OutboundOrder (teardown phase: make markShipped
  and the index read outbound->tracking_no, then stop writing fulfillment_group_orders.tracking_no).
- Dropping fulfillment_groups / sales_orders.courier_csv_exported_at.

## Tests (tests/Feature)
- A Yamato/Sagawa file whose reference matches an OutboundOrder ref (exact and 15-char suffix)
  writes the tracking to the outbound, its linked sales orders, and the group orders.
- Ambiguous (multiple matches) or no-match rows are skipped; counts correct.
- Unchanged tracking (same value) is a no-op.
- Re-uses the existing tracking-import test fixtures; adjust matching expectations to the outbound
  ref. All existing tracking-import tests pass (adjust only matching/write-target assertions).

## Acceptance Criteria
- Tracking import matches parcels by OutboundOrder.ref and writes tracking to the OutboundOrder plus
  the linked sales orders (and group orders for back-compat).
- Behavior unchanged for consolidated parcels; manual parcels become matchable by their ref.
- Full suite green.
