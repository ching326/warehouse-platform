# Task: Outbound Unification Phase 6 - Decouple Courier CSV Export to OutboundOrder

Parent plan: docs/tasks/outbound-unification-v1.md. Done: Phase 0 (048f221), 1 (1829ea7),
2 (a2f543b), 3 (ff62422), 4 (8d02813), 5 (497e4ee). This phase makes courier CSV export derive all
shipment identity + gating from the OutboundOrder so it no longer depends on sales-order data for
those, and records the export batch per parcel. Tracking import is a SEPARATE following phase. Pack
already reads outbound lines (Phase 3); courier export is the last big sales-order coupling in the
shipping path.

## Decisions (assumed below; tell me to change before implementing)
1. CSV item content for bundles: HYBRID - if the parcel has linked sales orders, build item
   names/summary from those sales-order lines (CSV byte-identical to today for consolidated
   parcels); if it has none (manual parcel), build from the OutboundOrder leaf lines. Rationale:
   zero CSV change for existing parcels, and enables manual parcels later.
2. Batch FK: add a nullable outbound_order_id to courier_export_batch_orders and make sales_order_id
   nullable. Consolidated parcels still record one row per linked sales order (now also stamped with
   outbound_order_id); manual parcels record one row with sales_order_id NULL + outbound_order_id
   set. This keeps existing per-order audit and supports manual parcels.

## Stack
- Laravel 13, Livewire 4, PHP 8.3, SQLite (dev). Tenant scoping unchanged. ASCII punctuation only.

## Scope / what stays
- ENTRY stays group-based this phase: FulfillmentGroupIndex still calls exportYamato/exportSagawa
  with fulfillment group ids; CourierExportService::validateGroupExport / exportGroups keep their
  signatures but operate through each group's OutboundOrder internally. (Outbound-based entry and
  manual-parcel export arrive with the UI merge phase.)
- Keep dual-writing sales_orders.courier_csv_exported_at (do not drop it; teardown phase does).

## Change A: shipment identity + gating from OutboundOrder
File: app/Services/Courier/CourierExportService.php
- groupShipmentRow($group): build the row from $group->outboundOrder:
  - recipient_* from the OutboundOrder (not the first sales order).
  - reference / platform_order_id: keep $group->reference_no (== outbound->ref) so CSV is unchanged.
  - package_count / package_weight_g / tracking_no from the OutboundOrder.
  - lines for item naming: per Decision 1 (linked sales orders if any, else outbound leaf lines).
- validateGroupExport gates read the OutboundOrder where they currently read sales orders:
  - printed/re-export check: outbound->courier_csv_exported_at (instead of
    groupOrders.salesOrder.courier_csv_exported_at).
  - carrier match (groupCarrierMatches): use outbound->shippingMethod->carrier (outbound has
    shipping_method_id from Phase 2; assert it matches the group's).
  - supports_courier_csv: outbound->shippingMethod->supports_courier_csv.
  - ready-to-ship lines: outbound has leaf lines (qty > 0) instead of sales-order READY lines.
  - blocked order_status: applies only to linked sales orders (outbound->salesOrders pivot); if a
    parcel has no sales orders (manual), this gate is skipped. For consolidated, block if any linked
    sales order order_status is on_hold / cancel_requested / cancelled (same set as today).
- loadGroups: eager-load outboundOrder.shippingMethod.carrier, outboundOrder.leafLines.sku,
  outboundOrder.salesOrders (+ their lines for the hybrid item naming).

File: app/Services/Courier/Concerns/BuildsCourierCsv.php
- itemNames($order)/itemSummary($order): accept a shipment row whose "lines" are either sales-order
  lines (current) or outbound leaf lines. Normalize so both expose sku + quantity for naming. Keep
  output identical for the sales-order path.

## Change B: batch records per parcel
- Migration: add outbound_order_id (nullable, constrained outbound_orders, cascadeOnDelete) to
  courier_export_batch_orders; change sales_order_id to nullable.
- exportGroups: when writing CourierExportBatchOrder rows, set outbound_order_id for every row. For
  consolidated parcels, keep one row per linked sales order (sales_order_id set + outbound_order_id
  set, platform_order_id as today). For manual parcels (no sales orders), write one row with
  sales_order_id NULL + outbound_order_id set + platform_order_id = outbound->ref.
- Printed stamp: keep stamping outbound->courier_csv_exported_at (primary) AND the member sales
  orders (dual-write) as today.
- CourierExportBatchOrder model: add outbound_order_id to fillable + an outboundOrder() relation;
  make sales_order_id nullable in fillable usage.

## Out of scope (later phases)
- Outbound-based export ENTRY and manual-parcel courier export (UI merge phase).
- Tracking import decoupling (next phase after this).
- Dropping sales_orders.courier_csv_exported_at and the SalesOrderFilters printed filter (teardown).
- Pack, hold, inventory math, statuses.

## Tests (tests/Feature)
- Consolidated parcel courier export produces a byte-identical CSV to before (item names/summary,
  recipient, reference) - assert against the existing expected CSV in the courier export tests.
- Export stamps outbound->courier_csv_exported_at and the member sales orders; re-export detection
  uses the outbound flag.
- courier_export_batch_orders rows carry outbound_order_id (and sales_order_id for consolidated).
- Carrier mismatch / unsupported method / blocked status / no-ready-lines gates still block, now
  resolved via the OutboundOrder (carrier, supports_courier_csv, leaf lines) and linked sales orders
  (blocked status).
- All existing courier export tests (FulfillmentGroupTest courier cases, SalesOrderExportTest) pass;
  adjust only flag-setting sides, not CSV expectations.

## Acceptance Criteria
- Courier export reads recipient, reference, carrier, printed flag, and ready-lines from the
  OutboundOrder; CSV content unchanged for consolidated parcels (hybrid item naming).
- Export batch rows reference the OutboundOrder (outbound_order_id), sales_order_id nullable.
- No new dependency on sales-order data for shipment identity/gating except the blocked-status check
  on linked sales orders. Full suite green.
