# Task: Shop CRUD v1

## Stack

Laravel 13, Livewire 4 (class-based components, not Volt), Flux UI (`livewire/flux ^2.14`),
SQLite (dev), PHP 8.3. Plain Blade views only -- no TypeScript.

---

## What this task covers

Two pages and two Livewire components:

- `GET /setup/shops` -- ShopIndex: list shops, filter by tenant / platform / status, search, activate/deactivate
- `GET /setup/shops/create` -- ShopCreate: create new shop

**One new migration required.** The `shops` table exists but its unique constraint must be
widened from `(tenant_id, code)` to `(tenant_id, platform, marketplace, code)`, and
`marketplace` must be changed from nullable to `string default ''` to avoid NULL-in-unique-index
issues on MySQL. See the Migration section below.
**No delete.** Shops are referenced by sales orders. Deactivate instead.
**Internal users only.** Both pages must guard against non-internal users in `mount()`.

---

## Target table schema (after migration)

```
shops
  id
  tenant_id          FK -> tenants.id
  platform           string                        -- amazon | rakuten | shopify | manual
  marketplace        string default ''             -- e.g. amazon_jp, amazon_us; empty string = none
  code               string                        -- unique within (tenant, platform, marketplace); stored uppercase
  name               string
  contact_name       string nullable
  contact_email      string nullable
  status             string default 'active'       -- active | inactive
  note               text nullable
  created_at / updated_at

  unique(tenant_id, platform, marketplace, code)
  index(tenant_id, platform)
  index(tenant_id, status)
```

The `Shop` model already exists at `app/Models/Shop.php`. It uses the
`Spatie\Activitylog\Traits\LogsActivity` trait with `logFillable()`.
Do NOT remove or modify this trait. All `Shop::create()` and `$shop->save()` calls will
automatically be logged.

---

## Migration

Create a new migration (e.g. `xxxx_xx_xx_update_shops_unique_constraint.php`):

```php
public function up(): void
{
    Schema::table('shops', function (Blueprint $table) {
        $table->dropUnique(['tenant_id', 'code']);
    });

    // Set existing NULLs to '' before changing the column
    DB::table('shops')->whereNull('marketplace')->update(['marketplace' => '']);

    Schema::table('shops', function (Blueprint $table) {
        $table->string('marketplace')->default('')->nullable(false)->change();
        $table->unique(['tenant_id', 'platform', 'marketplace', 'code']);
    });
}

public function down(): void
{
    Schema::table('shops', function (Blueprint $table) {
        $table->dropUnique(['tenant_id', 'platform', 'marketplace', 'code']);
        $table->string('marketplace')->nullable()->change();
        $table->unique(['tenant_id', 'code']);
    });
}

// NOTE: down() will fail if duplicate (tenant_id, code) rows were created after this migration
// ran -- for example, two shops with the same code but different platforms.
// This is acceptable for dev rollback. Do not rely on down() after production data exists.
```

`marketplace` is stored as empty string `''` (not NULL) so the unique constraint works correctly
on both SQLite and MySQL. NULL values in a MySQL unique index are treated as distinct, which
would allow silently bypassing the constraint.

---

## Livewire Components

### `app/Livewire/ShopIndex.php`

Use the `WithPagination` trait:

```php
use Livewire\WithPagination;

class ShopIndex extends Component
{
    use WithPagination;
}
```

Add `updated*()` hooks to reset pagination when filters change:

```php
public function updatedTenantId(): void       { $this->resetPage(); }
public function updatedPlatformFilter(): void { $this->resetPage(); }
public function updatedStatusFilter(): void  { $this->resetPage(); }
public function updatedSearch(): void        { $this->resetPage(); }
```

#### Wire properties

```php
#[Url(as: 'tenant', except: '')]
public string $tenantId = '';

#[Url(as: 'platform', except: '')]
public string $platformFilter = '';

#[Url(as: 'status', except: '')]
public string $statusFilter = '';

#[Url(as: 'q', except: '')]
public string $search = '';
```

#### `mount()`

```php
public function mount(): void
{
    if (! $this->isInternalUser()) {
        abort(403);
    }
}
```

#### `toggleStatus(int $shopId)`

```php
public function toggleStatus(int $shopId): void
{
    $shop = Shop::findOrFail($shopId);
    $shop->status = $shop->status === 'active' ? 'inactive' : 'active';
    $shop->save();
}
```

#### `render()`

```php
public function render()
{
    $shops = Shop::query()
        ->with('tenant')
        ->when($this->tenantId !== '', fn ($q) => $q->where('tenant_id', $this->tenantId))
        ->when($this->platformFilter !== '', fn ($q) => $q->where('platform', $this->platformFilter))
        ->when($this->statusFilter !== '', fn ($q) => $q->where('status', $this->statusFilter))
        ->when($this->search !== '', function ($q) {
            $like = '%' . $this->search . '%';
            $q->where(fn ($q) => $q
                ->where('code', 'like', $like)
                ->orWhere('name', 'like', $like)
            );
        })
        ->orderBy('name')
        ->paginate(30);

    return view('livewire.shop-index', [
        'shops'     => $shops,
        'tenants'   => Tenant::where('status', 'active')->orderBy('name')->get(),
        'platforms' => $this->platforms(),
    ])->layout('inventory', [
        'title'    => __('shop.shops_page_title'),
        'subtitle' => __('shop.shops_page_subtitle'),
    ]);
}
```

#### Private helpers

```php
// TODO: remove unauthenticated fallback when auth is implemented
private function isInternalUser(): bool
{
    $user = Auth::user();
    return ! $user || $user->user_type === 'internal';
}

private function platforms(): array
{
    return [
        'amazon'  => __('shop.platform_amazon'),
        'rakuten' => __('shop.platform_rakuten'),
        'shopify' => __('shop.platform_shopify'),
        'manual'  => __('shop.platform_manual'),
    ];
}
```

---

### `app/Livewire/ShopCreate.php`

#### Wire properties

```php
public string $tenantId      = '';
public string $platform      = '';
public string $marketplace   = '';
public string $code          = '';
public string $name          = '';
public string $contactName   = '';
public string $contactEmail  = '';
public string $status        = 'active';
public string $note          = '';
```

All properties must be `string` type for Livewire 4 hydration compatibility.

#### `mount()`

Guard: if not internal user, abort(403).

#### `save()`

```php
public function save()
{
    $this->code = strtoupper(trim($this->code));

    $this->validateInput();

    Shop::create([
        'tenant_id'     => (int) $this->tenantId,
        'platform'      => $this->platform,
        'marketplace'   => trim($this->marketplace),
        'code'          => $this->code,
        'name'          => trim($this->name),
        'contact_name'  => $this->nullableString($this->contactName),
        'contact_email' => $this->nullableString($this->contactEmail),
        'status'        => $this->status,
        'note'          => $this->nullableString($this->note),
    ]);

    session()->flash('status', __('shop.shop_created'));

    return redirect()->route('setup.shops.index');
}
```

#### `validateInput()`

```php
private function validateInput(): void
{
    // $this->code has already been normalized to uppercase in save() before this call
    validator([
        'tenant_id'     => $this->tenantId,
        'platform'      => $this->platform,
        'marketplace'   => trim($this->marketplace),
        'code'          => $this->code,
        'name'          => $this->name,
        'contact_name'  => $this->contactName,
        'contact_email' => $this->contactEmail,
        'status'        => $this->status,
        'note'          => $this->note,
    ], [
        'tenant_id'     => ['required', Rule::exists('tenants', 'id')->where('status', 'active')],
        'platform'      => ['required', 'string', Rule::in(array_keys($this->platforms()))],
        'marketplace'   => ['string', 'max:100'],
        'code'          => ['required', 'string', 'max:50',
                            Rule::unique('shops', 'code')
                                ->where('tenant_id', (int) $this->tenantId)
                                ->where('platform', $this->platform)
                                ->where('marketplace', trim($this->marketplace))],
        'name'          => ['required', 'string', 'max:255'],
        'contact_name'  => ['nullable', 'string', 'max:255'],
        'contact_email' => ['nullable', 'email', 'max:255'],
        'status'        => ['required', 'string', Rule::in(['active', 'inactive'])],
        'note'          => ['nullable', 'string', 'max:2000'],
    ])->validate();
}
```

`code` uniqueness is scoped to `(tenant_id, platform, marketplace)`; same code is allowed if the
platform or marketplace differs. This mirrors the DB unique constraint.
`marketplace` is passed as `trim($this->marketplace)`, which is `''` when blank; this matches how
it is stored. Do NOT use `nullableString()` here; NULL would break the unique constraint on MySQL.

`platform` uses `Rule::in(array_keys($this->platforms()))` to keep validation in sync with the
platforms array -- the same pattern used in warehouse-location-crud for location types.

`tenant_id` only accepts active tenants: `Rule::exists('tenants', 'id')->where('status', 'active')`.

#### `render()`

```php
public function render()
{
    return view('livewire.shop-create', [
        'tenants'   => Tenant::where('status', 'active')->orderBy('name')->get(),
        'platforms' => $this->platforms(),
    ])->layout('inventory', [
        'title'    => __('shop.shop_create_page_title'),
        'subtitle' => __('shop.shop_create_page_subtitle'),
    ]);
}
```

#### Private helpers

```php
// TODO: remove unauthenticated fallback when auth is implemented
private function isInternalUser(): bool
{
    $user = Auth::user();
    return ! $user || $user->user_type === 'internal';
}

private function nullableString(?string $value): ?string
{
    $value = trim((string) $value);
    return $value === '' ? null : $value;
}

private function platforms(): array
{
    return [
        'amazon'  => __('shop.platform_amazon'),
        'rakuten' => __('shop.platform_rakuten'),
        'shopify' => __('shop.platform_shopify'),
        'manual'  => __('shop.platform_manual'),
    ];
}
```

---

## Routes

Add to `routes/web.php`:

```php
use App\Livewire\ShopCreate;
use App\Livewire\ShopIndex;

Route::get('/setup/shops', ShopIndex::class)->name('setup.shops.index');
Route::get('/setup/shops/create', ShopCreate::class)->name('setup.shops.create');
```

---

## Navigation

In `resources/views/components/layout/navigation.blade.php`, add a Shops link to the existing
Setup dropdown. Place it after Warehouses and before Locations:

```blade
<a href="{{ route('setup.shops.index') }}"
   class="{{ request()->routeIs('setup.shops.*') ? 'is-active' : '' }}"
   wire:navigate @click="open = false">
    {{ __('common.nav_shops') }}
</a>
```

The `$setupActive` variable at the top of the file already uses `request()->routeIs('setup.*')`,
so it covers `setup.shops.*` with no change needed.

Add `nav_shops` to all four lang files (`lang/en/common.php`, `lang/ja/common.php`,
`lang/zh_TW/common.php`, `lang/zh_CN/common.php`):

```php
'nav_shops' => 'Shops',
```

---

## Lang: `lang/en/shop.php`

```php
<?php

return [
    // Pages
    'shops_page_title'          => 'Shops',
    'shops_page_subtitle'       => 'Manage shops and their platform connections.',
    'shop_create_page_title'    => 'Create Shop',
    'shop_create_page_subtitle' => 'Add a new shop to a tenant.',

    // Fields
    'field_tenant'           => 'Tenant',
    'field_platform'         => 'Platform',
    'field_marketplace'      => 'Marketplace / Region',
    'field_marketplace_hint' => 'Optional. e.g. amazon_jp, amazon_us.',
    'field_code'             => 'Code',
    'field_code_hint'        => 'Short unique identifier within this tenant. Stored uppercase.',
    'field_name'             => 'Name',
    'field_contact_name'     => 'Contact name',
    'field_contact_email'    => 'Contact email',
    'field_status'           => 'Status',
    'field_note'             => 'Note',

    // Platforms
    'platform_amazon'  => 'Amazon',
    'platform_rakuten' => 'Rakuten',
    'platform_shopify' => 'Shopify',
    'platform_manual'  => 'Manual',

    // Table columns
    'col_tenant'      => 'Tenant',
    'col_code'        => 'Code',
    'col_name'        => 'Name',
    'col_platform'    => 'Platform',
    'col_marketplace' => 'Marketplace',
    'col_contact'     => 'Contact',
    'col_status'      => 'Status',
    'col_actions'     => 'Actions',

    // Feedback
    'shop_created' => 'Shop created.',
    'empty_state'  => 'No shops match the current filters.',

    // Buttons & labels
    'btn_create_shop'    => 'Create shop',
    'btn_back_shops'     => 'Back to shops',
    'btn_cancel'         => 'Cancel',
    'btn_activate'       => 'Activate',
    'btn_deactivate'     => 'Deactivate',
    'status_active'      => 'Active',
    'status_inactive'    => 'Inactive',
    'all_tenants'        => 'All tenants',
    'all_platforms'      => 'All platforms',
    'all_statuses'       => 'All statuses',
    'search_placeholder' => 'Code, name...',
];
```

Also create `lang/ja/shop.php`, `lang/zh_TW/shop.php`, `lang/zh_CN/shop.php` with identical
content and a `// TODO: translate this file. English values are placeholders.` comment.

---

## Blade Views

### `resources/views/livewire/shop-index.blade.php`

Structure:
1. Flash message (session 'status') at top
2. Page actions row: "Create shop" button linking to `route('setup.shops.create')` with `wire:navigate`
3. Toolbar: tenant filter select, platform filter select, status filter select, search input
4. Table columns: Tenant, Code, Name, Platform, Marketplace, Contact (name + email stacked),
   Status badge, Actions
5. Actions: if active show "Deactivate" button, if inactive show "Activate" button;
   both call `wire:click="toggleStatus({{ $shop->id }})"`
6. Status badge: active = success, inactive = zinc
7. Empty state, pagination

### `resources/views/livewire/shop-create.blade.php`

Structure:
1. Panel 1 -- Identity: Tenant (select from `$tenants`), Platform (select from `$platforms`),
   Marketplace (text input, optional with hint), Code (with hint), Name, Status
2. Panel 2 -- Contact: Contact name, Contact email
3. Full-width panel: Note textarea
4. Sticky footer: back link (`route('setup.shops.index')`), submit button
5. Show field hints below Code and Marketplace fields
6. Show validation errors below each field

---

## Tests: `tests/Feature/ShopTest.php`

```
test_create_shop_succeeds()
```
- Active tenant exists
- Internal user creates shop: tenant = that tenant, platform = 'amazon', code = 'AMZJP',
  name = 'Amazon JP'
- Assert redirect to `setup.shops.index`
- Assert `Shop` record with `code = 'AMZJP'`, `platform = 'amazon'`, `status = 'active'`

```
test_create_shop_uppercases_code()
```
- Submit with code `amz-jp`
- Assert stored `code = 'AMZ-JP'`

```
test_create_shop_rejects_duplicate_code_within_same_tenant_platform_marketplace()
```
- Shop exists: tenant A, platform 'amazon', marketplace 'amazon_jp', code 'MAIN'
- Try to create: same tenant, same platform, same marketplace, code 'main' (lowercase)
- Assert `assertHasErrors(['code'])`

```
test_create_shop_allows_same_code_for_different_tenant()
```
- Shop with code 'MAIN', platform 'amazon', marketplace 'amazon_jp' exists for tenant A
- Create same code/platform/marketplace for tenant B (different tenant)
- Assert no validation errors; `Shop::count()` increased by 1

```
test_create_shop_allows_same_code_for_same_tenant_different_platform()
```
- Shop exists: tenant A, platform 'amazon', marketplace 'amazon_jp', code 'MAIN'
- Create: tenant A, platform 'shopify', marketplace '', code 'MAIN'
- Assert no validation errors; `Shop::count()` increased by 1

```
test_create_shop_allows_same_code_for_same_tenant_same_platform_different_marketplace()
```
- Shop exists: tenant A, platform 'amazon', marketplace 'amazon_jp', code 'MAIN'
- Create: tenant A, platform 'amazon', marketplace 'amazon_us', code 'MAIN'
- Assert no validation errors; `Shop::count()` increased by 1

```
test_create_shop_rejects_inactive_tenant()
```
- Inactive tenant exists
- Submit with that tenant's id as `tenant_id`
- Assert `assertHasErrors(['tenant_id'])`

```
test_create_shop_rejects_invalid_platform()
```
- Submit with `platform = 'ebay'`
- Assert `assertHasErrors(['platform'])`

```
test_toggle_shop_status()
```
- Active shop -> call `toggleStatus($shop->id)` -> assert `status = 'inactive'`
- Call again -> assert `status = 'active'`

```
test_non_internal_user_cannot_access_shop_pages()
```
- Tenant user visits /setup/shops and /setup/shops/create
- Both assert 403

```
test_shop_routes_render()
```
- GET /setup/shops, /setup/shops/create both return 200

---

## Implementation rules

- Run the migration to widen the unique constraint and change `marketplace` to `string default ''`
- No delete action -- deactivate only
- All wire properties must be `string` type
- Normalise `code` to uppercase before validation: `$this->code = strtoupper(trim($this->code))`
  at the start of `save()`
- `marketplace` is stored as empty string `''`, not NULL: use `trim($this->marketplace)`, never
  `nullableString()`, so the unique constraint works correctly on MySQL and SQLite
- `code` uniqueness is scoped to `(tenant_id, platform, marketplace)`:
  `Rule::unique('shops', 'code')->where('tenant_id', ...)->where('platform', ...)->where('marketplace', trim($this->marketplace))`
- `platform` validation uses `Rule::in(array_keys($this->platforms()))` to stay in sync with the
  platforms array
- `tenant_id` only accepts active tenants:
  `Rule::exists('tenants', 'id')->where('status', 'active')`
- The `Shop` model uses `LogsActivity` -- do NOT remove this trait
- Copy `isInternalUser()` with the `// TODO: remove unauthenticated fallback when auth is implemented` comment
- Use `Rule::unique(...)` and `Rule::exists(...)` not the string `'unique:...'` / `'exists:...'` syntax
- ASCII punctuation only in all strings
