# Task: Outbound Unification Phase 1 - OutboundOrder Schema and Model Scaffolding

Parent plan: docs/tasks/outbound-unification-v1.md (Phase 1). Phase 0 (rename in_group ->
arranged) is already done in commit 048f221. This task is Phase 1 only: add the new columns,
the sales-order pivot, model relations, and factory defaults. It is purely additive and inert -
NO feature wiring, NO behavior change, FulfillmentGroup stays exactly as is.

## Stack
- Laravel 13, Livewire 4, PHP 8.3, SQLite (dev).
- All data is dev dummy data; nullable columns + light backfill are fine (no production migration).
- ASCII punctuation only.

## Goal
Make OutboundOrder ready to become the single shipping entity in later phases by adding:
reason, ship_mode, source_sales_order_id, courier_csv_exported_at, shipping_method_id, and a
sales-order pivot. Nothing reads these yet.

## Migration 1: add columns to outbound_orders
Add (all nullable / safe defaults; place near the existing fulfillment_group_id column):
- reason            string, nullable. Allowed values (validated in app, not a DB enum):
  customer_order, re_ship, replacement, gift, fba, return_to_tenant, b2b, sample, other.
- ship_mode         string, not null, default 'parcel'. Allowed values: parcel, bulk.
- source_sales_order_id  foreignId nullable, constrained('sales_orders')->nullOnDelete().
- courier_csv_exported_at  timestamp nullable. (Additive only this phase; sales_orders keeps its
  own courier_csv_exported_at for now - do NOT touch it until Phase 2/4.)
- shipping_method_id foreignId nullable, constrained('shipping_methods')->nullOnDelete().
  (The legacy free-text shipping_method string column stays as a denormalized snapshot.)
Index: index(['tenant_id', 'reason']) optional for reporting later; ship_mode index optional.

Backfill in the same migration (dummy data, keep it simple and coherent):
- Rows with fulfillment_group_id NOT NULL -> reason = 'customer_order', ship_mode = 'parcel'.
- Rows with fulfillment_group_id NULL (manual outbounds) -> ship_mode = 'parcel', reason left null.
down(): drop the added columns / FKs.

## Migration 2: create outbound_order_sales_order pivot
- id
- foreignId outbound_order_id, constrained()->cascadeOnDelete()
- foreignId sales_order_id, constrained()->cascadeOnDelete()
- timestamp arranged_at, nullable
- unique(['outbound_order_id', 'sales_order_id'])
This will replace fulfillment_group_orders in later phases. Do NOT migrate or read it yet; just
create it. Leave fulfillment_group_orders untouched.

## Model: app/Models/OutboundOrder.php
- Add to $fillable: reason, ship_mode, source_sales_order_id, courier_csv_exported_at,
  shipping_method_id.
- Add to casts(): 'courier_csv_exported_at' => 'datetime'.
- Add constants:
  - SHIP_MODE_PARCEL = 'parcel', SHIP_MODE_BULK = 'bulk'.
  - REASON_CUSTOMER_ORDER = 'customer_order', REASON_RE_SHIP = 're_ship',
    REASON_REPLACEMENT = 'replacement', REASON_GIFT = 'gift', REASON_FBA = 'fba',
    REASON_RETURN_TO_TENANT = 'return_to_tenant', REASON_B2B = 'b2b', REASON_SAMPLE = 'sample',
    REASON_OTHER = 'other'.
- Add relations:
  - sourceSalesOrder(): belongsTo(SalesOrder::class, 'source_sales_order_id').
  - shippingMethod(): belongsTo(ShippingMethod::class).
  - salesOrders(): belongsToMany(SalesOrder::class, 'outbound_order_sales_order')
      ->withPivot('arranged_at'). (The consolidated sales orders a parcel covers; empty for a
      purely manual outbound.)
- Do NOT remove or change fulfillmentGroup(), lines(), parentLines(), leafLines().

## Factory: database/factories/OutboundOrderFactory.php
- Default ship_mode => OutboundOrder::SHIP_MODE_PARCEL.
- Default reason => null (or REASON_CUSTOMER_ORDER if the factory represents a fulfillment parcel;
  match whatever the existing factory implies). Keep existing fields unchanged.

## Tests (tests/Feature or tests/Unit)
- Migration adds the five columns and the pivot table (assert Schema::hasColumn /
  Schema::hasTable).
- OutboundOrder casts courier_csv_exported_at to a Carbon instance.
- sourceSalesOrder, shippingMethod, and salesOrders (pivot, with arranged_at) relations resolve.
- Backfill: an outbound order created with a fulfillment_group_id ends up reason=customer_order,
  ship_mode=parcel after migration; a manual one ends up ship_mode=parcel, reason null.
- Existing OutboundOrder / fulfillment / sales-order tests still pass unchanged.

## Do Not Do In This Task
- Do NOT wire consolidation, pack, courier export, tracking, or hold to the new columns (Phases 2-4).
- Do NOT move or stop writing sales_orders.courier_csv_exported_at yet (Phase 2/4).
- Do NOT touch fulfillment_groups / fulfillment_group_orders or drop anything.
- Do NOT change any UI, Livewire component behavior, or the Packing chip.
- Do NOT populate the outbound_order_sales_order pivot from existing data yet.

## Acceptance Criteria
- outbound_orders has reason, ship_mode (default parcel), source_sales_order_id,
  courier_csv_exported_at, shipping_method_id; outbound_order_sales_order pivot exists.
- OutboundOrder model exposes the new fillable, cast, constants, and three relations.
- Backfill sets sane reason/ship_mode on existing dummy rows.
- No behavior change anywhere; full test suite stays green.
