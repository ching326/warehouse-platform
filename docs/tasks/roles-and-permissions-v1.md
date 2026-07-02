# Roles and Permissions v1

## Goal

Make the system safe for internal staging use by defining clear user roles and action permissions.

This task is about access control, not UI redesign.

No read-only role in v1.

## Roles

Use four roles:

1. `internal_admin`
2. `warehouse_staff`
3. `tenant_admin`
4. `tenant_staff`

Current `users.user_type` may already distinguish internal vs tenant users. This task should add or normalize a role field only if needed, but avoid overbuilding a permission package unless the current code needs it.

Recommended model:

- `users.user_type`
  - `internal`
  - `tenant`
- `users.role`
  - for internal users only:
    - `internal_admin`
    - `warehouse_staff`
- `tenant_users.role`
  - for tenant membership roles:
    - `admin`
    - `staff`

Important: `tenant_users.role` already exists and is per tenant membership. Do not duplicate tenant roles into `users.role`.

Tenant role mapping:

- `tenant_users.role = admin` -> tenant admin capability for that tenant
- `tenant_users.role = staff` -> tenant staff capability for that tenant

This keeps support for a user who may be admin of tenant A and staff of tenant B.

If adding `users.role`, backfill:

- existing internal users -> `internal_admin`
- existing tenant users -> leave `users.role` null

Fail closed:

- internal user with null/unknown role is not admin
- tenant user permission comes from active `tenant_users` membership only
- inactive users must be denied regardless of role

Do not allow creating `warehouse_staff` users until the internal-admin-only gates in this task are implemented.

## Core Rules

### Internal Admin

Can do everything:

- manage tenants
- manage shops
- manage users
- manage warehouses
- manage carriers / shipping methods
- manage packaging / locations / setup data
- view and operate all tenant data
- export and download files
- billing setup and invoice generation

### Warehouse Staff

Can operate warehouse workflows across all tenants in v1, but cannot manage high-risk setup.

Allowed:

- inventory overview
- inventory movements
- stock adjustments
- stock adjustment import
- stock count
- inbound orders
- outbound orders
- fulfillment / order fulfillment
- pick summary
- scan pack
- courier CSV / Label10 export
- print history download
- issue / return receiving operations

Not allowed:

- tenant setup
- shop setup
- user management
- carrier CRUD
- shipping method CRUD, except selecting/remapping shipping method during operations if already allowed
- billing setup / invoice generation
- Amazon/SP-API credentials

### Tenant Admin

Can manage their own tenant's commercial/order data.

Implementation role source:

- `users.user_type = tenant`
- active `tenant_users` membership for the target tenant
- `tenant_users.role = admin`

Allowed, scoped to their tenant only:

- SKU master
- SKU import
- product images
- sales order / order import
- sales order paste import
- sales order reship
- returns pre-announcement
- issues
- view own outbound/fulfillment status
- view own inventory
- view own stock movements if currently exposed to tenant users
- manage tenant users, if user management exists for tenants

Not allowed:

- other tenants' data
- warehouse-wide operations that affect physical stock outside their tenant scope
- stock adjustment
- stock count
- inbound receive confirmation
- outbound ship confirmation
- courier export
- shipping method CRUD
- carrier CRUD
- warehouse/location setup
- billing generation
- API credential setup unless explicitly approved later

### Tenant Staff

Can do daily tenant-side input work, scoped to their tenant only.

Implementation role source:

- `users.user_type = tenant`
- active `tenant_users` membership for the target tenant
- `tenant_users.role = staff`

Allowed:

- SKU view/edit/import if tenant admin allows same workflow in v1
- sales order import / paste import
- create manual platform/non-platform shipping requests if available
- returns pre-announcement
- issues creation
- view own orders / returns / issues / inventory status

Not allowed:

- tenant user management
- billing
- warehouse operations
- shipping/export operations
- setup master data

If v1 does not need a functional difference between tenant admin and tenant staff yet, still store the role now and enforce the same permissions temporarily. Document the future split.

## Rollout Order

This retrofit must be fail-closed during rollout.

Order:

1. Add `users.role` for internal users only.
2. Backfill all existing internal users to `internal_admin`.
3. Add shared capability helpers / gates.
4. Add `internal_admin` gates to all setup, billing, user-management, Amazon/SP-API, and other high-risk pages.
5. Tighten direct controllers and download routes.
6. Add tests for the highest-risk boundaries.
7. Only after the above is merged, allow user create/edit UI to create `warehouse_staff`.

Reason: currently many pages gate only by `users.user_type === internal`. If a `warehouse_staff` account can be created before the new gates land, that user would temporarily inherit admin-level access.

## Page / Action Matrix

Before implementation, create a route inventory:

- route URI
- route name
- component/controller
- required capability
- tenant-scope rule

The matrix below is the intended policy, but the implementation should check all current routes in `routes/web.php`, not only the pages listed here.

Default rule:

- unknown/unmapped route or action should deny access until assigned a capability
- do not rely on nav/sidebar visibility as authorization

### Setup

Internal admin only:

- `/setup/tenants`
- `/setup/shops`
- `/setup/warehouses`
- `/setup/locations`
- `/setup/packagings`
- `/setup/shipping-methods`
- `/setup/fba-warehouses`
- product type settings
- other settings
- carrier CRUD inside shipping methods

Warehouse staff, tenant admin, tenant staff:

- no access

### Users

Internal admin:

- manage all users

Tenant admin:

- manage users under own tenant only, if this page exists in v1

Warehouse staff / tenant staff:

- no user management

### SKU / Stock Item

Internal admin:

- all tenants

Tenant admin / tenant staff:

- own tenant only
- create/edit/import/deactivate SKU if currently part of tenant workflow
- cannot access other tenant data

Warehouse staff:

- view SKU/stock item data needed for warehouse operations
- no tenant commercial-field editing in v1
- field-level SKU edit permissions are deferred to a later task if warehouse-specific edit needs become clear

### Inventory

Internal admin:

- all inventory operations

Warehouse staff:

- inventory overview
- inventory movements
- stock adjustments
- stock adjustment import
- stock count

Tenant admin / tenant staff:

- view own inventory only, if inventory page is exposed
- no adjustment / stock count / movement mutation

### Inbound

Internal admin / warehouse staff:

- create
- mark arrived
- receive
- cancel if allowed by current workflow

Tenant admin / tenant staff:

- view own inbound if exposed
- no receive confirmation

### Outbound

Internal admin / warehouse staff:

- create operational outbound
- pending/reserve/ship/cancel/hold/release
- direct pack / scan pack
- courier cost entry

Tenant admin / tenant staff:

- create tenant-side shipping requests if available
- view own outbound status
- no ship confirmation
- no courier export
- no warehouse hold/release unless explicitly enabled later

### Sales Order / Order Import

Internal admin:

- all tenants

Tenant admin / tenant staff:

- own tenant only
- import orders
- paste import
- edit order fields allowed by current workflow
- hold/release before warehouse processing if current business rule allows
- create reship

Warehouse staff:

- view order information needed for fulfillment
- should work mainly from Order Fulfillment / Outbound pages

### Order Fulfillment

Internal admin / warehouse staff:

- view fulfillment queue
- fill missing shipping
- export courier CSV / Label10
- print history
- requeue print
- hold/release outbound
- scan pack
- mark shipped

Tenant admin / tenant staff:

- no operational fulfillment actions
- may view status through sales order / outbound detail only

### Returns

Internal admin / warehouse staff:

- receive/inspect/disposition

Tenant admin / tenant staff:

- create pre-announced return
- edit only while not received
- view own returns

### Issues

Internal admin:

- all

Warehouse staff:

- view/update warehouse-related issue status and notes
- issue creation by warehouse staff is deferred to implementation review; allow it only if the current receiving/damage workflow already needs staff-created issues

Tenant admin / tenant staff:

- create own issues
- view own issues
- update own issue notes while open if current workflow allows

### Billing

Internal admin only in v1:

- fee rates
- invoice generation
- invoice finalization

Tenant users:

- no billing access in v1 unless a later invoice-view task adds it.

### API / Marketplace Credentials

Internal admin only:

- Amazon/SP-API connection settings
- Amazon order import credentials/configuration
- marketplace shipping notice setup if it exposes operational credentials or carrier mappings

Warehouse staff:

- no credential setup

Tenant users:

- no credential setup in v1 unless a later tenant-scoped credential task explicitly allows it

### Direct Downloads / Plain Controllers

Do not only update Livewire components. Also audit plain controllers and file download routes.

Split download/controller endpoints into policy classes.

#### Warehouse Operational Files

These are warehouse/export operation files. Tenant scope alone is not enough in v1.

Internal admin and warehouse staff can access:

- courier export batch download
- Label10 / address label download through courier export batches
- marketplace shipping notice batch download
- fulfillment tracking import controller, where applicable

Tenant users cannot download these files in v1, even if the batch belongs to their tenant. A later tenant-facing download task can loosen this deliberately.

Controllers in this class must enforce:

- authenticated user
- active user
- role/capability: internal admin or warehouse staff
- tenant scope where the file is tenant-specific

#### Tenant-Owned Media

These are commercial tenant assets, not warehouse export files.

Examples:

- product images
- future issue/return photos, if exposed through a media endpoint

Policy:

- internal admin can access any media
- warehouse staff can access any media under the v1 internal data scope
- tenant users can access media owned by their active tenant memberships
- guest users are denied

For `/media/{mediaAsset}`, tenant-scoped access is correct and should be preserved. Audit it to confirm the scope, but do not convert product-image access into a warehouse-only permission.

## Tenant Scope Rules

Separate data visibility from action permission.

### Data Visibility Scope

For v1:

- internal users (`internal_admin` and `warehouse_staff`) can see all tenant data
- tenant users can see only active tenant memberships from `tenant_users`
- guest users can see no tenant data
- inactive users can see no tenant data

This means warehouse staff are capability-limited, not tenant-scope-limited, in v1.

Do not route `warehouse_staff` through `activeTenantIds()` only; internal users normally have no `tenant_users` rows and would see zero records.

The internal data-scope predicate is:

```php
$user?->user_type === 'internal'
```

This predicate is for data visibility only. Do not use it as an action permission check.

Do not use guest-as-internal logic:

```php
! $user || $user->user_type === 'internal'
```

Recommended scope helper behavior:

```php
allowedTenantIds()
```

- guest or inactive user -> empty list / deny
- internal user -> all tenant ids
- tenant user -> active tenant ids from `tenant_users`

### Action Permission

Action permission must use capability helpers such as:

- `isInternalAdmin()`
- `canManageSetup()`
- `canOperateWarehouse()`
- `canExportCourierLabels()`
- `canMutateInventory()`
- `isTenantAdminFor($tenantId)`

Do not spread raw `user_type === internal` checks as action authorization.

For tenant capability helpers, always pass the acted-on row's `tenant_id`. Do not use a global tenant role on list pages, because a user may be admin of tenant A and staff of tenant B.

If helper methods such as `isInternalUser()`, `allowedTenantIds()`, or `visibleTenantIds()` are duplicated:

- use the current local pattern carefully, or
- extract a shared helper/middleware in a separate cleanup task.

Do not broaden scope while refactoring.

## Capability Helpers

Do not spread raw role checks across many components.

Add a small set of intent-named helpers on `User`, a policy class, or Laravel Gates. Do not install a full permission package for v1 unless clearly needed.

Suggested helper names:

```php
isInternalAdmin()
isWarehouseStaff()
isTenantAdminFor(int $tenantId)
isTenantStaffFor(int $tenantId)
canManageSetup()
canManageUsers()
canManageBilling()
canManageApiCredentials()
canOperateWarehouse()
canExportCourierLabels()
canMutateInventory()
canImportTenantOrders(int $tenantId)
```

Rules:

- helper methods must deny inactive users
- helper methods must deny guests
- tenant helpers must read `tenant_users.role`, not `users.role`
- tenant helpers must require active tenant membership
- internal admin can usually pass all capability checks
- warehouse staff passes warehouse-operation checks only

Use helpers consistently in components, controllers, and downloads.

## Implementation Notes

### Route Middleware

Keep all app routes behind authenticated middleware.

Role checks can be implemented in:

- route middleware for whole sections, and/or
- Livewire component `mount()` / action guards

Do not rely only on hiding buttons. Every action must check permissions server-side.

For high-risk route groups, prefer middleware or `mount()` guards that fail before rendering:

- setup
- billing
- user management
- API credentials
- export/download pages

### Livewire Actions

Every mutating Livewire action must:

- re-query the model inside the allowed tenant scope
- verify the role can perform the action
- pass the target row's `tenant_id` into tenant capability helpers
- abort 403 or show a clear error if not allowed

Examples:

- export courier CSV
- mark shipped
- receive inbound
- stock adjustment
- stock count confirm
- delete/deactivate SKU
- manage users

### Direct Controllers

Every controller action must check the same capability helpers as the matching page.

Important examples:

- download previously exported courier CSV / Label10 files
- import tracking files
- marketplace shipping notice downloads
- media/image routes

Tenant scope alone is not enough for v1 if the action is warehouse-only. Tenant-owned media is different: keep tenant-scoped access for product images and similar assets.

### UI

Hide buttons the user cannot use, but keep server-side checks.

If a page is forbidden, show 403.

Do not show confusing empty pages when the real issue is permission.

## Migration / Backfill

If adding `users.role`:

1. Add nullable/default role column.
2. Backfill existing internal users to `internal_admin`.
3. Leave tenant users' `users.role` null.
4. Keep tenant roles in `tenant_users.role`.
5. Make role required for internal users if appropriate.
6. Update user create/edit forms.

Suggested defaults:

- internal -> `internal_admin`
- tenant -> no `users.role`; use `tenant_users.role`

No dummy/test users should be created by this migration.

If the app currently has tenant-user create/edit forms, ensure they write the pivot role (`tenant_users.role = admin/staff`) instead of `users.role`.

## Tests

Add focused tests. Do not run the full suite by default during review unless needed.

### Role Access

1. `internal_admin_can_access_setup_pages`
2. `warehouse_staff_cannot_access_setup_pages`
3. `tenant_admin_cannot_access_setup_pages`
4. `tenant_staff_cannot_access_setup_pages`
5. `warehouse_staff_cannot_access_billing`
6. `warehouse_staff_cannot_manage_users`
7. `warehouse_staff_cannot_access_amazon_spapi_settings`

### Tenant Scope

8. `tenant_admin_cannot_view_other_tenant_sales_orders`
9. `tenant_staff_cannot_view_other_tenant_skus`
10. `tenant_membership_role_is_read_from_tenant_users_pivot`
11. `user_can_be_tenant_admin_for_one_tenant_and_staff_for_another`
12. `warehouse_staff_gets_internal_all_tenant_data_scope_in_v1`

### Warehouse Operations

13. `warehouse_staff_can_open_fulfillment`
14. `warehouse_staff_can_export_courier_labels`
15. `tenant_user_cannot_export_courier_labels`
16. `tenant_user_cannot_mark_outbound_shipped`

### Inventory

17. `warehouse_staff_can_create_stock_adjustment`
18. `tenant_user_cannot_create_stock_adjustment`
19. `warehouse_staff_can_create_stock_count`
20. `tenant_user_cannot_create_stock_count`

### Tenant Workflows

21. `tenant_admin_can_import_own_sales_orders`
22. `tenant_staff_can_import_own_sales_orders`
23. `tenant_user_can_create_own_issue`
24. `tenant_user_can_create_own_return_preannouncement`

### Server-Side Guard

25. `hidden_button_action_still_blocks_direct_livewire_call`
26. `tenant_user_cannot_download_courier_export_batch`
27. `warehouse_staff_can_download_courier_export_batch`
28. `inactive_user_is_denied_even_with_role`
29. `guest_is_not_internal`
30. `tenant_user_can_view_own_tenant_media`
31. `tenant_user_cannot_view_other_tenant_media`

Pick representative pages first. Do not try to test every route in one large brittle test.

## Acceptance Criteria

- Four roles are defined and stored.
- Internal roles are stored on `users.role`.
- Tenant roles are read from existing `tenant_users.role`.
- Setup pages are internal-admin only.
- Billing, user management, and API credential setup are internal-admin only.
- Warehouse staff can operate warehouse workflows but cannot manage high-risk setup.
- Warehouse staff creation is not enabled until internal-admin gates are in place.
- Tenant users are tenant-scoped.
- Tenant users cannot perform warehouse ship/export/stock mutation actions.
- Tenant users cannot download courier export batches in v1.
- Internal admin retains full access.
- Inactive users are denied regardless of role.
- Server-side guards exist for mutating actions.
- Server-side guards exist for direct download controllers.
- No guest user is treated as internal.
- Tests cover representative role and tenant-scope cases.
