# Task: Product Images v2 - Private Media + Issue / Return / Damage Photos

## Stack

Laravel 13, Livewire 4 (class-based components, not Volt), Flux UI (`livewire/flux ^2.14`),
SQLite (dev), PHP 8.3. Plain Blade views only. No TypeScript.

ASCII punctuation only in code, comments, and this doc. No em-dashes, smart quotes, or unicode arrows.

---

## Goal

Build on Product Images v1 (the `media_assets` table, `MediaAsset` model, and stock item product
photos already exist and ship on the public disk).

v2 adds two things:

1. A private storage tier plus an authenticated, tenant-scoped streaming route, so images that are NOT
   safe to expose by public URL can be served only to authorized users.
2. Photo attachments for Issues and Return Orders (including damage photos), which are more sensitive
   than product photos and must be stored privately.

Background: v1 stored stock item product photos on the `public` disk on purpose (acceptable for
non-sensitive product images). The v1 spec explicitly required a private disk + an authenticated
streaming route BEFORE any sensitive image type (damage / issue / return) is added. v2 builds that
foundation and then uses it.

---

## What v2 Covers

Includes:

- private storage tier using Laravel's `local` disk (root `storage/app/private`, not web-accessible)
- authenticated, tenant-scoped streaming route: `GET /media/{mediaAsset}` (name `media.show`)
- `MediaAsset` URL helper that returns a direct public URL for public assets and the streaming route
  URL for private assets
- `media_assets` support for `model_type` values: `issue`, `return_order`, `return_order_line`
- upload UI for issue photos and return order photos (desktop + mobile camera)
- thumbnail / gallery display for those photos via the streaming route
- delete / manage (reuse v1 patterns)
- tenant scope on upload and authorization on streaming
- tests

Does NOT include:

- migrating v1 public product photos to private (they stay public by the v1 decision)
- automatic Amazon image import / SP-API sync
- image editing / cropping / resize / physical thumbnail generation
- SKU-level image overrides
- CDN / S3 / R2 production storage migration

Leave those for later phases.

---

## Storage tiers

| Media | model_type | disk | served by |
|---|---|---|---|
| Product photo (v1, unchanged) | `stock_item` | `public` | direct `/storage/...` URL |
| Issue photo (v2) | `issue` | `local` (private) | `GET /media/{asset}` (auth) |
| Return / damage photo (v2) | `return_order` / `return_order_line` | `local` (private) | `GET /media/{asset}` (auth) |

- The `media_assets.disk` column already records which disk each asset is on. Pick the disk by context:
  product photos keep `public`; issue / return / damage photos use `local`.
- The `local` disk is private by default (`storage/app/private`). Do NOT put sensitive media on the
  `public` disk and do NOT expose it via `storage:link`.
- Private file paths, for example:
  - `media/private/tenant-{id}/issues/{issueId}/{uuid}.jpg`
  - `media/private/tenant-{id}/return-orders/{returnOrderId}/{uuid}.jpg`

---

## Streaming route

Add an authenticated route:

```text
GET /media/{mediaAsset}   name: media.show
```

- Place it behind the same auth middleware as the rest of the app (authenticated users only).
- Route-model binding to `MediaAsset`.
- Authorization (no guest-as-internal):
  - internal user (`user_type === 'internal'`): may view any tenant's asset
  - tenant user: may view only if `asset.tenant_id` is in their `activeTenantIds()`
  - otherwise: `abort(403)` (or 404 to avoid leaking existence)
- Stream the file inline from `Storage::disk($asset->disk)` with the correct mime type; `abort(404)`
  if the file is missing.
- This route can serve any asset, but private (`local`) assets MUST be reachable only through it.

Prefer a small controller (e.g. `App\Http\Controllers\MediaController` invokable) over a closure.
A `MediaAsset` policy is optional; an inline tenant check in the controller is acceptable if it
mirrors the existing tenant-scope helpers used elsewhere.

---

## MediaAsset URL helper

Add a method on `MediaAsset`, e.g. `url(): string`:

- if `disk === 'public'`: return `Storage::disk('public')->url($path)` (direct, unchanged for v1)
- else (private): return `route('media.show', $this)`

Blade should call `$asset->url()` so both tiers work transparently. Switching the v1 product
thumbnails to `$asset->url()` is allowed (they remain public direct URLs) but not required.

---

## Models

`MediaAsset`:

- add constants `MODEL_TYPE_ISSUE = 'issue'`, `MODEL_TYPE_RETURN_ORDER = 'return_order'`,
  `MODEL_TYPE_RETURN_ORDER_LINE = 'return_order_line'` (the v1 `MODEL_TYPE_STOCK_ITEM` already exists)
- add the `url()` helper above

Add `mediaAssets()` relations (hasMany filtered by `model_type`, ordered `sort_order` then `id`) to:

- `App\Models\Issue`
- `App\Models\ReturnOrder`
- `App\Models\ReturnOrderLine` (optional in v2 if you only attach at return-order level; see UI)

Reuse the v1 creation pattern: create rows with `MediaAsset::create([...])` and set `model_type`,
`model_id`, and `tenant_id` explicitly from the parent model (the relation `where('model_type', ...)`
constraint does not set `model_type` on create).

No migration is required if `media_assets` already has the needed columns; add one only if you find a
missing column or index.

---

## Upload rules (reuse v1)

- validation: `image`, `mimes:jpg,jpeg,png,webp`, `max:5120` (5 MB)
- soft limit: up to 10 images per parent (issue / return order); reject the 11th with a friendly message
- fill `width` / `height` with PHP `getimagesize()` (no image library required)
- sensitive uploads use `disk = 'local'` and the private path structure above
- `type`: for issue / return / damage uploads allow `damage` and `other` only (do NOT expose
  `main` / `gallery` / `barcode` / `packaging` here). Default `damage`.
- `is_primary` is not used for issue / return galleries; leave it `false` (no primary concept for
  these). Do not add primary toggles to these panels.

---

## UI

### Issue photos

Add a photo panel to the issue detail page (`App\Livewire\IssueShow`; check the actual component
before editing). It allows:

- upload (desktop + mobile camera: `<input type="file" accept="image/*" capture="environment">`)
- view thumbnails (served via `$asset->url()`, i.e. the streaming route)
- delete

Optionally allow uploading at issue creation (`IssueCreate`) if it fits; issue detail is the minimum.

### Return order photos

Add a photo panel to the return order detail page (`App\Livewire\ReturnOrderShow`; verify the actual
component). Damage photos are most relevant during inspection / receiving, so the panel may also be
surfaced on `ReturnOrderInspect` or `ReturnOrderReceive` if natural.

v2 minimum: attach photos at the `return_order` level. Per-line (`return_order_line`) attachment is
optional; if you implement it, use `model_type = return_order_line` and `model_id = the line id`.

### Display

- small thumbnails (reuse the v1 48x48 cover style), click to view larger
- always load image src from `$asset->url()` so private assets stream through the auth route

---

## Permissions / Tenant Scope

- Upload: load the parent (issue / return order / line) through a tenant-scoped query; set
  `media_assets.tenant_id` from the parent's `tenant_id`; never trust `tenant_id` from the request.
- Streaming (`media.show`): authorize by `asset.tenant_id` per the rules above.
- internal users: all visible tenants. Tenant users: their active tenant only. No guest-as-internal.

---

## Activity Log

If activity logging is used consistently, log on the parent model:

- image uploaded
- image deleted

Do not log binary data or temporary upload paths.

---

## Tests

Add feature tests. Use `Storage::fake('local')` and `Storage::fake('public')`, and
`UploadedFile::fake()->image(...)`. Do not depend on real `livewire-tmp` files.

Required:

1. issue / return uploads are stored on the `local` (private) disk, not `public`
2. streaming route returns the file for an authorized user (own tenant, and internal for any tenant)
3. streaming route denies another tenant's user (403 or 404)
4. streaming route denies a guest (auth redirect)
5. tenant user can upload an issue photo for their own tenant
6. tenant user cannot upload for another tenant's issue / return order
7. uploaded row has tenant_id from the parent and the correct model_type / model_id
8. upload rejects a non-image and a file over 5 MB
9. delete removes the media row and the private storage file
10. soft limit: the 11th image for the same parent is rejected
11. regression: v1 stock item product photos still resolve to a public `/storage` URL (not the route)
12. `MediaAsset::url()` returns a direct public URL for public assets and the `media.show` route URL
    for private assets

Run:

```bash
php artisan test
```

At minimum:

```bash
php artisan test tests/Feature/IssueTest.php tests/Feature/ReturnOrderTest.php tests/Feature/SkuManagementTest.php
```

(Confirm the actual issue / return test file names before running.)

---

## Acceptance Criteria

- a private storage tier exists and sensitive media is stored on the `local` disk
- `GET /media/{asset}` streams files only to authorized users and is tenant-scoped (no guest-as-internal)
- issue and return order photos can be uploaded, viewed, and deleted, and are never reachable by a
  public URL
- v1 stock item product photos are unchanged (still public, direct URL)
- tenant scoping is enforced on upload and on streaming
- invalid files are rejected; soft limit enforced
- tests pass

---

## Implementation Notes

Reuse the v1 `MediaAsset` model, validation, and upload/delete helpers. Do not duplicate logic.

Do not change v1 product-photo public behavior.

Do not store binary data in the database.

Do not build Amazon image import, image editing, or SKU-level overrides in v2.
