# Repo Review Findings

## Confirmed Findings
*(None)*

## Needs Verification
### [P2] Global Carriers vs. Tenant-Specific Use
- Location: `app/Models/Carrier.php`
- Why it is wrong: The `Carrier` model does not implement tenant scoping (`tenant_id` column or global scope). While `Carrier` appears to be intended as a global resource, it's worth verifying that no tenant-specific data is being inadvertently attached to or filtered by a global `Carrier` instance in a way that could lead to data leakage or incorrect data representation if carrier-specific logic were ever to become tenant-dependent.
- Proof: `app/Models/Carrier.php` lacks a `tenant_id` field and `Carrier::where('status', 'active')` queries are used directly in Livewire components without further tenant constraints, implying they are global.
- Suggested fix: Confirm whether Carriers are truly intended to be global and if so, document this assumption clearly. If there's any potential for tenant-specific carrier data, consider adding a `tenant_id` and appropriate scoping.
- Tests to add/run: Add a test case that creates a carrier for one tenant and attempts to access it from another tenant context, asserting that it should either be globally visible or restricted as per design intent.

### [P3] Duplicate Migration Timestamps
- Location: `database/migrations/2026_06_21_000003_add_default_shipping_method_to_skus_table.php` and `database/migrations/2026_06_21_000003_rename_exception_cases_to_issues.php`
- Location: `database/migrations/2026_06_23_000001_add_cartons_to_inbound_orders_table.php` and `database/migrations/2026_06_23_000001_backfill_normalized_tracking_numbers.php`
- Why it is wrong: While not strictly a bug, having multiple migration files with the same timestamp (differing only by suffix) can sometimes lead to unexpected ordering issues on systems with different file sorting mechanisms or when developers manually reorder files. Laravel generally handles this by alphabetical order of the suffix, but it's a minor point of potential ambiguity.
- Proof: Directory listing of `database/migrations` shows two pairs of files sharing the same timestamp.
- Suggested fix: When creating new migrations, ensure timestamps are unique to avoid potential ordering ambiguities. This is a best practice for clarity and robustness, though Laravel often resolves it gracefully.
- Tests to add/run: N/A, this is a structural observation.

## Checked And No Issue Found
- **Tenant-scope leaks**: The `SalesOrderFilters` (specifically `applyToOrderQuery` on line 60) and the `allowedTenantIds()` methods in Livewire components correctly apply `tenant_id` scoping to queries for `SalesOrder`, `OutboundOrder`, and `Sku` models. No explicit tenant-scope leaks were found in the reviewed components.
- **Inventory movement correctness**: A search for direct manipulation of `inventory_movements` outside of `App\Services\InventoryService` yielded no results, indicating that the architectural rule for using the service is being followed.
- **Query / relationship bugs**: In `app/Livewire/IssueCreate.php`, eager loading (`with()`) is appropriately used for relationships (`lines.sku.stockItem`, `warehouse`, `fulfillmentGroup.orders`, `stockItem`) where N+1 queries would typically occur, mitigating potential performance issues. Other `->get()` calls retrieve simple lists of IDs/names which do not suggest N+1 problems.
- **Migration/schema mismatch**: The known `exception_cases` to `issues` table rename is present in `database/migrations`, as noted in `AGENT_BRIEF.md`. No other significant functional migration mismatches were identified.
- **Duplicated or conflicting code**: No obvious instances of duplicated or conflicting code were immediately apparent during this audit.

## Test Results
All 599 tests passed (2347 assertions) in 162.91 seconds. This indicates a high level of correctness and stability in the existing codebase.
