# Task: Outbound Unification Phase 11 - Pack Entry Keyed on OutboundOrder

Parent plan: docs/tasks/done/outbound-unification-v1.md. Done so far: rename arranged, schema,
populate, pack lines (packLines already reads outboundOrder.leafLines), printed flag, printed
readers, courier export, tracking import, manual reason chooser, nav fold, detail consolidation,
queue re-key (Phase 10). This phase re-keys PACK ENTRY (the scan/pack page, the pack-start station
lookup, and the pack-scan record) from FulfillmentGroup to OutboundOrder, so packing no longer needs
a FulfillmentGroup to identify the parcel being packed or to store scans.

Consolidation producing an OutboundOrder without a FulfillmentGroup, dropping fulfillment_groups /
fulfillment_group_orders, dropping sales_orders.courier_csv_exported_at, the courier-export id
re-key, and the module/route rename are LATER phases. This phase keeps FulfillmentGroup populated
(1:1 with the outbound) for back-compat; pack just stops depending on it as the key.

## Scope guard
- Same pack capabilities: scan, wrong-item / over-scan / blocked-status results, quantity prompt,
  strict mode, progress summary, mark-shipped, station tracking-no lookup, station queue.
- Only the keying entity changes from FulfillmentGroup to OutboundOrder.
- packLines / packLinesWithProgress already operate on outboundOrder.leafLines - keep that; the
  change is what they receive (an OutboundOrder) and how accepted-scan quantities are counted.

## Stack
- Laravel 13, Livewire 4, PHP 8.3, SQLite. Tenant scoping unchanged. ASCII punctuation only.
- All data is dummy/reseeded - no production backfill needed. Reseed rather than migrate data.

## Schema
Add outbound_order_id to fulfillment_pack_scans (new migration):
- nullable()->constrained('outbound_orders')->cascadeOnDelete().
- Keep fulfillment_group_id (now nullable) for back-compat display this phase; do NOT drop it yet.
- Add index(['outbound_order_id', 'result', 'sku_id', 'stock_item_id']) mirroring the existing
  pack-scan progress index (see 2026_06_24_000003_add_pack_scan_progress_index), plus
  index(['outbound_order_id', 'created_at']).
- Reseed so existing dummy scans get an outbound_order_id (or just let the seeder create fresh).

FulfillmentPackScan model: add 'outbound_order_id' to $fillable and an outboundOrder() belongsTo.

## Service (FulfillmentPackService)
Re-type the pack methods to OutboundOrder. Today they take FulfillmentGroup but only use
group->outboundOrder->leafLines and group->id (for scan counts). Change signatures to accept
OutboundOrder directly:
- packLines(OutboundOrder $order): iterate $order->leafLines (drop the ->outboundOrder hop);
  loadMissing the leafLines.sku/stockItem/parentLine barcodeAliases on the order.
- packLinesWithProgress(OutboundOrder $order), acceptedScanQuantity/Count(OutboundOrder, line),
  allLinesComplete(OutboundOrder), acceptedScanQuantitiesByLine(OutboundOrder): filter scans by
  where('outbound_order_id', $order->id) instead of fulfillment_group_id. Line identity unchanged
  (component lines key on stock_item_id with sku_id=null; single lines sku:{sku_id}:stock:{...}).
- findGroupForTrackingNo -> rename to findOrderForTrackingNo returning a PackLookupResult that
  carries an OutboundOrder (rename PackLookupResult::group accessor to ->order; update found/
  alreadyShipped/cancelled factories). Query OutboundOrder::query()->whereNotNull(
  'fulfillment_group_id')->where('status', PENDING)->where('warehouse_id', ...)->where(
  'shipping_method_id', ...) matching on outbound.tracking_no OR linked salesOrders.tracking_no OR
  ref. Status checks switch to OutboundOrder STATUS_SHIPPED / STATUS_CANCELLED / PENDING.

## Pack page component (FulfillmentGroupPack)
- mount(OutboundOrder $order): bind/authorize the OutboundOrder (whereNotNull fulfillment_group_id,
  tenant-scoped); store $this->outboundOrderId. Drop groupId.
- loadOrder(): OutboundOrder with tenant, shippingMethod, fulfillmentGroup:id,reference_no, plus the
  leafLines.sku/stockItem/parentLine eager loads the service needs (or rely on service loadMissing).
- Gate on $order->status === OutboundOrder::STATUS_PENDING everywhere the code currently checks
  FulfillmentGroup::STATUS_RESERVED (scan, confirmPendingQuantity, markShipped, render readOnly).
  Blocked-status messages: shipped -> already_shipped, cancelled -> cancelled_group (reuse the same
  lang keys; "cancelled_group" text is fine to keep this phase).
- writeScan(): set outbound_order_id => $order->id and tenant_id => $order->tenant_id. Also set
  fulfillment_group_id => $order->fulfillment_group_id (back-compat) until teardown. Keep sku_id/
  stock_item_id as today.
- markShipped(): lock the OutboundOrder (not the group), recheck status PENDING + allLinesComplete,
  then shipService->ship($order, ['courier' => ..., 'shipping_method' => ..., 'tracking_no' => ...]).
  Source the courier/method/tracking from the outbound (outbound->courier, outbound->shippingMethod
  ?->name, outbound->tracking_no) - fall back to fulfillmentGroup values only if the outbound field
  is null. Redirect to outbound.show as today.
- progressSummary(): count exception scans by outbound_order_id.
- render(): subtitle = $order->fulfillmentGroup?->reference_no ?? $order->ref; pass $order (and keep
  $group for the blade only if still referenced - prefer rebinding the blade to $order).

## Pack page route + links
- Add route GET /outbound/{order}/pack -> FulfillmentGroupPack, name outbound.pack, bound to
  OutboundOrder (route-model-binding). Remove the old fulfillment-groups.{group}.pack route.
- Update link sites to route('outbound.pack', $outbound):
  - FulfillmentGroupIndex blade: pack button already has the OutboundOrder ($order) in scope - link
    to route('outbound.pack', $order) and gate on $order->status === PENDING (drop the
    ->fulfillmentGroup hop).
  - OutboundOrderDetail blade: pack/scan action -> route('outbound.pack', $order).
  - FulfillmentPackStart::search redirect -> route('outbound.pack', $result->order).
  - fulfillment-pick-summary / fulfillment-group-pack blades and anywhere else building
    route('fulfillment-groups.pack', ...) - point at route('outbound.pack', $outbound).
- Class/namespace/file rename of FulfillmentGroupPack is OUT of scope (module rename is a later
  phase); only the route + binding change here.

## Pack-start station (FulfillmentPackStart)
- queueQuery(): list OutboundOrders (whereNotNull fulfillment_group_id, status PENDING, warehouse,
  shipping_method_id) like the Phase 10 queue; search ref / tracking_no / recipient_name /
  recipient_phone / linked salesOrders.tracking_no / salesOrders.platform_order_id.
- render(): per-row progress via packLinesWithProgress($outbound); key queueProgress by outbound id.
  Eager-load tenant, salesOrders:id,platform_order_id,tracking_no, fulfillmentGroup:id,reference_no.
- stationSummary(): waiting_groups -> waiting count of outbounds; waiting_orders -> count of linked
  sales orders (count rows in outbound_order_sales_order for the queued outbounds, or sum); exception
  scans today filtered by outbound_order_id in the queued set.
- search(): call findOrderForTrackingNo; redirect route('outbound.pack', $result->order).
- Blade (fulfillment-pack-start): iterate outbounds; reference = fulfillmentGroup->reference_no ??
  ref; queue links -> route('outbound.pack', $outbound); progress keyed by outbound id.

## Scan history (out of scope to re-key, but must keep working)
- fulfillment-pack-scan-index still filters/displays by fulfillment_group_id, which remains
  populated this phase, so it keeps working unchanged. Adding an outbound filter/display is a later
  cleanup. Do not break it.

## Blades
- fulfillment-group-pack.blade.php: rebind $group -> $order (reference via
  $order->fulfillmentGroup?->reference_no ?? $order->ref; status checks via OutboundOrder status).
- fulfillment-pack-start.blade.php: as above.

## Out of scope (later phases)
- Consolidation producing an OutboundOrder without a FulfillmentGroup.
- Dropping fulfillment_groups / fulfillment_group_orders / fulfillment_group_id columns; dropping
  sales_orders.courier_csv_exported_at; courier-export id re-key to outbound ids; unifying the
  tracking store.
- Renaming the module / component classes / route prefixes.

## Tests
- Pack scan/quantity/strict/over-scan/wrong-item/blocked-status tests: construct the component with
  ['order' => $outbound] and assert scans persist with outbound_order_id; gate via outbound status.
- mark-shipped tests: ship via the outbound; assert OutboundOrder + group + sales orders update
  (ShipOutboundOrderService already mirrors the group).
- Station lookup tests (FulfillmentPackStartQueueTest): queue lists outbounds; tracking-no scan
  redirects to route('outbound.pack', $outbound); multiple/already-shipped/cancelled branches use
  OutboundOrder status.
- FulfillmentPackScanHistory tests: still pass (scans carry fulfillment_group_id).
- Route render test: GET route('outbound.pack', $outbound) is 200 and shows the reference.
- Full suite green.

## Acceptance Criteria
- The pack page, station lookup, and pack-scan records key on OutboundOrder (outbound_order_id);
  FulfillmentPackService takes OutboundOrder; no FulfillmentGroup is required to pack or to record a
  scan. FulfillmentGroup is still written for back-compat display.
- All pack capabilities work identically for the user.
- Full suite green.
