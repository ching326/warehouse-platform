# Task: Unify Shipping Under OutboundOrder, Eliminate FulfillmentGroup (v1)

## Stack
- Laravel 13, Livewire 4 (class based), Flux UI 2, PHP 8.3, SQLite (dev).
- Inventory is append only via App\Services\InventoryService (reserveStock, releaseReserve,
  shipReservedStock, adjustStock). Never mutate balances directly.
- Tenant scoping: components resolve allowedTenantIds() / visibleTenantIds(); never cross tenants.
- ASCII punctuation only in code, specs, and lang files.

## Goal
Make OutboundOrder the single shipping entity for everything that leaves the warehouse.
"Fulfillment" stops being a separate shipping module and becomes one way an OutboundOrder is
created (consolidating ready sales orders). FulfillmentGroup is eliminated (Depth 2). The mature
shipping features (pack scan, courier CSV export, tracking import, mark shipped, the index UI) are
decoupled from sales orders and operate on OutboundOrder / outbound_order_lines.

Decisions already made:
1. Depth 2: eliminate FulfillmentGroup entirely; migrate its data onto outbound_orders + a
   sales-order pivot.
2. The printed flag (courier_csv_exported_at) moves to outbound_orders. On the sales order pages,
   where the printed date used to show, display a derived "Processing" indicator (final wording TBD;
   candidates: Processing / Packing) meaning the parcel is printed and the order can no longer be
   held.
3. Bulk shipments keep a lightweight direct-ship path (no pack scan, no courier CSV).

This is large. Do it in the phases below; the app must stay green after each phase.

## Target data model

### outbound_orders (the one shipping entity) - add columns
- reason (string enum): customer_order, re_ship, replacement, gift, fba, return_to_tenant, b2b,
  sample, other.
- ship_mode (string enum): parcel | bulk. parcel = full flow (pack + courier CSV); bulk = lightweight
  direct ship. Default derived from reason (customer_order/re_ship/replacement/gift -> parcel;
  fba/return_to_tenant/b2b -> bulk; other -> parcel) but stored so it can be overridden.
- source_sales_order_id (nullable FK sales_orders): links a re_ship/replacement to the original order.
- courier_csv_exported_at (nullable timestamp): the "printed" flag, moved here from sales_orders.
- shipping_method_id (nullable FK shipping_methods): replace the legacy free-text shipping_method on
  the fulfillment side; keep shipping_method/courier strings only as denormalized ship snapshot.
- Keep existing: ref, status (pending -> shipped|cancelled), recipient_*, package_count,
  package_weight_g, tracking_no, shipped_at, cancelled_at, note, ship_note.

### outbound_order_lines (single source of truth for parcel contents)
- Already has sku_id, stock_item_id, parent_line_id (bundle parent), qty. Pack and courier CSV read
  from these. No change needed beyond ensuring parentLines / leafLines relations exist.

### outbound_order_sales_order (NEW pivot)
- outbound_order_id, sales_order_id, arranged_at. Replaces fulfillment_group_orders. Records which
  sales orders a consolidated parcel covers (0 rows for a purely manual outbound).

### sales_orders (OMS only)
- Drop courier_csv_exported_at (moved to outbound_orders). Anywhere it was read, derive from the
  linked OutboundOrder instead.
- Rename the fulfillment_status value "in_group" to "arranged" (constant
  FULFILLMENT_STATUS_IN_GROUP -> FULFILLMENT_STATUS_ARRANGED = 'arranged'). Migrate the column data
  (in_group -> arranged) and update every reference: model constant, queries (GroupSalesOrders /
  bulkHold / hold / releaseOrderForHold / fulfillment filters), blades, tests, and the lang label.
  Do this as an early, self-contained prep step (Phase 0) before the outbound refactor so later code
  uses "arranged" throughout. Update the lang label fulfillment_in_group to "Arranged"
  (ja keep 出荷手配済み; zh_TW 已安排發貨; zh_CN 已安排发货) to avoid colliding with the new Packing chip.
- "Packing" is NOT a stored status. It is derived: the order is linked (via the pivot) to an
  OutboundOrder whose courier_csv_exported_at is set. See Phase 5.

### Remove
- fulfillment_groups table and the FulfillmentGroup model (Phase 6).
- fulfillment_group_orders table (replaced by outbound_order_sales_order).
- outbound_orders.fulfillment_group_id column (Phase 6, after data migration).

## Consolidation (the former grouping) now produces an OutboundOrder
GroupSalesOrdersService becomes an OutboundOrder producer (rename to OutboundConsolidationService or
keep the name). createGroup/joinGroup logic:
- ship_together_key + shop consolidation validation stays (move it off FulfillmentGroup; operate on
  the candidate sales orders and the target OutboundOrder).
- Instead of creating a FulfillmentGroup, create (or join) an OutboundOrder with reason=customer_order,
  ship_mode=parcel, attach sales orders via the pivot (arranged_at), aggregate their ready lines into
  outbound_order_lines (bundles expanded - reuse the existing aggregateLines / createOutboundLines),
  reserve stock per stock item, set each sales order fulfillment_status = arranged.
- "join" appends sales orders to an existing pending parcel: re-aggregate and rebuild outbound lines
  (the pattern already used by releaseOrderForHold).

## Hold claw-back (already built) - migrate the gate
- releaseOrderForHold + SalesOrderIndex::bulkHold + SalesOrderDetail::hold currently read
  sales_orders.courier_csv_exported_at and the FulfillmentGroup. After the move:
  - eligibility gate reads the linked OutboundOrder.courier_csv_exported_at (printed = block).
  - claw-back releases reserve, detaches the sales order from the OutboundOrder pivot, rebuilds the
    outbound lines from remaining orders, and cancels the OutboundOrder if it becomes empty (the same
    logic, against OutboundOrder instead of FulfillmentGroup).

## Shipping features decoupled to OutboundOrder
- Pack (FulfillmentPackService, FulfillmentGroupPack, FulfillmentPackStart, FulfillmentPackScanIndex,
  FulfillmentPackScan): scan against outbound_order_lines (leaf lines), resolve barcodes via SKU.
  Pack applies only to ship_mode = parcel.
- Courier CSV (CourierExportService validateGroupExport/exportGroups, BuildsCourierCsv,
  CourierExportBatch, CourierExportBatchOrder): build rows from OutboundOrder recipient + package_*
  + ref + tracking; batch tables reference outbound_order_id. Applies only to ship_mode = parcel.
  Exporting sets outbound_orders.courier_csv_exported_at.
- Tracking import (route fulfillment.tracking-import): match by OutboundOrder ref / tracking.
- Mark shipped: ShipOutboundOrderService.ship() is already the action; it stays the single ship
  path and back-writes tracking to linked sales orders (via the pivot).

## Bulk direct-ship (lightweight)
- ship_mode = bulk OutboundOrders skip pack scan and courier CSV. They keep the existing simple
  OutboundOrderShip form (confirm recipient/shipping, enter tracking optional, submit -> ship).
- They still appear in the unified Outbound queue but show a "Ship" action that opens the direct
  ship form instead of the pack flow.

## UI: one Outbound queue
- Merge FulfillmentGroupIndex and OutboundOrderIndex into a single Outbound index listing
  OutboundOrders (all sources). Keep the filters / Details toggle / print-waiting pill / bulk
  mark-shipped already built on FulfillmentGroupIndex.
- Row actions by ship_mode: parcel -> Pack (Scan), Courier export, tracking; bulk -> Ship (direct).
- FulfillmentGroupDetail becomes OutboundOrderDetail (single detail page) showing source sales
  orders (if any), reason, lines, recipient, shipping, status.

## Sales order "Packing" indicator (decision 2)
- On SalesOrderIndex (the created/printed cell) and SalesOrderDetail, where the printed date showed:
  if the order is linked to an OutboundOrder with courier_csv_exported_at set, show a "Packing" chip
  (lang key sales_orders.label_packing; en "Packing", ja 梱包中, zh_TW 包裝中, zh_CN 包装中) instead of
  a printed date.
- This chip is the user-facing reason hold is blocked. Keep it consistent with the hold gate: the
  same condition (linked printed OutboundOrder) drives both the chip and the hold block. Pick and pack
  always happen after print, so "Packing" is accurate once printed.
- Not printed but arranged -> no chip (the fulfillment_status badge already conveys arranged).

## Phasing (keep app green after each)
0. Rename fulfillment_status value in_group -> arranged (constant, all references, lang label;
   re-seed dev data rather than migrate the column - data is dummy). Self-contained prep commit; no
   behavior change. NOTE: concurrent "Build sales orders v1" work already applied this rename in
   lang/en (fulfillment_arranged, cannot_cancel_arranged) - reconcile with it.
1. Schema: add reason, ship_mode, source_sales_order_id, courier_csv_exported_at, shipping_method_id
   to outbound_orders; create outbound_order_sales_order pivot. Backfill nothing yet. App still uses
   FulfillmentGroup.
2. Consolidation service writes BOTH old (FulfillmentGroup) and new (OutboundOrder pivot + columns)
   OR switch it to produce OutboundOrder directly behind a feature path. Move courier_csv_exported_at
   writes to the OutboundOrder. Update the hold gate to read the OutboundOrder flag.
3. Decouple pack to outbound_order_lines.
4. Decouple courier export + tracking import + batches to OutboundOrder.
5. Merge the index/detail UI into Outbound; add the sales order Packing chip; wire bulk direct-ship.
6. No data migration (all existing data is dev dummy data). Just drop fulfillment_groups,
   fulfillment_group_orders, and outbound_orders.fulfillment_group_id, drop
   sales_orders.courier_csv_exported_at, and update WarehousePlatformSeeder to seed the new shape
   directly: consolidated parcels (OutboundOrder reason=customer_order ship_mode=parcel + sales-order
   pivot + reserved stock), at least one manual parcel (e.g. replacement), and at least one bulk
   outbound (reason=fba ship_mode=bulk). Re-seed instead of migrate.
7. Rename module/nav Fulfillment -> Outbound; fold fulfillment_groups.php lang into an outbound lang
   file; update glossary. Isolated commit.

## i18n
- New lang keys: outbound reasons, ship_mode labels, sales_orders.label_packing (Packing / 梱包中 /
  包裝中 / 包装中). Update fulfillment_in_group label to "Arranged" (ja 出荷手配済み / zh_TW 已安排發貨 /
  zh_CN 已安排发货).
- fulfillment_groups.php content folds into outbound/outbound_orders lang file across en, ja, zh_TW,
  zh_CN (reuse the existing translations). Update docs/i18n-glossary.md module status.

## Tests
- Consolidation produces an OutboundOrder with pivot rows, reserved stock, sales orders marked
  arranged; join appends and rebuilds lines.
- fulfillment_status value migrated to "arranged"; no stray "in_group" remains in code or data.
- Hold gate reads the OutboundOrder printed flag: not printed -> hold + claw-back; printed -> blocked;
  Packing chip shows iff linked printed OutboundOrder.
- Pack scans against outbound_order_lines for both a consolidated parcel and a manual parcel outbound.
- Courier export builds from OutboundOrder, sets courier_csv_exported_at, batch references outbound.
- Bulk outbound (ship_mode=bulk) skips pack/courier and ships via the direct form.
- Mark shipped deducts reserved stock once and back-writes tracking to linked sales orders.
- Seeder produces the new shape: consolidated parcel(s), a manual parcel, and a bulk outbound, all
  as OutboundOrders with correct reserved stock; no fulfillment_groups rows remain.
- All existing FulfillmentGroupTest / SalesOrderTest / outbound tests are ported, not deleted.

## Do Not Do In This Task
- Do not keep FulfillmentGroup as a thin record (that is Depth 1, rejected).
- Do not put pack scan or courier CSV on bulk outbounds.
- Do not create phantom sales orders for manual outbounds.
- Do not change inventory math (reserve/release/ship quantities stay identical).

## Acceptance Criteria
- One shipping entity (OutboundOrder); fulfillment_groups tables removed.
- Platform consolidation, manual parcel, and manual bulk all produce OutboundOrders and ship through
  one queue (parcel = pack + courier; bulk = direct ship).
- Printed flag lives on OutboundOrder; sales orders show a Packing chip and block hold when their
  linked parcel is printed; hold + claw-back work against OutboundOrder.
- fulfillment_status uses "arranged" (no "in_group" anywhere).
- Inventory ledger stays correct (reserve released/shipped exactly once).
- Module/nav renamed to Outbound; lang + glossary updated; full test suite green.
