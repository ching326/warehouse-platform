# Task: SKU Deactivate + Safe Delete v1

## Stack

Laravel 13, Livewire 4 class-based components, Flux UI, SQLite dev/test, PHP 8.3.
Plain Blade views only. No TypeScript.

Use plain ASCII punctuation only in code, Blade, and lang files.

---

## Goal

Add a safe way to remove SKUs from normal daily use without breaking historical records.

Primary behavior:

- **Deactivate** is the normal "delete" action for used SKUs.
- Deactivated SKUs are hidden from normal SKU lists and new-order selectors.
- Existing orders, imports, movements, pack scans, returns, issues, and audit history still show the SKU.

Optional hard delete:

- **Permanent delete** is allowed only if the SKU has no related business/history records.
- If hard delete is blocked by foreign keys or usage checks, show a clear message and offer deactivation instead.

Do not delete stock items. This task is about SKU records only.

---

## Current State

Relevant model/table:

- `skus.status` already exists and uses values like `active`.
- Many workflows already filter active SKUs for creation/import/selection.
- SKU index lives in `App\Livewire\SkusIndex`.
- SKU create lives in `App\Livewire\SkuCreate`.
- SKU table views exist: detailed, catalog, marketplace, logistics.

Important rule:

- A SKU may already be referenced by sales order lines, bundle components, barcode aliases, imports,
  outbound/inbound flows, pack scans, returns, or issue lines. Those records must stay readable.

---

## Terminology

Use these labels in UI:

- `Deactivate` - hide the SKU from normal use.
- `Reactivate` - make it active again.
- `Delete permanently` - hard delete an unused SKU.

Do not label the normal action as just `Delete`, because it suggests data will be removed.

---

## Part A: Status Behavior

### Deactivate

When a SKU is deactivated:

- set `skus.status = 'inactive'`.
- do not touch `stock_item_id`.
- do not touch linked stock item, barcode aliases, sales order lines, or movement history.
- flash a success message.

### Reactivate

When reactivated:

- set `skus.status = 'active'`.
- keep all existing links unchanged.
- flash a success message.

### Default visibility

`/skus` should default to active SKUs only.

Add a Status filter:

- Active
- Inactive
- All

Default is `Active`.

Inactive rows:

- show an `Inactive` badge.
- can be viewed in inactive/all filters.
- should not appear in default active-only view.

---

## Part B: Safe Hard Delete

### Hard-delete eligibility

Add a helper on `Sku`, for example:

```php
public function canBeDeleted(): bool
{
    return ! $this->hasBusinessUsage();
}
```

The exact implementation can be service-based if cleaner, but keep one clear source of truth.

Hard delete is allowed only if the SKU has no meaningful related rows.

Minimum relationships/usages to check:

- `sales_order_lines.sku_id`
- `inbound_order_lines.sku_id`
- `outbound_order_lines.sku_id`
- `sku_bundle_components.bundle_sku_id`
- `sku_bundle_components.component_sku_id`
- `barcode_aliases` with `model_type = 'sku'` and this SKU id
- `issue_lines.sku_id` if present
- `return_order_lines.sku_id` if present
- any pack scan / fulfillment scan table that references SKU directly, if present

Use `Schema::hasTable()` only if some of these tables are optional in the current migration history.
Do not fail just because a future table is not present.

If the SKU is unused:

- allow permanent delete.
- delete SKU-owned non-history records if appropriate, such as SKU barcode aliases.
- do not delete the linked stock item.

If delete is not allowed:

- do not attempt hard delete.
- show message:
  - `This SKU is used by orders or history records. Deactivate it instead.`
- provide/keep a `Deactivate` action.

### DB FK fallback

Even with usage checks, a hard delete can still fail due to a FK.

Catch query/FK exceptions and show the same friendly message. Do not expose raw SQL errors.

---

## Part C: SKU Index UI

On `/skus`:

1. Add Status filter near existing filters.
2. Add action buttons per SKU row:
   - Active SKU:
     - `Deactivate`
     - `Delete permanently` only if `canBeDeleted()` is true.
   - Inactive SKU:
     - `Reactivate`
     - `Delete permanently` only if `canBeDeleted()` is true.

Button styling:

- `Deactivate` and `Reactivate`: normal teal primary/outline style consistent with existing page actions.
- `Delete permanently`: red danger style.

Confirm messages:

Deactivate:

```text
This SKU will be hidden from normal lists and cannot be selected for new orders. Existing orders and history will remain unchanged.
```

Permanent delete:

```text
Delete this unused SKU permanently? This cannot be undone.
```

Do not add bulk deactivate/delete in v1.

---

## Part D: Creation and Selection Rules

Inactive SKUs must not appear in new operational selectors.

Verify or update these places:

- Sales order create/manual order line selector
- Paste/grid import SKU validation
- Amazon report/API import SKU matching
- Inbound create SKU selector
- Outbound create SKU selector
- Fulfillment pack scan barcode/SKU matching, where relevant
- Return order create/receive selectors
- Issue create selectors

Rule:

- For new work, only `status = active` SKUs are selectable or valid.
- For historical display, inactive SKUs still display normally.

Important:

- If an existing order has an inactive SKU, the order detail should still show the SKU.
- If an import file contains an inactive SKU, treat it as not available for new import.
  Error message should be clear:
  - `SKU is inactive. Reactivate it before importing new orders.`

Pack scan behavior:

- If scanning a barcode that maps only to an inactive SKU, do not count it as a valid scan for new packing.
- Show a warning:
  - `This SKU is inactive.`

Do not overbuild pack-scan changes if the current service already only works from order lines.
Just make sure direct lookup paths do not select inactive SKUs for new work.

---

## Part E: Tenant Scope and Authorization

All SKU actions must be tenant-scoped.

Rules:

- Internal users can act on SKUs from any tenant.
- Tenant users can act only on SKUs belonging to their active tenant IDs.
- Guests are not internal.

Do not rely on client-provided tenant IDs.
Load the SKU through the same scoped query used by the SKU index.

Out-of-scope SKU action should return 404 or 403 consistently with existing SKU pages.

---

## Part F: Activity Log

If the repo's activity log pattern is active for SKU-related changes, log:

- SKU deactivated
- SKU reactivated
- SKU permanently deleted

At minimum, the action should be visible through normal audit logs if this model already logs changes.

Do not log sensitive data. SKU codes/names are fine.

---

## Part G: Language Keys

Add English keys only. Other locale files should keep fallback behavior unless this repo has already translated this page.

Suggested keys:

- `skus.filter_status`
- `skus.status_active`
- `skus.status_inactive`
- `skus.status_all`
- `skus.action_deactivate`
- `skus.action_reactivate`
- `skus.action_delete_permanently`
- `skus.confirm_deactivate`
- `skus.confirm_delete_permanently`
- `skus.deactivated`
- `skus.reactivated`
- `skus.deleted`
- `skus.delete_blocked_deactivate_instead`
- `skus.inactive_import_blocked`
- `skus.inactive_scan_blocked`

Use the existing lang file naming style if SKU labels currently live somewhere else.

---

## Part H: Tests

Add/update targeted tests. Do not run the full suite by default unless targeted failures suggest a broader regression.

Required tests:

1. `test_sku_can_be_deactivated`
   - active SKU becomes inactive.
   - stock item remains.

2. `test_deactivated_sku_is_hidden_from_default_sku_index`
   - default `/skus` does not show inactive SKU.
   - `status=inactive` or `status=all` shows it.

3. `test_sku_can_be_reactivated`
   - inactive SKU becomes active again.

4. `test_unused_sku_can_be_permanently_deleted`
   - SKU row deleted.
   - linked stock item remains.

5. `test_used_sku_cannot_be_permanently_deleted`
   - create a sales order line or another business reference.
   - delete action is blocked.
   - SKU still exists.
   - deactivation still works.

6. `test_tenant_user_cannot_deactivate_other_tenants_sku`
   - spoofing id/action does not modify another tenant's SKU.

7. `test_inactive_sku_is_rejected_by_sales_order_import`
   - inactive SKU in import grid/report produces a clear error.

8. `test_existing_order_with_inactive_sku_still_renders`
   - old order detail/index still displays the SKU.

Optional tests if touched:

9. `test_inactive_sku_does_not_appear_in_inbound_selector`
10. `test_inactive_sku_does_not_appear_in_outbound_selector`
11. `test_pack_scan_warns_for_inactive_sku_lookup`

---

## Acceptance Criteria

- Active SKUs can be deactivated.
- Inactive SKUs are hidden from default `/skus`.
- Inactive SKUs can be shown through filter and reactivated.
- Unused SKUs can be permanently deleted.
- Used SKUs cannot be permanently deleted and produce a friendly message.
- Hard delete never deletes stock items.
- New order/import/operation flows do not select inactive SKUs.
- Historical records continue to display inactive SKUs.
- Tenant users cannot modify SKUs outside their tenant scope.
- Targeted tests pass.

---

## Out of Scope

- Bulk deactivate/delete.
- Stock item deletion.
- Full archive module.
- Automatic cleanup of unused stock items.
- UI redesign of the SKU page beyond the status filter/actions.
- Changing old historical records.
