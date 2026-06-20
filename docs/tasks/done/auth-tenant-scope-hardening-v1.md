# Auth and Tenant Scope Hardening v1

## Goal

Fix the P1 security issue from:

```text
docs/audits/repo-health-audit-v1-findings.md
```

Main issue:

- Application routes are currently public.
- Many helpers treat `Auth::user() === null` as an internal user.
- A guest request can therefore reach internal/tenant data paths as if it were an internal user.

This task should harden authentication and tenant scope without changing unrelated business logic.

## Scope

Do:

- Put operational app routes behind authentication.
- Remove guest-as-internal fallback from all relevant helpers.
- Centralize repeated internal-user / allowed-tenant logic.
- Keep existing internal-user and tenant-user behavior working.
- Add regression tests for guest access and tenant scope.

Do not:

- Fix export file transaction ordering in this task.
- Refactor unrelated UI.
- Change sales order status logic.
- Change inventory movement logic.
- Change marketplace shipping notice export formats.
- Rewrite unrelated tests unless required by auth behavior.

## Current problem to verify first

Before changing code, verify the issue:

- `routes/web.php` registers most operational routes without `auth` middleware.
- Many classes contain:

```php
return ! $user || $user->user_type === 'internal';
```

This must be changed. Guest users must never be treated as internal.

Useful search:

```bash
rg -n "return ! \\$user \\|\\| \\$user->user_type === 'internal'|TODO: remove unauthenticated fallback|function isInternalUser|function allowedTenantIds" app routes
```

## Route protection

Wrap all operational app routes in `routes/web.php` with auth middleware.

Routes that should require login include:

- `/`
- `/inventory`
- `/inventory/movements`
- `/inbound*`
- `/outbound*`
- `/sales-orders*`
- `/courier-export-batches/{batch}/download`
- `/marketplace-shipping-notice-batches/{batch}/download`
- `/fulfillment-groups*`
- `/skus*`
- `/setup/*`
- `/stock-adjustments/create`

The locale switch route can remain public if needed:

```php
Route::post('/locale/{locale}', LocaleController::class)->name('locale.switch');
```

If the Laravel starter auth routes are not present yet, do not build a whole login UI in this task.
Use the app's existing auth/testing conventions. The important behavior is:

- guest web requests are redirected to login, or
- guest requests return 403 if no login route exists.

Pick the behavior that matches the current Laravel app setup and tests.

## Shared authorization helper

Create one shared place for tenant/internal access logic.

Suggested option:

```text
app/Support/UserAccess.php
```

or a trait:

```text
app/Livewire/Concerns/ResolvesUserAccess.php
```

Prefer a plain support/service class if both Livewire components and HTTP controllers need to use it.

Required behavior:

```php
UserAccess::isInternal(?User $user): bool
```

- returns `true` only when authenticated user exists and `user_type === 'internal'`
- returns `false` for guests

```php
UserAccess::allowedTenantIds(?User $user): array
```

- for internal user: all tenant ids
- for tenant user: active tenant memberships only
- for guest: empty array

```php
UserAccess::authorizeTenantAccess(?User $user): void
```

- internal user: allow
- tenant user with at least one active tenant: allow
- guest or tenant user with no active tenant: abort/forbid

```php
UserAccess::authorizeInternal(?User $user): void
```

- internal user: allow
- everyone else, including guest: 403

Naming can differ, but behavior must be centralized.

## Replace duplicated helpers

Update components/controllers that currently define duplicated methods:

- `isInternalUser()`
- `allowedTenantIds()`
- `authorizeTenantAccess()`

Use the shared helper instead.

At minimum cover all classes found by:

```bash
rg -n "TODO: remove unauthenticated fallback|function isInternalUser|function allowedTenantIds" app
```

Important classes to include:

- `app/Livewire/SalesOrderIndex.php`
- `app/Http/Controllers/SalesOrderExportController.php`
- `app/Http/Controllers/CourierExportController.php`
- `app/Http/Controllers/CourierExportValidateController.php`
- `app/Http/Controllers/CourierExportDownloadController.php`
- `app/Http/Controllers/MarketplaceShippingNoticeDownloadController.php`
- `app/Livewire/AmazonSpapiOrderImport.php`
- setup pages such as shipping methods, tenants, warehouses, shops, locations, packaging
- inbound / outbound / fulfillment / SKU / stock adjustment components

Remove obsolete TODO comments once the fallback is gone.

## Internal-only pages

The following pages should stay internal-only:

- setup tenants
- setup warehouses
- setup shops
- setup shipping methods / carriers
- setup locations
- setup packaging
- setup other settings
- Amazon SP-API connection/settings pages if present

Tenant users should get 403.
Guests should not be treated as internal.

## Tenant-scoped pages

Tenant users should continue to access their own tenant data only:

- inventory
- inventory movements
- SKUs
- inbound
- outbound
- sales orders
- fulfillment groups
- stock adjustment if currently allowed by product rules

Internal users should continue to see all tenants.

## Download/export routes

Be strict with download routes:

- guest: redirect to login or 403
- tenant user: only own tenant's batch
- internal user: all batches
- batch with `tenant_id = null`: internal only

Check:

- `CourierExportDownloadController`
- `MarketplaceShippingNoticeDownloadController`
- Sales order export route
- Any future marketplace notice batch download route already present

## Tests

Add/adjust feature tests.

### Guest route access

Add tests such as:

1. `test_guest_cannot_access_sales_orders_index`
2. `test_guest_cannot_access_inventory`
3. `test_guest_cannot_access_setup_shipping_methods`
4. `test_guest_cannot_download_courier_export_batch`
5. `test_guest_cannot_download_marketplace_shipping_notice_batch`
6. `test_guest_cannot_export_sales_orders`

Expected result:

- redirect to login if auth middleware redirects, or
- 403 if app has no login route

Use one consistent expectation matching the app.

### Internal routes

Add/keep tests:

1. `test_internal_user_can_access_sales_orders`
2. `test_internal_user_can_access_setup_shipping_methods`
3. `test_internal_user_can_download_export_batches`

### Tenant scope

Add/keep tests:

1. `test_tenant_user_can_access_own_sales_orders`
2. `test_tenant_user_cannot_access_other_tenant_download_batch`
3. `test_tenant_user_cannot_access_internal_setup_pages`
4. `test_tenant_user_with_no_active_tenant_gets_forbidden`

### Shared helper tests

If creating `UserAccess`, add unit or feature tests for:

- guest is not internal
- internal user is internal
- tenant user is not internal
- guest allowedTenantIds is empty
- tenant user allowedTenantIds returns active memberships only
- internal user allowedTenantIds returns all tenants

## Regression checks

Run:

```bash
php artisan route:list
php artisan test
npm run build
```

If `npm run build` is unrelated and slow, still run it if UI route/layout files changed.

## Acceptance criteria

- No route that exposes operational data is publicly accessible.
- No helper treats `Auth::user() === null` as internal.
- Guest users cannot access sales orders, inventory, setup pages, export routes, or download routes.
- Tenant users keep access to their own data only.
- Internal users keep existing full access.
- Repeated auth/tenant helper logic is centralized or materially reduced.
- Existing business logic tests remain green.
- New guest/security regression tests are added.
- Full test suite passes.

## Notes

Do not silently change product permissions.
If you find a page where it is unclear whether tenant users should have access, document it and choose the conservative option:

- setup/config pages: internal-only
- operational tenant pages: tenant-scoped

