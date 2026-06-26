# SKU FNSKU / platform_label_code <-> Barcode Alias Sync v1

## Goal

Stop users from entering the same FNSKU twice -- once in the SKU
`platform_label_code` field (shown in the UI as the "FNSKU" column) and again
as a `barcode_aliases` row of type `platform_label`.

Make `platform_label_code` the single source of truth for the FNSKU, and have
the system automatically keep a matching scannable barcode alias in sync. The
user enters the FNSKU once (in the field they already use) and gets pack-scan
resolution for free.

## Background / why this is needed

The two stores look like duplicates but do different jobs today:

| | `skus.platform_label_code` (UI label "FNSKU") | BarcodeAlias type `fnsku` |
| --- | --- | --- |
| Storage | one flat string on `skus` | own table, many per SKU |
| Used for | text search only (`platform_label_code like ?` in OutboundOrderCreate, InventoryIndex, SkusIndex) | actual pack-scan resolution (FulfillmentPackService matches only active aliases) |
| Normalized | no | yes (`BarcodeAlias::normalize()` -> uppercase, strip spaces/hyphens) |
| Uniqueness | none | `normalized_barcode` unique per tenant |

Consequence: an FNSKU typed only into `platform_label_code` does not scan; one
typed only as an alias does not appear in the FNSKU column / text search. Users
end up entering it in both places.

This task connects them: the field stays the entry point, and a managed alias
mirrors it.

## Scope

Build:

1. A `source` marker on `barcode_aliases` so we can tell managed (field-derived)
   aliases apart from hand-entered ones.
2. A small service that syncs a SKU's `platform_label_code` to a managed
   `fnsku` alias on the SKU (create / update / deactivate).
3. Call that sync from the three write paths: `SkuCreate`, `SkuEdit`,
   `SkuImport` (via `SkuWriter`).
4. Collision handling against the tenant-wide `normalized_barcode` unique
   constraint.
5. Read-only presentation of managed aliases in the alias panel.
6. Tenant-safe validation and targeted tests.

Do not build:

- Backfill of existing `platform_label_code` values into aliases (call out as a
  follow-up; see "Existing data").
- Removing the `platform_label_code` column (that is Option B, out of scope).
- Two-way sync (editing the managed alias does not write back to the field).
- platform_label / supplier / regional alias automation -- only FNSKU.

## Data model

Add one nullable column to `barcode_aliases`:

```text
source   string, nullable, default null
         null   -> manually entered alias (existing behavior)
         'platform_label_code' -> managed: derived from skus.platform_label_code
```

Migration notes:

- Add the column nullable with default null so existing rows stay "manual".
- No new index needed; lookups are by `tenant_id + model_type + model_id +
  source`, already covered well enough by the existing
  `tenant_id + model_type + model_id` index.
- Add `source` to `BarcodeAlias::$fillable`.
- Optionally add a constant:
  ```php
  public const SOURCE_PLATFORM_LABEL_CODE = 'platform_label_code';
  ```
- Keep `source` out of the activity-log `logOnly(...)` set (internal plumbing,
  not user-meaningful).

## Sync service

Create:

```text
app/Services/Sku/PlatformLabelAliasSync.php
```

Single public method, called inside the existing save transaction of each
write path:

```php
public function sync(Sku $sku): void
```

Behavior (the SKU already has its new `platform_label_code` persisted, and
`tenant_id` + `id` available):

1. Compute `$raw = trim((string) $sku->platform_label_code)`.
2. Find the existing managed alias:
   ```php
   $managed = BarcodeAlias::query()
       ->where('tenant_id', $sku->tenant_id)
       ->where('model_type', BarcodeAlias::MODEL_TYPE_SKU)
       ->where('model_id', $sku->id)
       ->where('source', BarcodeAlias::SOURCE_PLATFORM_LABEL_CODE)
       ->first();
   ```
3. If `$raw === ''`:
   - If a managed alias exists, hard-delete it (it was system-created, no audit
     value in keeping an empty managed row). Manual aliases are never touched.
   - Return.
4. Else compute `$normalized = BarcodeAlias::normalize($raw)`.
5. Collision check against the tenant-wide unique index, excluding the managed
   row itself:
   ```php
   $conflict = BarcodeAlias::query()
       ->where('tenant_id', $sku->tenant_id)
       ->where('normalized_barcode', $normalized)
       ->when($managed, fn ($q) => $q->whereKeyNot($managed->id))
       ->first();
   ```
   - If `$conflict` exists and it belongs to **this same SKU** (model_type sku,
     model_id == sku->id) -- treat as "already covered", do not create a second
     row. If it is a manual alias on this SKU, leave it as-is and delete the
     managed row if any (the manual one wins; avoid two rows for one code).
   - If `$conflict` exists and belongs to a **different** model/SKU -- this is a
     real ambiguity. Throw a validation error keyed to the field so the SKU save
     is rejected with a clear message (see "Validation"). Do not silently
     swallow it.
6. Otherwise upsert the managed alias:
   - Update existing `$managed` (set `barcode = $raw`, `normalized_barcode`,
     keep `is_active = true`, `barcode_type = 'fnsku'`), or
   - Create a new one:
     ```php
     BarcodeAlias::create([
         'tenant_id' => $sku->tenant_id,
         'model_type' => BarcodeAlias::MODEL_TYPE_SKU,
         'model_id' => $sku->id,
         'barcode' => $raw,
         'normalized_barcode' => $normalized,
         'barcode_type' => 'platform_label',
         'label' => null,
         'is_active' => true,
         'source' => BarcodeAlias::SOURCE_PLATFORM_LABEL_CODE,
     ]);
     ```

The sync must run inside the same DB transaction as the SKU write so a
collision rollback leaves no partial state.

## Write-path wiring

Call `app(PlatformLabelAliasSync::class)->sync($sku)` after the SKU row is
written, inside the existing transaction:

1. `app/Livewire/SkuEdit.php` -- in `save()`, inside the `DB::transaction`
   closure, after `$this->sku->update([... 'platform_label_code' => ...])`
   (around line 154). Use `$this->sku->refresh()` or the updated model.

2. `app/Livewire/SkuCreate.php` -- in the create/save method, inside its
   transaction, after the `Sku::create([... 'platform_label_code' => ...])`
   (around line 149). Pass the freshly created `$sku`.

3. `app/Services/SkuImport/SkuWriter.php` -- in `upsert()`, after the SKU is
   created/updated (after line 47 for update, after line 57 for create), call
   the sync for the resulting `$sku`/`$existing`. This covers CSV/Excel import.
   - Import collision handling: a thrown validation error here would abort the
     whole row. Instead, in the import path catch the ambiguity and surface it
     as a per-row error (the existing `confirmImport()` try/catch in
     `app/Livewire/SkuImport.php` already records row errors), so one bad FNSKU
     does not kill the batch. Prefer making `sync()` throw a dedicated
     exception (e.g. `AliasCollisionException`) that the Livewire field
     validation maps to a field error, and the importer maps to a row error.

## Validation

When `sync()` hits a cross-SKU collision (step 5), the user-facing SKU forms
must reject the save:

- `SkuEdit` / `SkuCreate`: translate to a field error on `platformLabelCode`,
  e.g. `__('skus.fnsku_alias_conflict')` -- "This FNSKU is already registered as
  a barcode for another product."
- The form should not advance / flash success when this happens.

Add lang keys in all four locales (`en`, `ja`, `zh_TW`, `zh_CN`):

```text
skus.fnsku_alias_conflict
```

Follow the ASCII-punctuation rule in all values (no em-dash, smart quotes, or
unicode arrows; CJK characters in the value text are fine).

## UI -- managed alias is read-only

In the alias panel (`resources/views/livewire/skus-index.blade.php`, the alias
list around the `aliasBarcode` / `aliasBarcodeType` block):

- Render managed aliases (`source === 'platform_label_code'`) with a small badge
  / note like "from FNSKU field" and no Deactivate action (or disabled).
- Block `deactivateBarcodeAlias()` in `app/Livewire/SkusIndex.php` from acting
  on a managed alias (guard: if `$alias->source !== null`, `abort(403)` or
  ignore) so the field stays the source of truth.
- Optional nicety: a hint under the `platform_label_code` input on SkuEdit /
  SkuCreate -- "Also registered as a scannable FNSKU barcode."

Add lang keys as needed (4 locales), e.g.:

```text
skus.alias_source_fnsku_field
skus.fnsku_also_scannable_hint
```

## Existing data

Out of scope for v1, but document for the operator:

- Existing SKUs already have `platform_label_code` values with no managed alias,
  and some tenants may already have a manual `fnsku` alias with the same code.
- A follow-up backfill task (`sku-fnsku-alias-backfill-v1`) should: for each SKU
  with a non-empty `platform_label_code`, run the same `sync()` logic, letting
  the collision rule adopt/skip existing manual aliases. Running `sync()` is
  idempotent, so the backfill is just "loop all SKUs, call sync".
- Do not auto-run the backfill as part of this task's migration.

## Tests

Add targeted tests (SKU + import scope only; do not rerun the full suite by
default).

Sync service / SkuEdit / SkuCreate:

1. Saving a SKU with a new `platform_label_code` creates a managed `platform_label`
   alias (source = platform_label_code, is_active true, normalized correct).
2. Changing `platform_label_code` updates the managed alias barcode +
   normalized value (no duplicate managed row).
3. Clearing `platform_label_code` removes the managed alias.
4. Saving normalizes correctly (e.g. `"x00-abc 123"` -> `"X00ABC123"`).
5. A manual alias on the same SKU with the same normalized code does not produce
   a second managed row (manual wins; no managed row created, or managed row
   removed).
6. A cross-SKU collision (same normalized code already on a different SKU in the
   tenant) rejects the save with a `platformLabelCode` field error and writes
   nothing (transaction rolled back).
7. Same FNSKU across different tenants is allowed (no collision).
8. Managed alias cannot be deactivated via `deactivateBarcodeAlias()`
   (guard fires).

Import:

9. CSV import with a `platform_label_code` column creates the managed alias per
   row.
10. An import row whose FNSKU collides with another SKU is recorded as a row
    error and does not abort the rest of the batch.

Regression:

11. Existing manual alias create / deactivate flows still work (source = null
    path unchanged).
12. Pack scan resolves a SKU by its managed FNSKU alias (end-to-end: set
    `platform_label_code`, then scan it at pack).

## Acceptance criteria

- Entering an FNSKU in `platform_label_code` makes it scannable at pack with no
  second manual step.
- No way to end up with two rows (field + manual alias) for the same normalized
  FNSKU on one SKU.
- A cross-SKU duplicate FNSKU is blocked at save with a clear, localized error.
- Managed aliases are read-only in the alias panel; manual aliases are
  unaffected.
- Tenant isolation preserved; cross-tenant duplicates still allowed.
- Targeted SKU / import / pack tests pass.
- No change to inventory or fulfillment status behavior.

## Notes / design decisions

- The managed alias `barcode_type` is always `'platform_label'` since it derives
  from the `platform_label_code` field. This works for Amazon FNSKU (which is a
  platform label code) and any other platform-specific barcode that tenants may
  store in that field.
- This is Option A from the design discussion (field canonical, alias derived).
  Option B (drop the column, alias canonical) remains the cleaner long-term end
  state but is a much larger migration and is intentionally deferred.
