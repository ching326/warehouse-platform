# Agent Brief: KuraLinks WMS

Paste this to any AI coding agent (Hermes Agent) before it reviews,
tests, or changes this repo. Goal: keep the agent grounded so it does not waste time or
report known/non-issues.

## Project

- KuraLinks warehouse management system (multi-tenant).
- Laravel 13, Livewire 4 (class-based components, not Volt), Flux UI (`livewire/flux ^2.14`).
- SQLite for dev, PHP 8.3. Plain Blade views. No TypeScript.
- Read `docs/PROJECT_STATE.md` for current module names, active work, and known state.
- Specs for features live in `docs/tasks/*.md`. Audits live in `docs/audits/`.

## How to run tests

Use whichever PHP works in your shell. Try in this order:

1. If `php` is on PATH:
   ```bash
   php artisan test
   ```
2. Windows / Laragon (PHP not on PATH), call the Laragon binary directly:
   ```bash
   C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan test
   ```
3. From WSL using Windows interop (same Laragon binary via /mnt/c):
   ```bash
   /mnt/c/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe artisan test
   ```
   (Or install PHP 8.3 + sqlite inside WSL and use `php artisan test`.)

Run a single file:
```bash
php artisan test tests/Feature/SalesOrderTest.php
```

Note: `composer` is not on PATH in this environment.

## Known state - do NOT report these as new bugs

- Routes are behind the local `authenticated` middleware group. If a helper still treats
  a guest as internal, that is a real tenant-scope/auth bug and may be reported with proof.
  Do not assume guest-as-internal is intentional.
- Some import/export tests are flaky in the FULL suite due to `livewire-tmp` temp-file
  pollution between test classes. They pass when run in isolation. If a test fails in the
  full run, re-run that single test file before reporting it as a real failure.
- The migration file `2026_06_21_000001_create_exception_cases_table.php` creates the
  `issues` table (module was renamed from Exception Cases to Issues). Filename/content
  mismatch is known and cosmetic.
- The working tree may contain ongoing user changes. Do not revert or rewrite unrelated
  dirty files while reviewing.

## Architecture rules to respect

- Tenant scope: every query that returns tenant data must scope by the allowed tenant IDs.
  Helpers are named `visibleTenantIds()` (inbound/outbound/issues side) or
  `allowedTenantIds()` (sales-order side). Internal users see all tenants; tenant users see
  only their own active tenant. Do not introduce a guest-as-internal fallback.
- Inventory: all stock changes go through `App\Services\InventoryService`
  (`receiveStock`, `adjustStock`, `reserveStock`, `releaseReserve`, `shipReservedStock`,
  `placeHold`, `releaseHold`, `markDamaged`). Never write `inventory_movements` or balances
  directly. `markDamaged`/`placeHold` move existing available stock; they do not receive new
  stock.
- Inventory movements carry `ref_type` + `ref_id` (for example `outbound_order`,
  `inbound_order`, `fulfillment_group`, `return_order`, `manual_adjustment`).
- Reference numbers (FG-, EC-/ISS-, RTN-) are generated from the row id after insert. The
  row is first inserted with a temporary unique placeholder like `RTN-PENDING-{uuid}` then
  updated, because the column is unique and not nullable.
- Shared filter logic for sales orders lives in `App\Support\SalesOrderFilters` and is used
  by both the index and the export controller.

## Language files and encoding

- During feature/UI churn, add new translation keys to `lang/en` only. The app fallback
  locale is English, so missing CJK keys display English instead of raw keys.
- Defer `lang/ja`, `lang/zh_TW`, and `lang/zh_CN` updates to a batched translation pass
  after the page or feature stabilizes.
- Track which pages still need CJK translation in `docs/translation-backlog.md`: add a row
  to its Pending table when you add en-only (or English-placeholder) keys, and move it to
  Done after the CJK pass. That file is the single source of truth for outstanding
  translations. Prefer en-only over writing English placeholders into the CJK files.
- Prefer real translation keys over hardcoded English in Blade/components, so the later
  translation pass only fills missing locale files.
- Do not rewrite files with PowerShell `Set-Content`, `Out-File`, or shell redirects.
  Windows shell rewrites can introduce UTF-16/BOM or corrupt multibyte text. Use surgical
  edits (`apply_patch`/editor tools) and force UTF-8 no BOM if a script must write a file.
- After any language-file edit, check the diff and confirm only the intended lines changed.

## How to report a finding

For each issue:
- Cite `file:line`.
- Give a severity: P0 data loss/security, P1 correctness/security, P2 important bug,
  P3 polish/performance.
- Explain why it is wrong (the bug, not a style opinion).
- Prove it: reproduce it (a failing test, a tinker snippet, or a query that errors), or
  point at the exact assertion/line. Do not report speculative findings as confirmed.
- Do not propose changes that break tenant scoping or bypass `InventoryService`.
- If uncertain, label it "Needs verification" instead of reporting it as a confirmed bug.

Suggested report format:

```md
# Repo Review Findings

## Confirmed Findings

### [P1] Short title
- Location: `path/to/file.php:123`
- Why it is wrong:
- Proof:
- Suggested fix:
- Tests to add/run:

## Needs Verification

## Checked And No Issue Found

## Test Results
```

## Good bounded tasks (prefer these over "review the whole repo")

- "Run the full suite. Re-run any failure in isolation first. Report only genuine failures."
- "Review `git diff main` for correctness bugs. Cite file:line and prove each."
- "Find SQL/query bugs in `app/Livewire` and `app/Services` (ambiguous columns after joins,
  N+1 in loops, missing tenant scope)."
- "Audit one module against its spec, e.g. the Return Orders code vs
  `docs/tasks/return-orders-v1.md`."
- "Run a read-only repo health audit and write all findings to
  `docs/audits/<name>.md`; do not edit production code."

## Guardrails

- Start read-only: allow `git`, `php artisan test`, grep/read. Only allow file edits after
  the bug-finding has proven accurate.
- Keep changes scoped to one task. Run the affected test file after any change.
- Verify findings before trusting them. The agent will confidently surface non-bugs,
  especially the known auth/flaky-test items above.
- Do not run destructive git commands (`reset --hard`, checkout/revert unrelated files).
