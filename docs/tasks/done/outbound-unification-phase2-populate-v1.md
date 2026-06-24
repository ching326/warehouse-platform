# Task: Outbound Unification Phase 2 - Populate New OutboundOrder Structures (additive)

Parent plan: docs/tasks/outbound-unification-v1.md. Phase 0 (rename, 048f221) and Phase 1
(schema scaffolding, 1829ea7) are done. This is Phase 2, scoped narrowly as ADDITIVE population:
fill the new columns and the sales-order pivot from the consolidation flow, and dual-write the
printed flag onto the OutboundOrder. NO reads are switched yet (hold gate, pack, courier export
matching, and UI all keep their current sources), so the app stays green with minimal test churn.
The read-switch (hold gate + Packing chip) happens in the courier-decoupling / UI phases.

## Stack
- Laravel 13, Livewire 4, PHP 8.3, SQLite (dev). Inventory append-only via InventoryService.
- Tenant scoping unchanged. ASCII punctuation only. All data is dev dummy data.

## Goal
After this phase, every consolidated parcel's OutboundOrder carries reason=customer_order,
ship_mode=parcel, its shipping_method_id, and an outbound_order_sales_order pivot mirroring the
member sales orders; and courier export stamps courier_csv_exported_at on the OutboundOrder too.
Nothing reads these yet - they shadow the existing FulfillmentGroup data so later phases can flip
reads over safely.

## 1. Consolidation populates the new OutboundOrder fields + pivot
File: app/Services/Fulfillment/GroupSalesOrdersService.php

- In createGroupFromOrders(), when creating the OutboundOrder, also set:
  - reason => OutboundOrder::REASON_CUSTOMER_ORDER
  - ship_mode => OutboundOrder::SHIP_MODE_PARCEL
  - shipping_method_id => the group's resolved shipping method id (the same value passed to
    FulfillmentGroup via resolveGroupShippingMethodId / $defaultShippingMethodId).
- In attachAndReserve() (used by both create and join paths), right after
  $group->orders()->attach($attachPayload), mirror the same rows onto the OutboundOrder pivot:
  $outbound->salesOrders()->attach($attachPayload)  // same [sales_order_id => ['arranged_at' => $now]]
  So outbound_order_sales_order stays in lock-step with fulfillment_group_orders.
- Keep ALL existing FulfillmentGroup behavior exactly as is (dual-write, do not remove anything).

## 2. Hold claw-back keeps the pivot in sync
File: app/Services/Fulfillment/GroupSalesOrdersService.php (releaseOrderForHold)

- It already detaches the order from the group ($group->orders()->detach) and rebuilds outbound
  lines. Add a mirror detach so the new pivot does not go stale:
  $outbound->salesOrders()->detach($order->id);
- No other change to claw-back logic (still operates on FulfillmentGroup this phase).

## 3. Courier export dual-writes the printed flag to the OutboundOrder
File: app/Services/Courier/CourierExportService.php (exportGroups)

- Where it stamps each member sales order's courier_csv_exported_at (the
  $order->update(['courier_csv_exported_at' => $exportedAt]) loop), also stamp the group's linked
  OutboundOrder with the same timestamp:
  $group->outboundOrder?->update(['courier_csv_exported_at' => $exportedAt]);
  (Ensure outboundOrder is loaded/eager-loaded in that query.)
- Re-export must update both again (same timestamp semantics as today).
- Do NOT stop writing sales_orders.courier_csv_exported_at - keep dual-write.

## Out of scope (explicitly later phases)
- Do NOT switch the hold gate (canPutOnHold / releaseOrderForHold eligibility / bulkHold query)
  to read the OutboundOrder flag - it keeps reading sales_orders.courier_csv_exported_at this
  phase. (Switch happens with courier decoupling, Phase 4.)
- Do NOT add the sales-order Packing chip yet (Phase 5 UI).
- Do NOT change pack (Phase 3) or merge/replace courier matching logic (Phase 4).
- Do NOT touch manual outbound create / the reason chooser (Phase 5); manual outbounds keep the
  Phase 1 defaults (ship_mode=parcel, reason null).
- Do NOT remove or stop populating fulfillment_group_orders or FulfillmentGroup fields.
- Do NOT change inventory reserve/release/ship math.

## Tests (tests/Feature)
- Creating a consolidated group: the linked OutboundOrder has reason=customer_order,
  ship_mode=parcel, the expected shipping_method_id, and outbound_order_sales_order pivot rows
  (with arranged_at) matching the member sales orders.
- Joining orders to an existing group appends matching pivot rows on the OutboundOrder.
- Holding one order out of a multi-order group detaches it from BOTH fulfillment_group_orders and
  the OutboundOrder pivot (remaining order still linked on both).
- Courier export sets courier_csv_exported_at on the linked OutboundOrder (and still on the member
  sales orders).
- All existing FulfillmentGroupTest / SalesOrderTest / SalesOrderIndexBulkTest / OutboundOrderTest /
  courier export tests pass unchanged (no existing assertions should need editing - this phase only
  adds data).

## Acceptance Criteria
- Consolidated parcels carry reason/ship_mode/shipping_method_id and a populated sales-order pivot
  that stays in sync on create, join, and hold-claw-back.
- Courier export dual-writes the printed flag to the OutboundOrder.
- No reads switched; no existing behavior or test changes; full suite green.
