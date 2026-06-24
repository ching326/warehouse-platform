# Task: Outbound Unification Phase 10 - Fulfillment Queue Lists OutboundOrders

Parent plan: docs/tasks/outbound-unification-v1.md. Done so far: rename arranged, schema, populate,
pack lines, printed flag, printed readers, courier export, tracking import, manual reason chooser,
nav fold, detail consolidation. This phase re-keys the FULFILLMENT QUEUE (FulfillmentGroupIndex)
from FulfillmentGroup to OutboundOrder, so the queue no longer depends on FulfillmentGroup for
listing or row actions. Pack entry, consolidation, and the FulfillmentGroup table drop are LATER
phases (pack still runs through the group via outbound->fulfillmentGroup this phase).

Scope guard: the page stays at the same route (fulfillment-groups.index) and keeps all current
features (filters, Details toggle, print-waiting pill, inline edit, bulk mark-shipped, courier
export, tracking import). Only the underlying entity changes from FulfillmentGroup to OutboundOrder.

## Stack
- Laravel 13, Livewire 4, PHP 8.3, SQLite. Tenant scoping unchanged. ASCII punctuation only.

## What the queue lists now
Consolidated parcels = OutboundOrders that came from sales-order consolidation. Identify them by
fulfillment_group_id IS NOT NULL (equivalently reason = customer_order). The query becomes:

    OutboundOrder::query()
        ->whereNotNull('fulfillment_group_id')
        ->whereIn('tenant_id', $this->allowedTenantIds())
        ->with([
            'tenant:id,code,name',
            'warehouse:id,code,name',
            'shippingMethod:id,name',
            'fulfillmentGroup:id,reference_no,status',
            'salesOrders:id,shop_id,platform_order_id',
            'salesOrders.shop:id,name',
            'salesOrders.lines:id,sales_order_id,sku_id,quantity',
        ])
        ->when($this->detailed, ... load salesOrders.lines.sku.stockItem ...)
        ... filters ...
        ->orderBy(...)->paginate(30);

Drop the withCount('orders') / withMin('groupOrders','arranged_at') group helpers; compute the
"arranged at" from the pivot (outbound_order_sales_order.arranged_at) - e.g. withMin on the
salesOrders pivot, or read created_at. Reference number shown = fulfillmentGroup->reference_no
(== outbound->ref); keep displaying it.

## Filter remapping (status source of truth = OutboundOrder)
- tenantIds: outbound.tenant_id (unchanged).
- warehouseId: outbound.warehouse_id.
- statusesFilter: was group status (reserved/shipped/cancelled). OutboundOrder status is
  pending/shipped/cancelled. Map reserved->pending in the filter (and in the status labels/colors:
  pending shows as the "reserved/processing" label). Keep the same three filter options the user
  sees; translate to outbound status values in the query.
- printWaiting / OTHER_PRINTED / OTHER_NOT_PRINTED: outbound.courier_csv_exported_at (whereNull /
  whereNotNull) directly (no more whereHas outboundOrder).
- shippingMethodsFilter: outbound.shipping_method_id (+ EMPTY_SHIPPING -> whereNull).
- othersFilter OTHER_MULTI_ITEM: parcels whose combined leaf-line qty > 1 (use leafLines), or
  reuse the existing multi-item definition against salesOrders.lines.
- search: outbound ref, recipient_name, tracking_no, and linked salesOrders.platform_order_id.

## Status label/color
statusLabel/statusColor switch to OutboundOrder statuses. Keep the user-facing labels: pending ->
"Reserved" (fulfillment_groups.status_reserved), shipped -> status_shipped, cancelled ->
status_cancelled. (The queue still talks "reserved" to users; it just maps to outbound pending.)

## Row actions (operate on OutboundOrder; mirror group for back-compat)
- selectedIds / visibleGroupIds become OUTBOUND order ids (rename to selectedIds / visibleOrderIds).
- updateNote(outboundId): outbound->update(note) (+ mirror fulfillmentGroup->note).
- updateShippingMethod(outboundId): outbound->update(shipping_method_id) (+ mirror group). (Same as
  today's group update which already mirrors to outbound - now primary on outbound.)
- updateTracking(outboundId): outbound->update(tracking_no) + linked salesOrders + group_orders
  (back-compat), inside a transaction.
- markShipped(): ship the selected OutboundOrders directly via ShipOutboundOrderService (drop the
  group lookup; iterate selected outbound ids that are pending + have leaf lines).
- exportYamato/exportSagawa: selection is outbound ids; map to fulfillment group ids
  (OutboundOrder::whereIn('id', selected)->pluck('fulfillment_group_id')) and call the existing
  CourierExportService::validateGroupExport / exportGroups (still group-keyed this phase). Re-export
  warning unchanged.
- Pack / Scan-history links: use outbound->fulfillmentGroup (route fulfillment-groups.pack +
  fulfillment.pack-scans.index). Scan-pack button shows when outbound status = pending.
- Detail link: route('outbound.show', $outbound) (already the case from the detail-consolidation
  phase).

## Blade (fulfillment-group-index.blade.php)
- Iterate $orders (OutboundOrders). Per row read: $order->fulfillmentGroup->reference_no,
  $order->salesOrders (platform ids, shops, combined items), $order->shipping_method_id,
  $order->tracking_no, $order->note, $order->courier_csv_exported_at (printed), $order->status.
- Keep Details toggle, print-waiting pill, filters markup; just rebind to the new fields.
- checkbox values = outbound id.

## Out of scope (later phases)
- Pack entry / FulfillmentPackScan re-key to OutboundOrder.
- Consolidation producing OutboundOrder without a FulfillmentGroup.
- Dropping fulfillment_groups / fulfillment_group_orders, courier export re-key to outbound ids,
  unifying the tracking store, dropping sales_orders.courier_csv_exported_at.
- Renaming the route/module.

## Tests
- Port FulfillmentGroupTest index/filter/bulk tests to assert against OutboundOrder-backed listing
  and actions (selection by outbound id; mark-shipped ships the outbound; courier export still works
  via mapped group ids; printWaiting/printed filters via outbound flag; search by ref/tracking/
  platform id).
- Tenant visibility test: queue only lists outbounds whose tenant is allowed.
- All existing tests pass (migrate selection-id and reference assertions as needed).

## Acceptance Criteria
- FulfillmentGroupIndex queries and acts on OutboundOrders (fulfillment_group_id not null); no
  FulfillmentGroup query in the component except resolving pack/scan links + courier export id
  mapping.
- All queue features work identically for the user (filters, Details, print-waiting, inline edit,
  bulk mark-shipped, courier export, tracking import, pack).
- Full suite green.
