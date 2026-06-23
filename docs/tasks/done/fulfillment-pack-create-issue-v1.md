# Task: Create Issue From Fulfillment Pack Screen v1

## Stack

Laravel 13, Livewire 4 class components, Flux UI, PHP 8.3, SQLite dev. Plain Blade views only. No TypeScript.

---

## Goal

Let warehouse staff create an Issue directly from the pack check screen when they discover a problem while packing.

Current pack flow:

- staff opens `/fulfillment/pack`
- scans tracking no. to open `/fulfillment-groups/{group}/pack`
- scans product barcode / enters quantity
- marks shipped when complete

Problem:

If staff discovers missing stock, damaged goods, wrong item, barcode mismatch, or any other packing problem, they currently need to leave the pack screen and manually create an Issue elsewhere.

This task adds a direct Issue creation path from the pack screen.

---

## Scope

V1 creates Issue records only.

Do not change inventory in this task.
Do not auto-cancel / hold / unship orders in this task.
Do not create returns in this task.

The Issue is a traceable problem record linked to the fulfillment context.

---

## User Flow

On `/fulfillment-groups/{group}/pack`, staff can click:

```text
Create issue
```

The system opens the Issue Create page with context prefilled:

```text
tenant
fulfillment group
outbound order if available
sales order if line/order is known
SKU / stock item line if known
issue type
note
```

V1 can use a normal link / redirect.
No modal required.

---

## Routes

Existing routes likely include:

```text
/issues/create
/sales-orders/{order}/issues/create
```

Add a fulfillment-pack context route if needed:

```php
Route::get('/fulfillment-groups/{group}/issues/create', IssueCreate::class)
    ->name('fulfillment-groups.issues.create');
```

Alternative acceptable approach:

- keep `/issues/create`
- pass query params:

```text
/issues/create?fulfillment_group_id=1&outbound_order_id=2&sales_order_id=3&stock_item_id=4&sku_id=5
```

Prefer route-model context if cleaner.

---

## Data Model Check

Existing `issues` table already supports:

```text
tenant_id
sales_order_id nullable
outbound_order_id nullable
issue_type
status
note
```

Existing `issue_lines` table supports:

```text
issue_id
sales_order_line_id nullable
outbound_order_line_id nullable
sku_id nullable
stock_item_id nullable
qty
condition
action
note
```

If `issues.fulfillment_group_id` does not exist yet, add it.

### Add fulfillment_group_id if missing

Migration:

```php
Schema::table('issues', function (Blueprint $table): void {
    $table->foreignId('fulfillment_group_id')
        ->nullable()
        ->after('outbound_order_id')
        ->constrained('fulfillment_groups')
        ->nullOnDelete();

    $table->index(['fulfillment_group_id']);
});
```

Model:

```php
Issue::fillable add fulfillment_group_id
Issue::fulfillmentGroup(): BelongsTo
FulfillmentGroup::issues(): HasMany
```

If the column already exists, do not add a duplicate migration.

---

## Pack Page UI

Page:

```text
/fulfillment-groups/{group}/pack
```

Add a secondary action near the scan controls or page header:

```text
Create issue
```

Button style:

- not danger
- not primary if `Mark shipped` is already primary
- use outline/secondary style

Suggested placement:

- near the top group summary row, right side
- or next to `Back`

Do not place it too close to `Mark shipped` to avoid accidental click.

---

## Create Issue From Whole Group

Clicking the top-level `Create issue` should create an Issue context for the whole fulfillment group.

Prefill:

```text
tenant_id = group.tenant_id
fulfillment_group_id = group.id
outbound_order_id = group.outbound_order_id if available
issue_type = other or missing by default
status = open
note includes fulfillment group reference, optional
```

Do not automatically select all lines.
Staff can select or add lines on IssueCreate.

---

## Create Issue From Specific Pack Line

Optional but strongly recommended in v1:

Each pack line can have a small action:

```text
Issue
```

When clicked, open IssueCreate with line context.

Prefill:

```text
fulfillment_group_id
tenant_id
outbound_order_id if available
sales_order_id if line maps to exactly one sales order line
sales_order_line_id if known
sku_id
stock_item_id
qty = remaining qty or 1
condition = unknown
action = investigate
```

Important:

Pack lines can be aggregated across multiple sales order lines or bundle components.
If a pack line maps to multiple sales order lines, do not guess one sales order line.
In that case:

- prefill `sku_id` / `stock_item_id`
- leave sales_order_line_id null
- use a manual issue line

---

## IssueCreate Changes

Component:

```text
App\Livewire\IssueCreate
```

It already supports:

- create from sales order
- async sales order picker
- outbound order picker
- unknown/manual issue
- manual lines

Add support for fulfillment group context.

### Mount Inputs

Support one or both:

```php
public function mount(?SalesOrder $order = null, ?FulfillmentGroup $group = null): void
```

or query params:

```text
fulfillment_group_id
outbound_order_id
sales_order_id
sku_id
stock_item_id
qty
```

Use the route style that best fits the existing codebase.

### Tenant Scope

When loading group/order/line context:

- always scope by `allowedTenantIds()`
- guest must not be treated as internal
- tenant user can only create issue for own active tenant
- internal user can create for all tenants

### Prefill Rules

When fulfillment group is provided:

```text
tenantId = group.tenant_id
outboundOrderId = group.outbound_order_id if available
fulfillmentGroupId = group.id
```

If sales order is also provided:

- load sales order lines as existing create-from-sales-order flow does

If only SKU/stock item is provided:

- add one manual line with sku_id if known
- qty default = provided qty or 1

---

## Issue Types From Pack Problems

Use existing issue type options.

Suggested mapping for UI buttons later:

```text
missing stock -> missing
wrong item -> wrong_item if exists, else other
damaged item -> damaged
barcode mismatch -> other
```

Do not add new issue types unless the current enum/options already need them.
If adding type is necessary, update:

```text
Issue::typeOptions()
lang/en/issues.php
tests
```

---

## Pack Page Context Links

After creating an Issue:

- redirect to Issue detail page as current IssueCreate does
- from Issue detail, show linked fulfillment group if `fulfillment_group_id` exists
- from Fulfillment Group detail or Pack page, show linked Issues if practical

Minimum v1 requirement:

- Issue detail shows fulfillment group reference if linked
- Pack page button can create an Issue with the group link

---

## Tests

Add tests mainly in:

```text
tests/Feature/IssueTest.php
tests/Feature/FulfillmentGroupTest.php
```

Required tests:

1. pack page shows `Create issue` link for reserved group
2. pack page create issue link includes fulfillment group context
3. internal user can open IssueCreate from fulfillment group context
4. tenant user cannot open IssueCreate for another tenant's fulfillment group
5. guest cannot open IssueCreate from fulfillment group context
6. IssueCreate from fulfillment group pre-fills tenant id
7. IssueCreate from fulfillment group stores `fulfillment_group_id`
8. IssueCreate from fulfillment group stores `outbound_order_id` if group has outbound order
9. IssueCreate from pack line can create a manual issue line with sku/stock item context
10. aggregated line does not wrongly assign a sales_order_line_id when ambiguous
11. creating issue from pack page does not change inventory balances
12. creating issue from pack page does not change fulfillment group status
13. Issue detail shows linked fulfillment group reference
14. pack page or fulfillment group detail shows linked issue count/reference if implemented

Run targeted tests only:

```bash
php artisan test tests/Feature/IssueTest.php tests/Feature/FulfillmentGroupTest.php
```

Do not run full suite unless there is a specific cross-module concern.

---

## Do Not Do In This Task

Do not add:

- inventory adjustment from issue
- return order creation
- photo upload from pack page
- modal issue creation
- automatic hold/cancel/backorder
- packed status
- manager approval workflow

Those are later phases.

---

## Acceptance Criteria

- Staff can start an Issue directly from a pack screen.
- Issue is linked to the fulfillment group.
- Tenant scope is enforced.
- Issue creation does not change inventory or shipment status.
- Pack workflow remains usable and safe.
