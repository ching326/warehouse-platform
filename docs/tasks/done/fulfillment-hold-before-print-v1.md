# Task: Allow Hold In Fulfillment Until Courier CSV Is Printed (v1)

## Stack
- Laravel 13, Livewire 4 (class based), Flux UI 2, PHP 8.3, SQLite (dev).
- Inventory movements are append only via App\Services\InventoryService.
- Tenant scoping: components resolve allowedTenantIds(); never query across tenants.

## Goal
Today a sales order that is in a fulfillment group cannot be put on hold. Change this so a
grouped order CAN be held as long as it has NOT been printed yet, where "printed" means its
courier CSV has been exported (sales_orders.courier_csv_exported_at is set).

Confirmed workflow: pick and pack always happen AFTER courier CSV print. So courier_csv_exported_at
is a sufficient and authoritative gate. Do NOT add a new fulfillment group status, and do NOT add a
lock flag.

Rule:
- courier_csv_exported_at IS NULL  -> hold allowed (claw the order back out of the group).
- courier_csv_exported_at IS NOT NULL -> hold blocked.

## Current Behavior (for reference, do not keep)
- App\Livewire\SalesOrderIndex::bulkHold() only holds orders where order_status = pending,
  fulfillment_status in (unfulfilled, ready), AND the order is NOT in a non cancelled
  fulfillment group (whereDoesntHave fulfillmentGroupOrders ... status != cancelled).
- App\Livewire\SalesOrderDetail::hold() blocks via hasManualFulfillmentStatus(), which only
  returns true for fulfillment_status in (unfulfilled, ready), i.e. excludes in_group.
- Grouping (App\Services\Fulfillment\GroupSalesOrdersService::attachAndReserve) does three things:
  reserves stock per stock item (InventoryService::reserveStock), creates aggregated OutboundOrder
  lines, and sets each order fulfillment_status = in_group. The FulfillmentGroup is status reserved
  and has one linked OutboundOrder (status pending) with lines aggregated by sku + stock item across
  ALL member orders (virtual bundles are expanded into child component lines).

## New Behavior

### Hold eligibility (both entry points)
An order may be held when ALL of:
- order_status = pending
- courier_csv_exported_at IS NULL
- fulfillment_status in (unfulfilled, ready, in_group)
- if in_group: the group is status reserved (not shipped, not cancelled)

Apply this in BOTH:
- SalesOrderIndex::bulkHold() (replace the whereDoesntHave fulfillmentGroupOrders guard with a
  whereNull('courier_csv_exported_at') guard and allow in_group orders through).
- SalesOrderDetail::hold() (extend hasManualFulfillmentStatus path to allow in_group when not
  printed; keep the cannot_hold flash for ineligible orders).

When held, the order ends as order_status = on_hold, fulfillment_status = unfulfilled, and it must
no longer belong to any active group (see claw back). This makes it drop out of the fulfillment
groups list automatically and show as on hold on the sales order pages.

### Claw back (the real work)
Add a method to App\Services\Fulfillment\GroupSalesOrdersService, e.g.

    public function releaseOrderForHold(SalesOrder $order): void

Behavior, inside a DB::transaction with lockForUpdate on the group and order:
1. Find the order's active group (status reserved, not shipped/cancelled) via its
   fulfillmentGroupOrders pivot. If none, return (nothing to claw back; caller still holds it).
2. Re-aggregate the order's READY lines into per stock item quantities, expanding virtual bundles
   exactly like aggregateLines() does (reuse / extract that logic so bundle expansion stays
   identical). This is the order's contribution to the group reservation.
3. For each stock item, call InventoryService::releaseReserve(tenantId, warehouseId, stockItemId,
   qty, context: ['ref_type' => 'fulfillment_group', 'ref_id' => (string) group->id,
   'user_id' => Auth::id()]).
4. Detach the order from the group (group->orders()->detach(order->id)).
5. Rebuild the OutboundOrder lines from the REMAINING attached orders: delete the outbound order's
   existing lines and re-create them via the same aggregation used in attachAndReserve. Rebuilding
   is preferred over decrementing because it handles bundle parent/child lines correctly. (The
   OutboundOrder stays status pending while orders remain.)
6. If NO orders remain in the group:
   - set FulfillmentGroup status = cancelled
   - set the linked OutboundOrder status = cancelled, cancelled_at = now(),
     cancelled_by_user_id = Auth::id()
   (Reservation is already fully released by step 3 across all held orders, so no extra release here.)

Caller (bulkHold / hold) then sets the order order_status = on_hold,
fulfillment_status = unfulfilled.

Note: joinableGroupFor() already excludes printed orders (whereNotNull courier_csv_exported_at), so
the "not printed" boundary is already consistent on the grouping side.

## Should held orders stay in fulfillment?
No. After claw back the order is unfulfilled / on_hold and no longer attached to an active group, so
it disappears from the fulfillment groups list. A single order group becomes empty and is cancelled.
A multi order group keeps its remaining orders (still reserved) and just shrinks.

## Edge Cases
- Printed order (courier_csv_exported_at not null): hold blocked, no inventory or group change,
  show sales_orders.cannot_hold (detail) or skip in bulk count (index).
- Hold one of several orders in a not printed group: that order leaves, others stay reserved,
  outbound lines rebuilt from remaining orders, group stays reserved.
- Hold the only / last order in a group: group cancelled, outbound cancelled, reservation released.
- Order in a shipped or cancelled group: shipped group orders are always printed, so blocked;
  cancelled group is not active so treated as "not grouped".
- Concurrency: lockForUpdate the group and order; InventoryService::changeBalance already locks the
  balance row. Re-validate courier_csv_exported_at IS NULL inside the transaction.
- Tenant scope: only operate within allowedTenantIds().
- bulkHold mixed selection: hold the eligible (not printed) ones, skip the rest, report counts via
  the existing finishBulk result string.

## Tests (tests/Feature)
Add to or alongside FulfillmentGroupTest / SalesOrderTest:
1. Hold a not printed single order group: asserts group status cancelled, outbound cancelled,
   reserved_qty released back to available, order on_hold + unfulfilled, order absent from
   fulfillment index.
2. Hold one order from a not printed multi order group: held order removed and on_hold; remaining
   order still in_group and still reserved; outbound lines rebuilt to match remaining order only;
   group still reserved.
3. Cannot hold a printed order (courier_csv_exported_at set): bulkHold and detail hold both no op
   with no inventory/group change.
4. bulkHold mixed: printed grouped order skipped, not printed grouped order held; counts correct.
5. Inventory ledger: a release_reserve movement is recorded for the held order's stock items with
   the correct quantity; available_qty increases, reserved_qty decreases.
6. Detail page hold honors the same rule (in_group not printed allowed; printed blocked).

## Do Not Do In This Task
- Do NOT add a new FulfillmentGroup status (no processing / picking / pending).
- Do NOT add a lock column or flag.
- Do NOT change the courier export flow or what sets courier_csv_exported_at.
- Do NOT allow hold after print under any path.
- Do NOT change shipping or pack behavior.

## Acceptance Criteria
- A grouped, not printed order can be held from both the sales order index (bulk) and the sales
  order detail page; doing so releases its reservation, removes it from the group, rebuilds or
  cancels the linked outbound order, and leaves it on_hold / unfulfilled.
- A printed order can never be held.
- Inventory balances and the append only ledger stay correct (reserved released exactly once).
- All existing FulfillmentGroupTest and SalesOrderTest cases still pass, plus the new tests above.
