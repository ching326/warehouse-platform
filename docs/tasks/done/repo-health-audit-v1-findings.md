# Repo Health Audit v1 Findings

## Executive Summary

- Total findings: 6
- P1: 1
- P2: 2
- P3: 3
- Test gaps: 2
- Overall status: the current test suite and frontend build pass, and several previously risky areas are already in good shape: inventory balance creation is transaction-safe, inventory movement writing is private to `InventoryService`, fulfillment group creation re-checks selected orders inside a transaction, and the shipping-method search ambiguity has already been fixed in commit `9969ce7`.

## Commands Run

- `php artisan route:list` using `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe`: passed, 67 routes listed.
- `php artisan migrate:status` using `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe`: passed, all listed migrations are marked ran.
- `php artisan test` using `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe`: passed, 423 tests and 1654 assertions.
- `npm run build`: passed, Vite build completed with a plugin timing warning only.
- `git status --short`: showed existing docs-task moves/deletions, plus this audit output after creation.
- `git log --oneline -20`: reviewed recent commits through `9969ce7`, `a2a8508`, `ef74500`, and sales-order UI/export work.
- `composer validate`: attempted, but `composer` is not available on the current shell `PATH`.
- Additional read-only searches: `rg`, route/code inspection, migration inspection, service/controller/component inspection.

## P1 - Must Fix

### P1-1: Unauthenticated requests are treated as internal users on public app routes

**Evidence**

- `routes/web.php:51-90` registers application routes such as `/inventory`, `/sales-orders`, `/sales-orders/export`, `/fulfillment-groups`, `/skus`, and `/setup/*` without an `auth` middleware group.
- `app/Livewire/SalesOrderIndex.php:895-912` has a TODO to remove the unauthenticated fallback, and `isInternalUser()` returns true when `Auth::user()` is null. `allowedTenantIds()` then returns all tenant IDs for that internal path.
- `app/Http/Controllers/SalesOrderExportController.php:71-82` has the same fallback and returns all tenant IDs for a guest request.
- `app/Livewire/ShippingMethodIndex.php:35-39` blocks only when `! $this->isInternalUser()`. Its `isInternalUser()` helper also returns true for a missing user.
- `rg "TODO: remove unauthenticated fallback" app` finds the same pattern across inbound, outbound, sales orders, fulfillment groups, setup pages, packaging, stock adjustment, and other modules.

**Problem**

The app currently relies on component/controller helpers for authorization, but those helpers intentionally treat a missing authenticated user as internal. Because the routes themselves are public, a guest request can reach internal-only pages and tenant-scoped data paths as if it were an internal user.

**Why It Matters**

If the app is reachable outside a trusted local/dev context, this is a real data exposure and workflow-control risk. It can expose sales orders, inventory, setup pages, exports, and tenant data. The export routes are especially sensitive because they produce downloadable order data.

**Suggested Solution**

- Put all application routes behind authentication middleware, leaving only explicitly public routes such as health checks, locale switching if desired, and framework assets outside the group.
- Change every `isInternalUser()` fallback so guests are not internal. A safe default is `return $user?->user_type === 'internal';`.
- Centralize this logic in a shared policy, gate, middleware, or small authorization service instead of repeating it across components/controllers.
- Keep tenant scoping checks inside services/controllers as defense in depth, but do not use them as the only boundary.

**Tests Affected**

- Existing tenant-user forbidden tests should continue to pass after proper auth is added.
- New tests recommended:
  - Guest requests to `/sales-orders`, `/inventory`, `/setup/shipping-methods`, `/sales-orders/export`, courier export/download, and marketplace notice download should redirect to login or return 403.
  - Guest Livewire component mount tests for representative internal-only pages should be forbidden.
  - Existing internal and tenant user visibility tests should remain green.

**Rechecked**

Yes. Rechecked route definitions, `SalesOrderIndex`, `SalesOrderExportController`, `ShippingMethodIndex`, and the repository-wide TODO search. This is not speculative; the code currently grants the guest path internal behavior.

## P2 - Should Fix

### P2-1: Export files are written before the database audit transaction succeeds

**Evidence**

- `app/Services/Courier/CourierExportService.php:137-139` writes the CSV file with `Storage::disk('local')->put($path, $csv)` before entering `DB::transaction(...)`.
- `app/Services/MarketplaceShippingNotice/MarketplaceShippingNoticeExportService.php:175-177` writes the notice file before entering `DB::transaction(...)`.
- The transaction then creates the export batch, creates related rows, marks orders exported, and writes activity logs.

**Problem**

If the storage write succeeds but the later database transaction fails, the private file remains on disk without a corresponding batch record, pivot/detail rows, exported timestamps, or activity log. The reverse ordering problem is also possible if the design later moves the write after the transaction without cleanup.

**Why It Matters**

Exports are audit-sensitive. Orphan files make it harder to answer whether an order was exported, can confuse re-export confirmation behavior, and can leave sensitive files in storage without a database trail.

**Suggested Solution**

- Use a two-phase pattern:
  - Write to a temporary path.
  - Complete the database transaction.
  - Move/rename the file to the final path only after the transaction succeeds.
  - Delete the temp file on any exception.
- Or create a pending batch first, write the file, then finalize the batch state with explicit failure cleanup.
- Keep the existing tenant and status validation before file generation.

**Tests Affected**

- Existing courier and marketplace notice export success tests should remain green.
- New tests recommended:
  - Fake storage, simulate a database failure after file generation, and assert no final export file remains.
  - Assert no batch/detail/exported timestamp is created on the failure path.

**Rechecked**

Yes. Rechecked both export services and confirmed the same ordering exists in courier CSV and marketplace shipping notice export.

### P2-2: Tenant/internal authorization helpers are duplicated across many modules

**Evidence**

- `rg "TODO: remove unauthenticated fallback|allowedTenantIds\\(" app` finds repeated `isInternalUser()` and `allowedTenantIds()` implementations in `SalesOrderIndex`, `SalesOrderCreate`, `SalesOrderDetail`, `SalesOrderImport`, `FulfillmentGroup*`, `InboundOrder*`, `OutboundOrder*`, `StockAdjustmentCreate`, setup pages, packaging pages, and export/download controllers.
- `app/Livewire/SalesOrderIndex.php:895-920` includes one copy with memoization.
- `app/Http/Controllers/SalesOrderExportController.php:71-89` includes another copy without memoization.

**Problem**

The same security-sensitive behavior is repeated in many places. That makes it easy to fix one class but miss another, and makes later changes to guest handling, tenant memberships, inactive tenant users, or internal-only pages drift.

**Why It Matters**

Authorization logic is one of the highest-risk areas in a multi-tenant warehouse platform. Duplication increases the chance of inconsistent tenant scoping across index pages, create pages, detail pages, export controllers, and download controllers.

**Suggested Solution**

- Extract a shared authorization/tenant-scope helper, trait, service, or policy layer.
- Prefer route middleware for broad access boundaries and policies/gates for model-specific checks.
- Keep a single implementation for:
  - internal-user detection,
  - active tenant membership lookup,
  - visible tenant IDs,
  - internal-only setup guards,
  - export/download tenant checks.

**Tests Affected**

- Existing tenant visibility and forbidden tests should continue to pass.
- Add a small test set around the shared helper/policy so every module does not need to duplicate the same low-level tenant membership assertions.

**Rechecked**

Yes. Rechecked concrete examples and repository-wide search output. This is a maintainability issue today and directly related to the P1 auth fallback risk.

## P3 - Cleanup

### P3-1: Final inventory movement migration is still named as a rebuild and drops the table

**Evidence**

- `database/migrations/2026_06_18_000012_rebuild_inventory_movements_table.php:14-16` calls `Schema::dropIfExists('inventory_movements')` and then creates the final bucket-based table.
- `php artisan migrate:status` shows this migration is the current ran migration for inventory movements.
- The older v1 create migration is no longer present in the working tree, so this file now acts as the create migration despite its rebuild name.

**Problem**

In the current tree, this migration is effectively the final create migration, but its name and body imply a destructive rebuild. That is confusing for future contributors and dangerous if someone later applies it in an environment where an `inventory_movements` table already exists but the migration has not been recorded.

**Why It Matters**

Inventory movements are append-only audit history. A migration that begins with `dropIfExists` on that audit table sends the wrong signal and can be hazardous outside a fresh early-dev database.

**Suggested Solution**

- If this project is still allowed to rewrite early migrations, rename this to a normal `create_inventory_movements_table` migration and remove `Schema::dropIfExists`.
- If migration history must remain stable, leave the file but add a follow-up migration/comment documenting why this destructive rebuild is safe only in the already-applied dev history.

**Tests Affected**

- Migration/fresh database tests only. The current test suite passes, so this is cleanup, not an active runtime failure.

**Rechecked**

Yes. Rechecked migration contents and migration status. This is real cleanup, not a broken schema finding.

### P3-2: Working tree contains unrelated docs-task moves/deletions

**Evidence**

- `git status --short` shows deleted tracked files:
  - `docs/tasks/sales-order-client-side-selection-v1.md`
  - `docs/tasks/sales-order-filter-toolbar-chips-v1.md`
  - `docs/tasks/sales-order-print-waiting-selection-ui-v1.md`
  - `docs/tasks/sales-order-toolbar-compact-print-ready-v1.md`
- The same task files appear as untracked files under `docs/tasks/done/`.
- `docs/tasks/repo-health-audit-v1.md` is also untracked.

**Problem**

The repo is not clean before this audit output. These appear to be docs housekeeping moves, but they are not committed or staged. Future feature commits can accidentally include or omit these changes.

**Why It Matters**

Dirty working trees make reviews harder and increase the chance of unrelated docs churn being bundled with code changes.

**Suggested Solution**

- Commit the docs moves separately if they are intentional.
- Or revert/clean them if they were accidental.
- Keep this audit report in its own change.

**Tests Affected**

- None directly.

**Rechecked**

Yes. Rechecked `git status --short` before writing the report.

### P3-3: Composer validation is not runnable in the current shell environment

**Evidence**

- `composer validate` was attempted from `C:\laragon\www\warehouse-platform`.
- The shell returned that `composer` is not recognized as a command.
- PHP artisan commands worked when using `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe`.

**Problem**

The repository may be fine, but this environment cannot currently run one of the standard health checks from the documented command list.

**Why It Matters**

Composer validation catches package metadata and dependency issues that the PHP test suite may not catch. If developers use different shells/paths, health checks become inconsistent.

**Suggested Solution**

- Add Composer to PATH for this development shell, or document the local Composer executable path if Laragon provides one.
- Re-run `composer validate` once available.

**Tests Affected**

- None directly.

**Rechecked**

Yes. This is an environment/tooling cleanup item only, not an application code defect.

## Test Gaps

### TG-1: Guest access is not covered for the main public app routes

**Evidence**

- There are many tests for tenant users being forbidden, for example `tests/Feature/SalesOrderTest.php:349-358`, `tests/Feature/ShippingMethodTest.php:269-275`, and setup-page tests.
- The current risk is specifically the guest path: `Auth::user()` null is treated as internal in many helpers.
- `rg "guest|assertGuest|assertRedirect\\(|assertForbidden\\(" tests/Feature` shows forbidden tests, but no broad guest-access regression suite for the main application routes.

**Problem**

The existing tests validate tenant-user restrictions but do not lock down unauthenticated access. That leaves the P1 fallback behavior untested.

**Recommended Tests**

- Add route-level guest tests for representative pages and export/download endpoints.
- Add Livewire mount tests for representative components.
- Decide whether expected behavior is redirect-to-login or 403, then assert it consistently.

### TG-2: Export failure cleanup is not tested

**Evidence**

- Courier and marketplace notice export tests cover success paths, blocked statuses, confirmation, and download authorization.
- The export services write files before transaction creation, but there is no failure-path test proving file cleanup or absence of orphan files if the database work fails.

**Problem**

The current test suite can pass while storage/database consistency is not guaranteed on partial failure.

**Recommended Tests**

- Use `Storage::fake('local')`.
- Force an exception after file content is generated but before or inside batch creation.
- Assert no final file remains and no database export state is persisted.

## Questions / Product Decisions

1. Should marketplace shipping notice export require all selected orders to share the same marketplace, not only the same platform?
   - Evidence: `MarketplaceShippingNoticeExportService` blocks mixed tenant and mixed platform, then stores `$marketplace` from the first unique shop marketplace at `app/Services/MarketplaceShippingNotice/MarketplaceShippingNoticeExportService.php:168`.
   - If Amazon JP and Amazon US orders are selected together, the batch marketplace can become ambiguous even though platform is the same. This may be acceptable if the exported format is platform-wide, but it should be a product decision.

2. What is the intended public surface once authentication is implemented?
   - The code currently contains explicit TODOs saying the unauthenticated fallback should be removed later.
   - Decide whether routes like `/locale/{locale}` remain public, while operational pages and all export/download endpoints require login.

3. Should inventory movement migration history be rewritten while the project is still early-dev?
   - The current schema works and tests pass.
   - The cleanup is easier now than after more environments have run the migration.

## Self Review

- Rechecked and removed a potential finding about shipping-method search ambiguity. It was already fixed in commit `9969ce7`, and the targeted shipping/sales tests were reported green.
- Rechecked and removed a potential finding about `InventoryService` balance creation races. `InventoryService` now creates/reloads balances inside a transaction and catches unique constraint races.
- Rechecked and removed a potential finding about unsafe direct movement creation. `recordMovement()` is private and public stock changes go through service methods.
- Rechecked and removed a potential finding about fulfillment group stale selections. `FulfillmentGroupCreate::save()` locks selected orders and re-checks `order_status = pending` and `fulfillment_status = ready` inside the transaction.
- Kept the unauthenticated internal-user finding because it is supported by public route definitions, repeated TODOs, and concrete helper behavior.
- Kept the export file/transaction ordering finding because both courier and marketplace notice export services use the same storage-before-transaction pattern.
- Kept the duplicated auth helper finding because it appears across many components/controllers and directly increases the risk of inconsistent tenant-scope fixes.
- Kept the migration naming/drop cleanup finding because the schema is currently healthy, but the migration remains confusing and potentially destructive outside a fresh dev history.
