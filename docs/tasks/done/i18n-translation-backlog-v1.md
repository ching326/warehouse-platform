# Task: i18n Translation Backlog v1

## Stack

Laravel 13 lang files under `lang/{locale}/`. Source locale `en`; target locales `ja`, `zh_TW`,
`zh_CN`; `fallback_locale = en`.

ASCII punctuation only in code, comments, and this doc. No em-dashes, smart quotes, or unicode
arrows. CJK characters in translation VALUES are of course fine; the rule is about punctuation
(use plain `-`, `...`, straight quotes), not the translated text.

---

## Goal

Bring the three target locales to full parity with `lang/en`. This was scoped from an i18n audit and
verified against the working tree. Three independent batches, committed separately.

Canonical terminology lives in `docs/i18n-glossary.md`. Use it for every recurring term so wording
stays consistent across files. After each batch, update the status section of that glossary.

---

## What v1 Covers

Includes:

- create 12 missing lang files (4 files x 3 locales)
- translate 8 English-placeholder files (the ones carrying a `// TODO: translate` header)
- fix 1 straggler missing key in `issues.php` across all three locales
- update `docs/i18n-glossary.md` status after each batch

Does NOT include:

- changing any `lang/en` source strings
- adding new keys that do not already exist in `lang/en`
- touching files already at full parity
- the legitimate "untranslated" false positives from the audit (brand names, acronyms, pure
  placeholder patterns) -- see the Do Not Touch list below

---

## Verification before starting

Confirm the scope still matches the tree (files may have changed since this spec was written):

```
# whole-file-missing per locale
for loc in ja zh_TW zh_CN; do for f in lang/en/*.php; do b=$(basename "$f"); \
  [ -f "lang/$loc/$b" ] || echo "$loc MISSING $b"; done; done

# English-placeholder files
grep -rl "TODO: translate" lang/ja lang/zh_TW lang/zh_CN

# straggler missing keys in existing, non-placeholder files
# (use a key array_diff of en vs each target; see batch 3)
```

---

## Batch 1: create the 12 missing files

These four files do not exist in ja, zh_TW, or zh_CN. Create each by translating the matching
`lang/en` file key-for-key (same keys, same order, translated values). Approximate key counts (verify
against the current `lang/en` file):

| file | en keys (approx) |
| --- | --- |
| amazon_spapi.php | 28 |
| amazon_spapi_import.php | 55 |
| fulfillment_pick.php | 49 |
| media.php | 12 |

For each of the 3 locales, create:

- `lang/{locale}/amazon_spapi.php`
- `lang/{locale}/amazon_spapi_import.php`
- `lang/{locale}/fulfillment_pick.php`
- `lang/{locale}/media.php`

Rules:

- Every key present in the en file must be present in the target, with identical key names and the
  same `:placeholder` tokens preserved exactly (e.g. `:message`, `:count`, `:orders`).
- Keep nested arrays (if any) with the same structure.
- Acronyms and proper nouns that have no localized form stay as-is (SKU, ASIN, FNSKU, Amazon, etc.).
- Suggested commit: `Add ja/zh_TW/zh_CN translations for amazon and media lang files`
  (or one commit per file group if you prefer smaller diffs).

---

## Batch 2: translate the English-placeholder files

These files exist but begin with `// TODO: translate this file. English values are placeholders.`
and hold English values. Translate every value and REMOVE the TODO header line.

| locale | files |
| --- | --- |
| ja | movements.php, stock_adjustments.php |
| zh_TW | inventory.php, movements.php, stock_adjustments.php |
| zh_CN | inventory.php, movements.php, stock_adjustments.php |

(`ja/inventory.php` is already translated; do not touch it.)

Rules:

- Translate values only; keep keys and placeholder tokens unchanged.
- Delete the `// TODO: translate ...` comment once translated.
- Suggested commit: `Translate movements, stock_adjustments, inventory placeholder lang files`.

---

## Batch 3: straggler key + glossary status

1. `issues.php` is missing the key `field_stock_item` in all three locales. Add it (translate the
   value; the en value is the English label for that field). Place it near the related field keys to
   match the en file ordering.
2. Re-run the straggler check to confirm no other keys are missing:

   ```
   php -r '
   foreach (["ja","zh_TW","zh_CN"] as $loc) {
     foreach (glob("lang/en/*.php") as $enf) {
       $b = basename($enf); $tf = "lang/$loc/$b";
       if (!file_exists($tf)) continue;
       $miss = array_diff(array_keys(require $enf), array_keys(require $tf));
       if ($miss) echo "$loc $b: ".implode(", ", $miss)."\n";
     }
   }'
   ```

   Expected output after batches 1 and 2: empty.
3. Update the status section of `docs/i18n-glossary.md` to mark these files complete.
- Suggested commit: `Fill issues.field_stock_item and update i18n glossary status`.

---

## Do Not Touch (audit false positives)

The audit's "untranslated" flag uses a strict value match and wrongly flags values that are correctly
identical across languages. Leave these as-is:

- `common.app_eyebrow` = `KuraLinks` (brand)
- `common.nav_skus`, `inbound.field_sku`, `issues.field_sku` = `SKU` (acronym)
- `fulfillment.orders_items_value` = `:orders / :items` (pure placeholder pattern)
- any other key whose en value is a bare acronym, brand, or placeholder-only string

---

## Definition of done

- The verification commands at the top all return empty (no missing files, no TODO headers, no
  missing keys).
- `php artisan test` stays green (translation-only changes should not affect tests, but confirm).
- `docs/i18n-glossary.md` status reflects the completed files.
