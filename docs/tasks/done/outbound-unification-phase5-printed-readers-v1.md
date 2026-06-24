# Task: Outbound Unification Phase 5 - Move FulfillmentGroup Printed Readers to the OutboundOrder Flag

Parent plan: docs/tasks/outbound-unification-v1.md. Done: Phase 0 (048f221), Phase 1 (1829ea7),
Phase 2 (a2f543b), Phase 3 (ff62422), Phase 4 (8d02813). Phase 4 moved the SALES ORDER printed
readers (hold gate + Packing chip) to OutboundOrder.courier_csv_exported_at. This phase does the
same for the FULFILLMENT GROUP page so that, after it, nothing in the fulfillment UI reads
sales_orders.courier_csv_exported_at. Small, contained, low-risk. (Courier export / tracking import
decoupling is a separate later phase; see the note at the end.)

## Stack
- Laravel 13, Livewire 4, PHP 8.3, SQLite (dev). Tenant scoping unchanged. ASCII punctuation only.
- Phase 2 dual-writes courier_csv_exported_at to BOTH sales_orders and the linked OutboundOrder, so
  switching these reads is behavior-equivalent on current data.

## Background (current readers to move)
app/Livewire/FulfillmentGroupIndex.php currently reads the printed flag through the member sales
orders:
- printWaiting filter:      ->whereHas('groupOrders.salesOrder', fn ($sub) => $sub->whereNull('courier_csv_exported_at'))
- Others "printed":         ->whereHas('groupOrders.salesOrder', fn ($sub) => $sub->whereNotNull('courier_csv_exported_at'))
- Others "not printed":     ->whereHas('groupOrders.salesOrder', fn ($sub) => $sub->whereNull('courier_csv_exported_at'))
resources/views/livewire/fulfillment-group-index.blade.php Added-cell:
- $printed = $members->map(fn ($go) => $go->salesOrder?->courier_csv_exported_at)->filter()->min();

A FulfillmentGroup is 1:1 with an OutboundOrder, and that OutboundOrder already carries
courier_csv_exported_at (Phase 2). Switch all of the above to read the group's OutboundOrder flag.

## Changes
File: app/Livewire/FulfillmentGroupIndex.php
- printWaiting:    ->whereHas('outboundOrder', fn ($sub) => $sub->whereNull('courier_csv_exported_at'))
- OTHER_PRINTED:   ->whereHas('outboundOrder', fn ($sub) => $sub->whereNotNull('courier_csv_exported_at'))
- OTHER_NOT_PRINTED: ->whereHas('outboundOrder', fn ($sub) => $sub->whereNull('courier_csv_exported_at'))
- In the render() eager-load, make sure the outboundOrder select includes courier_csv_exported_at
  (it currently loads 'outboundOrder:id,fulfillment_group_id,shipping_method' - add the column, or
  load the needed columns).

File: resources/views/livewire/fulfillment-group-index.blade.php
- Replace the $printed computation with the group's outbound flag:
  $printed = $group->outboundOrder?->courier_csv_exported_at;
  (Keep the existing Added-cell display: "Printed :time" via fulfillment_groups.printed_at when
  $printed is set, else fulfillment_groups.not_printed. The detailed-mode year formatting stays.)

## After this phase
The only remaining readers of sales_orders.courier_csv_exported_at are the courier export validation
and any sales-order export/date logic - those move with the courier-export decoupling phase, after
which sales_orders.courier_csv_exported_at can be dropped (teardown phase). Do NOT drop it here, and
keep the Phase 2 dual-write.

## Tests (tests/Feature)
- printWaiting / Others printed / Others not-printed on FulfillmentGroupIndex filter by the
  OutboundOrder's courier_csv_exported_at: a group whose outbound is printed appears under
  "printed" and is excluded from printWaiting / "not printed", and vice versa.
- The Added-cell shows the printed time from the outbound order when printed, "Not printed"
  otherwise.
- Tests that simulate "printed" by setting the flag must set it on the OutboundOrder (or run a real
  courier export, which dual-writes). Update the few that set it on the sales order.
- All existing FulfillmentGroupTest cases pass (adjust only the flag-setting side).

## Do Not Do In This Task
- Do NOT touch courier export, tracking import, or their batch tables.
- Do NOT stop dual-writing or drop sales_orders.courier_csv_exported_at.
- Do NOT change pack, hold, inventory math, statuses, or the sales-order pages (Phase 4 owns those).

## Acceptance Criteria
- FulfillmentGroupIndex (filters + Added-cell printed display) reads the printed flag from the
  group's OutboundOrder, not from member sales orders.
- No fulfillment UI reads sales_orders.courier_csv_exported_at anymore.
- Full suite green; user-visible behavior unchanged.

## Note on the remaining big phase (courier export + tracking import)
Decoupling courier CSV export and tracking import to OutboundOrder is the next major phase and needs
two decisions first, so it is intentionally NOT specced here:
1. CSV item naming for bundles: keep sales-order-line SKUs (current CSV, works only for consolidated
   parcels) vs outbound leaf lines (expanded components, also works for manual parcels). Likely
   answer: use linked sales orders when present, fall back to outbound leaf lines for manual parcels.
2. courier_export_batch_orders currently FKs sales_order_id; manual parcels have no sales order, so
   the batch likely needs an outbound_order_id reference (schema change) for per-parcel re-export
   detection.
Resolve these before writing that phase.
