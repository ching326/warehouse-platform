# Task: Outbound Unification Phase 12 - Courier Export Keyed on OutboundOrder

Parent plan: docs/tasks/done/outbound-unification-v1.md. Done so far: rename arranged, schema,
populate, pack lines, printed flag/readers, tracking import decouple, manual reason + ship mode,
nav fold, detail consolidation, queue re-key (Phase 10), pack entry re-key (Phase 11, commit
b0dbd52). This phase re-keys COURIER EXPORT (the CSV/label generation path) from FulfillmentGroup to
OutboundOrder, so courier CSVs are produced from outbound ids. The point is to unblock manual
outbounds (Replacement / Re-ship / Gift / Sample, which have no FulfillmentGroup) so they can be
selected for export and get a courier label.

Consolidation producing an OutboundOrder without a FulfillmentGroup, dropping fulfillment_groups /
fulfillment_group_orders, dropping sales_orders.courier_csv_exported_at, unifying the tracking
store, and the module/route/class rename remain LATER phases. This phase keeps FulfillmentGroup
populated (1:1 with the outbound) for back-compat; export just stops depending on it as the key.

## Why now
The export path is the last group-keyed gate between a manual outbound and a shipping label. Today
CourierExportService.validateGroupExport / exportGroups take fulfillment_group_ids and FulfillmentGroupIndex
is the only export entry point, so an outbound with fulfillment_group_id = null can never be
exported. Everything downstream of the group is already outbound-centric: the service reaches
through group->outboundOrder for shippingMethod, carrier, courier_csv_exported_at, salesOrders, and
the export batch row already stores outbound_order_id. So this is mostly a signature + loader
re-key, not a behavior rewrite.

## Scope guard
- Same export capabilities: carrier match, blocked-status / wrong-carrier / unsupported-courier /
  mixed-tenant / already-exported / no-ready-lines validation buckets, re-export confirmation, CSV
  build (Yamato + Sagawa), batch + batch-order rows, activity log, courier_csv_exported_at stamping.
- Only the keying entity changes from FulfillmentGroup to OutboundOrder.
- The CSV row content (recipient, address, tracking, item lines) must be byte-identical for an
  outbound that has a FulfillmentGroup - it is already built from the outbound, so do not change
  YamatoCsvBuilder / SagawaCsvBuilder shapes.
- Do NOT yet enable export of manual (group-less) outbounds in the UI selection if that pulls in
  unscoped work; the service must ACCEPT group-less outbounds, but the index that lists them stays a
  separate concern. Decide explicitly (see Open question) and keep the chosen scope tight.

## Stack
- Laravel 13, Livewire 4, PHP 8.3, SQLite. Tenant scoping unchanged. ASCII punctuation only.
- All data is dummy/reseeded - no production backfill needed. Reseed rather than migrate data.

## Service (CourierExportService)
Re-type the public methods to take outbound order ids:
- validateGroupExport(array $fulfillmentGroupIds, ...) -> validateOrderExport(array $outboundOrderIds, ...).
  Keep the same CourierExportValidationResult shape and the same bucket semantics, but compute them
  from OutboundOrder rows. The result currently reports id buckets - decide whether those ids stay
  group ids or become outbound ids (recommend outbound ids; update the blade that renders them and
  any lang keys that say "group").
- exportGroups(...) -> exportOrders(...). loadGroups -> loadOrders: query
  OutboundOrder::query()->whereIn('id', $ids)->whereIn('tenant_id', $allowedTenantIds) with
  shippingMethod.carrier, salesOrders, and leafLines eager loads the CSV builders need. Preserve the
  caller-supplied id ordering (the current sortBy(array_search id, ids) trick).
- Status gate: replace FulfillmentGroup::STATUS_RESERVED check with OutboundOrder::STATUS_PENDING.
  Note the blocked-status bucket also keys off SalesOrder statuses on the linked orders - keep that.
- groupCarrierMatches -> orderCarrierMatches: CourierCarrier::normalize($order->shippingMethod?->
  carrier?->code) === $carrier (drop the ->outboundOrder hop).
- groupShipmentRow -> orderShipmentRow: build from $order directly (drop the ->outboundOrder hop).
- courier_csv_exported_at: still stamped on the outbound and on each linked sales order (unchanged).
- Batch + batch-order rows: already carry outbound_order_id; keep. The activity-log properties that
  record fulfillment_group_id / fulfillment_group_reference_no should fall back gracefully when the
  outbound has no group (log outbound ref + id; include group id only when present).

## UI (FulfillmentGroupIndex)
- The export action currently passes selected fulfillment_group_ids. Switch it to pass the selected
  rows' outbound order ids (the index already lists OutboundOrder rows post Phase 10). Update the
  validate/export calls and the result-rendering (bucket ids now outbound ids).
- Keep the existing selection UX; only the id type flowing into the service changes.
- Verify the "already exported" badge / re-export confirmation still reads courier_csv_exported_at
  off the outbound.

## Open question (resolve before coding)
Should this phase also let group-less manual outbounds be SELECTED for export in the UI, or only
make the service capable of exporting them (UI still lists group-backed rows this phase)? Recommend:
make the service capable now (the whole point), but only widen the index's listing/selection to
manual outbounds if FulfillmentGroupIndex already shows them - if it still filters to
whereNotNull('fulfillment_group_id'), widening that is its own phase. Keep this phase to the
service + existing-UI re-key unless widening is trivial and tested.

## Tests
Migrate existing courier-export tests from group ids to outbound ids:
- tests/Feature covering validateGroupExport / exportGroups (search CourierExportService usages and
  FulfillmentGroupIndex export tests). Re-point selection to outbound ids; assert the same buckets,
  same CSV bytes, same batch/batch-order rows, same courier_csv_exported_at stamping, same activity
  log event.
- Add at least one NEW test proving a manual (group-less) outbound - reason Replacement, ship_mode
  parcel, a supported courier shipping method, ready lines - validates OK and exports to a CSV +
  batch row + courier_csv_exported_at, with no FulfillmentGroup involved. This is the acceptance
  test for the motivating gap.
- Keep tenant-scope assertions: export must reject outbound ids outside allowedTenantIds and must
  not leak across tenants in the mixed-tenant bucket.

## Acceptance
- Full suite green (php artisan test).
- A Replacement outbound with no FulfillmentGroup can be exported to a courier CSV end to end.
- CSV output for group-backed outbounds is unchanged (byte-identical).
- No remaining references to validateGroupExport / exportGroups / findGroup* in app code.
- ASCII punctuation only in all touched specs, code, and lang files.

## Commit
Per-phase workflow: implement, full suite green, then commit. Suggested message:
"Re-key courier export from FulfillmentGroup to OutboundOrder". Move this spec to docs/tasks/done/
after commit.
