# Task: Symmetric Localized Names -- Add name_en v1

## Stack

Laravel 13, Livewire 4 (class-based components, not Volt), Flux UI (`livewire/flux ^2.14`),
SQLite (dev), PHP 8.3. Plain Blade views only. No TypeScript.

ASCII punctuation only in code, comments, and this doc. No em-dashes, smart quotes, or unicode arrows.

---

## Goal

Make the per-locale product name system symmetric across all four app locales (en, ja, zh_TW, zh_CN)
so that any locale can be the per-tenant base language and the entry form always offers the other
three as overrides.

### The problem today

The four app locales are en, ja, zh_TW, zh_CN. The schema only has override columns for three of
them: `name_ja`, `name_zh_tw`, `name_zh_cn`. There is no `name_en`; English lives in the base `name`
column and is treated as the implicit source/fallback.

Result: when a tenant sets a base language other than English, the entry form drops the base
language from the expander but English becomes un-enterable (no column, and the base field now holds
the base language). The expander shows only 2 fields instead of 3.

| Base language | Expander shows today | Should show |
| --- | --- | --- |
| English (default) | ja / zh_TW / zh_CN (3) | ja / zh_TW / zh_CN (3) |
| Japanese | zh_TW / zh_CN (2, wrong) | en / zh_TW / zh_CN (3) |
| zh_TW | ja / zh_CN (2, wrong) | en / ja / zh_CN (3) |

### The model after this change

- The base `name` column always holds the text typed into the labeled base field, in the tenant's
  base language. It remains the universal fallback.
- Override columns become four: `name_en`, `name_ja`, `name_zh_tw`, `name_zh_cn`.
- The override column whose locale equals the base language is always left NULL (its input is hidden
  in the form). Resolution for that locale falls through to `name`. This keeps the base value in
  exactly one place (no duplication) and makes all four locales resolvable.

Resolution becomes fully symmetric:

- `localized('name', L)` returns `name_{L}` if filled, else the base `name`.
- For L == base language: `name_{L}` is NULL, so it returns `name` (which is in that language). Correct.
- For L != base language: returns the override if set, else falls back to base. Correct.

---

## What v1 Covers

Includes:

- `name_en` column on `stock_items` and `skus`
- `en => en` added to the locale suffix map in `HasLocalizedAttributes`
- `name_en` added to `DISPLAY_NAME_COLUMNS` and `$fillable` on both models
- effective base locale resolution: a null/empty tenant setting means `en`
- the `localized-name-field` partial offers all four locales and always hides the base one
- SkuCreate and SkuEdit carry an `en` slot in their name-translation state and persist `name_en`
- tests

Does NOT include:

- any change to `short_name` (still language-neutral, not localized)
- any change to the tenant base-language storage (columns stay nullable; null still means English)
- changing display ordering on any screen (Sku and StockItem `displayName()` ordering is unchanged)
- a `name_en` for any model other than Sku and StockItem

---

## 1. Migration

New file: `database/migrations/2026_06_25_000030_add_name_en_to_stock_items_and_skus.php`

```php
public function up(): void
{
    Schema::table('stock_items', function (Blueprint $table) {
        $table->string('name_en')->nullable()->after('name');
    });

    Schema::table('skus', function (Blueprint $table) {
        $table->string('name_en')->nullable()->after('name');
    });
}

public function down(): void
{
    Schema::table('stock_items', fn (Blueprint $table) => $table->dropColumn('name_en'));
    Schema::table('skus', fn (Blueprint $table) => $table->dropColumn('name_en'));
}
```

Add a docblock matching the existing 000010 migration style: `name_en` is the English override; the
base `name` column still holds the base-language value and is the fallback.

---

## 2. Trait: app/Models/Concerns/HasLocalizedAttributes.php

Add `en` to the suffix map. The base column is still the fallback; adding `en` means an English-base
tenant leaves `name_en` NULL and still resolves to `name`, so there is no regression for the default.

```php
protected static function localizedColumnSuffixes(): array
{
    return [
        'en'    => 'en',
        'ja'    => 'ja',
        'zh_TW' => 'zh_tw',
        'zh_CN' => 'zh_cn',
    ];
}
```

Update the docblock: the source locale `en` now has its own override column; the base column is the
fallback when any override (including `en`) is empty.

---

## 3. Models

`app/Models/Sku.php`:

- `DISPLAY_NAME_COLUMNS`: add `'name_en'`.
- `$fillable`: add `'name_en'`.

`app/Models/StockItem.php`:

- `DISPLAY_NAME_COLUMNS`: add `'name_en'`.
- `$fillable`: add `'name_en'`.

No change to `localizedName()` or `displayName()` on either model -- they delegate to the trait and
the ordering is unchanged. All index, pack, and pick eager-load selects already spread
`DISPLAY_NAME_COLUMNS`, so they pick up `name_en` automatically. Do not hand-edit those select lists.

---

## 4. Effective base locale (null means en)

The tenant columns `sku_name_locale` and `stock_item_name_locale` stay nullable. A null or empty
value means English. Resolve an effective base locale wherever the form needs it:

```php
$effective = $tenant?->sku_name_locale ?: 'en';
```

This is passed to the partial as `baseLocale`, which is now always one of en, ja, zh_TW, zh_CN.

---

## 5. Form partial: resources/views/livewire/partials/localized-name-field.blade.php

- Add `'en' => 'English'` to `$localeLabels`.
- `$baseLocale` is now always concrete (caller passes the effective locale, default `en`).
- Keep appending the language suffix to the base label ONLY when `baseLocale !== 'en'`, so the
  default English tenant still sees a clean `SKU name` label (no `(English)` suffix).
- Continue to `unset($displayLocaleModels[$baseLocale])` so the base language's own override input is
  hidden. With en in the list, an English-base tenant now hides `en` and shows ja / zh_TW / zh_CN
  (same as today); a Japanese-base tenant hides `ja` and shows en / zh_TW / zh_CN.

```php
$baseLocale = $baseLocale ?? 'en';
$displayLabel = ($baseLocale !== 'en' && isset($localeLabels[$baseLocale]))
    ? $label.' ('.$localeLabels[$baseLocale].')'
    : $label;
$displayLocaleModels = $localeModels;
unset($displayLocaleModels[$baseLocale]);
```

---

## 6. SkuCreate.php and sku-create.blade.php

`app/Livewire/SkuCreate.php`:

- `$nameTranslations`: add an `en` key -> `['en' => '', 'ja' => '', 'zh_TW' => '', 'zh_CN' => '']`.
- `$stockItem` array: add `'name_en' => ''`.
- In the `Sku::create([...])` call, add `'name_en' => $this->nullableString($this->nameTranslations['en'] ?? '')`.
- In `stockItemPayload()`, add `'name_en' => $this->nullableString($this->stockItem['name_en'] ?? '')`.
- In `validateInput()`, the existing `'name_translations.*'` rule already covers `en`; add
  `'stock_item.name_en' => ['nullable', 'string', 'max:255']`.
- In `render()`, the effective base locales already pass through; ensure
  `skuNameBaseLocale` and `stockItemNameBaseLocale` default to `en` when the tenant setting is null
  (use `?: 'en'`).

`resources/views/livewire/sku-create.blade.php`:

- In both `@include('livewire.partials.localized-name-field', [...])` calls, add an `en` entry to
  `localeModels`:
  - SKU name: `'en' => 'nameTranslations.en'`
  - stock item name: `'en' => 'stockItem.name_en'`

The base-language slot stays empty because the partial hides that input, so its column is written NULL.

---

## 7. SkuEdit.php and sku-edit.blade.php

`app/Livewire/SkuEdit.php`:

- `$nameTranslations`: add an `en` key.
- `$stockItem` array: add `'name_en' => ''`.
- In `mount()`, load `'en' => $sku->name_en ?? ''` into `$nameTranslations`, and
  `$this->stockItem['name_en'] = $si->name_en ?? ''`.
- In `save()`, write `'name_en'` for both the sku update and the stock item update.
- In `validateInput()`, add `'stock_item.name_en' => ['nullable', 'string', 'max:255']`.
- In `render()`, include `name_en` in the `array_filter(...)` checks that drive
  `skuNameHasTranslations` and `stockItemHasTranslations` (so the expander auto-opens when an English
  override exists), and default the base locales to `en` with `?: 'en'`.

`resources/views/livewire/sku-edit.blade.php`:

- Add the `en` entry to `localeModels` in both partial includes, same as SkuCreate.

---

## 8. Tenant setup (no schema change)

`app/Livewire/TenantEdit.php` and `resources/views/livewire/tenant-edit.blade.php` are unchanged.
The locale dropdown already offers English (default) plus ja / zh_TW / zh_CN. English is stored as
null and resolved to `en` by the effective-base-locale logic above.

---

## 9. Tests

`tests/Feature/LocalizedProductNameTest.php`:

- Add a case: set `name` (base, e.g. a Japanese string) and `name_en`; assert
  `localizedName('en')` returns `name_en`, and `localizedName('ja')` falls back to the base `name`
  when `name_ja` is empty.
- Confirm the existing English-default behavior is unchanged: with only `name` set and no overrides,
  every locale resolves to `name`.

`tests/Feature/SkuManagementTest.php`:

- Extend the localized-override create test to also set `nameTranslations['en']` and
  `stockItem.name_en`, then assert both `name_en` columns persisted and resolve per locale.

---

## 10. Verification

Run in order; all must pass:

```
php artisan migrate
vendor/bin/pint app database/migrations tests
php vendor/bin/phpstan analyse --memory-limit=512M
php artisan test
```

PHPStan must stay at zero new errors against the baseline. If new baseline-worthy errors appear from
the added columns, fix the underlying type issue rather than regenerating the baseline.

---

## Notes

- `short_name` is deliberately untouched; it is a language-neutral operator shorthand.
- Display ordering on every screen is unchanged. This task only widens the override matrix and the
  entry form; `Sku::displayName()` and `StockItem::displayName()` keep their current precedence.
- Because `DISPLAY_NAME_COLUMNS` is spread into every constrained eager load, adding a locale in the
  future is a migration plus a constant edit plus the suffix-map entry -- no hunting through selects.
