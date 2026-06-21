# Project State: KuraLinks WMS

Last updated: 2026-06-21

This file is the current project truth for AI agents and humans. Read it before planning,
reviewing, or coding. For agent behavior rules, also read `docs/AGENT_BRIEF.md`.

## Current Product Direction

KuraLinks is a multi-tenant overseas warehouse / 3PL platform.

Core idea:

- Tenant = a company using the warehouse service.
- Tenant can have users, shops, SKUs, stock items, inbound/outbound orders, sales orders,
  fulfillment groups, issues, and return orders.
- Internal staff can see/manage all tenants.
- Tenant users should only see their own active tenant data.

Primary stack:

- Laravel 13
- Livewire 4 class-based components
- Flux UI
- SQLite for local development
- PHP 8.3 via Laragon on Windows

Use this PHP path when `php` is not on PATH:

```powershell
C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe
```

If using WSL/bash:

```bash
/mnt/c/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe
```

## Naming Decisions

Use current names below:

- `Issues`, not `Exception Cases`
- `/issues`, not `/exception-cases`
- `Issue` / `IssueLine`, not `ExceptionCase` / `ExceptionCaseLine`
- `issues` / `issue_lines`, not `exception_cases` / `exception_case_lines`
- `issue_no` uses `ISS-YYYYMMDD-0001`

Known cosmetic historical mismatch:

- Migration files named `2026_06_21_000001_create_exception_cases_table.php` and
  `2026_06_21_000002_create_exception_case_lines_table.php` now create `issues` and
  `issue_lines` because the module was renamed.
- A later migration `2026_06_21_000003_rename_exception_cases_to_issues.php` handles
  existing local DBs that still had old table names.
- Do not report this filename mismatch as a new bug unless it causes a real failure.

## Implemented / Mostly Working Modules

These modules exist in code and have tests around them:

- Tenants
- Tenant users / user tenant linkage
- Shops
- Warehouses
- Warehouse locations
- Packaging materials
- Product types / multilingual options
- Stock items
- SKUs and SKU bundles
- Inventory balances
- Inventory movements ledger
- Manual stock adjustment
- Inbound orders and receiving
- Outbound orders, shipping, cancellation flow
- Sales orders
- Sales order CSV/XLSX import/export
- Amazon order report import
- Amazon SP-API connection settings and API import groundwork
- Fulfillment groups
- Courier CSV export
- Marketplace shipping notice export
- Shipping methods, carriers, rates, and marketplace mappings
- Issues
- Return Orders, at least initial implementation files/migrations

## Important Architecture Rules

### Tenant Scope

Every query returning tenant data must scope by the allowed tenant IDs.

Common helper names:

- `allowedTenantIds()`
- `visibleTenantIds()`

Do not introduce guest-as-internal behavior. Current routes are protected by local
`authenticated` middleware, but helpers should still treat guests as not internal.

### Inventory

All stock changes must go through `App\Services\InventoryService`.

Do not write `inventory_movements` or `inventory_balances` directly from feature code.

Current stock-changing methods:

- `receiveStock`
- `adjustStock`
- `reserveStock`
- `releaseReserve`
- `shipReservedStock`
- `placeHold`
- `releaseHold`
- `markDamaged`

Important:

- `receiveStock()` brings new stock into the warehouse.
- `markDamaged()` and `placeHold()` move existing available stock into damaged/hold buckets.
- For returned damaged/hold goods, receive first, then mark damaged/place hold in the same
  transaction.

Inventory movements must carry:

- `ref_type`
- `ref_id`
- `user_id` where available

Examples:

- `inbound_order`
- `outbound_order`
- `fulfillment_group`
- `manual_adjustment`
- `return_order`

### Sales Order Filters

Shared sales order filtering lives in:

```text
app/Support/SalesOrderFilters.php
```

Index and export should use the same filter logic to avoid divergence.

`order_date` exists to avoid slow `COALESCE(platform_ordered_at, created_at)` filtering.

## Current Work / Active Specs

Open task specs in `docs/tasks`:

- `issues-v1.md`
- `issues-order-search-ui-v1.md`
- `outbound-order-detail-cancel-v1.md`
- `return-orders-v1.md`

Current likely active task:

- `issues-order-search-ui-v1.md`
  - Replace internal ID filters on `/issues`.
  - Search sales orders by real order number/tracking/recipient.
  - Search outbound orders by real outbound reference.
  - Move Sales Order filter to next row.
  - Make global search wider and right-aligned.
  - Convert Sales Order and Outbound Order pickers on `/issues/create` to async/search pickers.

Recent user request:

- Hide `resolved` and `closed` Issues by default.
- Limit Issue status badge colors:
  - `open` = red
  - `resolved` / `closed` = green
  - all others = blue

This was implemented and `IssueTest` passed at the time of writing.

## Half-Done / Needs Care

### Return Orders

Return Orders have files/migrations/components present:

- `ReturnOrderIndex`
- `ReturnOrderCreate`
- `ReturnOrderShow`
- `ReturnOrderReceive`
- `ReturnOrderInspect`
- `ReturnOrderDisposition`

The task spec is in:

```text
docs/tasks/return-orders-v1.md
```

Important design:

- Return Orders are physical returned parcels / return ASNs.
- Issues are problem/claim records.
- A Return Order may link to an Issue, but Return Orders own physical receiving and
  inventory disposition.
- Return inventory movements use `ref_type = return_order`, not `issue`.

Before editing Return Orders, compare implementation to the spec. Some code may be initial
or rough and may need cleanup/testing.

### Issues

Issues were renamed from Exception Cases.

Current routes:

- `/issues`
- `/issues/create`
- `/issues/{issue}`
- `/sales-orders/{order}/issues/create`

Current model/table:

- `Issue`
- `IssueLine`
- `issues`
- `issue_lines`

Known next improvement:

- Search/link by real order numbers instead of internal table IDs.
- See `docs/tasks/issues-order-search-ui-v1.md`.

### Working Tree

The repo may be dirty with user/agent changes.

Do not revert unrelated files.
Do not run destructive git commands.
Before editing, inspect `git status --short`.

## Known Non-Issues / Do Not Report Without New Proof

- Full-suite file upload temp pollution can make some import/export tests flaky. If a full
  suite failure appears, rerun the single failing test file before reporting it.
- Migration filename/content mismatch for the old Exception Cases -> Issues rename is
  cosmetic unless a real migration failure is proven.
- Some documents in `docs/tasks/done` describe older implementation decisions and may not
  match the current code exactly.

## Things Worth Auditing

Good audit targets for a read-only agent:

1. Tenant-scope leaks in Livewire components/controllers.
2. Inventory changes that bypass `InventoryService`.
3. Query bugs after joins: ambiguous columns, missing `whereIn(tenant_id, ...)`, N+1 loops.
4. Migrations that do not match models.
5. Issues module after rename.
6. Return Orders implementation vs `docs/tasks/return-orders-v1.md`.
7. Sales order export/import filters vs `SalesOrderFilters`.
8. Shipping method/carrier mapping behavior for courier export and marketplace shipping notices.

## Recommended Test Commands

Use:

```powershell
C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan test
```

Run targeted tests:

```powershell
C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan test tests\Feature\IssueTest.php
C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan test tests\Feature\SalesOrderTest.php
C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan test tests\Feature\OutboundOrderTest.php
C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan test tests\Feature\ShippingMethodTest.php
```

If full suite times out or fails:

- rerun the failing file alone
- report isolated confirmed failures only

## Next Priorities

Suggested order:

1. Finish Issues order-search UI (`issues-order-search-ui-v1.md`).
2. Review/finish Return Orders against `return-orders-v1.md`.
3. Run a Qwen/Hermes read-only repo health audit using `docs/AGENT_BRIEF.md`.
4. Fix confirmed audit findings one by one.
5. Continue marketplace/API integrations after the core WMS flows are stable.

## Instruction For Other Agents

Before doing repo work:

1. Read this file.
2. Read `docs/AGENT_BRIEF.md`.
3. Check `git status --short`.
4. Start read-only unless explicitly asked to implement.
5. Keep findings proven with file/line/test evidence.
