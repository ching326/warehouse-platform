# Task: Outbound Unification Phase 16 - Rename the Fulfillment Module

Parent plan: docs/tasks/done/outbound-unification-v1.md. Done so far: rename arranged, schema,
populate, pack lines, printed flag/readers, tracking import decouple, manual reason + ship mode,
nav fold, detail consolidation, queue re-key (Phase 10), pack entry re-key (Phase 11), courier export
service re-key (Phase 12), manual outbound export UI (Phase 13), consolidation produces OutboundOrder
directly (Phase 14), drop FulfillmentGroup tables and models (Phase 15, commit dcfc5b2).

This is the FINAL phase. After Phase 15 the OutboundOrder is the only model; FulfillmentGroup is gone.
But the operational screens, routes, Livewire classes, blade views, and lang files are still named
after the retired "fulfillment group" concept. This phase renames that naming to match the unified
Outbound domain. It is a naming/cosmetic refactor only - no behavior, query, or schema change.

## The naming collision (read first - drives Open question 1)
The unified OutboundOrder CRUD screens already own the "outbound" namespace:
- OutboundOrderIndex / OutboundOrderCreate / OutboundOrderDetail / OutboundOrderShip
- routes outbound.index, outbound.create, outbound.show, outbound.ship, outbound.pack
- nav label common.nav_outbound_orders

The still-"fulfillment"-named screens are the OPERATIONAL fulfillment/shipping workflow over
customer_order outbounds (courier export, tracking import, mark shipped, pack scan station, pick
summary):
- FulfillmentGroupIndex   -> route fulfillment-groups.index   (nav common.nav_fulfillment_group_list)
- FulfillmentGroupCreate  -> route fulfillment-groups.create
- FulfillmentGroupPack    -> route outbound.pack (already outbound-named) + lang fulfillment_pack
- FulfillmentPackStart    -> route fulfillment.pack.start
- FulfillmentPackScanIndex-> route fulfillment.pack-scans.index
- FulfillmentPickSummary  -> route fulfillment.pick-summary  (nav common.nav_pick_summary)
- FulfillmentTrackingImportController -> route fulfillment.tracking-import

So "Fulfillment -> Outbound" cannot be a literal rename: FulfillmentGroupIndex cannot become
OutboundOrderIndex (already taken). The two index screens are distinct: OutboundOrderIndex is the
generic outbound listing/CRUD; FulfillmentGroupIndex is the operational shipping queue. Resolve the
target naming and whether the two listings stay separate before coding (Open question 1).

## Scope guard
- Pure rename: class names, route names/paths, blade view filenames, lang file names + keys, nav
  labels, the importFulfillmentGroups method name. No behavior, no query, no schema, no CSV output
  change. The full suite must stay green with only renamed references.
- Do not merge or redesign the two index screens unless Open question 1 explicitly chooses that
  (recommended to keep them separate this phase; a merge is its own task).
- ASCII punctuation only. Update docs/i18n-glossary.md for any renamed canonical terms.

## Part A: classes + routes (after Open question 1 picks the scheme)
Using the chosen module name (placeholder <Mod> / <mod> below; recommended <Mod> = Outbound domain
"shipping"/"fulfillment" operational naming - see Open question 1):
- Rename Livewire classes (file + class + namespace references + Livewire component name):
  FulfillmentGroupIndex, FulfillmentGroupCreate, FulfillmentGroupPack, FulfillmentPackStart,
  FulfillmentPackScanIndex, FulfillmentPickSummary.
- Rename app/Services/Fulfillment/* and app/Http/Controllers/FulfillmentTrackingImportController as
  fits the scheme (GroupSalesOrdersService is already misnamed - it produces OutboundOrders; rename to
  e.g. OutboundConsolidationService).
- Rename TrackingImportService::importFulfillmentGroups (and its caller) to a domain-accurate name
  (e.g. importOutboundTracking).
- Rename routes: fulfillment-groups.index/.create, fulfillment.pick-summary, fulfillment.pack.start,
  fulfillment.pack-scans.index, fulfillment.tracking-import to the new scheme. Update every route()
  caller: navigation.blade.php (x2), FulfillmentTrackingImportController (x2),
  FulfillmentGroupIndex::pickSummaryUrl, and the blade links in fulfillment-group-create,
  fulfillment-group-index, fulfillment-group-pack, fulfillment-pick-summary, outbound-order-detail.
- Decide whether to keep the old route paths/names as redirects/aliases or hard-rename
  (Open question 3).

## Part B: blade views (required)
- Rename the view files (fulfillment-group-create/-index/-pack, fulfillment-pack-scan-index,
  fulfillment-pack-start, fulfillment-pick-summary) and update each Livewire component's
  ->view(...) / render() reference.
- Update CSS class hooks / data-testid only if a test asserts them (keep churn minimal otherwise).

## Part C: lang files (required)
- Rename / fold the lang files: lang/{en,ja,zh_CN,zh_TW}/fulfillment_groups.php and fulfillment_pack
  and fulfillment_pick. Decide the target file names (Open question 2; recommended: keep one file per
  screen but under the new module prefix, or fold the group file into the chosen module file).
- Update every __('fulfillment_groups.*') / __('fulfillment_pack.*') / __('fulfillment_pick.*') usage
  in app/ and resources/ (about 10 files) plus the nav labels common.nav_fulfillment_group_list and
  common.nav_pick_summary.
- Keep all four locales in sync; ASCII punctuation only.

## Part D: nav + glossary (required)
- navigation.blade.php: relabel and re-point the "Fulfillment Groups" and "Pick Summary" entries to
  the new routes; fix the routeIs() active-state patterns.
- Update docs/i18n-glossary.md canonical terms (fulfillment group -> chosen term) so future
  translations stay consistent.

## Tests
- Update the test files that reference the renamed route names and Livewire classes:
  FulfillmentGroupTest, FulfillmentPackScanHistoryTest, FulfillmentPackStartQueueTest,
  FulfillmentPickSummaryTest, OutboundOrderTest, SalesOrderImportTest (route('fulfillment-groups.*')
  and ::class references). Consider renaming the test files/classes to match.
- No new behavior to test; this phase is green-on-rename. Run the full suite (php artisan test).

## Acceptance
- No class, route name, route path, view file, lang file, lang key, nav label, or method name still
  uses the "fulfillment group" naming (except where the team chose to keep "fulfillment" as the
  operational module name per Open question 1).
- Full suite green; CSV output and all behavior byte-identical. migrate:fresh --seed clean.
- docs/i18n-glossary.md updated. ASCII punctuation only.
- Outbound Unification project complete.

## Open questions (resolve before coding)
1. Target naming for the operational screens, given OutboundOrder* already owns "outbound". Options:
   (a) keep "Fulfillment" as the operational module name but drop "Group" (FulfillmentGroupIndex ->
   FulfillmentQueueIndex, route fulfillment.index, lang fulfillment.php) - smallest change, keeps the
   two index screens clearly distinct; (b) rename to a shipping domain (ShipmentQueueIndex, route
   shipping.*, lang shipping.php); (c) merge the operational queue into the OutboundOrder screens
   (larger, likely its own task). Recommended: (a) - drop "Group", keep "Fulfillment" as the
   operational/packing-and-shipping module, since these are genuinely fulfillment operations distinct
   from the generic outbound CRUD.
2. Lang file layout: fold fulfillment_groups.php into the chosen module file, or keep one file per
   screen under the new prefix? Recommended: keep per-screen files, just drop the "group" naming
   (fulfillment_groups.php -> fulfillment_queue.php or similar) to minimize key churn.
3. Route compatibility: hard-rename the route names/paths, or keep the old names as aliases for one
   release? Recommended: hard-rename (no external consumers; all callers are in-repo and updated here).

## Commit
Per-phase workflow: implement, full suite green, then commit. Suggested message:
"Rename fulfillment module to unified outbound naming". Move this spec to docs/tasks/done/ after
commit. This closes the Outbound Unification project.
