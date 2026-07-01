# Warehouse Billing v1

## Goal

Let internal staff generate a monthly bill per tenant for warehouse services, using a
per-tenant rate card. Fees follow the standard 3PL model: storage, handling (in/out),
QC, return shipping, and postage. Every invoice line traces back to the concrete source
events (by id) that produced it, so a tenant (and we) can reconcile the charge.

This is a large feature; it is phased so the prerequisite (courier cost capture) lands
first and the rest builds on a stable base.

## Background: what already exists (reusable)

- `inventory_movements` is an append-only ledger with `on_hand_after` (+ per-type
  deltas) and `created_at` per `(tenant_id, warehouse_id, stock_item_id)`. This lets us
  reconstruct on-hand at any timestamp -> derive **average daily on-hand** for storage.
- `stock_items` has `length_value`, `width_value`, `height_value`, `dimension_unit`
  (+ `weight_value`) -> unit volume (m3) for volumetric storage.
- `inbound_receipts` (`received_qty`, `received_at`, tenant/warehouse/stock_item,
  `inventory_movement_id`) -> the real **per-event** receiving source. NOTE:
  `inbound_order_lines.received_qty` is a cumulative running total and must NOT be used
  for billing (it would re-charge old receipts).
- `outbound_orders` (shipped) + `outbound_order_lines.qty` (leaf lines) -> outbound
  handling. Reship is a `re_ship` outbound in `fulfillableReasons()`, billed as outbound
  handling (no separate fee type).
- `return_order_costs` (`cost_type`, `amount`, `currency`) -> return shipping we front.
  Real constants: `COST_FREIGHT_COLLECT`, `COST_RESEND_SHIPPING`, `COST_INSPECTION`,
  `COST_RESTOCKING`, `COST_DISPOSAL`, `COST_OTHER`.
- Return QC exists via `ReturnOrderInspect` (return status `inspected`).

## Confirmed fee model (industry-standard defaults)

| Fee type | Unit | Cadence | Source |
| --- | --- | --- | --- |
| `storage` | `per_m3_month` (per-tenant override to `per_unit_month`) | per-period, monthly | `inventory_movements.on_hand_after` + `stock_items` volume |
| `handling_inbound` | per unit received | per-event | `inbound_receipts` (by `received_at`) |
| `handling_outbound_order` | per outbound order | per-event | `outbound_orders` shipped in period |
| `handling_outbound_unit` | per unit shipped | per-event | `outbound_order_lines.qty` (leaf) |
| `qc` | per unit inspected | per-event | `return_order_lines` (by `inspected_at`) |
| `return_shipping` | cost + markup | per-event | `return_order_costs` (`freight_collect`, by `cost_incurred_at`) |
| `postage` | cost + markup | per-event | `outbound_orders.courier_cost` |

Two rate shapes:
- **flat**: `amount = rate * quantity` (storage, handling, qc).
- **cost + markup**: `amount = cost_base * (1 + markup_pct)` (postage, return_shipping) -
  requires a captured cost.

Two cadences:
- **per-event**: sum events whose operational date falls in the period.
- **per-period**: storage, computed from average daily on-hand over the month.

`value_added_label` is **deferred** (see Non-Goals): there is no persistent label-print
event to bill against today.

## Decisions

- **Custom per-tenant, effective-dated rate card.** Rates carry `effective_from` /
  `effective_to`. Rate is resolved by the **date of the chargeable moment**: per-event
  fees use each event's operational date; storage uses each **day** of the month. If
  more than one rate applies to a fee type within a period, the run emits **one invoice
  line per rate window**, so every line has a single unambiguous rate (and a single
  source set).
- **Everything derived from events; auditable by id.** Each `invoice_line` links to its
  concrete source rows via `invoice_line_sources`. Nothing is billed from a text summary.
- **Costs are snapshotted at generation.** Cost+markup lines store `cost_base`, so a
  finalized invoice is frozen even if the underlying outbound/return cost is later edited.
- **No FX.** If a source cost's currency differs from the invoice currency, the run
  **fails loudly** for that tenant/period (no silent mixing).
- **No rates means no invoice.** If a tenant has no applicable rate rows at all for the
  period, generation aborts with a clear "no rates configured" error. This avoids an
  invoice with an undefined currency.
- **Idempotent + concurrency-safe.** One invoice per (tenant, period). Regenerating a
  DRAFT rebuilds its lines under a row lock; a FINALIZED invoice is never overwritten.
- **Internal only.**

## Explicit Non-Goals (v1)

- `value_added_label` billing. Prerequisite: a `label_print_events` table (tenant,
  sku/stock_item, qty, label_type, printed_at, printed_by). Add the fee type when that
  event source exists.
- Tax / VAT.
- Multi-currency conversion (store currency; do not convert; fail on mismatch).
- Automated courier-cost import from carrier files (v1 cost is staff-entered at ship).
- Payment / AR tracking (`tenants.billing_terms` stays free-text).
- Storage proration finer than per-day average.
- Tenant-facing billing UI, credit-note UI, kitting fee type, per-hour VAS.

## Phasing

1. **Phase 1 - Courier cost capture** (prerequisite, independently useful for cost
   reporting).
2. **Phase 2 - Rate card** (`fee_rates` + internal CRUD).
3. **Phase 3 - Billing run + invoices** (aggregation, invoice model, review, export).

Build after reship settles; reship feeds billing as normal outbound handling/postage.
Label billing remains deferred until `label_print_events` exists.

## 1. Phase 1: Courier cost capture

Migration - add to `outbound_orders`:

```php
$table->decimal('courier_cost', 12, 2)->nullable()->after('package_weight_g');
$table->string('courier_cost_currency', 3)->nullable()->after('courier_cost');
$table->foreignId('courier_cost_updated_by_user_id')->nullable()->after('courier_cost_currency')->constrained('users')->nullOnDelete();
$table->timestamp('courier_cost_updated_at')->nullable()->after('courier_cost_updated_by_user_id');
```

Migration - add to `return_order_costs` (operational date for return-cost billing):

```php
$table->timestamp('cost_incurred_at')->nullable()->after('amount'); // defaults to now / receive date
```

Why: a `freight_collect` cost entered late must bill in the month it was **incurred**
(the return leg), not the month staff typed it. Default `cost_incurred_at` to the return
order's `received_at` (or `now()` if unknown) on create; the billing run bills by it.
**Backfill existing rows** in the migration:
`cost_incurred_at = return_orders.received_at ?? return_order_costs.created_at`.

- Add `courier_cost*` to `OutboundOrder::$fillable`; add `cost_incurred_at` to
  `ReturnOrderCost::$fillable` (default it on create).
- `ShipOutboundOrderService::ship()` accepts optional `courier_cost` (+ currency) and
  persists it; stamp `courier_cost_updated_by/at` on any change.
- **Currency is required when a cost is present:** if `courier_cost` is set,
  `courier_cost_currency` must be non-null (app-level validation). Same rule already
  holds for `return_order_costs.currency`.
- **Cover every ship path.** All shipping funnels through `ShipOutboundOrderService::ship()`,
  but it is invoked from three callers - `FulfillmentIndex` (bulk ship), `FulfillmentPack`
  (`markShipped`), and `OutboundOrderShip`. Each must pass `courier_cost` (or explicitly
  leave it null). No code path may set an outbound to `shipped` outside this service; an
  order shipped with null `courier_cost` is billed as **no postage + a warning**, never 0.
- Editable on the outbound detail (staff); every edit updates the audit stamp (or logs an
  activity event). Applies to `re_ship` outbounds too (reship postage is billable).
- The audit stamp is for traceability; billed postage is frozen via the invoice line's
  `cost_base` snapshot, so later edits do not change a finalized invoice.

## 2. Phase 2: Rate card (`fee_rates`)

```php
$table->id();
$table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
$table->string('fee_type');
$table->string('unit');
$table->decimal('rate', 12, 4)->default(0);        // flat fee types
$table->decimal('markup_pct', 8, 4)->nullable();   // cost+markup fee types
$table->string('currency', 3)->default('JPY');
$table->date('effective_from');
$table->date('effective_to')->nullable();
$table->timestamps();
$table->index(['tenant_id', 'fee_type', 'effective_from']);
```

- `fee_type`: `storage`, `handling_inbound`, `handling_outbound_order`,
  `handling_outbound_unit`, `qc`, `return_shipping`, `postage`.
- **Allowed units per fee type (validate on save):**
  - `storage` -> `per_m3_month` or `per_unit_month`
  - `handling_inbound` / `handling_outbound_unit` / `qc` -> `per_unit`
  - `handling_outbound_order` -> `per_order`
  - `return_shipping` / `postage` -> `percent` (uses `markup_pct`, not `rate`)
  - A non-storage fee type must reject storage units, and vice versa.
- **No overlapping effective windows** for the same `(tenant_id, fee_type)` - validate on
  save (`fee_rates` has no status; overlap is purely by `effective_from`/`effective_to`).
  Rate resolution for a date picks the covering row.
- Missing rate for a fee type => that fee is **not charged** and a `no_rate` entry is
  recorded in `invoices.warnings` (never silently billed at 0).
- **One currency per tenant/period:** all rate rows applicable to a tenant for the period
  must share one currency; if they differ, generation aborts. That shared currency is the
  invoice currency.
- If there are **no applicable rate rows at all** for the tenant/period, generation
  aborts before creating/regenerating an invoice because the invoice currency cannot be
  determined.
- Internal setup CRUD (`FeeRateIndex`/create/edit), tenant-scoped.

## 3. Phase 3: Billing run + invoices

```php
// invoices
$table->id();
$table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
$table->string('period');                     // 'YYYY-MM'
$table->string('status')->default('draft');   // draft | finalized | void
$table->string('currency', 3);
$table->decimal('total', 14, 2)->default(0);
$table->timestamp('finalized_at')->nullable();
$table->foreignId('generated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
$table->json('warnings')->nullable();     // [{code, message, count}] e.g. no_rate, missing_courier_cost
$table->timestamps();
$table->unique(['tenant_id', 'period']);

// invoice_lines
$table->id();
$table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
$table->string('fee_type');
$table->string('unit');
$table->decimal('quantity', 14, 4);
$table->decimal('rate', 12, 4)->nullable();
$table->decimal('markup_pct', 8, 4)->nullable();
$table->decimal('cost_base', 14, 2)->nullable();   // snapshot for cost+markup lines
$table->date('rate_from')->nullable();             // rate window this line covers
$table->date('rate_to')->nullable();
$table->decimal('amount', 14, 2);
$table->string('source_summary')->nullable();      // display only
$table->timestamps();

// invoice_line_sources  (auditable, clickable trace)
$table->id();
$table->foreignId('invoice_line_id')->constrained()->cascadeOnDelete();
$table->string('source_type');   // outbound_order | inbound_receipt | return_order_line | return_order_cost | stock_item
$table->unsignedBigInteger('source_id');
$table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete(); // storage: which warehouse
$table->date('source_date')->nullable();           // storage: the day this basis is for
$table->decimal('quantity', 14, 4)->nullable();    // units / m3-month fraction from this source
$table->decimal('amount_basis', 14, 2)->nullable();// cost contributed (cost+markup lines)
$table->index(['source_type', 'source_id']);
```

### Service: `app/Services/Billing/BillingRunService.php`

```php
public function generate(Tenant $tenant, string $period): Invoice; // 'YYYY-MM'
```

Behavior (single transaction after the rate/currency preflight, `lockForUpdate` the
invoice row):

1. Resolve the period window `[start, endExclusive]` in the **warehouse timezone**
   (`warehouses.timezone`). Each billable event is bucketed into the period by **its own
   warehouse's** month window, so events at warehouses in different timezones each land
   in the correct month. Storage is already per `(warehouse, stock_item)`, so it uses
   that warehouse's timezone; per-event fees use the event's warehouse
   (`inbound_receipts.warehouse_id`, `outbound_orders.warehouse_id`); return costs use the
   return's receiving warehouse. A tenant that spans multiple warehouses can therefore
   have events grouped under more than one timezone boundary in the same run.
2. Load the tenant's applicable rate rows for the period **before creating the invoice**.
   If there are no applicable rate rows, abort with "no rates configured" and create no
   invoice. If applicable rate rows use more than one currency, abort. The surviving
   shared rate-card currency is the invoice currency.
3. Load-or-create the invoice for (tenant, period), passing the resolved currency.
   `lockForUpdate` only locks an existing row, so **create-or-load must handle the
   `unique(tenant, period)` race**: on a duplicate-key error, reload the row
   `lockForUpdate` and proceed (two concurrent Generate clicks then serialize instead
   of both building lines). If FINALIZED -> abort. If DRAFT -> delete its lines +
   line-sources and rebuild.
4. **Source-cost currency guard:** only check source costs that are actually billed in
   this run - outbound `courier_cost_currency` for shipped-in-window orders with a
   non-null courier cost, and `return_order_costs.currency` for billable
   `COST_FREIGHT_COLLECT` rows in the window. If any billed source cost differs from
   the invoice currency, throw a billing error naming the offending records. No
   conversion. Do not let unrelated/non-billed return cost rows abort the run.
5. Build lines per fee type (below), one line per **rate window** where a rate change
   splits the period. Attach `invoice_line_sources` rows. Round each line half-up to the
   currency minor unit.
6. `total` = sum of rounded line amounts. Save invoice (draft) + lines + sources.

**Per-fee computation:**

- **storage** (per-period, per-day rate resolution):
  - Item set = every `(warehouse, stock_item)` that has `on_hand > 0` at period start
    **OR** any movement inside the window. Seed opening on-hand from the **last
    `inventory_movements` row before `start`** (its `on_hand_after`), then apply in-window
    movements day by day, carrying the balance across movement-free days.
  - Volume conversion is explicit: convert dimensions to meters based on
    `stock_items.dimension_unit` (`mm`, `cm`, or `m`), then
    `unit_volume_m3 = length_m * width_m * height_m`. Do not multiply raw stored numbers.
    If the storage unit is `per_m3_month` and any on-hand stock item is missing one or
    more dimensions, treat its volume as 0 **and** add an `invoices.warnings`
    `missing_dimensions` entry listing those stock item ids. Tenants using
    `per_unit_month` are unaffected.
  - For each day compute `m3_on_day = on_hand * unit_volume_m3` (or `on_hand` if the unit
    is `per_unit_month`). The line **quantity is m3-months**, to match the
    `per_m3_month` rate: `quantity = sum(m3_on_day) / days_in_month` (= the average daily
    on-hand over the month). `amount = quantity * per_m3_month rate`. Do **not** bill
    m3-days against a monthly rate - that overcharges by ~`days_in_month`. Aggregate per
    rate window into one line per window. A partial rate window is prorated against the
    **full month** denominator, not the number of days in that window:
    `window quantity = sum(m3_on_day for days in window) / days_in_month`.
    Source rows: source_type `stock_item`, `source_id` = stock_item id, plus
    `warehouse_id` and `source_date` (one row per stock_item x warehouse x day, or per
    stock_item x warehouse with the window, carrying enough normalized m3-month detail
    to reconcile the quantity). For daily storage source rows, store
    `quantity = m3_on_day / days_in_month` so source quantities sum directly to the
    invoice line's m3-month quantity.
- **handling_inbound**: `inbound_receipts` with `received_at` in window; quantity = sum
  `received_qty`; rate per receipt's date; sources = receipt ids.
- **handling_outbound_order**: outbound orders shipped in window
  (`shipped_at`, reason in `fulfillableReasons()`); quantity = outbound shipment count,
  not sales-order count. A consolidated outbound linked to multiple sales orders is still
  one outbound shipment for this base fee. Rate per order's ship date; sources = outbound
  order ids.
- **handling_outbound_unit**: leaf `outbound_order_lines.qty` for those orders; sources =
  outbound order ids (qty per source). "Leaf" means physical component lines: a virtual
  bundle bills the component units actually picked/packed, not just the parent ordered
  SKU quantity.
- **qc**: `return_order_lines` with `inspected_at` in window; quantity = sum
  `received_qty`; rate per line's `inspected_at`; source_type `return_order_line`,
  source ids = line ids. There is no separate `inspected_qty`; v1 assumes a line's full
  `received_qty` is inspected when `inspected_at` is stamped.
- **return_shipping** (cost + markup): `return_order_costs` with
  `cost_type = COST_FREIGHT_COLLECT` whose `cost_incurred_at` is in window; `cost_base`
  = sum amount; `amount = cost_base * (1 + markup_pct)`; sources = return_order_cost ids.
  **Do NOT include `COST_RESEND_SHIPPING`** - resend shipping is a reship's postage, which
  is already billed via the `re_ship` outbound's `courier_cost` under `postage`. Billing
  both would double-charge. (Decision: `freight_collect` only in v1.)
- **postage** (cost + markup): `outbound_orders.courier_cost` for orders shipped in
  window; `cost_base` = sum; `amount = cost_base * (1 + markup_pct)`; sources = outbound
  order ids. Orders shipped with null `courier_cost` are **not** billed and are recorded
  in `invoices.warnings` (`missing_courier_cost`, with the order ids), never billed as 0.

Confirm exact operational timestamps during build (`outbound_orders.shipped_at`,
`inbound_receipts.received_at`, return inspected timestamp) - always the operational
date, never `created_at`, and interpreted in the event's **warehouse timezone**, so the
charge lands in the correct month.

### Livewire: billing screen

- `BillingRunIndex` (internal): pick tenant + period; Generate/Regenerate draft; review
  lines (each expandable to its sources); Finalize; Export CSV (PDF later).
- Tenant-scoped via `allowedTenantIds()`; internal users only.

## 4. Currency, rounding, tenant scope

- One tenant per run; every query filters `tenant_id`.
- Invoice currency = rate-card currency; billed source costs in another currency abort
  the run.
- Round each line half-up to the currency minor unit; `total` = sum of rounded lines.

## 5. Tests

Unit (`tests/Unit/`):
- Storage averager: a small seeded ledger with an opening balance and mid-month
  movements yields the expected **m3-months** (`sum(m3_on_day) / days_in_month`), NOT
  m3-days, **including an item with on-hand but no in-window movement** (seeded from the
  prior movement).
- Storage volume conversion + warnings: `mm`/`cm`/`m` dimensions convert to m3 correctly;
  stock items missing dimensions under `per_m3_month` create a `missing_dimensions`
  warning and are not silently invisible.
- Rate resolution: covering-window pick; no overlap allowed; mid-month change splits into
  two windows, with each window quantity divided by `days_in_month`, not by window days.

Feature (`tests/Feature/BillingRunTest.php`):
1. handling_inbound bills from `inbound_receipts` per event; a cumulative
   `inbound_order_lines.received_qty` change does NOT re-bill prior receipts.
2. handling_outbound_order/unit: counts + amounts; a `re_ship` outbound is counted.
   Consolidated outbound counts as one outbound-order fee; virtual bundle unit handling
   counts leaf physical component units.
3. qc: bills from `return_order_lines` by `inspected_at`; quantity = sum `received_qty`;
   sources = line ids.
4. postage + return_shipping: `amount = cost_base * (1 + markup_pct)`; `cost_base`
   snapshotted; `COST_RESEND_SHIPPING` is NOT billed as return_shipping; a `freight_collect`
   cost entered late bills in its `cost_incurred_at` month, not the entry month.
5. Mid-month rate change emits one line per rate window with correct per-window amounts.
6. Period boundary: last day in, first of next month out, by operational timestamp
   interpreted in the event's **warehouse timezone** (an event near midnight in a
   non-UTC warehouse lands in the month its warehouse-local date falls in).
7. `invoice_line_sources`: per-event rows reference exact source ids and sum to line
   qty/cost_base; storage rows carry `warehouse_id` + `source_date` and reconcile to the
   m3-month invoice quantity.
8. Currency: rate rows disagreeing on currency abort the run; a source cost
   (courier_cost / return cost) in another currency aborts with the offending ids.
8a. Warnings: a fee type with no rate records `no_rate`; an order shipped with null
    `courier_cost` records `missing_courier_cost` (with order ids); stock items missing
    dimensions under `per_m3_month` record `missing_dimensions` - none are silently billed
    as 0.
8b. Tenant with no applicable rates at all aborts with "no rates configured" instead of
    creating an invoice with undefined currency.
9. Idempotent re-run rebuilds DRAFT lines; FINALIZED is not overwritten; a later edit to
   an outbound `courier_cost` does not change a finalized invoice.
10. `invoices.total` = sum of rounded line amounts.
11. Tenant isolation across events and rates.
12. Phase 1: `courier_cost` persists from the ship form with audit stamp; run reads it.

## 6. Verification

```
php artisan migrate
vendor/bin/pint app database/migrations tests config
php vendor/bin/phpstan analyse --memory-limit=512M
php artisan test
```

PHPStan must stay at zero new errors against the baseline.

## Notes / future

- **Courier cost import**: reconcile staff-entered `courier_cost` against carrier files.
- **Storage snapshots**: if per-day ledger walking is too heavy at scale, add a nightly
  `stock_snapshots` (tenant/warehouse/day on-hand + volume) so storage is a simple sum.
  Start ledger-derived; add only on measured need.
- **`value_added_label`**: add the fee type once `label_print_events` exists.
- **Tax, credit notes, tenant self-serve, payment tracking, kitting, per-hour VAS**:
  out of v1; the model (`status`, manual adjustment lines, `invoice_line_sources`) leaves
  room to grow.
- Lang: add `en` keys only for the billing UI; defer CJK and log a row in
  `docs/translation-backlog.md`.
