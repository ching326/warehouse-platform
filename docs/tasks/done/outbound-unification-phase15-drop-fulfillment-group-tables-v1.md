# Task: Outbound Unification Phase 15 - Drop FulfillmentGroup Tables and Columns

Parent plan: docs/tasks/done/outbound-unification-v1.md. Done so far: rename arranged, schema,
populate, pack lines, printed flag/readers, tracking import decouple, manual reason + ship mode,
nav fold, detail consolidation, queue re-key (Phase 10), pack entry re-key (Phase 11), courier export
service re-key (Phase 12), manual outbound export UI + drop free-text shipping_method (Phase 13),
consolidation produces OutboundOrder directly (Phase 14, commit 9b8428b).

After Phase 14 the FulfillmentGroup tables receive no new rows: the OutboundOrder is the source of
truth for the queue, pack, courier export, tracking import, pick summary, ship, cancel, and
consolidation. FulfillmentGroup / FulfillmentGroupOrder are now write-dead. This phase removes them:
the tables, the dependent columns, the models, and the last few code paths that still read the
FulfillmentGroup model.

The module / route / class rename (Fulfillment -> Outbound) remains a LATER phase (Phase 16). Do NOT
rename routes, Livewire classes, lang files, or nav here; this phase is the schema/model teardown
only.

## Why now (everything that still references the group)
Phase 14 left these read-only / dead references to retire:
- app/Livewire/IssueCreate.php: the legacy group-issue route (fulfillment-groups.issues.create)
  binds a FulfillmentGroup and prefills issue context from it
  (mount, validatedFulfillmentGroup, selectedFulfillmentGroup, applyFulfillmentGroupContext,
  fulfillment_group_id on the created Issue, the outbound-search eager loads of fulfillmentGroup).
- app/Livewire/AmazonSpapiOrderImport.php:329: "already arranged" check reads
  FulfillmentGroupOrder::where('sales_order_id', ...)->exists().
- app/Actions/BackfillNormalizedTrackingNumbers.php:11-12: still lists fulfillment_groups and
  fulfillment_group_orders among the tracking tables it normalizes.
- app/Services/Courier/CourierExportService.php:193-195: dead activity-log block guarded by
  $order->fulfillment_group_id !== null (always null now).
- Models with the relation/column: OutboundOrder::fulfillmentGroup, FulfillmentPackScan::fulfillmentGroup
  and ::fulfillmentGroupOrder, SalesOrder::fulfillmentGroupOrders, Issue::fulfillmentGroup,
  ReturnOrder (verify), plus the FulfillmentGroup and FulfillmentGroupOrder models themselves and
  their factories.
- sales_orders.courier_csv_exported_at: the printed flag was moved to outbound_orders in Phase 5.
  SalesOrder::isPrinted() already derives from activeOutboundOrder()?->courier_csv_exported_at, but
  app/Support/SalesOrderFilters.php:109,113 still query a bare courier_csv_exported_at column - verify
  whether that runs against sales_orders (drop + re-point to the linked outbound) or against an
  outbound subquery (no change).

## Scope guard
- Drop only what is write-dead after Phase 14. Do not change courier CSV byte output, tenant scoping,
  reservation accounting, or any user-visible behavior.
- All data is dummy / reseeded - no production backfill. Reseed; do not write data migrations.
- Keep the operational screens working: the fulfillment-groups.index / .create / pack / pack-scans
  routes and Livewire classes stay (renamed in Phase 16); only their FulfillmentGroup reads go away.
- ASCII punctuation only in all touched specs, code, and lang files.

## Part A: re-key the last FulfillmentGroup readers (required, before dropping)
- IssueCreate: drop the FulfillmentGroup path. Decide (Open question 1) whether to (a) retire the
  fulfillment-groups.issues.create route and create issues only from sales order / outbound context,
  or (b) repoint that route to bind an OutboundOrder. Recommended: retire the group route and add an
  outbound issue entry point (issues already store outbound_order_id). Remove validatedFulfillmentGroup,
  selectedFulfillmentGroup, applyFulfillmentGroupContext, the fulfillmentGroup eager loads, and stop
  writing issues.fulfillment_group_id.
- AmazonSpapiOrderImport:329: replace the FulfillmentGroupOrder existence check with the
  OutboundOrder equivalent - the order is "already arranged" if it has a non-cancelled linked
  OutboundOrder (whereHas('activeOutboundOrders') or SalesOrder::activeOutboundOrder()).
- BackfillNormalizedTrackingNumbers: remove fulfillment_groups and fulfillment_group_orders from the
  normalized-table list (they hold no rows). Keep sales_orders, outbound_orders, return_orders.
- CourierExportService:193-195: remove the dead fulfillment_group_id activity-log block (keep
  outbound_order_id / outbound_order_ref).
- SalesOrderFilters printed filter: confirm it derives the printed flag from the linked OutboundOrder
  (via activeOutboundOrders / a whereHas on outbound_orders.courier_csv_exported_at), not from
  sales_orders.courier_csv_exported_at.

## Part B: drop the schema (required)
One migration (or a small ordered set), dropping FKs before columns/tables:
- outbound_orders.fulfillment_group_id (drop FK + column).
- fulfillment_pack_scans.fulfillment_group_id and fulfillment_pack_scans.fulfillment_group_order_id
  (drop FKs + columns; outbound_order_id is the key now).
- sales_orders.courier_csv_exported_at (drop column once Part A confirms no reader).
- Drop tables fulfillment_group_orders then fulfillment_groups (orders table first - it FKs the group).
- Provide down() that recreates them for rollback parity (dev only).

## Part C: retire the models + factories (required)
- Delete app/Models/FulfillmentGroup.php and app/Models/FulfillmentGroupOrder.php and their factories
  (database/factories/FulfillmentGroup*Factory.php), plus the FulfillmentGroupFactory references.
- Remove the relations: OutboundOrder::fulfillmentGroup, FulfillmentPackScan::fulfillmentGroup +
  ::fulfillmentGroupOrder, SalesOrder::fulfillmentGroupOrders, Issue::fulfillmentGroup,
  any ReturnOrder reference; remove the now-unused $fillable / casts entries (issues.fulfillment_group_id,
  sales_orders.courier_csv_exported_at, fulfillment_pack_scans.fulfillment_group_id/_order_id).
- Grep for App\Models\FulfillmentGroup and FulfillmentGroupOrder and confirm zero remaining
  references in app/, database/seeders, and config.

## Tests
- Reseed: migrate:fresh --seed is clean; no fulfillment_groups / fulfillment_group_orders tables exist.
- Schema test: assert the dropped tables and columns are gone
  (Schema::hasTable / Schema::hasColumn === false).
- AmazonSpapiOrderImportTest: update test_fulfillment_group_existing_order_is_not_overwritten to set
  up the "already arranged" state via a linked OutboundOrder instead of a FulfillmentGroup +
  FulfillmentGroupOrder; assert the import still does not overwrite.
- IssueTest: migrate the group-context issue tests to the chosen outbound entry point (Open question 1);
  remove the fulfillment-groups.issues.create coverage if that route is retired.
- BackfillNormalizedTrackingNumbers: tests already only cover sales/outbound/return tables (Phase 14) -
  confirm still green.
- Keep the full suite green (php artisan test); no FulfillmentGroup model remains under test.

## Acceptance
- fulfillment_groups, fulfillment_group_orders tables and the fulfillment_group_id / 
  fulfillment_group_order_id / sales_orders.courier_csv_exported_at columns no longer exist.
- FulfillmentGroup and FulfillmentGroupOrder models and factories are deleted; zero references remain.
- Issue creation, Amazon import "already arranged" guard, tracking backfill, and courier export all
  work with no FulfillmentGroup involvement.
- Courier CSV output unchanged (byte-identical). migrate:fresh --seed clean. Full suite green.
- ASCII punctuation only.

## Open questions (resolved)
1. Retire the legacy group-issue route (fulfillment-groups.issues.create). IssueCreate drops the
   FulfillmentGroup binding entirely; issues are created from sales order / outbound context
   (issues already carry outbound_order_id).
2. sales_orders.courier_csv_exported_at is dummy data - drop the column outright. Re-point the
   SalesOrderFilters printed filter to the linked OutboundOrder if it still reads the bare column.

## Commit
Per-phase workflow: implement, full suite green, then commit. Suggested message:
"Drop FulfillmentGroup tables and models". Move this spec to docs/tasks/done/ after commit.
