You are working in an existing Laravel 13 + Livewire 4 (class-based, NOT Volt) + Flux UI 2.14
warehouse system (PHP 8.3, SQLite dev). Plain Blade only.

TASK: Implement docs/tasks/product-images-v3-amazon-import.md (on-demand Amazon product image import).
That spec is the source of truth; follow it. This prompt adds repo guardrails.

RULES
- ASCII punctuation only. Match surrounding style. Enforce tenant scoping. Do NOT break the green suite.
- Build on Product Images v1 (stock_item photos, public disk) and v2 (private media); do NOT change
  their behavior. Reuse the v1 MediaAsset model, storage path, validation, and primary helpers.

STEP 0 - READ FIRST (verify real names/signatures; do not trust this summary)
- docs/tasks/product-images-v3-amazon-import.md   (the spec)
- app/Models/MediaAsset.php, app/Models/StockItem.php  (v1 model + mediaAssets()/primaryImage())
- app/Livewire/SkusIndex.php + its blade           (v1 upload/primary/scoped-query pattern; add the action)
- app/Services/Amazon/AmazonSpapiOrdersClient.php  (mirror its HTTP/token/region/error style)
- app/Services/Amazon/AmazonSpapiTokenService.php, app/Support/AmazonSpapiRegion.php,
  app/Models/AmazonSpapiConnection.php, app/Services/Amazon/AmazonSpapiApiException.php
- app/Models/Shop.php, app/Models/Sku.php          (how an Amazon shop is identified; ASIN =
  skus.platform_product_id; how a shop links to its AmazonSpapiConnection + marketplace id)

BUILD (per spec)
1. New SP-API Catalog client App\Services\Amazon\AmazonSpapiCatalogClient with e.g.
   getMainImageUrl(AmazonSpapiConnection $connection, string $asin, string $marketplaceId): ?string.
   Reuse AmazonSpapiTokenService + AmazonSpapiRegion. Use the NORMAL LWA access token, NOT the
   Restricted Data Token (catalog images are not PII). Use Laravel Http; throw a typed exception on API
   error. Must be fakeable via Http::fake().
2. On-demand action on SkusIndex ("Fetch Amazon image") for an Amazon-shop SKU with an ASIN and a
   stock_item_id (hidden/rejected for virtual bundles): load SKU+stock item via the v1 tenant-scoped
   query -> resolve ASIN + shop connection + marketplace id -> get main image URL -> download bytes
   (HTTPS, timeout) -> reject if not an image or > 5 MB -> store on public disk at
   product-images/tenant-{id}/stock-items/{stockItemId}/{uuid}.{ext} -> create media_assets row
   (type=amazon, disk=public, original_url=Amazon URL, model_type=stock_item, model_id=stock item id,
   tenant_id from stock item, width/height via getimagesize, uploaded_by_user_id=Auth::id()). Network
   call outside the DB transaction; only row create + primary flip inside it. Honor the v1 soft limit
   (10/stock item).
3. Primary: make the imported image primary ONLY if the stock item has no primary yet; never steal an
   existing user-chosen primary.
4. Idempotency: at most one type=amazon image per stock item -- if one exists with the same
   original_url skip ("already imported"); if different, replace the previous amazon row. Never touch
   non-amazon images.
5. Tenant scope: stock item loaded scoped; tenant_id from the stock item; never trust request. internal
   all / tenant own active / no guest-as-internal. Activity log "amazon image imported" on the stock
   item (asin, original_url, media_asset_id).

CONSTRAINTS
- No bulk/scheduled import (on-demand only). No image editing/resize. No RDT. No non-Amazon sources.
- Do not change v1/v2 behavior. Reuse v1 helpers; do not duplicate.

TESTS (tests/Feature; Http::fake() + Storage::fake('public'); never hit real Amazon)
- success creates type=amazon row with original_url + file on public disk + width/height
- becomes primary only when no primary exists
- no ASIN / non-Amazon shop -> friendly failure, no row
- API error -> friendly failure, no row, no orphan file
- idempotent: same ASIN/URL twice -> no duplicate amazon row
- tenant user cannot import for another tenant's SKU; tenant_id comes from the stock item
- virtual bundle SKU does not allow the action
- non-image / >5MB download rejected
- regression: v1 upload + v2 private media unchanged

RUN: php artisan test (whole suite green). Fix anything you broke.


# Task: Product Images v3 - Amazon Image Import

## Stack

Laravel 13, Livewire 4 (class-based components, not Volt), Flux UI (`livewire/flux ^2.14`),
SQLite (dev), PHP 8.3. Plain Blade views only. No TypeScript.

ASCII punctuation only in code, comments, and this doc. No em-dashes, smart quotes, or unicode arrows.

---

## Goal

Build on Product Images v1 (stock item product photos on the public disk) and v2 (private issue/return
media). v3 adds importing a product image from Amazon for a stock item, on demand.

The v1 spec's "Amazon Image Import - Future Phase" section is the basis: identify the ASIN, fetch the
Amazon image URL, download a copy into our own storage, and create a `media_assets` row with
`type = amazon` and `original_url` kept for traceability.

Why keep a downloaded copy (not just the URL): external image URLs can expire and marketplace
permissions can change; the warehouse system should keep a stable local copy.

---

## What v3 Covers

Includes:

- a new SP-API Catalog Items client to fetch product images for an ASIN (reusing existing SP-API
  token / region / connection infrastructure)
- on-demand import: a per-SKU action ("Fetch Amazon image") on the SKU page that imports the image and
  attaches it to the SKU's stock item
- store: download the image into our own storage (public disk, same as v1 product photos),
  `media_assets` row with `type = amazon`, `original_url` = the Amazon image URL, attached to the
  `stock_item`
- idempotency, error handling, tenant scope, tests (with a faked HTTP layer)

Does NOT include:

- bulk / scheduled / automatic import for many SKUs (v3 is on-demand, one stock item at a time)
- importing non-image catalog data (titles, attributes)
- image editing / resize / thumbnail generation (still no image library)
- importing from non-Amazon marketplaces
- changing v1 / v2 behavior

Leave those for later phases.

---

## Dependencies and reuse

Reuse the existing Amazon SP-API infrastructure (verify the real class names before coding):

- `App\Services\Amazon\AmazonSpapiTokenService` (LWA access token)
- `App\Models\AmazonSpapiConnection` (per-shop connection / refresh token)
- `App\Support\AmazonSpapiRegion` (region + endpoint)
- mirror `App\Services\Amazon\AmazonSpapiOrdersClient` for HTTP call style, error handling, and how it
  resolves the connection / token / region.

The existing SP-API client is orders-only; there is NO Catalog Items client yet. Add one.

Important: Catalog Items images are NOT restricted PII data, so use the normal LWA access token
(`AmazonSpapiTokenService`). Do NOT use the Restricted Data Token (RDT) path (that is for order PII).

---

## Image source

- ASIN comes from the SKU: `skus.platform_product_id` for SKUs whose shop is an Amazon marketplace
  (verify how an Amazon shop is identified, e.g. `shops.platform === 'amazon'` or the marketplace
  field; check the codebase).
- Catalog Items API: call `getCatalogItem` (SP-API Catalog Items v2022-04-01) for the ASIN with
  `includedData=images` and the shop's marketplace id. Pick the primary / largest `MAIN` image
  variant's URL.
- If the SKU has no ASIN, the shop is not Amazon, or there is no Amazon connection for the shop, the
  action fails gracefully with a clear message (no row created).

---

## Flow (on-demand)

From the SKU page (`App\Livewire\SkusIndex`), for a SKU that is on an Amazon shop and has an ASIN, add
a "Fetch Amazon image" action. When triggered:

1. Load the SKU + its stock item through a tenant-scoped query (reuse the v1 scoped query). The SKU
   must have a `stock_item_id` (virtual bundles have none -> action not shown / rejected).
2. Resolve the ASIN (`platform_product_id`) and the shop's Amazon connection + marketplace id.
3. Call the Catalog Items client to get the main image URL.
4. Download the image bytes over HTTPS (with a timeout). Reject if it is not an image or exceeds the
   v1 size limit (5 MB) after download.
5. Store the file on the `public` disk under the v1 product-image path
   (`product-images/tenant-{id}/stock-items/{stockItemId}/{uuid}.{ext}`).
6. Create a `media_assets` row: `tenant_id` from the stock item, `model_type = stock_item`,
   `model_id = stock item id`, `type = amazon`, `disk = public`, `original_url` = the Amazon URL,
   `file_name`, `mime_type`, `size_bytes`, `width`/`height` via `getimagesize()`,
   `uploaded_by_user_id = Auth::id()`.
7. Primary: if the stock item has no primary image yet, make this one primary; otherwise leave the
   existing primary unchanged (do not steal primary from a user-chosen image).

Run the network call outside the DB transaction; only the row creation (and primary flip) is
transactional. Honor the v1 soft limit (max 10 images per stock item).

---

## Idempotency / replace

- Do not create duplicate Amazon images: if the stock item already has a `type = amazon` row with the
  same `original_url`, skip (report "already imported"). If it has an `amazon` row with a different
  `original_url`, either skip with a message or replace the old amazon row (pick one and state it;
  default: replace the previous `amazon` row for that stock item so there is at most one Amazon image).
- Never touch non-amazon images (user uploads stay).

---

## Data model

No migration expected: `media_assets` already has `type` (allows `amazon`) and `original_url`. Add the
`MediaAsset::MODEL_TYPE_*` only if missing (stock_item already exists). Reuse the v1 `MediaAsset` model
and `StockItem::mediaAssets()` / `primaryImage()`.

If you find a missing column or index, add a migration; otherwise none.

---

## New SP-API Catalog client

Create `App\Services\Amazon\AmazonSpapiCatalogClient` (or similar):

- a method like `getMainImageUrl(AmazonSpapiConnection $connection, string $asin, string $marketplaceId): ?string`
- reuse `AmazonSpapiTokenService` for the access token and `AmazonSpapiRegion` for the endpoint
- use Laravel's HTTP client; throw a typed exception (mirror `AmazonSpapiApiException`) on API error so
  the caller can show a friendly message
- it must be fakeable in tests (use `Http::fake()` or inject the client) -- do NOT hit real Amazon

---

## Tenant scope

- Load the SKU / stock item through the v1 tenant-scoped query; set `media_assets.tenant_id` from the
  stock item; never trust tenant_id from the request.
- internal users: all visible tenants. Tenant users: their active tenant only. No guest-as-internal.
- A tenant user cannot import for another tenant's SKU / stock item.

---

## Activity log

Log on the stock item: `amazon image imported` (with properties: media_asset_id, asin, original_url).
Do not log binary data.

---

## Tests (use Http::fake(); never hit real Amazon; use Storage::fake('public'))

1. successful import creates a `media_assets` row with `type = amazon`, `original_url` set, file stored
   on the public disk, width/height filled
2. import becomes primary only when the stock item has no primary image yet
3. SKU with no ASIN (or non-Amazon shop) -> friendly failure, no row created
4. Amazon API error -> friendly failure, no row, no orphan file
5. idempotency: importing the same ASIN/URL twice does not create a duplicate amazon row
6. tenant user cannot import for another tenant's SKU/stock item
7. tenant_id on the new row comes from the stock item
8. virtual bundle SKU (no stock_item_id) does not expose / allow the action
9. download that is not an image or exceeds 5 MB is rejected
10. v1 / v2 behavior unchanged (regression: normal upload + private media still work)

Run:

```bash
php artisan test
```

At minimum:

```bash
php artisan test tests/Feature/SkuManagementTest.php
```

---

## Acceptance Criteria

- a Catalog Items SP-API client exists and is fakeable; v3 never calls real Amazon in tests
- on-demand "Fetch Amazon image" imports the Amazon main image, downloads a local copy on the public
  disk, and creates a `media_assets` row with `type = amazon` and `original_url`
- import is tenant-scoped and attaches to the stock item
- idempotent (no duplicate amazon image), graceful on missing ASIN / API error / no image
- does not steal an existing user-chosen primary image
- v1 and v2 behavior unchanged
- tests pass

---

## Implementation Notes

Reuse the v1 `MediaAsset`, storage path, validation, and primary helpers. Reuse the existing SP-API
token / connection / region infrastructure.

Do not build bulk or scheduled import in v3.

Do not use the Restricted Data Token path (Catalog images are not PII).

Do not change v1 public product-photo behavior or v2 private media.

Do not store binary data in the database.
