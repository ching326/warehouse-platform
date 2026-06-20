# Repo Health Audit v1

## Goal

Audit the whole repository for duplicated code, conflicting logic, dead code, inconsistent schema/model/test behavior, and workflow gaps.

This task is **read-only**.

Do not modify production code.
Do not modify tests.
Do not modify migrations.
Do not fix anything yet.

The only file you should create or update is the audit report:

```text
docs/audits/repo-health-audit-v1-findings.md
```

After writing the report, review your own findings one more time and remove anything that is not actually a real issue.

## Output required

Create:

```text
docs/audits/repo-health-audit-v1-findings.md
```

The report must contain:

1. Executive summary
2. Findings grouped by priority
3. Evidence for each finding
4. Why it matters
5. Suggested solution
6. Whether tests are affected
7. Follow-up questions / product decisions
8. Self-review section confirming which findings were rechecked

Do not just list suspicions.
Every finding must cite concrete files, classes, routes, migrations, tests, or commands.

## Priority levels

Use these labels:

### P1 - Must fix

Real correctness, security, tenant-scope, data-loss, export-wrong-file, inventory-balance, or migration issue.

Examples:

- tenant user can access another tenant's data
- export can include wrong orders
- inventory movement/balance can drift
- migration creates broken schema
- duplicate route shadows another route
- status transition can create impossible state

### P2 - Should fix

Important consistency, maintainability, or likely bug.

Examples:

- two modules implement same logic differently
- model fillable/casts do not match migration
- seeder creates data that cannot exist through UI
- tests rely on old naming or old behavior
- service and UI validate different rules

### P3 - Cleanup

Dead code, old scaffold, unused views, unused lang keys, duplicate helpers, confusing names.

Examples:

- unused model / seeder / migration
- old docs in wrong place
- duplicated CSS class doing same job
- unused route/component

### Test gap

Missing test coverage for a real risk.

Only list a test gap if the underlying risk is real and important.

## Areas to audit

### 1. Routes and Livewire components

Check:

- `routes/web.php`
- `app/Livewire`
- `resources/views/livewire`

Look for:

- route actions pointing to missing classes
- route ordering problems
- routes/components no longer used
- duplicate pages for the same workflow
- UI buttons that call missing methods
- export/download routes that should not use `wire:navigate`

### 2. Database and models

Check:

- `database/migrations`
- `app/Models`
- `database/factories`
- `database/seeders`

Look for:

- migrations that create old/dead schemas
- migrations that immediately rebuild/drop earlier migrations
- model fillable/casts mismatch with columns
- missing relationships used by views/services/tests
- nullable columns that logic treats as required
- unique constraints that do not match product rules
- seed data inconsistent with real UI rules

### 3. Tenant scope and permissions

Check every module that reads or writes tenant data:

- inventory
- SKUs
- stock items
- inbound
- outbound
- sales orders
- fulfillment groups
- courier export
- marketplace shipping notice export specs
- Amazon SP-API connection/import
- setup pages

Look for:

- internal-only pages accessible by tenant users
- tenant user can pass another tenant id/order id/shop id
- download route can enumerate another tenant's file
- service validates tenant scope but UI/controller bypasses it

### 4. Inventory logic

Check:

- `InventoryService`
- inventory movements
- inventory balances
- inbound receiving
- outbound reserve/ship/cancel
- stock adjustment

Look for:

- movement and balance not updated in same transaction
- append-only movement violated
- race conditions around balance creation
- status/bucket movement semantics inconsistent
- location/bin receiving logic mismatch

### 5. Sales order workflow

Check:

- sales order create/import/detail/index
- line edit
- mark ready / unmark ready
- hold / release hold
- backorder / release backorder
- cancel / cancel request
- mark shipped
- fulfillment groups

Look for:

- impossible status combinations
- order hidden/shown incorrectly in default view
- shipped/cancelled orders still appearing where they should not
- actions available in wrong status
- bulk actions and detail actions behaving differently
- line status not respected in summaries/filters

### 6. Export/import flows

Check:

- sales order CSV/XLSX export
- Amazon report import
- courier export
- marketplace shipping notice export spec
- tracking no import/export

Look for:

- export uses different filters from index
- selected export can become full export when no IDs are selected
- duplicate import handling inconsistent
- already exported orders can be exported without confirmation
- wrong shipping method/carrier can be exported
- cancel requested/on hold/backorder incorrectly exportable
- files generated with wrong timezone/encoding/line endings

### 7. Shipping methods / carriers / marketplace mapping

Check:

- carriers
- shipping methods
- rates
- marketplace mappings
- shipping method dropdowns
- import mapping
- courier export carrier check

Look for:

- legacy `shipping_method` string and `shipping_method_id` diverge
- mapping row exists but missing required carrier code
- carrier/service naming inconsistent
- setup pages not internal-only
- hard delete possible where only deactivate should be allowed

### 8. Tests

Yes, audit test files too.

Check:

- `tests/Feature`
- `tests/Unit`

Look for:

- tests asserting old UI labels / old column counts / old route behavior
- tests that pass by testing implementation details instead of behavior
- duplicate tests covering same easy path but missing risky path
- tests that create impossible data
- tests that bypass tenant scope in unrealistic ways
- important workflows without regression tests

Do not rewrite tests in this task.
Only report issues and suggested test changes.

## Commands you may run

You may run read-only commands and tests.

Recommended:

```bash
php artisan test
php artisan route:list
php artisan migrate:status
composer validate
npm run build
git status --short
git log --oneline -20
```

You may also use:

```bash
rg
git grep
git diff
git show
```

Do not run destructive commands.
Do not run `migrate:fresh` unless explicitly agreed separately.

## Report format

Use this structure:

```md
# Repo Health Audit v1 Findings

## Executive Summary

- Total findings:
- P1:
- P2:
- P3:
- Test gaps:
- Commands run:

## P1 - Must Fix

### P1-1: Title

**Evidence**
- File / line / command output:

**Problem**
Explain the real issue.

**Why It Matters**
Explain user/data/business impact.

**Suggested Solution**
Explain the fix, but do not implement it.

**Tests Affected**
Existing tests likely affected:
New/changed tests recommended:

**Rechecked**
Yes / No, and how.

## P2 - Should Fix

...

## P3 - Cleanup

...

## Test Gaps

...

## Questions / Product Decisions

...

## Self Review

List findings you removed after rechecking and why.
List findings that remain and why they are real.
```

## Important self-review requirement

After writing the first draft of findings:

1. Re-open each finding.
2. Re-check the cited code.
3. Confirm it is still true.
4. Remove weak/speculative items.
5. If something is only a product preference, move it to "Questions / Product Decisions" instead of calling it a bug.

The final report should be high-signal.
Do not pad it with guesses.

