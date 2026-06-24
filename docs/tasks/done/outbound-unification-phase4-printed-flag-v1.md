# Task: Outbound Unification Phase 4 - Printed Flag on OutboundOrder Drives Hold + Packing Chip

Parent plan: docs/tasks/outbound-unification-v1.md. Done: Phase 0 (048f221), Phase 1 (1829ea7),
Phase 2 (a2f543b), Phase 3 (ff62422). This phase makes the OutboundOrder's courier_csv_exported_at
("printed") the source of truth for the hold gate, and adds the sales-order "Packing" chip
(decision 2). It is the read-switch that Phase 2 deliberately deferred. Courier export / tracking
decoupling and the remaining printed-flag readers (filters) are LATER phases.

Since Phase 2 dual-writes courier_csv_exported_at to BOTH sales_orders and the linked OutboundOrder,
the two are consistent, so switching the hold gate to read the OutboundOrder flag is behavior-
equivalent except where intended.

## Stack
- Laravel 13, Livewire 4, PHP 8.3, SQLite (dev). Tenant scoping unchanged. ASCII punctuation only.

## Background
- A sales order reaches its parcel via the outbound_order_sales_order pivot (populated in Phase 2):
  it is in at most one NON-cancelled OutboundOrder (its active parcel).
- Today the hold gate and the sales-order "printed date" display read
  sales_orders.courier_csv_exported_at. We move both to the OutboundOrder flag.

## 1. SalesOrder: relation + "is packing" helper
File: app/Models/SalesOrder.php
- Add outboundOrders(): belongsToMany(OutboundOrder::class, 'outbound_order_sales_order')
  ->withPivot('arranged_at').
- Add a helper, e.g. activeOutboundOrder(): the single linked OutboundOrder whose status is not
  cancelled (there is at most one). And isPacking(): bool => activeOutboundOrder has
  courier_csv_exported_at !== null. Keep these efficient (eager-loadable).

## 2. Hold gate reads the OutboundOrder printed flag
- app/Livewire/SalesOrderDetail.php (canPutOnHold): replace the
  $order->courier_csv_exported_at !== null check with "the order's active OutboundOrder is printed"
  (isPacking()). Not-grouped orders have no active outbound -> not packing -> holdable.
- app/Services/Fulfillment/GroupSalesOrdersService.php (releaseOrderForHold eligibility re-check):
  replace the courier_csv_exported_at check the same way (re-resolve the active outbound under lock
  and read its printed flag).
- app/Livewire/SalesOrderIndex.php (bulkHold query): replace
  ->whereNull('courier_csv_exported_at') with a constraint that excludes orders whose active
  OutboundOrder is printed, e.g. whereDoesntHave('outboundOrders', fn ($q) => $q
    ->where('status', '!=', OutboundOrder::STATUS_CANCELLED)
    ->whereNotNull('courier_csv_exported_at')). Keep the rest of the eligibility unchanged.
- Net behavior unchanged for users (dual-write keeps flags consistent), but the source of truth is
  now the parcel.

## 3. Sales-order "Packing" chip (decision 2)
- lang: add sales_orders.label_packing => en "Packing", ja "梱包中", zh_TW "包裝中", zh_CN "包装中"
  (all four locales; keep ASCII punctuation, CJK value only).
- SalesOrderIndex blade (the created/printed cell) and SalesOrderDetail: where the printed date was
  shown from sales_orders.courier_csv_exported_at, instead show a "Packing" chip when the order
  isPacking() (active outbound printed). When not packing, render as before (no chip / order date).
- The chip and the hold block share the exact same condition (isPacking) so the UI explains why hold
  is disabled.
- Eager-load outboundOrders on the index query to avoid N+1.

## Out of scope (later phases)
- Do NOT stop dual-writing sales_orders.courier_csv_exported_at, and do NOT drop the column yet -
  other readers still use it (sales-order export date logic, FulfillmentGroupIndex printWaiting /
  printed-vs-not-printed Others filter and the Added-cell printed display). Those move in a later
  printed-readers cleanup phase, before the column is dropped in the teardown phase.
- Do NOT decouple courier export / tracking import (next big phase).
- Do NOT touch pack, inventory math, or statuses.

## Tests (tests/Feature)
- Update existing hold tests that simulate "printed" by setting sales_orders.courier_csv_exported_at
  to instead set the linked OutboundOrder's courier_csv_exported_at (that is now the gate). Printed
  parcel -> hold blocked (detail + bulk); not printed -> hold allowed + claw-back, as today.
- A sales order whose active OutboundOrder is printed shows the Packing chip on index + detail; one
  that is not printed does not.
- A not-grouped order (no active outbound) is holdable and shows no Packing chip.
- All other existing SalesOrder / FulfillmentGroup / OutboundOrder tests pass (adjust only the few
  that set the printed flag on the wrong side).

## Acceptance Criteria
- Hold eligibility (detail hold, bulkHold, releaseOrderForHold) is driven by the active
  OutboundOrder's courier_csv_exported_at, not sales_orders.
- The sales-order pages show a "Packing" chip exactly when hold is blocked due to a printed parcel.
- sales_orders.courier_csv_exported_at is still written (dual-write) and not dropped; full suite green.
