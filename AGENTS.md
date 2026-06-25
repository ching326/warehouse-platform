# AGENTS.md

Operating rules for AI coding agents working in this repo (KuraLinks WMS). Read this first.
For deeper context see `docs/AGENT_BRIEF.md` (review guidance), `docs/PROJECT_STATE.md`
(current module names and state), `docs/i18n-glossary.md` (translations), and the feature specs in
`docs/tasks/*.md`.

Write in ASCII punctuation only -- code, comments, commit messages, specs, and lang files. No
em-dashes, smart quotes, or unicode arrows. CJK characters in translation VALUES are fine.

## Stack

- Laravel 13, Livewire 4 (class-based components, not Volt), Flux UI (`livewire/flux ^2.14`), PHP 8.3.
- Plain Blade views. No TypeScript. Vite for assets.
- SQLite in dev and test (`:memory:` for the suite, pinned in `phpunit.xml`).

## Golden rules (do not break these)

1. **Tenant scope.** Every query returning tenant data must scope by the allowed tenant IDs. Use the
   existing helpers: `visibleTenantIds()` (inbound/outbound/issues) or `allowedTenantIds()`
   (sales-order side). Internal users (`user_type === 'internal'`) see all tenants; tenant users see
   only their own active tenants. Never add a guest-as-internal fallback.
2. **Inventory writes go through `App\Services\InventoryService`** only -- `receiveStock`,
   `adjustStock`, `reserveStock`, `releaseReserve`, `shipReservedStock`, `placeHold`, `releaseHold`,
   `markDamaged`. Never write `inventory_movements` or balance rows directly, and always inside a DB
   transaction. `placeHold`/`markDamaged` move existing available stock; they do not receive new stock.
3. **OutboundOrder is the single source of truth for outbound.** The old FulfillmentGroup model and
   its tables/columns were removed (Outbound Unification). Do not reintroduce `fulfillment_group_id`
   or a FulfillmentGroup model.
4. **Localized product names.** Base column `name` is the fallback; per-locale overrides live in
   `name_ja` / `name_zh_tw` / `name_zh_cn` (resolved via the `HasLocalizedAttributes` trait).
   `short_name` is language-neutral (operator shorthand like `brand`) and is NOT localized. When
   selecting name columns for eager loads, spread the model's `DISPLAY_NAME_COLUMNS` constant rather
   than hand-listing columns.
5. **Two display orderings exist on purpose.** `Sku::displayName()` is for index/list screens
   (short_name, then sku name, then stock-item name); `StockItem::displayName()` is for pack/pick
   screens (short_name, then stock-item name). Do not "unify" them into one accessor.
6. **Reference numbers** (IB-, OB-, RTN-, ISS-) are built from the row id after insert. Insert with a
   temporary unique placeholder like `RTN-PENDING-{uuid}`, then update -- the column is unique and not
   nullable.
7. **Models use Spatie activity logging** (`LogsActivity`, `logFillable`). New loggable fields belong
   in `$fillable`.

## Workflow: implementing vs reviewing

**When implementing a change**, run local checks and self-correct before finishing:

- `vendor/bin/pint --dirty` (format only changed files; never run repo-wide `vendor/bin/pint`).
- `php vendor/bin/phpstan analyse --memory-limit=512M` (Larastan level 4).
- `php artisan test` (or the affected test file). Do not stop until green, then summarize.

**When reviewing code**, assume CI already ran Pint, Larastan, and PHPUnit. Do not spend time on
formatting, undefined-method, or type nits. Focus on architecture, security, tenant isolation,
inventory consistency, business rules, race conditions, edge cases, performance, and maintainability.
Cite `file:line` and prove each finding; label anything uncertain "Needs verification".

## Tooling and the "do not" list

- **Pint** enforces the Laravel preset. The repo is fully Pint-clean; keep it that way with scoped
  runs. A pre-commit git hook runs `pint --test --dirty` and will block unformatted commits.
- **Larastan** runs at level 4 with `phpstan-baseline.neon` capturing pre-existing brownfield errors.
  Do NOT regenerate or edit the baseline to silence a new error -- fix the underlying type issue.
- **CI** (`.github/workflows/laravel.yml`) runs Pint, Larastan, migrations, and the full suite on push
  and PR. It is free and does not cost tokens; let it be the safety net.
- Do not run destructive git commands (`reset --hard`, reverting unrelated dirty files). The working
  tree may hold ongoing user changes.

## Tests

- `php artisan test`; single file: `php artisan test tests/Feature/SkuManagementTest.php`.
- Some import/export tests can flake in the FULL suite due to `livewire-tmp` temp-file pollution
  between classes. If a test fails in the full run, re-run that file in isolation before reporting it
  as a real failure.

## Conventions

- Feature specs go in `docs/tasks/<name>-v1.md` (see existing ones for the format); audits in
  `docs/audits/`.
- i18n: source locale `en`; targets `ja`, `zh_TW`, `zh_CN`; `fallback_locale = en`. Keep all target
  files at key-parity with `lang/en`. Use the canonical terms in `docs/i18n-glossary.md`.
- Commit messages: imperative subject, ASCII only, and end with:
  `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`
- Keep changes scoped to one task; run the affected test file after any change.
