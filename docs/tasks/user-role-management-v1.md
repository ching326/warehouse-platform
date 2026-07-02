# User and Role Management UI v1

## Goal

Provide the missing UI to assign roles, completing roles-and-permissions-v1.

Right now `users.role`, `tenant_users.role`, capability helpers, and route
middleware exist, but there is no page to set a role. Roles can only be changed
in the database. This task adds the UI.

Two audiences, two surfaces:

1. Internal admin -- manage all users (internal role, tenant memberships,
   active flag, create).
2. Tenant admin -- self-service membership management for their own tenant(s).

This is rollout step 7 of roles-and-permissions-v1 ("allow user create/edit UI
to create warehouse_staff"). It also unblocks assigning tenant_admin/tenant_staff.

This task also closes two known gaps from the role-guard commit review:

- `/dev-login` must create the local testing user with `role = internal_admin`,
  otherwise a fresh local DB can log in but get 403 on capability-guarded pages.
- Amazon SP-API order import must no longer allow every internal user. Guard
  `AmazonSpapiOrderImport` with `capability:manage_api_credentials` on the route
  and `canManageApiCredentials()` in `mount()`, matching how `ShopEdit`'s Amazon
  actions are already gated.

These two fixes are independent of the UI. The SP-API one is a live privilege
hole, so it may ship as its own small PR ahead of the rest (see Rollout).

## Builds On (already shipped, commit 6a21da5)

- `users.user_type` (internal/tenant), `users.role` (internal_admin/warehouse_staff, null for tenant), `users.is_active`.
- `tenant_users` pivot with `role` (admin/staff), `status` (active), `invited_at`, `joined_at`.
- Capability helpers on `User`: `isInternalAdmin`, `isWarehouseStaff`, `canManageUsers`, `isTenantAdminFor`, `isTenantStaffFor`, etc.
- `RequireCapability` middleware + `capability:*` route aliases.
- `RequireAuthenticatedUser` already denies inactive users globally.

No schema change is required for v1. Everything writes `users` and `tenant_users`.

## Non-goals / Deferred to v2

- Email invitations and forced first-login password reset (no mail infra yet).
- Tenant admin creating internal users (never allowed).
- Bulk role edits, CSV import of users.
- A read-only auditor role.
- Cross-tenant "attach existing user" enumeration UX beyond the exact-email rule below.

## New Capability Helpers

Add to `User`, following the existing rules (deny inactive, deny guest, tenant
helpers read `tenant_users` and require active membership + active tenant):

```php
adminTenantIds(): array           // active tenant_users where role = admin, tenant active
administersAnyTenant(): bool      // adminTenantIds() !== []
canManageTenantUsers(int $tenantId): bool  // isInternalAdmin() || isTenantAdminFor($tenantId)
```

`adminTenantIds()` mirrors the existing `activeTenantIds()` but filters
`role = admin`. `canManageUsers()` already exists (internal_admin only) and is reused.

Add two cases to `RequireCapability`:

- `manage_users` -> `canManageUsers()` (internal admin)
- `manage_tenant_members` -> `administersAnyTenant()` (coarse gate; per-tenant
  scope is enforced in the component, not the middleware, because the tenant id
  is data, not a fixed route section)

## Routes

Inside the existing `authenticated` group:

Internal admin:

- `GET /setup/users` -> `UserIndex` -- `capability:manage_users`
- `GET /setup/users/create` -> `UserCreate` -- `capability:manage_users`
- `GET /setup/users/{user}/edit` -> `UserEdit` -- `capability:manage_users`

Tenant admin self-service:

- `GET /team` -> `TenantTeam` -- `capability:manage_tenant_members`

`/team` is for tenant admins. Internal admins are not the audience (they have
the fuller `/setup/users`); with no tenant memberships they would see nothing,
so the coarse gate is `administersAnyTenant()`, not internal.

Nav: add "Users" under Setup (internal admin only). Add "Team" as a top-level
entry visible only when `administersAnyTenant()`.

## Data Model Rules

- Internal user: `user_type = internal`, `role` in {internal_admin, warehouse_staff}, never null once created.
- Tenant user: `user_type = tenant`, `users.role` stays null. Permission comes only from `tenant_users`.
- Membership: `tenant_users.role` in {admin, staff}, `status = active`, `joined_at = now()` when created active.
- Deactivating a membership sets `tenant_users.status = inactive`; it does not delete the row.
- Re-adding a user whose membership row already exists with `status = inactive`
  must reactivate that row, update the selected role, and set `joined_at` if it
  is still null. Do not insert a second pivot row.
- Deactivating an account sets `users.is_active = false`; the global middleware then denies the user everywhere.
- `tenant_users.status` and `tenant_users.role` are bare strings today, with no
  enum. Add constants (`TenantUser::STATUS_ACTIVE`, `STATUS_INACTIVE`,
  `ROLE_ADMIN`, `ROLE_STAFF`) and reference them everywhere so a typo cannot
  create a membership that never grants. `inactive` is the chosen literal for a
  deactivated membership and is new -- nothing in the code uses it yet.

## Pages

### Internal: Users index (`/setup/users`)

- Filters: search (name/email), user type (all/internal/tenant), role, status (active/all). `simplePaginate(30)`.
- Columns: User (avatar + name + email), Type badge, Role, Status, Edit.
  - Internal user Role shows the internal role badge (Internal admin / Warehouse staff).
  - Tenant user Role shows membership chips ("Admin - ACME", "Staff - Globex"); collapse to "+N" past two.
- "Invite user" button -> create page.

### Internal: Create user (`/setup/users/create`)

- Fields: name, email (unique), user type (internal/tenant), active (default true).
  The initial password is system-generated (not an input field).
- If user type = internal: role is required (internal_admin / warehouse_staff). Fail closed -- no null role for internal users.
- If user type = tenant: no `users.role`; require at least one tenant membership (tenant + admin/staff) so the account is usable.
- Create the user and its initial membership in a single transaction, so a tenant
  user is never persisted without a usable membership.
- Creating does not send email in v1; the generated temp password is displayed
  once on success with a copy control, then never shown again and never logged.

### Internal: Edit user (`/setup/users/{user}/edit`)

Branches on `user_type`.

Internal variant:

- Read-only name/email.
- Internal role: two option cards (internal_admin / warehouse_staff) with a one-line capability summary each.
- Active toggle.
- Save.

Tenant variant:

- Read-only name/email + account active toggle.
- Callout: tenant users have no global role; permissions come from memberships.
- Memberships table: Tenant | Role (admin/staff select) | Membership status | Remove.
- Add membership: tenant select (any tenant, internal admin sees all) + role + Add.

### Tenant: Team (`/team`)

The same memberships editor as the tenant variant above, scoped and locked to
the acting user's admin tenant(s).

- If the user administers more than one tenant, a tenant selector at the top
  lists only tenants where they are admin. Otherwise it is fixed to their one tenant.
- Members table for the selected tenant: User (name/email) | Role (admin/staff select) | Status | Remove.
- Add member (see Account creation, tenant flow).
- The acting admin cannot: see other tenants, edit internal users, grant internal
  roles, change `user_type`, or manage a tenant where they are only staff.

## Account Creation

Internal admin (from `/setup/users/create`):

- Can create internal or tenant users.
- Internal users require a role; tenant users require at least one membership.
- Sets an initial temp password, shown once.

Tenant admin (from `/team`, "Add member"), scoped to a tenant they administer:

- Create new: name + email + role (admin/staff). Email must be globally unique.
  In one transaction, creates a `user_type = tenant`, `role = null`, active user
  plus an active membership to the selected tenant. A system-generated temp
  password is shown once.
- Attach existing: enter an exact email. Attach a membership only if the email
  maps to an existing tenant user. If an inactive membership already exists for
  the selected tenant, reactivate it and update the role instead of inserting.
  If the email belongs to an internal user, does not exist, or is already an
  active member, show a single generic message ("No eligible user for that
  email") -- do not reveal which case it was, and never attach internal users
  to a tenant.

## Shared Guards (enforced server-side, mirrored in the UI)

Put the mutation logic in small services so the two surfaces do not duplicate it:

- `App\Services\Users\TenantMembershipService` -- add, setRole, remove, setStatus.
  Both `/setup/users` (tenant variant) and `/team` call this same service, so the
  add/reactivate and last-admin logic is written and tested once.
- Internal role change guarded in `UserEdit` or a thin `UserRoleService`.

Rules:

1. Last active internal admin cannot be demoted or deactivated. Server check +
   disabled control with a reason.
2. Last active admin of a tenant cannot be demoted, removed, or membership-deactivated.
   Applies to both internal-admin edits and tenant self-service.
   Also block deactivating a tenant user account if that user is the last active
   admin for any tenant.
3. Self-change confirm: an admin changing their own internal role, or a tenant
   admin removing/demoting their own admin membership, must confirm; still blocked
   if it would violate rule 1 or 2.
4. Fail closed: internal users always have a non-null role; tenant users always null `users.role`.
5. Tenant capability checks always pass the target row's `tenant_id`
   (`canManageTenantUsers($row->tenant_id)`), never a global role.
6. Tenant admin can never touch internal users, grant internal roles, or change `user_type`.
7. Inactive/guest users are denied by the helpers and the outer middleware.
8. Last-admin checks (rules 1 and 2) run inside a DB transaction that locks the
   candidate admin rows (`lockForUpdate`) and re-counts remaining active admins
   under the lock before writing. A plain read-then-write can be raced by two
   concurrent demotions and drop the count to zero. Follow the existing
   `RequeuePrintService` transaction/lock pattern.

## Rollout Order

1. Add the three helpers and the two `RequireCapability` cases.
2. Fix the two known guard gaps: `/dev-login` role assignment and Amazon SP-API
   import internal-admin/API-credential guard.
3. Build `/setup/users` index + edit + create (internal admin).
4. Build `/team` (tenant admin), reusing the membership service.
5. Add nav entries (Setup > Users; top-level Team).
6. Tests for guards and scope.
7. Backfill note: existing internal users already have `internal_admin` from the
   prior migration; no data change here.

Step 2 has no dependency on the UI. Since the SP-API guard closes a live
privilege hole, prefer shipping step 2 as its own small PR first, rather than
gating the fix on the whole user-management feature.

## UI Rules

- Hide controls the user cannot use, but every action re-checks server-side.
- Forbidden page -> 403, not an empty list.
- Temp password shown once with a copy control and a "store it now" note; never
  shown again and never logged.
- Confirm dialogs for self-change and for removing a member.
- Sentence case, ASCII punctuation in all new lang keys.

## Language / Encoding

- Add new keys to `lang/en` only (new file `lang/en/users.php`, plus a few
  `common.nav_*` entries).
- `/team` is tenant-user-facing, so add a row to `docs/translation-backlog.md`
  for ja / zh_TW / zh_CN and defer CJK to a batched pass.
- Do not edit CJK lang files or use PowerShell rewrites for lang files.

## Tests

Focused tests; do not run the full suite by default.

### Internal admin

1. `internal_admin_can_open_users_index`
2. `warehouse_staff_cannot_open_users_index`
3. `tenant_user_cannot_open_users_index`
4. `internal_admin_can_set_internal_role_admin_to_warehouse`
5. `internal_admin_can_add_tenant_membership_and_role`
6. `internal_admin_can_deactivate_user`
7. `create_internal_user_requires_role`
8. `create_tenant_user_leaves_users_role_null_and_requires_membership`
9. `dev_login_creates_internal_admin_role`
10. `warehouse_staff_cannot_open_amazon_spapi_import`

### Tenant admin self-service

11. `tenant_admin_can_open_team_for_own_tenant`
12. `tenant_staff_cannot_open_team`
13. `tenant_admin_sees_only_own_admin_tenants`
14. `tenant_admin_can_set_member_role_within_own_tenant`
15. `tenant_admin_cannot_manage_other_tenant_members`
16. `tenant_admin_cannot_edit_internal_user`
17. `tenant_admin_cannot_grant_internal_role`
18. `tenant_admin_add_existing_attaches_membership`
19. `tenant_admin_add_existing_inactive_membership_reactivates_it`
20. `tenant_admin_add_existing_internal_email_is_rejected_generically`

### Guards

21. `cannot_demote_last_internal_admin`
22. `cannot_deactivate_last_internal_admin`
23. `cannot_remove_last_admin_of_tenant`
24. `cannot_deactivate_user_who_is_last_admin_of_any_tenant`
25. `self_demotion_requires_confirmation`
26. `hidden_action_still_blocks_direct_livewire_call`
27. `inactive_user_is_denied_even_if_admin`

## Acceptance Criteria

- Internal admin can set internal roles and manage tenant memberships from the UI.
- Tenant admin can manage members and roles for their own tenant(s) only.
- Tenant admin cannot see or affect other tenants, internal users, internal roles, or user_type.
- Internal users always have a non-null role; tenant users always have null `users.role`.
- Last internal admin and last per-tenant admin cannot be removed or demoted.
- Inactive tenant memberships can be reactivated by adding/attaching the same
  user again; no duplicate pivot row is created.
- New tenant users created by a tenant admin are scoped to that admin's tenant only.
- Warehouse staff cannot access Amazon SP-API import.
- Every mutating action is guarded server-side, not only by hidden buttons.
- Inactive users remain denied.
- New language keys are English-only and logged in the translation backlog.
