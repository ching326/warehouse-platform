# Task: Inbound Cartons + Documents v1

## Stack

Laravel 13, Livewire 4 (class-based components, not Volt), Flux UI (`livewire/flux ^2.14`),
SQLite (dev), PHP 8.3. Plain Blade views only. No TypeScript.

ASCII punctuation only in code, comments, and this doc. No em-dashes, smart quotes, or unicode arrows.

---

## Goal

Improve inbound orders with:

1. A standardized reference number `IB-{tenant code}-{yymmdd}-{001}`.
2. Carton counts split into EXPECTED vs RECEIVED, because shipments often arrive in batches.
3. A carton mark (shipping mark printed on the cartons).
4. Shipping document uploads (invoice, packing list, B/L, etc.), stored privately, managed on the
   inbound detail page.

Build on existing infrastructure: the reference helpers (`OutboundOrder::buildRef`,
`ReturnOrder::buildReturnNo`), the polymorphic `media_assets` table, the v2 private-media streaming
route `GET /media/{asset}`, and the `HandlesPrivateMediaAssets` Livewire concern.

---

## What v1 Covers

Includes:

- `IB-` auto-generated reference (replaces the manual ref input on inbound create)
- `inbound_orders.expected_carton_count`, `inbound_orders.received_carton_count`,
  `inbound_orders.carton_mark`
- expected cartons + carton mark entered on inbound create; received cartons recorded on the receive page
- shipping documents via `media_assets` (`model_type = inbound_order`, `type = document`, private
  `local` disk), uploaded / listed / deleted on the inbound detail page, served via `GET /media/{asset}`
- display on the inbound index and detail
- tests

Does NOT include:

- per-batch receipt records (received_carton_count is a cumulative running total in v1)
- changes to the inventory receive/quantity logic
- public documents, OCR, or document preview rendering beyond inline serving
- a new documents table (reuse `media_assets`)

---

## 1. Reference number (IB-)

- Add `InboundOrder::buildRef(int $id, string $tenantCode, ?CarbonInterface $date = null): string`
  returning `IB-{TENANTCODE}-{yymmdd}-{NNN}` (sanitize tenant code to uppercase A-Z0-9, fallback
  `TENANT`; 3-digit zero-padded id, grows past 999). Mirror `OutboundOrder::buildRef`.
- `InboundOrderCreate`: auto-generate the ref (create with an `IB-PENDING-{uuid}` placeholder, then
  `update` with `buildRef($order->id, $order->tenant->code)`). Honor an explicitly provided `ref`
  (so existing tests that set one keep working); otherwise auto-generate. Remove the manual ref input
  from the create form.
- `InboundOrderIndex`: in the reference cell, drop the leading `#{id}`; show the clickable `ref` only.

(Inbound has no platform-derived path, so every inbound ref becomes `IB-...`.)

---

## 2. Carton counts + carton mark

Migration on `inbound_orders`:

- `expected_carton_count` unsignedInteger nullable -- declared total cartons at creation
- `received_carton_count` unsignedInteger nullable -- cumulative cartons received so far (batches add up)
- `carton_mark` text nullable -- the shipping mark (can be multi-line)

Add all three to `InboundOrder::$fillable`. Cast the two counts to integer.

UI:

- `InboundOrderCreate`: add an "Expected cartons" number input and a "Carton mark" textarea.
- `InboundOrderReceive`: add a "Received cartons" input that edits `received_carton_count` as the
  cumulative total received so far (staff updates it on each batch). Because deliveries are split,
  this is a running total, not a per-batch value. Do not change the existing line/quantity receive
  logic; just persist this count alongside it.
- `InboundOrderIndex` and `InboundOrderDetail`: show cartons as `received / expected`
  (e.g. `12 / 20`, or `-` when not set). Show the carton mark on the detail page.

Per-batch receipt history is out of scope for v1 (a future `inbound_receipts` table could track each
delivery).

---

## 3. Shipping documents (reuse media_assets)

Do NOT create a new table. Reuse the polymorphic `media_assets` table and the v2 private-media stack.

- `MediaAsset`: add `MODEL_TYPE_INBOUND_ORDER = 'inbound_order'`. The `type` column already accepts
  any string; use `type = 'document'` for these (no migration; no enum constraint).
- `InboundOrder::mediaAssets(): HasMany` -> `MediaAsset` (model_id = inbound order id, filtered by
  `model_type = inbound_order`, ordered by `sort_order` then `id`).
- `InboundOrderDetail`: use the existing `App\Livewire\Concerns\HandlesPrivateMediaAssets` concern to
  upload / delete documents. Documents go on the private `local` disk under
  `media/private/tenant-{id}/inbound-orders/{id}/...` and are served via the existing
  `GET /media/{asset}` route (the `MediaController` already authorizes by tenant).
  - Upload validation (in the component, as the concern does not validate): `required`, `file`,
    `mimes:pdf,jpg,jpeg,png,webp`, `max:10240` (10 MB; documents can be larger than photos).
  - `type` is fixed to `document` for this panel.
  - The concern fills `width`/`height` via `getimagesize()` (null for PDFs) and enforces the soft
    limit; v1 keeps the concern's existing per-parent limit.
- Display on the detail page: list each document with its `file_name`, a link to `$asset->url()`
  (the streaming route for private assets), and a delete action. Mobile upload uses a normal file
  input (camera not relevant for documents).

Tenant scope: load the inbound order through the existing inbound tenant-scoped query; set
`media_assets.tenant_id` from the order; never trust the request. internal users see all; tenant users
only their active tenant; no guest-as-internal.

---

## Tests (tests/Feature; use Storage::fake('local'); UploadedFile::fake())

Reference:

1. `InboundOrder::buildRef` formats `IB-{CODE}-{yymmdd}-{NNN}` (e.g. `IB-ACME-260623-007`).
2. Creating an inbound order without a ref auto-generates an `IB-...` reference.
3. The inbound index reference cell no longer shows `#{id}`.

Cartons:

4. Expected cartons + carton mark entered on create persist.
5. Received cartons recorded on the receive page persist on `received_carton_count`.

Documents:

6. Uploading a document stores it on the `local` (private) disk with `model_type = inbound_order`,
   `type = document`, and `tenant_id` from the order.
7. A PDF upload is accepted; a non-allowed file type and an oversized file are rejected.
8. A tenant user cannot upload a document to another tenant's inbound order.
9. Deleting a document removes the media row and the private file.
10. The `GET /media/{asset}` route serves an inbound document to an authorized user and denies another
    tenant (reuse / extend the v2 behavior).

Run:

```bash
php artisan test tests/Feature/InboundOrderTest.php
```

(then the full suite when wiring up).

---

## Acceptance Criteria

- inbound reference numbers are `IB-{tenant code}-{yymmdd}-{NNN}`, auto-generated; the index drops `#id`
- inbound orders track expected vs received carton counts and a carton mark
- expected cartons + carton mark are set on create; received cartons on the receive page
- shipping documents upload to the private disk via `media_assets` (`inbound_order` / `document`),
  managed on the detail page, served by the authenticated tenant-scoped media route
- tenant scoping enforced; no guest-as-internal; no new documents table
- tests pass

---

## Implementation Notes

Reuse `OutboundOrder::buildRef` as the pattern for `InboundOrder::buildRef`. Reuse the v2
`HandlesPrivateMediaAssets` concern and `MediaAsset` for documents. Do not store binary data in the
database. Do not change the inventory receive logic.
