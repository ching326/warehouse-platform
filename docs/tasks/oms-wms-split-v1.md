# Task: OMS / WMS Split (Sales Order vs Fulfillment vs Outbound) v1

## Stack

Laravel 13, Livewire 4 (class-based components, not Volt), Flux UI (`livewire/flux ^2.14`),
SQLite (dev), PHP 8.3. Plain Blade views only. No TypeScript.

ASCII punctuation only in code, lang files, and this doc. No em-dashes, smart quotes, or unicode arrows.

---

## Goal

Make the role of each module explicit and stop the Sales Order page from doing warehouse work.

Three modules, three clear jobs:

| Module | Who uses it | Manages | Daily platform shipping main page |
|---|---|---|---|
| Sales Order | tenant / admin | platform orders, can-this-ship decision | No |
| Fulfillment | warehouse staff | ship queue, label print, pack, tracking | Yes |
| Outbound | staff / admin / tenant request | non-platform outbound (manual) | No |

Platform order path: `Sales Order -> Fulfillment -> Courier / Packing / Shipped`.
Non-platform path: `Outbound Order -> Ship`.

### Background (current state, for the implementer)

- `SalesOrderIndex` currently does both order management AND warehouse actions
  (courier CSV export, tracking import, inline tracking edit, print-waiting filter, bulk mark shipped).
- Reserve happens in `FulfillmentGroupCreate` (creates `FulfillmentGroup` + `OutboundOrder`, calls
  `InventoryService::reserveStock`, sets `SalesOrder.fulfillment_status = in_group`).
- Inventory ship happens in `OutboundOrderShip` (`InventoryService::shipReservedStock`).
- So platform orders already flow through `OutboundOrder`, and the Outbound page currently shows BOTH
  platform-derived outbound orders (with `fulfillment_group_id`) and manual ones (without).

This task does NOT rewrite the inventory primitive. `OutboundOrder` stays as the internal
reserve/ship record. We change UI ownership, move tracking ownership to the group, and reposition the
pages.

---

## Design invariants (v1)

These two rules resolve the gaps found in review. Build to them.

### Invariant 1: every Ship Ready order is in a FulfillmentGroup

There is no "ready but ungrouped" persisted state. Marking Ship Ready creates OR joins a
FulfillmentGroup in the same user action:

- combine confirmed -> one group holding the combined orders
- combine declined / no candidate -> a single-order FulfillmentGroup for that order

So the Fulfillment page only ever deals with FulfillmentGroups (never loose sales orders). This keeps
Fulfillment as the single WMS queue with one row type.

Do NOT hard-code a one-to-one order/group constraint. In v1 (ship-complete, see Partial Shipment
Policy) an order maps to exactly one group, but v2 partial shipment will let one order span multiple
groups/shipments over time. The order/group link (`fulfillment_group_orders`) is already
many-to-many; keep it that way (no unique constraint on `sales_order_id`).

### Invariant 2: one FulfillmentGroup = one shipment = one tracking number (v1)

A ship-together group is consolidated into one physical parcel, so it has ONE tracking number.
Tracking is entered once per group; on save it is written to every `fulfillment_group_orders` row in
the group and synced to every member `sales_orders.tracking_no`. Reporting that one tracking number to
each member order's marketplace notice is correct, because they are physically the same parcel.

The per-row `tracking_no` column is kept for forward-compatibility, but v1 always writes the same
value across a group. Per-order distinct tracking (multiple labels / multi-parcel) is deferred to v2.

ASSUMPTION (confirm): combining ("ship together") means one physical parcel with one label. If your
operation prints one label PER order even when combined, do not use this invariant -- instead keep
courier export and tracking per sales order (closer to today's behavior) and treat the group as a
pick/pack batch only. This choice drives the courier export granularity below.

---

## Module responsibilities (target)

### 1. Sales Order (OMS) -- tenant / admin

Keeps:

- platform order import (Amazon / Rakuten / Shopify / CSV / manual)
- edit order details (address, SKU mapping, shipping method, note)
- order status: hold / backorder / cancel request / release hold
- mark Ship Ready (this is the can-ship decision)
- ship-together combine prompt (see Flow A)
- marketplace shipping notice export (OMS / channel action). It keeps reading
  `sales_orders.tracking_no`, which is now synced from `fulfillment_group_orders.tracking_no`. No
  change to the export reader; only the write source changes.
- show tracking as READ ONLY (synced mirror from fulfillment)

Removes (moves to Fulfillment):

- courier CSV export (Yamato / Sagawa)
- tracking import
- inline tracking edit
- print-waiting filter
- bulk mark shipped (the inventory ship now lives in WMS)

Sales Order must NOT: pick, pack, courier export, mark physically shipped, deduct inventory.

### 2. Fulfillment (WMS) -- warehouse staff

This is the warehouse main work surface. Per Invariant 1 it operates on FulfillmentGroups only (each
Ship Ready order is already in a group); it never shows loose ungrouped sales orders.

Adds / owns:

- list FulfillmentGroups (the ship queue), each showing its member orders
- batch by warehouse / shipping method / print-waiting
- courier export Yamato / Sagawa (moved from Sales Order), one parcel row per group (Invariant 2)
- tracking import (moved from Sales Order), maps the returned tracking to the group
- print waiting filter (moved from Sales Order)
- mark shipped (deducts inventory through the extracted ship service)
- picking / packing summary

### 3. Outbound -- non-platform outbound only

Reposition, do not delete. Outbound answers: "is there a warehouse outbound order that deducts
stock and ships, but does NOT come from a platform Sales Order?"

Use cases: manual outbound, B2B shipment, FBA transfer / removal resend, tenant requests a batch to a
given address, internal adjustment shipment, replacement shipment, return-order resend.

Change: the Outbound index must show ONLY `OutboundOrder` where `fulfillment_group_id IS NULL`.
Platform (fulfillment-group) outbound orders move to the Fulfillment surface.

---

## Roles and access

Today `user_type = internal` is one bucket that sees everything; tenant users are scoped by
`activeTenantIds()`. We add an internal sub-role so order management and warehouse work are separated.

DECIDED: three internal roles.

- Add nullable `users.role` for internal users (null / tenant users unaffected) with values:
  - `admin` -- full access to everything.
  - `cs` -- customer service / ops. Manages the OMS side on behalf of tenants: Sales Order (import,
    edit, mark ready, hold/backorder, cancel request, marketplace notice), Issues, Inventory
    (read). Read-only view of Fulfillment is allowed; no Setup; does not run warehouse ship actions.
  - `warehouse` -- the operator role: Inbound, Outbound, Fulfillment, Inventory. No Sales Order, no
    Setup.
- Nav + route gating:

  | Surface | tenant | cs | warehouse | admin |
  |---|---|---|---|---|
  | Sales Order | own scope | yes | no | yes |
  | Issues | own scope | yes | no | yes |
  | Fulfillment | no | read-only | yes | yes |
  | Inbound / Outbound | no | no | yes | yes |
  | Inventory | no | read | yes | yes |
  | Setup | no | no | no | yes |

- Keep all existing tenant scoping (`allowedTenantIds()` / `visibleTenantIds()`) unchanged; role
  gating is an additional layer on top, not a replacement.
- This section ships as its own phase (Phase 6) after the data + flow phases land.

---

## Data model changes

### 1. Per-order tracking on the pivot (new source of truth)

`fulfillment_group_orders` must become a real, writable model, not just a join table. Today it is a
bare pivot (`$fillable = ['fulfillment_group_id', 'sales_order_id']`). This must be done in full or
the migration will add columns that nothing can write.

#### 1a. Migration

Add columns to `fulfillment_group_orders`:

- `tracking_no` nullable string(255)
- `courier` nullable string(100)
- `arranged_at` nullable timestamp -- when this order was arranged / queued for shipping (set when it
  is attached to a FulfillmentGroup)
- `shipped_at` nullable timestamp -- when this order was physically shipped

The table already has pivot timestamps (`created_at` / `updated_at` via `withTimestamps()`); keep them.

#### 1b. Model `FulfillmentGroupOrder`

- `$fillable`: add `tracking_no`, `courier`, `arranged_at`, `shipped_at`.
- casts: `arranged_at => datetime`, `shipped_at => datetime`.
- relations: `fulfillmentGroup(): BelongsTo`, `salesOrder(): BelongsTo`.

#### 1c. Related-model relations

- `FulfillmentGroup::groupOrders(): HasMany` -> `FulfillmentGroupOrder` (the editable per-order rows;
  keep the existing `orders()` BelongsToMany for read convenience).
- `SalesOrder::fulfillmentGroupOrders(): HasMany` -> `FulfillmentGroupOrder`, plus a helper for the
  latest active group row (e.g. `activeFulfillmentGroupOrder()` -- the row whose group is not
  cancelled) so the Sales Order page can read synced tracking and the arranged/shipped times.

Rationale: per-order `arranged_at` / `shipped_at` belong on the group-to-order link, and the app must
read/write them through Eloquent. `tracking_no` / `courier` also live here, but per Invariant 2 v1
writes the SAME value across all rows of a group (the column is per-row for v2 multi-parcel forward
compatibility, not because v1 sets different values).

### 2. `sales_orders.tracking_no` becomes a synced mirror

- Keep the existing `sales_orders.tracking_no` column.
- It is now a denormalized READ-ONLY mirror, not an editable field.
- Source of truth = `fulfillment_group_orders.tracking_no` (one value per group in v1).
- Only fulfillment-side write paths (tracking import, fulfillment tracking edit, mark shipped) set the
  group tracking, write it to every pivot row in the group, then sync down to each member
  `sales_orders.tracking_no` in the same transaction.
- The Sales Order page shows tracking read-only and no longer edits it.

Why keep the column: marketplace shipping notice export, Sales Order list display, and several
existing queries/exports already read `sales_orders.tracking_no`. Removing it would force every
reader to join through the pivot, and an ungrouped order would have no pivot row. Mirroring is the
lower-risk choice.

### 3. Legacy columns

- `fulfillment_groups.tracking_no` / `fulfillment_groups.courier`: stop writing/reading in the new
  flow. Leave the columns for now; mark for cleanup in a later task. Do not migrate-drop in this task.
- `outbound_orders.tracking_no` / `courier`: still valid for the NON-platform Outbound path
  (1 outbound = 1 parcel). For platform groups, the per-order pivot value is authoritative.

### 4. Printed / exported state (print-waiting source)

v1 is conservative: keep `sales_orders.courier_csv_exported_at` as the printed/exported mirror.

- Fulfillment courier export updates `sales_orders.courier_csv_exported_at` for every exported sales
  order (per-order), exactly as the Sales Order page does today.
- The Fulfillment print-waiting filter reads `sales_orders.courier_csv_exported_at` (waiting = ready /
  in-group order with this still null).
- Do NOT introduce a parallel group-level or pivot-level exported timestamp in v1. Keeping one source
  avoids the printed-state logic splitting across two columns. (A later task may move it to the pivot
  if per-parcel export tracking is needed.)

### 6. Shipping method lineage (implemented)

Shipping method has three roles across the journey; do not collapse them:

- `sales_orders.shipping_method_id` -- the INSTRUCTION (what the tenant / platform requested). Left
  unchanged when the warehouse overrides; preserved for OMS audit.
- `fulfillment_groups.shipping_method_id` (NEW, nullable FK) -- the DECISION (what this parcel will
  actually ship with). Defaulted from the member sales order at group creation, editable by warehouse
  staff via the Fulfillment Shipping dropdown.
- `outbound_orders.shipping_method` -- the EXECUTED record, written at ship time from the group's
  decision (the ship service also derives `courier` from the method's carrier).

Why the group, not the sales order or outbound: a group can span multiple sales orders (each with a
different requested method), so the one-method-per-parcel decision can only live on the group; the
outbound's method is only known at ship, so it cannot back a pre-ship dropdown. This lineage also
feeds courier export (carrier from the group's method) and removes the need for a courier filter.

### 5. Shop consolidation setting + ship_together_key recompute

DECIDED: cross-shop combine is controlled by a per-shop setting in Setup.

- Add `shops.consolidation_mode` (string, default `same_shop`) with values:
  - `none` -- this shop's orders are never combined (always single-order groups).
  - `same_shop` -- combine only with other orders from the SAME shop (same recipient/address).
  - `cross_shop` -- may also combine with orders from OTHER shops of the same tenant.
- Setup UI: add the setting to the Shop create/edit form (Setup -> Shop).
- Cross-shop combine between shop A and shop B is allowed only when BOTH shops are `cross_shop`.
  Same-shop combine requires the shop to be `same_shop` or `cross_shop`. `none` never combines.
- `ship_together_key` must stop including `shop_id` so it identifies the destination only
  (tenant + recipient name + full address). The shop setting (not the key) decides eligibility.
  - Update `SalesOrder::recalculateShipTogetherKey()` to drop `shop_id`.
  - Migration: recompute `ship_together_key` for all non-shipped, non-cancelled sales orders so
    existing pending orders match by destination. Do not touch already-shipped history.
  - Existing `FulfillmentGroup.ship_together_key` rows are historical; leave them.

Default `same_shop` preserves today's behavior (today the key includes shop_id, so combining only
happens within one shop).

---

## Order lifecycle (status transitions)

`SalesOrder.fulfillment_status`:

```
unfulfilled  -> ready      (Ship Ready validation passes; transient, within the same action)
ready        -> in_group   (create or join a FulfillmentGroup; same user action, see Invariant 1)
in_group     -> shipped    (mark shipped in Fulfillment / WMS)
any          -> cancelled  (cancel flow)
```

`ready` is a transient state inside the Mark Ship Ready action, not a resting state: the same action
always continues to `in_group` by creating or joining a group. An order is never persisted as `ready`
without a group. (If the action is interrupted before grouping, the order stays `unfulfilled`; nothing
is half-committed.)

`SalesOrder.order_status` is unchanged (pending / on_hold / backorder / cancel_requested /
cancelled / completed). On mark shipped, also set `order_status = completed` and `shipped_at`,
matching today's `bulkMarkShipped`, but driven from the WMS side.

---

## Partial shipment policy

Industry background (target model, for context): in 3PL / overseas fulfillment the tracking-bearing
unit is the parcel (package), not the order and not the SKU. The real relationship is many-to-many:
consolidation puts N orders in 1 parcel (one shared tracking); a split shipment puts 1 order in N
parcels (multiple tracking numbers, e.g. when only part of a multi-SKU order is in stock). A
FulfillmentGroup in this system IS that parcel/shipment, and tracking belongs to it.

### v1 policy: ship-complete only

v1 does NOT support partial shipment. An order can only be marked Ship Ready / grouped when ALL of its
fulfillable lines are ready. There is no backorder remainder shipped separately in v1.

Required behavior changes to close today's "half-partial" gap (today the code ships only `ready` lines
but flips the whole order to shipped, which would silently drop the un-ready remainder):

- Mark Ship Ready eligibility (`bulkMarkReady`): require EVERY fulfillable line of the order to be
  `ready` (and stock-item-valid). If any line is not ready, the order cannot be marked ready; surface
  why. Do not mark an order ready when only some lines are ready.
- Group aggregation (`FulfillmentGroupCreate::aggregateLines` and the shared
  `GroupSalesOrdersService`): an order entering a group must have all lines ready. Keep the existing
  per-line guard as a safety net, but the order-level gate above prevents partial orders from reaching
  here.
- Therefore "order shipped" == "all its lines shipped" holds trivially in v1, and `fulfillment_status`
  / `order_status` can be flipped at the order level on mark shipped (as today).

### Deferred to v2 (do not build now, but do not block)

- Partial shipment: ship the ready lines now as one shipment, leave the remainder as a backorder line,
  ship it later as a second FulfillmentGroup with its own tracking.
- Line/quantity-level fulfillment status and derived order completion (order `completed` only when all
  lines shipped).
- One order spanning multiple groups/shipments and therefore multiple tracking numbers; partial
  marketplace shipping-notice confirmation per shipment.

The schema choices in this task (many-to-many `fulfillment_group_orders`, per-row tracking column,
no unique constraint on `sales_order_id`) are made so v2 partial shipment does not require a data
remodel.

---

## Hold and recall policy

The thing that gates a hold is NOT the `ready` status by itself, but whether the order has entered
fulfillment, i.e. whether it is in an ACTIVE (non-cancelled) FulfillmentGroup with reserved stock.

Three tiers:

| Order state | Hold | What happens |
|---|---|---|
| Not in an active group (`unfulfilled`, or `ready` but not yet grouped) | allowed (casual bulk hold) | set `order_status = on_hold` and reset `fulfillment_status = unfulfilled` (leaves the ship queue) |
| In an active group, group still `reserved` and NOT exported / shipped | NOT a plain hold | requires a deliberate "Recall from fulfillment" action: release the reserved stock, remove the order from the group, then hold |
| In a group already courier-exported or shipped | blocked | too late; stopping it is a cancellation / warehouse recall, not a tenant hold |

### bulkHold (implemented)

`SalesOrderIndex::bulkHold` holds an order only when it is:

- `order_status = pending`, and
- `fulfillment_status` in (`unfulfilled`, `ready`), and
- NOT in an active fulfillment group
  (`whereDoesntHave('fulfillmentGroupOrders', group status != cancelled)`).

Held orders are set to `order_status = on_hold` + `fulfillment_status = unfulfilled`. Everything else
in the selection is skipped and reported in the result count. This blocks the dangerous case (order
already reserved / being picked) while still letting a not-yet-grouped order be held.

### Recall from fulfillment (deferred to Phase 4)

A separate action for an order in a `reserved`, not-exported/not-shipped group: release its reserved
stock (`InventoryService::releaseReserve`), detach it from the group, then set it on hold /
unfulfilled. Refused when the group is already exported or shipped. Not built in this step.

---

## Flow A: Sales Order -- Mark Ship Ready + combine prompt

On the Sales Order page, when the tenant marks one or more orders Ship Ready (per Invariant 1, every
such order ends up in a group):

1. Validate readiness (ship-complete: EVERY fulfillable line must be ready and stock-item-valid -- see
   Partial Shipment Policy). Only orders that fully pass continue; the rest are reported and excluded.
2. Look for a JOINABLE existing group and other ready candidates for the same recipient
   (`ship_together_key` now identifies destination only -- see Data model 5):
   - a joinable group = a `FulfillmentGroup` with the same `ship_together_key`, same warehouse,
     `status = reserved`, not yet courier-exported, not shipped
   - other same-key orders being marked ready in this same action are also combine candidates
   - apply the shop consolidation setting (Data model 5): a candidate from the SAME shop needs that
     shop `same_shop`/`cross_shop`; a candidate from a DIFFERENT shop is only eligible when BOTH the
     order's shop and the candidate's shop are `cross_shop`. `none` shops never combine (single-order
     group only).
   - same-recipient orders that are still `unfulfilled` are shown as a suggestion only ("also going to
     this address, not yet ready"); they are NEVER auto-grouped (that would skip their readiness check)
3. If a joinable group or another ready candidate exists, prompt the user:
   `Found a shipment to the same recipient and address. Combine into one parcel?`
4. On confirm -- in one transaction via the shared group service:
   - join the existing joinable group (reserve the delta stock for the new order, append its outbound
     lines, attach the pivot row), OR create one new group for the combined ready orders;
   - set each attached order `fulfillment_status = in_group` and the pivot
     `arranged_at = now()` (use the attach payload: `attach($ids, ['arranged_at' => now()])`).
5. On decline (or no candidate): create a single-order `FulfillmentGroup` for each readied order
   (same shared service, same reserve + outbound-line + `arranged_at` behavior). No order is left
   ungrouped.

Extract the group create/join logic from `FulfillmentGroupCreate` into one shared service
(e.g. `app/Services/Fulfillment/GroupSalesOrdersService.php`) used by the combine prompt, the
single-order default path, and the existing Fulfillment-side group creation. Group creation still
creates the `OutboundOrder` and reserves stock exactly as today. Joining an existing group is new
logic: it must reserve only the delta and append the new order's outbound lines, and must be refused
if the group is already exported or shipped.

The combine decision belongs to the tenant (who decides which orders ship together). The warehouse
executes; it should not have to guess ship-together intent. Merging two already-existing groups is out
of scope for v1 (use join-existing or single-order instead).

---

## Flow B: Fulfillment -- warehouse work surface

The Fulfillment page (`FulfillmentGroupIndex` plus its detail) becomes the operator surface. It
operates on FulfillmentGroups (Invariant 1) and must support:

- filter by warehouse / shipping method / print-waiting
- courier export (Yamato / Sagawa CSV) -- one parcel row per selected group (Invariant 2)
- tracking import -- import the returned tracking and apply it to the matched group
- one tracking number per group (entered once, written to all member rows + synced to all member
  sales orders); no per-order tracking input in v1
- show per-order `arranged_at` (when queued for shipping) and, once shipped, `shipped_at`
- mark shipped -- calls `ShipOutboundOrderService` (see Inventory ownership). Do not duplicate the
  ship logic here.
- picking / packing summary

Courier export granularity and `CourierExportService` input: today the service takes `salesOrderIds`
and emits one row per order. For v1 group shipping, change the Fulfillment entry point to operate on
group IDs: add a group-oriented method (e.g. `exportGroups(array $fulfillmentGroupIds, ...)`) that
emits one parcel row per group (recipient + aggregated package info), keyed by the group
`reference_no`. Keep the underlying CSV row builder shared. It still sets
`sales_orders.courier_csv_exported_at` for every member order of an exported group.

Tracking import matching: because export is keyed by group `reference_no`, the courier return CSV maps
back to the FulfillmentGroup (not per `platform_order_id`). On import, resolve the group, write the
returned tracking to every `fulfillment_group_orders` row in that group, and sync to each member
`sales_orders.tracking_no`. Update `TrackingImportService` matching accordingly.

Reuse, do not duplicate: extract the courier export and tracking import logic that currently lives on
`SalesOrderIndex` into shared services so the Fulfillment page calls the same code. Remove the Sales
Order page copies after the move (do not keep dead code to satisfy old tests; migrate the tests).

Route naming: when the tracking import controller/route moves to Fulfillment, rename it (e.g.
`fulfillment.tracking-import`). Do not keep stale `sales-orders.tracking-import` style names.

### Fulfillment list: columns and toolbar

One row = one FulfillmentGroup (one shipment). Columns, left to right:

| Column | Content | Editable | Notes |
|---|---|---|---|
| Select | checkbox; header selects all visible | - | drives batch toolbar |
| Reference | `FG-...` (bold). Below: member order id(s) (`platform_order_id`; single order shows one, multi shows each or first + count) | no | `FG-...` links to the detail page. Do NOT show the group `#id`. |
| Shop | tenant code + shop name (header label is just "Shop"). Multi-shop group shows tenant + shop count | no | shop derived from member orders |
| Recipient | recipient name + city / postal | no | from group |
| Orders / items | order count + total item qty | no | qty summed from member order lines |
| Shipping | shipping method name | yes -- dropdown of active shipping methods | stored on `fulfillment_groups.shipping_method_id` (see Data model 6), defaulted from member orders at group creation |
| Tracking | tracking number | yes -- text input | one per group (Invariant 2); on save writes every pivot row + syncs member sales orders |
| Note | note text | yes -- inline (same UX as the Sales Order page note) | stored on `fulfillment_groups.note`, initialized by copying the member sales order note at group creation |
| Added | `arranged_at` (top) and printed time = `courier_csv_exported_at` (below), mirroring the Sales Order page added/printed display | no | printed time shown here, NOT as a status pill |
| Status | reserved / shipped / cancelled badge only | no | no print-waiting pill (printed state lives in the Added column) |

No actions column. Detail is reached via the `FG-...` link; ship / export / import happen from the
batch toolbar.

Toolbar:

- Filters (top row): warehouse, shipping method, status, print-waiting toggle, search.
  (No standalone courier filter: a shipping method already implies its carrier, so it would be redundant.)
- Batch actions (shown when at least one row is selected): Export Yamato, Export Sagawa,
  Import tracking, Mark shipped.
- Default sort: `arranged_at` ascending (oldest queued first / FIFO).

Inline edits (Shipping dropdown, Tracking input, Note) follow the Sales Order page patterns
(`wire:model` drafts + debounced save), tenant/role scoped.

---

## Inventory ownership and ship service

### Extract a ship service (do NOT reuse the page component)

`OutboundOrderShip` is a full-page Livewire component that validates form input and redirects to the
outbound index. Fulfillment needs the ship LOGIC, not the page. Extract it.

Create `app/Services/Outbound/ShipOutboundOrderService.php` (or similar). It takes an `OutboundOrder`
plus ship inputs (courier, shipping method, one tracking number for the shipment, package
count/weight, note) and, inside a single DB transaction with `lockForUpdate`:

1. `InventoryService::shipReservedStock` for each leaf line (`ref_type = outbound_order`), as today.
2. update `outbound_orders` (status = shipped, shipped_at, shipped_by_user_id, courier, shipping
   method, tracking_no, package fields, note).
3. update `fulfillment_groups` (status = shipped, shipped_at, shipped_by_user_id) when the outbound
   belongs to a group.
4. update every `fulfillment_group_orders` row of that group (tracking_no, courier, shipped_at) with
   the one shipment tracking (Invariant 2).
5. sync `sales_orders.tracking_no` from the pivot, and set `fulfillment_status = shipped`,
   `order_status = completed`, `shipped_at` for each member sales order.

For a non-platform outbound order (no group), steps 3-4 are skipped; tracking lives on the outbound
order itself.

Both `OutboundOrderShip` (non-platform page) and the Fulfillment mark-shipped action call this one
service. After the move, `OutboundOrderShip::save()` becomes a thin wrapper around the service.

Migrate the existing `OutboundOrderShip` tests to exercise the service so behavior stays covered; do
not keep duplicated ship logic.

### Inventory timing (unchanged primitive)

- Reserve: at FulfillmentGroup creation (`reserveStock`, `ref_type = fulfillment_group`) -- as today.
- Ship: at Fulfillment mark-shipped, through the extracted ship service
  (`shipReservedStock`, `ref_type = outbound_order`) -- same movements as today, just triggered from
  the Fulfillment UI instead of the Outbound page.
- Sales Order never touches inventory. Remove the `bulkMarkShipped` inventory-free status flip from
  the Sales Order page.

---

## Function relocation summary

| Function | From | To |
|---|---|---|
| Courier CSV export (Yamato / Sagawa) | Sales Order | Fulfillment |
| Tracking import | Sales Order | Fulfillment |
| Tracking edit | Sales Order (per-order, editable) | Fulfillment (one per group, editable); Sales Order read-only mirror |
| Print-waiting filter | Sales Order | Fulfillment |
| Mark shipped (+ inventory) | Sales Order status flip / Outbound page | Fulfillment |
| Marketplace shipping notice export | Sales Order | Sales Order (stays) |
| Platform outbound orders in list | Outbound | Fulfillment |
| Manual / non-platform outbound | Outbound | Outbound (stays, filtered) |

---

## Phasing

Build and verify in order; each phase keeps the suite green.

- Phase 1 (data + service): pivot model upgrade (migration columns `tracking_no` / `courier` /
  `arranged_at` / `shipped_at`, fillable, casts, relations); tracking sync helper
  (pivot -> sales_orders); extract `ShipOutboundOrderService` from `OutboundOrderShip` and make the
  page a thin wrapper. Backfill not required for the new flow.
- Phase 2 (Sales Order trim): make Sales Order tracking read-only; remove courier export, tracking
  import, print-waiting, bulk mark shipped from Sales Order; keep marketplace notice export.
- Phase 3 (Fulfillment build) -- split internally, land and verify each before the next:
  - 3a: move courier export (per group, group-oriented `CourierExportService` entry) + tracking import
    (match by group `reference_no`) + `courier_csv_exported_at` update + print-waiting filter to the
    Fulfillment surface.
  - 3b: one-tracking-per-group edit + mark shipped via `ShipOutboundOrderService`.
  - 3c: picking / packing summary.
- Phase 4 (combine prompt + ship-complete): add `shops.consolidation_mode` + Setup UI; recompute
  `ship_together_key` to drop `shop_id`; enforce ship-complete in `bulkMarkReady` (all lines must be
  ready); extract `GroupSalesOrdersService` (create + join + single-order); wire Mark Ship Ready so
  every readied order creates or joins a group (Invariant 1); combine prompt auto-combines `ready`
  candidates / joins a joinable group only, gated by the shop consolidation setting; sets `arranged_at`
  via attach payload.
- Phase 5 (Outbound reposition): filter Outbound index to `fulfillment_group_id IS NULL`.
- Phase 6 (roles): add `users.role` (`admin` / `cs` / `warehouse`) + nav/route gating per the Roles
  and access matrix.

Sequencing note: until Phase 4 enforces auto-grouping, the existing `FulfillmentGroupCreate` page
remains the grouping entry (it already lists ready orders by `ship_together_key`), so ready orders are
never stranded between Phase 2 and Phase 4. Invariant 1 is only fully guaranteed once Phase 4 lands.

---

## Open decisions

All major decisions are now resolved:

1. Roles: DECIDED -- three internal roles (`admin` / `cs` / `warehouse`). See Roles and access.
2. Cross-shop combine: DECIDED -- controlled per shop by `shops.consolidation_mode`
   (`none` / `same_shop` / `cross_shop`); `ship_together_key` drops `shop_id`. See Data model 5.
3. One-parcel assumption (Invariant 2): DECIDED -- combine = one physical parcel / one label / one
   tracking. Per-order distinct tracking + multi-parcel deferred to v2.
4. Other resolved: every Ship Ready order joins/creates a group (Invariant 1); v1 ship-complete only
   (Partial Shipment Policy); `CourierExportService` gains a group-oriented entry; tracking import
   matches by group `reference_no`.

Still pending (naming, not blocking): whether the user-facing unit label "Fulfillment Group" should be
shown as "Shipment". Nav module stays "Fulfillment"; code identifiers stay `FulfillmentGroup`
regardless. Decide before translating the Fulfillment module.

---

## Tests

Add / update feature tests. Keep `php artisan test` green at each phase.

Phase 1 (data + service):

1. `FulfillmentGroupOrder` can read/write `tracking_no`, `courier`, `arranged_at`, `shipped_at`
   through Eloquent (fillable + casts work).
2. `ShipOutboundOrderService` ships an outbound order: deducts inventory via `shipReservedStock`,
   writes the ship movement, sets outbound + group + pivot + sales-order statuses, and syncs
   `sales_orders.tracking_no` from the pivot. (Migrated from the old `OutboundOrderShip` tests.)
3. `OutboundOrderShip` page still ships a non-platform outbound order (thin wrapper over the service).

Phase 2 (Sales Order trim):

4. `sales_orders.tracking_no` is no longer editable from the Sales Order page.
5. Sales Order page no longer exposes courier export, tracking import, print-waiting, bulk mark shipped.
6. Marketplace shipping notice export still works from Sales Order and reads the synced tracking.

Phase 3 (Fulfillment build):

7. (3a) Fulfillment courier export emits one parcel row per group, keyed by `reference_no`, and sets
   `sales_orders.courier_csv_exported_at` for every member order of the exported group.
8. (3a) Fulfillment print-waiting filter selects ready/in-group orders whose
   `courier_csv_exported_at` is null.
9. (3a) Tracking import matches by group `reference_no`, writes the returned tracking to every
   `fulfillment_group_orders` row in the group, and syncs to each member `sales_orders.tracking_no`.
10. (3b) A multi-order group's tracking is one value: setting it writes the same tracking to all member
    rows and member sales orders.
11. (3b) Fulfillment mark shipped calls `ShipOutboundOrderService`: deducts inventory, sets group +
    outbound + every member pivot (`shipped_at`) + sales-order statuses, and writes the ship movement.
12. (3b) Mark shipped is blocked for an already-shipped group.

Phase 4 (combine prompt + Invariant 1 + ship-complete):

12b. An order with a not-ready line cannot be marked Ship Ready (ship-complete gate).
12c. An order is only markable / groupable when all fulfillable lines are ready.
13. Marking Ship Ready with no candidate creates a single-order FulfillmentGroup (order ->
    `in_group`), reserves stock, and sets `arranged_at`.
14. Marking Ship Ready surfaces a combine prompt when a joinable group or same-recipient READY
    candidate exists.
15. Confirming combine joins the existing joinable group (delta reserved, outbound lines appended,
    pivot attached with `arranged_at`) or creates one group for the combined ready orders.
16. Same-address `unfulfilled` orders are NOT auto-grouped by the combine prompt.
17. Joining a group that is already courier-exported or shipped is refused.
18. No order is left `ready` without a group after the Mark Ship Ready action completes.
18b. `ship_together_key` no longer includes `shop_id` (two same-address orders from different shops
    share a key after recompute).
18c. Same-shop combine works when the shop is `same_shop`; a `none` shop always produces single-order
    groups.
18d. Cross-shop combine is offered only when BOTH shops are `cross_shop`; if either is not, the
    different-shop order is not auto-combined.

Phase 5 (Outbound reposition):

19. Outbound index shows only `fulfillment_group_id IS NULL` (manual) orders.
20. Group-derived outbound orders are hidden from Outbound but visible/linkable from Fulfillment detail.

Phase 6 (roles):

21. Internal `warehouse` user cannot reach Sales Order or Setup routes.
22. Internal `cs` user can reach Sales Order and Issues, sees Fulfillment read-only, cannot reach
    Setup, and cannot run a warehouse ship action.
23. Internal `admin` user retains full access (including Setup).
24. Tenant scoping is unchanged for all roles.

Run:

```bash
php artisan test
```

---

## Acceptance criteria

- Sales Order page manages orders only: import, edit, hold/backorder/cancel-request, mark ready,
  marketplace notice export. Tracking shown read-only. No courier export, tracking import,
  print-waiting, or inventory-affecting ship.
- v1 is ship-complete: an order can only be marked Ship Ready / grouped when ALL its fulfillable lines
  are ready. No partial shipment or backorder remainder in v1 (deferred to v2; schema is v2-ready).
- Every Ship Ready order creates or joins a FulfillmentGroup; the Fulfillment page only shows groups
  (Invariant 1). No persisted ready-without-group state.
- Fulfillment page is the warehouse main surface: courier export (one parcel row per group), tracking
  import (matched by group reference), one tracking per group, mark shipped (deducts inventory).
- One FulfillmentGroup = one shipment = one tracking number in v1 (Invariant 2); per-order distinct
  tracking is deferred to v2.
- Tracking source of truth is `fulfillment_group_orders.tracking_no` (one value per group);
  `sales_orders.tracking_no` is a synced read-only mirror.
- `FulfillmentGroupOrder` is a real writable model (tracking_no, courier, arranged_at, shipped_at)
  with relations on both sides; `arranged_at` is recorded when an order is queued for shipping and
  shown on the Fulfillment page.
- A single `ShipOutboundOrderService` performs every ship; both the Fulfillment mark-shipped action
  and the non-platform Outbound ship page call it. No duplicated ship logic.
- Print-waiting state is driven by `sales_orders.courier_csv_exported_at` (single source in v1).
- Cross-shop combine is governed by `shops.consolidation_mode` (`none` / `same_shop` / `cross_shop`);
  `ship_together_key` identifies destination only (no `shop_id`).
- Outbound page shows non-platform outbound only.
- Inventory reserve/ship behavior is unchanged (reserve at group create, ship at mark shipped).
- Three internal roles (`admin` / `cs` / `warehouse`) gate nav/routes per the access matrix; tenant
  scoping unchanged.
- Tests pass.
