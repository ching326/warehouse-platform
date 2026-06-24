# Task: Outbound Unification Phase 14 - Consolidation Produces OutboundOrder Directly

Parent plan: docs/tasks/done/outbound-unification-v1.md. Done so far: rename arranged, schema,
populate, pack lines, printed flag/readers, tracking import decouple, manual reason + ship mode,
nav fold, detail consolidation, queue re-key (Phase 10), pack entry re-key (Phase 11), courier export
service re-key (Phase 12), manual outbound export UI + drop free-text shipping_method (Phase 13,
commits cb46095 / 434f5da).

After Phase 13 the OutboundOrder is the source of truth for the queue, pack entry, courier export,
tracking import matching, and manual creation. FulfillmentGroup is now a vestigial 1:1 shadow record
that the consolidation flow still creates alongside every OutboundOrder. This phase makes the
consolidation producer create the OutboundOrder DIRECTLY and stop writing FulfillmentGroup, then
moves the last few group-coupled reads onto OutboundOrder. This is the blocker for dropping the
fulfillment_groups tables.

Dropping fulfillment_groups / fulfillment_group_orders / outbound_orders.fulfillment_group_id /
sales_orders.courier_csv_exported_at remains a LATER phase (Phase 15). The module / route / class
rename (Fulfillment -> Outbound) remains a LATER phase (Phase 16). Do NOT do either here.

## Why now (what still couples to FulfillmentGroup)
GroupSalesOrdersService.createGroupFromOrders (app/Services/Fulfillment/GroupSalesOrdersService.php
around line 205) creates a FulfillmentGroup, then an OutboundOrder with
ref = group->reference_no and fulfillment_group_id = group->id, and keeps them 1:1. Everything
downstream already keys on the OutboundOrder, so the group is dead weight except for these remaining
reads:
- Tracking back-write to the fulfillment_group_orders pivot in ShipOutboundOrderService,
  TrackingImportService, and FulfillmentGroupIndex.updateTracking (the outbound_order_sales_order
  pivot, added in Phase 1, is the replacement link; linked sales orders are also written directly).
- Pack scans keyed by fulfillment_pack_scans.fulfillment_group_id (FulfillmentPackService writes,
  FulfillmentPackScanIndex filters). The outbound_order_id column already exists on that table
  (migration 2026_06_24_000007).
- FulfillmentPickSummary reads FulfillmentGroup.
- OutboundOrderObserver syncs FulfillmentGroup status and back-writes the group's sales orders on
  ship / cancel.
- SalesOrderDetail / IssueCreate / OutboundOrderDetail read $order->fulfillmentGroup for display.

## Scope guard
- Stop CREATING FulfillmentGroup in the consolidation flow; move the remaining reads onto
  OutboundOrder. Do NOT drop the fulfillment_groups / fulfillment_group_orders tables or the
  fulfillment_group_id column yet (Phase 15) - leave them in place and simply unwritten, so this
  phase stays reviewable and reversible.
- Do not change courier CSV byte output, tenant scoping, or reservation accounting.
- All data is dummy / reseeded - no production backfill. Update WarehousePlatformSeeder to the
  group-less shape and reseed; do not write a data migration.
- ASCII punctuation only in all touched specs, code, and lang files.

## Part A: consolidation producer (required)
GroupSalesOrdersService is the last FulfillmentGroup writer. Re-key it to OutboundOrder-only.
- createGroupFromOrders: create the OutboundOrder directly with reason = customer_order,
  ship_mode = parcel, shipping_method_id = resolveGroupShippingMethodId(orders), and the recipient
  fields from the first order. Use OutboundOrder::buildRef(id, tenantCode) for the canonical ref
  (it already exists and is used by the manual create flow); drop FulfillmentGroup::buildReferenceNo
  and the FG- reference_no entirely. Stop setting fulfillment_group_id (leave it null).
- appendOrdersToGroup / place / unplace / rebuild: operate on the OutboundOrder and the
  outbound_order_sales_order pivot (attach / detach / reserve / release). Remove the parallel
  FulfillmentGroupOrder pivot writes.
- attachAndReserve / createOutboundLines: keep the reservation and line-building logic; just drop the
  group argument and any group-side writes.
- The eligibility gate that blocks re-grouping an already-exported consolidation must keep reading
  the OutboundOrder.courier_csv_exported_at (it already does at lines 86 and 369); verify it no
  longer depends on the group.

## Part B: move the remaining group-coupled reads (required)
- Tracking back-write: in ShipOutboundOrderService, TrackingImportService, and
  FulfillmentGroupIndex.updateTracking, write tracking to the linked sales orders via
  $outbound->salesOrders (outbound_order_sales_order pivot) and stop touching
  fulfillment_group_orders. Keep the SalesOrder tracking_no / fulfillment_status / order_status
  back-writes identical.
- Pack scans: FulfillmentPackService and FulfillmentPackStart must write
  fulfillment_pack_scans.outbound_order_id (not fulfillment_group_id). FulfillmentPackScanIndex must
  filter by an outbound_order_id URL param. FulfillmentPackScan model: make outbound_order_id the
  relation key.
- OutboundOrderObserver: the ship / cancel sales-order back-write must go through
  $order->salesOrders instead of $group->orders(); remove the FulfillmentGroup status sync (the group
  is no longer created). Confirm ship and cancel still set the linked sales orders to
  shipped/completed and ready respectively.
- FulfillmentPickSummary: re-key its grouping/aggregation onto OutboundOrder (status = pending,
  ship_mode = parcel) instead of FulfillmentGroup.
- SalesOrderDetail / IssueCreate / OutboundOrderDetail: replace $order->fulfillmentGroup display
  reads with the linked OutboundOrder (ref, status, pack scans by outbound_order_id). The scan
  history link/section in OutboundOrderDetail must key on outbound_order_id.

## Part C: seeder + lang (required)
- WarehousePlatformSeeder: the consolidation/sales-order seeding path must produce group-less
  OutboundOrders (no FulfillmentGroup / FulfillmentGroupOrder rows). Manual outbounds are already
  group-less (Phase 13). Reseed and confirm migrate:fresh --seed is clean.
- Lang: leave fulfillment_groups.php keys in place this phase (the screen is renamed in Phase 16);
  only adjust any string that asserts a FG- reference where it now shows an OB- ref.

## Tests
- Consolidation acceptance: build a consolidated outbound through GroupSalesOrders(Service) from two
  ready sales orders; assert NO FulfillmentGroup / FulfillmentGroupOrder rows are created, the
  OutboundOrder has reason = customer_order, an OB- ref, both sales orders attached via
  outbound_order_sales_order, and stock reserved once.
- Append / unplace / rebuild: adding and removing a sales order updates the outbound lines and pivot
  and releases/reserves stock correctly, with no group involved.
- Ship: shipping a consolidated outbound back-writes tracking / shipped / completed to BOTH linked
  sales orders via the pivot (no fulfillment_group_orders).
- Cancel: cancelling resets the linked sales orders to ready and releases reservations.
- Pack: pack scans are stored against outbound_order_id and the scan-history screen filters by it.
- Tracking import: a CSV keyed by OutboundOrder.ref imports and back-writes tracking to the linked
  sales orders.
- Pick summary renders for group-less consolidated outbounds.
- Keep the existing courier export, manual export (Phase 13), queue, and pack tests green.

## Acceptance
- Consolidating ready sales orders produces an OutboundOrder with NO FulfillmentGroup row.
- All queue / pack / courier export / tracking import / pick summary / ship / cancel flows operate
  with zero FulfillmentGroup reads or writes.
- fulfillment_groups and fulfillment_group_orders tables exist but receive no new rows.
- Courier CSV output is unchanged (byte-identical for the same recipient/line data).
- migrate:fresh --seed is clean and seeds only group-less outbounds.
- Full suite green (php artisan test). ASCII punctuation only.

## Open questions (resolved)
1. Consolidated outbound ref now uses OutboundOrder::buildRef (OB- prefix); the FG- reference_no is
   gone. Tracking import matches on OutboundOrder.ref, so no functional impact.
2. FulfillmentGroup model + tables remain physically present but receive no new rows this phase.
   fulfillment_pack_scans.fulfillment_group_id was made nullable so group-less pack scans insert.
   The actual table DROP is deferred to Phase 15.

## Notes for Phase 15 (table drop)
- A new pack-scan-history "view scans" deep link, the OutboundOrderDetail scan-history section, and
  the FulfillmentPackScanIndex filter are now keyed on outbound_order_id. The per-line "create issue"
  button on the pack station was removed (it required a group; there is no outbound-issue route yet).
- OutboundOrderObserver now back-writes linked sales orders (ship -> shipped/completed, cancel ->
  ready) via the outbound_order_sales_order pivot. ShipOutboundOrderService no longer touches groups.
- FulfillmentGroup model, fulfillment_groups / fulfillment_group_orders tables,
  outbound_orders.fulfillment_group_id, and fulfillment_pack_scans.fulfillment_group_id are now
  write-dead and can be dropped in Phase 15. IssueCreate / AmazonOrderImport still reference the
  FulfillmentGroup model for the legacy group-issue route; re-key or retire those in Phase 15/16.

## Commit
Per-phase workflow: implement, full suite green, then commit. Suggested message:
"Produce OutboundOrder directly from consolidation". Move this spec to docs/tasks/done/ after commit.
