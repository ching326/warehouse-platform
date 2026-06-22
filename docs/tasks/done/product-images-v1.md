# Task: Product Images v1

## Stack

Laravel 13, Livewire 4 (class-based components, not Volt), Flux UI (`livewire/flux ^2.14`),
SQLite (dev), PHP 8.3. Plain Blade views only. No TypeScript.

---

## Goal

Add product image support to the WMS.

The system should let users attach photos to products so warehouse staff and tenants can visually identify items.

Primary use cases:

- tenant uploads a product photo from desktop
- tenant uploads a product photo by taking a photo on mobile
- warehouse staff uploads packaging / barcode / item photos
- SKU and Inventory pages show a small thumbnail
- stock item detail / future detail pages can show a gallery

Important design decision:

**Product images belong to `stock_items` in v1.**

Reason:

- `stock_items` represent the physical goods in the warehouse
- multiple SKUs can share one `stock_item`
- inventory is counted by `stock_item_id`
- warehouse staff need photos of the physical item, not only marketplace listings

Do not attach v1 product images primarily to `skus`.
SKU-level image overrides can be added later.

---

## What v1 Covers

V1 includes:

- database table for reusable media assets
- stock item image upload
- primary image per stock item
- multiple images per stock item
- image type classification
- thumbnail display on SKU and Inventory pages
- image preview modal / panel
- mobile-friendly upload input
- basic image validation
- tenant scope
- tests

V1 does not include:

- automatic Amazon image import
- Amazon SP-API image sync
- image editing / cropping UI
- CDN integration
- S3/R2 production storage migration
- OCR / barcode recognition
- issue / return order photo upload
- private authenticated media streaming

Leave those for later phases.

---

## Data Model

Create a general-purpose `media_assets` table.

Use a general table instead of `stock_item_images` because Issues, Returns, and damage photos will also need attachments later.

### `media_assets`

```text
id
tenant_id
model_type
model_id
type
disk
path
original_url
file_name
mime_type
size_bytes
width
height
sort_order
is_primary
uploaded_by_user_id
created_at
updated_at
```

### Column notes

`tenant_id`

- required
- used for tenant scoping
- must match the linked model tenant

`model_type`

Use string values, not full PHP class names:

```text
stock_item
sku
issue
return_order
return_order_line
```

V1 only implements:

```text
stock_item
```

Keep the other values reserved for future use.

`model_id`

- id of the related model
- for v1, this is `stock_items.id`

`type`

Allowed values:

```text
main
gallery
barcode
packaging
amazon
damage
other
```

V1 upload UI should allow:

```text
main
gallery
barcode
packaging
other
```

Do not expose `amazon` or `damage` in v1 upload UI.

`disk`

Default:

```text
public
```

Use Laravel storage disk.

Local dev path:

```text
storage/app/public/product-images/...
```

`path`

Relative storage path, for example:

```text
product-images/tenant-1/stock-items/123/abc.jpg
```

`original_url`

- nullable
- reserved for Amazon image import later

`is_primary`

- boolean
- only one primary image per stock item
- when an image is saved with `is_primary = true`, unset `is_primary` on all other images for that stock item (regardless of image `type`)
- `type = main` does NOT by itself make an image primary; primary is driven only by the `is_primary` flag
- if a stock item has no image and user uploads any image, make first image primary automatically

Indexes:

```text
index: tenant_id + model_type + model_id
index: model_type + model_id + is_primary
index: tenant_id + type
```

Foreign keys:

```text
tenant_id -> tenants.id restrictOnDelete
uploaded_by_user_id -> users.id nullOnDelete
```

Do not use a DB foreign key for `model_id` because `media_assets` is polymorphic.

---

## Models

Create:

```text
App\Models\MediaAsset
```

Fillable:

```text
tenant_id
model_type
model_id
type
disk
path
original_url
file_name
mime_type
size_bytes
width
height
sort_order
is_primary
uploaded_by_user_id
```

Casts:

```text
is_primary => boolean
size_bytes => integer
width => integer
height => integer
sort_order => integer
```

Relationships:

```php
tenant()
uploadedBy()
```

Add relationships to `StockItem`:

```php
mediaAssets()
primaryImage()
```

`primaryImage()` should return the primary image first, then lowest `sort_order`, then lowest `id`.

---

## Storage

Run:

```bash
php artisan storage:link
```

if not already linked.

V1 stores images on the `public` disk.

Do not store image binary data in the database.

Production note:

- local disk is acceptable for v1
- later production can move to S3 / Cloudflare R2
- because `disk` and `path` are stored separately, migration to object storage will be possible later

Security note:

V1 product photos use the `public` disk intentionally for simplicity.

This means the image file itself is public if someone has the `/storage/...` URL.
This is acceptable for non-sensitive stock item product photos in v1, but it is **not acceptable** for future sensitive photos such as:

- damage photos
- Issue photos
- Return Order photos
- customer parcel photos
- photos showing customer labels or PII

Before implementing sensitive image types, add:

```text
private disk
authenticated media streaming route
tenant-scoped authorization check
```

Example future route:

```text
GET /media/{asset}
```

That route must:

1. load `MediaAsset`
2. verify the current user can access `tenant_id`
3. stream the file from private storage

Do not reuse public disk for future sensitive image types.

---

## Upload Rules

Accept:

```text
jpg
jpeg
png
webp
```

Validation:

```text
image
mimes:jpg,jpeg,png,webp
max:5120
```

Max file size:

```text
5 MB
```

If possible, normalize orientation and resize large images.

Target resize:

```text
max width: 1600
max height: 1600
```

Thumbnail display should use CSS sizing.
Do not generate physical thumbnails in v1 unless the project already has an image-processing helper.

If image processing package is not installed, keep original file and add a TODO for future compression.

Even without an image processing package, use PHP's built-in `getimagesize()` to store:

```text
width
height
```

Do not leave width/height blank for normal uploaded images unless `getimagesize()` fails.

Soft limit:

- allow up to 10 images per stock item in v1
- reject additional uploads with a friendly validation message

---

## UI

### Where to add upload UI

Add v1 upload UI to the SKU page, because users already manage SKU / stock item master data there.

Route:

```text
/skus
```

Component:

```text
App\Livewire\SkusIndex
```

For rows with a linked `stock_item_id`, show:

- thumbnail image
- upload / replace button
- gallery / manage button if multiple images exist

For `virtual_bundle` SKUs with no `stock_item_id`:

- do not show upload button
- show `-` or no image

### Thumbnail display

Add an image thumbnail column to useful views:

- Catalog view
- Detailed view
- Logistics view if space allows

Do not add it to Marketplace view unless layout remains readable.

Thumbnail:

```text
48px x 48px
object-fit: cover
border radius: 6px
```

If no image:

```text
small placeholder box
```

Keep row height stable.

### Upload modal / panel

Use a Livewire modal or inline panel.

Implementation choice:

Prefer Livewire `WithFileUploads` for v1 because the upload UI lives inside the SKU management page.

Testing requirement:

- use `Storage::fake('public')`
- use `UploadedFile::fake()->image(...)`
- do not rely on real `livewire-tmp` files in tests

This is important because the project previously had flaky file-upload behavior around temporary upload files.
Tests must isolate the upload disk.

Fields:

```text
Image file
Image type
Set as primary
```

Image type options:

```text
Main
Gallery
Barcode
Packaging
Other
```

Default:

```text
Main
```

Important distinction:

`type` and `is_primary` are separate concepts.

```text
type = classification
is_primary = which image should be shown as the main thumbnail
```

Do not treat `type = main` as the source of truth for the primary image.

Primary logic:

- if `is_primary = true`, unset `is_primary` on all other images for the same stock item
- this applies regardless of image type
- a `gallery`, `barcode`, or `packaging` image can technically be primary if the user chooses it
- if the stock item has no images, the first uploaded image becomes primary automatically

Mobile:

Use an input that allows phone camera selection:

```html
<input type="file" accept="image/*" capture="environment">
```

Do not rely only on drag-and-drop; mobile users need the normal file picker / camera flow.

### Image manage panel

V1 manage panel should allow:

- view all images for stock item
- set primary
- delete image
- change image type

Delete behavior:

- remove file from storage
- delete `media_assets` row

Recommended delete order:

1. delete or mark the DB row in a transaction where possible
2. attempt to delete the storage file
3. do not leave a visible media row pointing at a missing file

If storage deletion fails, log the failure for cleanup.

If deleting primary image:

- choose next available image as primary
- if no image remains, stock item has no primary image

---

## Inventory Page

Update Inventory page to eager-load primary stock item image.

Route:

```text
/inventory
```

Display thumbnail next to stock item name/code if it does not make table too wide.

If it makes the table too dense, show thumbnail only on hover/detail later.

Do not break current inventory filters, totals, or pagination.

---

## Permissions / Tenant Scope

Use existing tenant visibility pattern, but do not introduce guest-as-internal fallback.

Rules:

- internal users can view/upload/manage images for all visible tenants
- tenant users can view/upload/manage images only for their active tenant
- tenant user cannot upload to another tenant's stock item
- warehouse staff can upload photos for stock items they can access

Every upload action must:

1. load the stock item through tenant-scoped query
2. use the stock item's `tenant_id` as `media_assets.tenant_id`
3. never trust tenant_id from request

---

## Activity Log

If activity logging is used consistently in this project, log:

- image uploaded
- image deleted
- primary image changed

Do not log binary data.
Do not log temporary upload paths.

---

## Amazon Image Import - Future Phase

Do not build this in v1.

Future design:

- identify Amazon SKU / ASIN from `skus.platform_product_id`, ASIN, or marketplace fields
- fetch product image URL from Amazon API or imported product data
- create `media_assets` row with:

```text
type = amazon
original_url = Amazon image URL
```

Preferred future behavior:

- download a copy into own storage
- keep `original_url` for traceability

Reason:

- external image URLs can expire
- marketplace permissions can change
- warehouse system should keep stable product visuals

---

## Tests

Add feature tests.

Required tests:

1. internal user can upload stock item image
2. tenant user can upload image for own tenant stock item
3. tenant user cannot upload image for another tenant stock item
4. upload rejects non-image file
5. upload rejects image over 5 MB
6. uploaded image creates `media_assets` row with correct tenant_id, model_type, model_id
7. first uploaded image becomes primary automatically
8. uploading an image with `is_primary = true` unsets the previous primary image
9. set primary action changes primary image
10. delete image removes media row and storage file
11. deleting primary image promotes another image if available
12. virtual bundle SKU does not show upload action
13. SKU index eager-loads primary image without N+1
14. Inventory page still renders with stock item thumbnails
15. unauthenticated user cannot upload
16. upload stores width and height using `getimagesize()`
17. upload rejects the 11th image for the same stock item
18. `type = main` without `is_primary = true` does not unset the existing primary image
19. setting `is_primary = true` unsets other primary images regardless of type
20. tests use `Storage::fake('public')` and do not depend on real `livewire-tmp` files

Run:

```bash
php artisan test
```

At minimum, run:

```bash
php artisan test tests/Feature/SkuManagementTest.php tests/Feature/InventoryPageTest.php
```

---

## Acceptance Criteria

- `media_assets` table exists
- stock item can have multiple images
- stock item can have one primary image
- SKU page shows thumbnail for stock item
- SKU page supports image upload from desktop and mobile
- tenant scoping is enforced
- virtual bundle SKUs do not show stock item image upload
- invalid files are rejected
- tests pass

---

## Implementation Notes

Keep v1 simple.

Do not add heavy image library unless necessary.

Do not build Amazon image sync yet.

Do not attach images to SKU directly in v1.

Do not store file binary data in DB.

Do not create a public unauthenticated image-management route.
