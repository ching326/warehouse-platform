# Repo Health Review From Clean Thread

Review date: 2026-06-21

Scope: read-only repository health review for confirmed, code-backed issues. Production code was not edited.

## Confirmed Findings

### [P1] Tenant allowed-ID helpers ignore `tenants.status`, so inactive tenants can remain visible through an active tenant-user link

- Location: `app/Livewire/SalesOrderIndex.php:967`
- Also seen at: `app/Livewire/InventoryIndex.php:288`, `app/Livewire/InboundOrderIndex.php:150`, `app/Livewire/ReturnOrderIndex.php:60`, and the same `tenantUsers()->where('status', 'active')->pluck('tenant_id')` pattern in multiple controllers/components.
- Why it is wrong: The project rule says tenant users should only see their own active tenant data. The database has a tenant status column (`database/migrations/2026_06_18_000002_create_tenants_table.php:22`), and other code treats inactive tenants as blocked for new setup flows, but allowed/visible tenant helpers only check `tenant_users.status = active`.
- Proof: `SalesOrderIndex::allowedTenantIds()` returns tenant IDs from the pivot at `app/Livewire/SalesOrderIndex.php:967-970` without joining/filtering `tenants.status`. `InventoryIndex::visibleTenantIds()` does the same at `app/Livewire/InventoryIndex.php:288-290`. An active tenant-user link to an inactive tenant would still pass these scopes.
- Suggested fix: Centralize allowed tenant resolution and filter tenant users through the related tenant, for example `tenantUsers()->where('status', 'active')->whereHas('tenant', fn ($q) => $q->where('status', 'active'))`, while preserving internal-user behavior.
- Suggested test: Add tenant-scope tests where a tenant user has an active `tenant_users` row for a `tenants.status = inactive` tenant; assert Sales Orders, Inventory, Inbound, Outbound, Issues, Return Orders, exports, and downloads return 403 or empty results as appropriate.

### [P1] Return disposition can be confirmed repeatedly and duplicate inventory movements

- Location: `app/Livewire/ReturnOrderDisposition.php:22`
- Why it is wrong: Confirming disposition should apply inventory effects once. The current method loops every line on every submit, updates the line disposition, and calls `applyInventory()` for each non-undecided line without checking whether the line was already dispositioned or whether movements were already applied.
- Proof: `confirmDisposition()` calls `$this->applyInventory($inventory, $order, $line->refresh())` inside the line loop at `app/Livewire/ReturnOrderDisposition.php:22`. `applyInventory()` then calls `receiveStock()`, `markDamaged()`, or `placeHold()` at `app/Livewire/ReturnOrderDisposition.php:25`. There is no guard on existing `dispositioned_at`, no movement reference lookup, and no stored movement ID on `return_order_lines`, so a second call re-receives the same returned quantity.
- Suggested fix: Make disposition idempotent. Skip lines already dispositioned, block confirm when the order is already `dispositioned`/`closed`, or store per-line inventory application metadata and enforce one-time application in the transaction.
- Suggested test: Add a test that confirms a `return_to_inventory` disposition twice and asserts only one receive movement exists and the inventory balance is increased once.

### [P2] Return receiving accepts negative or otherwise unvalidated received quantities

- Location: `app/Livewire/ReturnOrderReceive.php:21`
- Why it is wrong: Backend state can be mutated to invalid return quantities. Browser `min` attributes do not protect Livewire method calls, tests, or crafted requests.
- Proof: `saveReceive()` casts the submitted draft directly with `(int)($draft['received_qty'] ?? 0)` at `app/Livewire/ReturnOrderReceive.php:21`. Unexpected lines do the same at `app/Livewire/ReturnOrderReceive.php:22`. There is no validator requiring integer `min:0` for existing lines or `min:1` for unexpected lines.
- Suggested fix: Validate `lineDrafts.*.received_qty` as integer `min:0`, `newLines.*.received_qty` as integer `min:1`, and validate selected SKUs/locations against the order tenant/warehouse before updates.
- Suggested test: Add Livewire tests that set an existing line received qty to `-1` and an unexpected line received qty to `0`/`-1`, then assert validation errors and unchanged database rows.

### [P2] Return disposition accepts arbitrary disposition strings

- Location: `app/Livewire/ReturnOrderDisposition.php:22`
- Why it is wrong: The line disposition is an enum-like string with defined constants, but the backend writes whatever comes from `lineDrafts`. Invalid values can put a return order into `dispositioned` state while no intended inventory action runs.
- Proof: `confirmDisposition()` assigns `$disposition = $draft['disposition'] ?? ReturnOrderLine::DISPOSITION_UNDECIDED` and immediately persists it at `app/Livewire/ReturnOrderDisposition.php:22`. The allowed values exist in `ReturnOrderLine::dispositionOptions()` at `app/Models/ReturnOrderLine.php:44`, but `confirmDisposition()` never validates against them.
- Suggested fix: Validate each `lineDrafts.*.disposition` with `Rule::in(array_keys(ReturnOrderLine::dispositionOptions()))` before updating any line, and validate disposition locations when required.
- Suggested test: Add a Livewire test that submits `lineDrafts.{id}.disposition = "not_a_real_disposition"` and asserts validation fails, the line remains unchanged, and the order is not marked dispositioned.

### [P2] Return Order test coverage is far below the module spec and misses permission/workflow regressions

- Location: `tests/Feature/ReturnOrderTest.php:29`
- Why it is wrong: Return Orders are marked as half-done/needs-care in project state, and the spec lists critical tenant-scope, permission, workflow, search, and inventory tests. The current test file covers only a small subset, so high-risk defects above are not protected.
- Proof: The spec requires 33 tests at `docs/tasks/return-orders-v1.md:929-963`. The current test file defines only five tests at `tests/Feature/ReturnOrderTest.php:29`, `tests/Feature/ReturnOrderTest.php:37`, `tests/Feature/ReturnOrderTest.php:53`, `tests/Feature/ReturnOrderTest.php:82`, and `tests/Feature/ReturnOrderTest.php:102`. Missing examples include tenant visibility, tenant create/forbidden create, tenant cannot receive/inspect/disposition, closed returns read-only, hold quarantine disposition, unexpected lines, search, and filters.
- Suggested fix: Expand `ReturnOrderTest` against the spec before further feature work, especially around tenant permissions and idempotent inventory behavior.
- Suggested test: Implement the missing spec tests from `docs/tasks/return-orders-v1.md:929-963`, prioritizing tenant scope, staff-only actions, closed/read-only behavior, repeated disposition, and invalid receive/disposition input.

## Needs Verification

### [P2] Return Order cancel action is hidden from tenants but callable for their own draft/announced returns

- Location: `app/Livewire/ReturnOrderShow.php:41`
- Why it needs verification: The view hides all workflow buttons behind `$isInternal` at `resources/views/livewire/return-order-show.blade.php:4`, but `cancelReturn()` does not call `staffOnly()` before updating the order. The spec says tenants can edit draft/announced returns, so tenant cancellation might be intended, but the UI/backend policy is inconsistent.
- Proof: `markArrived()` and `closeReturn()` call `staffOnly()` at `app/Livewire/ReturnOrderShow.php:27` and `app/Livewire/ReturnOrderShow.php:36`; `cancelReturn()` updates status at `app/Livewire/ReturnOrderShow.php:45` without the same guard.
- Suggested fix: Decide the intended policy. If only staff can cancel, add `staffOnly()` and a tenant-forbidden test. If tenants can cancel pre-arrival returns, expose a tenant-safe cancel control and add tests for allowed/blocked statuses.
- Suggested test: Add tests for tenant cancellation of `announced`, `arrived`, `received`, and `closed` returns matching the chosen policy.

## Checked And No Issue Found

- The known Exception Cases to Issues migration filename/content mismatch was not reported as a new bug.
- Sales order shared filtering is used by index/export paths and the suspected `short_name` search column exists in the stock item migration.
- Inventory-changing feature paths found in the reviewed code use `InventoryService`; Return Orders correctly use `ref_type = return_order` for disposition movements.
- Inventory and sales order targeted tests passed during review.

## Test Results

- `php artisan test tests\Feature\ReturnOrderTest.php`: passed, 5 tests, 22 assertions.
- `php artisan test tests\Feature\SalesOrderTest.php tests\Feature\SalesOrderExportTest.php`: passed, 125 tests, 439 assertions.
- `php artisan test`: passed, 481 tests, 1869 assertions.
