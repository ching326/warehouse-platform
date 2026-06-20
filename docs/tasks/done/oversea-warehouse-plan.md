# 海外倉 (Overseas Warehouse / 3PL) — System Design Plan

Status: **Draft for discussion**
Audience: owner + dev
Goal: build a warehouse-fulfillment system the owner operates **and** that
the owner's **customers** can eventually log into to see their own stock,
orders, and bills.

---

## 1. Why this is a new system, not a modification

### 1.1 Two different meanings of "tenant"

The existing `docs/multi_tenant_onboarding.md` already defines a tenancy model,
but it solves a *different* problem:

| | Existing model ("ERP tenant") | 海外倉 model ("warehouse customer") |
|---|---|---|
| What a "tenant" is | Another **seller business** licensing the whole ERP | A **client of the warehouse** storing goods with us |
| Isolation method | Separate **database + deployment folder** per tenant | Many customers **share one** warehouse DB/operation |
| Who logs in | That business's own staff | Our staff **and** the external customer |
| Data they see | Everything in their own DB | **Only their own** SKUs / inbound / stock / orders / bills |
| Count | A handful of deployments | 10–50 customers in **one** deployment, year one |

A warehouse's customers all share the same physical warehouse, the same shelves,
the same receiving dock. They **cannot** be split into separate databases the way
the current ERP tenants are — they must coexist in one database with strict
**row-level scoping by `customer_id`**. So the existing DB-per-tenant approach
does not carry over; this is genuinely new tenancy.

### 1.2 Why not bolt it onto the current app

The current app is an **internal-staff, single-org** tool:

- Auth is a shared-login model — one `AUTH_2FA_SHARED_SECRET` for all users
  (`includes/config.php`), account literally named `shared-login`. Fundamentally
  unsuited to external customers.
- **Nothing is scoped by customer.** `seller` (RA-ALD, YA-CSP…) is a marketplace
  label, not an account boundary. There is no `customer_id` on inventory, orders,
  or shipments — everyone who logs in sees everything.

Retrofitting per-customer isolation onto every query across ~10 modules, while
keeping the shared-login model, is high-risk (one missed `WHERE customer_id`
leaks one customer's data to another). So we build the 海外倉 as a **new app**.

### 1.3 Why not greenfield everything

The current codebase already contains proven 海外倉 building blocks worth porting:

- **`inventory/`** — real WMS primitives: `inventory_in`, `inventory_out`,
  `inventory_location`, `inventory_stock_take`, `inventory_record`.
- **`oversea_shipping/`** — a working cross-border **rate engine**:
  `oversea_shipping` + `oversea_shipping_tier` (price per kg / per CBM, customs &
  declaration rates, DG fees, min weight/qty/CBM per box/ship, label fees).
- **`item` / `sku` / `supplier` / `product_type`** — catalog schema.

We reuse these as the source to port from, then retire the old modules as the new
app absorbs them. We do **not** run two systems forever.

---

## 2. Decisions locked

| Decision | Choice | Rationale |
|---|---|---|
| Customer access | **Phased**: staff-operated first, customer login later | Lower risk; ship value sooner |
| Tenancy in schema | **From day one** | Retrofitting `customer_id` at 30 customers is the failure mode to avoid |
| Isolation | **Shared DB + `customer_id` row scoping** | At 10–50 customers, DB-per-customer is needless ops overhead |
| Stack | **Recommend Laravel (PHP)**; raw PHP is the fallback | Built-in auth, query-scoping, billing (Cashier), queues; reuses team's PHP + existing schema |

> Stack note: "Open to anything" was the answer. Laravel wins on ROI because it
> eliminates exactly the scaffolding this project needs (auth, policies,
> per-tenant scoping, billing, API) while letting us port the existing MySQL
> schema and rate logic almost 1:1. Staying on raw PHP is viable but means
> hand-rolling all of that. A non-PHP stack throws away the schema/domain reuse.

---

## 3. Architecture

```
        ┌─ Internal back-office (Phase 1) ─┐   ┌─ Customer portal (Phase 2) ─┐
 staff →│  receiving 入庫, putaway,         │   │  customer login:             │← customer
        │  picking, packing, outbound 出庫,  │   │  own SKUs, inbound status,   │
        │  acting on behalf of customer X    │   │  stock, outbound orders,     │
        │                                    │   │  invoices/billing            │
        └─────────────────┬──────────────────┘   └──────────────┬─────────────┘
                          └──────── one app, one shared DB ───────┘
                       every business table scoped by customer_id
```

- **Roles:** `admin` (owner), `staff` (warehouse ops), `customer` (external).
  Staff/admin can act across all customers; a customer is hard-scoped to their
  own `customer_id` by a global query scope (Laravel) or a single enforced
  helper (raw PHP).
- **Phase 1 has no customer login** — but the schema is already multi-customer,
  so staff always select/operate "on behalf of customer X." Phase 2 just opens a
  login that applies the scope automatically.

---

## 4. Core data model (all business tables carry `customer_id`)

Names mirror existing tables so porting is mechanical. New/renamed tables noted.

### 4.1 Identity & tenancy
- `customers` — the warehouse's clients. `id`, `code`, `name`, contact,
  `billing_terms`, `status`, timestamps.
- `users` — `id`, `name`, `password`, `role` (`admin`|`staff`|`customer`),
  `customer_id` NULL for staff/admin. Replaces shared-login with per-user
  credentials + per-user 2FA (the current shared-secret model is dropped).

### 4.2 Catalog (port from `item` / `sku`)
- `products` — per customer. `customer_id`, `item_code`, `name`, `barcode`,
  attributes, `product_type_id`. (Port `item`/`item_details`.)
- `skus` — variants/sellable units. `customer_id`, `product_id`, `sku`,
  dimensions, weight. (Port `sku`.)

### 4.3 Warehouse & inventory (port from `inventory/`)
- `warehouses` — physical warehouses (e.g. JP, CN, US). Shared across customers.
- `locations` — shelves/bins within a warehouse. (Port `inventory_location`.)
- `inventory` — on-hand by `customer_id × sku_id × location_id`: `qty_on_hand`,
  `qty_reserved`, `qty_inbound`.
- `inventory_movements` — append-only ledger; the source of truth.
  `customer_id`, `sku_id`, `location_id`, `type`
  (`inbound`|`outbound`|`adjustment`|`stock_take`|`transfer`), `qty_delta`,
  `ref_type`, `ref_id`, `user_id`, `created_at`.
  (Port the logic behind `inventory_in` / `inventory_out` / `inventory_stock_take`.)

### 4.4 Inbound 入庫
- `inbound_shipments` — `customer_id`, `reference`, `carrier`, `tracking`,
  `expected_at`, `received_at`, `status`
  (`announced`|`in_transit`|`receiving`|`received`|`cancelled`).
- `inbound_items` — `inbound_shipment_id`, `sku_id`, `qty_expected`,
  `qty_received`. Receiving writes `inventory_movements` rows.

### 4.5 Outbound 出庫 / fulfillment
- `outbound_orders` — `customer_id`, `reference`, recipient address, `carrier`,
  `shipping_method_id`, `status`
  (`pending`|`allocated`|`picking`|`packed`|`shipped`|`cancelled`), `tracking`.
- `outbound_items` — `outbound_order_id`, `sku_id`, `qty`. Allocation reserves
  stock; shipping writes outbound `inventory_movements`.

### 4.6 Shipping rates (port from `oversea_shipping`)
- `shipping_methods` — port `oversea_shipping`: carrier, method, currency,
  duration, customs/declaration rates, DG/label/customs fees, min weight/qty/CBM.
  Add `customer_id` NULL = available to all; non-NULL = customer-specific rate.
- `shipping_tiers` — port `oversea_shipping_tier`: `price_per_kg`, `price_per_cbm`
  by weight/volume band. Drives both quoting and billing.

### 4.7 Billing — **the genuinely new part** (no equivalent today)
- `billing_rate_cards` — per customer (or default): storage fee
  (per CBM/pallet/SKU per period), inbound handling fee, outbound handling/pick
  fee, plus link to `shipping_methods` for freight.
- `billing_records` — generated per customer per period: line items
  (storage / inbound handling / outbound handling / freight), amounts, currency,
  status (`draft`|`issued`|`paid`). Built by a scheduled job from
  `inventory_movements` (handling), period-end on-hand snapshots (storage), and
  `outbound_orders` (freight via `shipping_tiers`).

---

## 5. Phasing

### Phase 1 — Internal MVP (staff-operated, multi-customer schema)
1. `customers`, `users` (roles), `warehouses`, `locations`.
2. `products`, `skus` (ported catalog).
3. `inventory` + `inventory_movements` ledger.
4. Inbound 入庫 (announce → receive → stock updated).
5. Outbound 出庫 (create → allocate → pick/pack → ship → stock updated).
6. Staff "operate as customer X" selector.

Deliverable: owner runs the whole warehouse for all customers from one app.

### Phase 2 — Customer portal (read-first, then self-service)
1. Customer login (per-user creds + 2FA), global `customer_id` scope.
2. Read-only views: my stock, my inbound status, my outbound/order tracking.
3. Self-service: customer creates outbound orders / inbound announcements.

### Phase 3 — Billing & statements
1. `billing_rate_cards` + `shipping_methods`/`shipping_tiers` port.
2. Scheduled billing job → `billing_records`.
3. Customer-facing invoices, statements, exports, notifications.

---

## 6. Open questions to resolve before Phase 1 build

1. **Storage billing basis** — per CBM, per pallet, per SKU/location, or per
   item-day? Drives the `billing_rate_cards` + period-snapshot design.
2. **Multi-warehouse at launch?** One country first (e.g. JP), or JP+CN day one?
3. **Barcode/scanner workflow** for receiving & picking — needed in Phase 1 or
   later? Affects the receiving/picking UI.
4. **Final stack call** — Laravel vs stay-on-PHP. Recommendation: Laravel.

---

## 7. What we explicitly reuse vs. rebuild

| Asset | Action |
|---|---|
| `oversea_shipping` + `oversea_shipping_tier` | **Port** to `shipping_methods` / `shipping_tiers` (+ `customer_id`) |
| `inventory_in/out/location/stock_take/record` | **Port logic** into `inventory` + `inventory_movements` |
| `item` / `sku` / `product_type` / `supplier` | **Port** schema (+ `customer_id`) |
| Shared-login + `AUTH_2FA_SHARED_SECRET` | **Drop** — replace with per-user auth + 2FA |
| Marketplace integrations (Amazon/Rakuten/Yahoo/Mercari) | **Out of scope** for 海外倉; stays in the old internal app |
| Billing | **New build** — no current equivalent |
