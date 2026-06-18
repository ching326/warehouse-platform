# Task: Tenant and Warehouse Setup v1

## Stack

Laravel 13, Livewire 4 (class-based components, not Volt), Flux UI (`livewire/flux ^2.14`),
SQLite (dev), PHP 8.3. Plain Blade views only -- no TypeScript.

---

## What this task covers

Four pages and four Livewire components:

- `GET /setup/tenants` -- TenantIndex: list, search, filter by status, activate/deactivate
- `GET /setup/tenants/create` -- TenantCreate: create new tenant
- `GET /setup/warehouses` -- WarehouseIndex: list, search, filter by status, activate/deactivate
- `GET /setup/warehouses/create` -- WarehouseCreate: create new warehouse

**No new migrations needed.** Both `tenants` and `warehouses` tables already exist.
**No delete.** Tenants and warehouses are referenced throughout the system. Deactivate instead.
**Internal users only.** All four pages must guard against non-internal users in `mount()`.

---

## Existing table schemas (DO NOT modify)

```
tenants
  id
  code               string unique
  name               string
  contact_name       string nullable
  contact_email      string nullable
  contact_phone      string nullable
  billing_terms      string nullable
  status             string default 'active'    -- active | inactive
  notes              text nullable
  created_at / updated_at

warehouses
  id
  code               string unique
  name               string
  country_code       string
  timezone           string default 'Asia/Tokyo'
  postal_code        string nullable
  state              string nullable
  city               string nullable
  address_line1      string nullable
  address_line2      string nullable
  phone              string nullable
  status             string default 'active'    -- active | inactive
  created_at / updated_at
```

The `Tenant` model uses the `Spatie\Activitylog\Traits\LogsActivity` trait with `logFillable()`.
Do NOT remove or change this trait. All `Tenant::create()` and `$tenant->save()` calls will
automatically be logged.

---

## Livewire Components

### `app/Livewire/TenantIndex.php`

Use the `WithPagination` trait:

```php
use Livewire\WithPagination;

class TenantIndex extends Component
{
    use WithPagination;
}
```

Add `updated*()` hooks to reset pagination when filters change:

```php
public function updatedSearch(): void       { $this->resetPage(); }
public function updatedStatusFilter(): void { $this->resetPage(); }
```

#### Wire properties

```php
#[Url(as: 'q', except: '')]
public string $search = '';

#[Url(as: 'status', except: '')]
public string $statusFilter = '';
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

#### `toggleStatus(int $tenantId)`

```php
public function toggleStatus(int $tenantId): void
{
    $tenant = Tenant::findOrFail($tenantId);
    $tenant->status = $tenant->status === 'active' ? 'inactive' : 'active';
    $tenant->save();
}
```

#### `render()`

```php
public function render()
{
    $tenants = Tenant::query()
        ->when($this->statusFilter !== '', fn ($q) => $q->where('status', $this->statusFilter))
        ->when($this->search !== '', function ($q) {
            $like = '%' . $this->search . '%';
            $q->where(fn ($q) => $q
                ->where('code', 'like', $like)
                ->orWhere('name', 'like', $like)
                ->orWhere('contact_name', 'like', $like)
                ->orWhere('contact_email', 'like', $like)
            );
        })
        ->orderBy('name')
        ->paginate(30);

    return view('livewire.tenant-index', [
        'tenants' => $tenants,
    ])->layout('inventory', [
        'title'    => __('setup.tenants_page_title'),
        'subtitle' => __('setup.tenants_page_subtitle'),
    ]);
}
```

#### Private helper

```php
// TODO: remove unauthenticated fallback when auth is implemented
private function isInternalUser(): bool
{
    $user = Auth::user();
    return ! $user || $user->user_type === 'internal';
}
```

---

### `app/Livewire/TenantCreate.php`

#### Wire properties

```php
public string $code         = '';
public string $name         = '';
public string $contactName  = '';
public string $contactEmail = '';
public string $contactPhone = '';
public string $billingTerms = '';
public string $status       = 'active';
public string $notes        = '';
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

    Tenant::create([
        'code'          => $this->code,
        'name'          => trim($this->name),
        'contact_name'  => $this->nullableString($this->contactName),
        'contact_email' => $this->nullableString($this->contactEmail),
        'contact_phone' => $this->nullableString($this->contactPhone),
        'billing_terms' => $this->nullableString($this->billingTerms),
        'status'        => $this->status,
        'notes'         => $this->nullableString($this->notes),
    ]);

    session()->flash('status', __('setup.tenant_created'));

    return redirect()->route('setup.tenants.index');
}
```

#### `validateInput()`

```php
private function validateInput(): void
{
    // $this->code has already been normalized to uppercase in save() before this call
    validator([
        'code'          => $this->code,
        'name'          => $this->name,
        'contact_name'  => $this->contactName,
        'contact_email' => $this->contactEmail,
        'contact_phone' => $this->contactPhone,
        'billing_terms' => $this->billingTerms,
        'status'        => $this->status,
        'notes'         => $this->notes,
    ], [
        'code'          => ['required', 'string', 'max:50', Rule::unique('tenants', 'code')],
        'name'          => ['required', 'string', 'max:255'],
        'contact_name'  => ['nullable', 'string', 'max:255'],
        'contact_email' => ['nullable', 'email', 'max:255'],
        'contact_phone' => ['nullable', 'string', 'max:50'],
        'billing_terms' => ['nullable', 'string', 'max:255'],
        'status'        => ['required', 'string', Rule::in(['active', 'inactive'])],
        'notes'         => ['nullable', 'string', 'max:2000'],
    ])->validate();
}
```

#### `render()`

```php
public function render()
{
    return view('livewire.tenant-create')
        ->layout('inventory', [
            'title'    => __('setup.tenant_create_page_title'),
            'subtitle' => __('setup.tenant_create_page_subtitle'),
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
```

---

### `app/Livewire/WarehouseIndex.php`

Use the `WithPagination` trait:

```php
use Livewire\WithPagination;

class WarehouseIndex extends Component
{
    use WithPagination;
}
```

Add `updated*()` hooks to reset pagination when filters change:

```php
public function updatedSearch(): void       { $this->resetPage(); }
public function updatedStatusFilter(): void { $this->resetPage(); }
```

#### Wire properties

```php
#[Url(as: 'q', except: '')]
public string $search = '';

#[Url(as: 'status', except: '')]
public string $statusFilter = '';
```

#### `mount()`

Guard: if not internal user, abort(403).

#### `toggleStatus(int $warehouseId)`

```php
public function toggleStatus(int $warehouseId): void
{
    $warehouse = Warehouse::findOrFail($warehouseId);
    $warehouse->status = $warehouse->status === 'active' ? 'inactive' : 'active';
    $warehouse->save();
}
```

#### `render()`

```php
public function render()
{
    $warehouses = Warehouse::query()
        ->when($this->statusFilter !== '', fn ($q) => $q->where('status', $this->statusFilter))
        ->when($this->search !== '', function ($q) {
            $like = '%' . $this->search . '%';
            $q->where(fn ($q) => $q
                ->where('code', 'like', $like)
                ->orWhere('name', 'like', $like)
                ->orWhere('city', 'like', $like)
            );
        })
        ->orderBy('name')
        ->paginate(30);

    return view('livewire.warehouse-index', [
        'warehouses' => $warehouses,
    ])->layout('inventory', [
        'title'    => __('setup.warehouses_page_title'),
        'subtitle' => __('setup.warehouses_page_subtitle'),
    ]);
}
```

#### Private helper

Same `isInternalUser()` as above with the `// TODO` comment.

---

### `app/Livewire/WarehouseCreate.php`

#### Wire properties

```php
public string $code         = '';
public string $name         = '';
public string $countryCode  = '';
public string $timezone     = 'Asia/Tokyo';
public string $postalCode   = '';
public string $state        = '';
public string $city         = '';
public string $addressLine1 = '';
public string $addressLine2 = '';
public string $phone        = '';
public string $status       = 'active';
```

#### `mount()`

Guard: if not internal user, abort(403).

#### `save()`

```php
public function save()
{
    $this->code        = strtoupper(trim($this->code));
    $this->countryCode = strtoupper(trim($this->countryCode));

    $this->validateInput();

    Warehouse::create([
        'code'          => $this->code,
        'name'          => trim($this->name),
        'country_code'  => $this->countryCode,
        'timezone'      => $this->timezone,
        'postal_code'   => $this->nullableString($this->postalCode),
        'state'         => $this->nullableString($this->state),
        'city'          => $this->nullableString($this->city),
        'address_line1' => $this->nullableString($this->addressLine1),
        'address_line2' => $this->nullableString($this->addressLine2),
        'phone'         => $this->nullableString($this->phone),
        'status'        => $this->status,
    ]);

    session()->flash('status', __('setup.warehouse_created'));

    return redirect()->route('setup.warehouses.index');
}
```

#### `validateInput()`

```php
private function validateInput(): void
{
    validator([
        'code'          => $this->code,
        'name'          => $this->name,
        'country_code'  => $this->countryCode,
        'timezone'      => $this->timezone,
        'postal_code'   => $this->postalCode,
        'state'         => $this->state,
        'city'          => $this->city,
        'address_line1' => $this->addressLine1,
        'address_line2' => $this->addressLine2,
        'phone'         => $this->phone,
        'status'        => $this->status,
    ], [
        'code'          => ['required', 'string', 'max:50', Rule::unique('warehouses', 'code')],
        'name'          => ['required', 'string', 'max:255'],
        'country_code'  => ['required', 'string', 'regex:/^[A-Z]{2}$/'],
        'timezone'      => ['required', 'string', 'timezone'],
        'postal_code'   => ['nullable', 'string', 'max:20'],
        'state'         => ['nullable', 'string', 'max:100'],
        'city'          => ['nullable', 'string', 'max:100'],
        'address_line1' => ['nullable', 'string', 'max:255'],
        'address_line2' => ['nullable', 'string', 'max:255'],
        'phone'         => ['nullable', 'string', 'max:50'],
        'status'        => ['required', 'string', Rule::in(['active', 'inactive'])],
    ])->validate();
}
```

`country_code` is a 2-letter uppercase ISO code (e.g. 'JP', 'HK', 'SG').
Use `'regex:/^[A-Z]{2}$/'` because this rejects numeric values like '12' that `'size:2'` would accept.
Since `save()` normalizes `$this->countryCode` to uppercase before validation, lowercase input
like 'jp' is accepted in the form and normalized before the regex check.
`timezone` uses Laravel's built-in `'timezone'` validation rule.

#### `render()`

```php
public function render()
{
    return view('livewire.warehouse-create')
        ->layout('inventory', [
            'title'    => __('setup.warehouse_create_page_title'),
            'subtitle' => __('setup.warehouse_create_page_subtitle'),
        ]);
}
```

#### Private helpers

Same `isInternalUser()` and `nullableString()` as TenantCreate.

---

## Routes

Add to `routes/web.php`:

```php
use App\Livewire\TenantCreate;
use App\Livewire\TenantIndex;
use App\Livewire\WarehouseCreate;
use App\Livewire\WarehouseIndex;

Route::get('/setup/tenants', TenantIndex::class)->name('setup.tenants.index');
Route::get('/setup/tenants/create', TenantCreate::class)->name('setup.tenants.create');
Route::get('/setup/warehouses', WarehouseIndex::class)->name('setup.warehouses.index');
Route::get('/setup/warehouses/create', WarehouseCreate::class)->name('setup.warehouses.create');
```

---

## Navigation

In `resources/views/components/layout/navigation.blade.php`, extend the existing "Setup" dropdown
(added by the warehouse-location-crud task) to include Tenants and Warehouses links.

If the Setup dropdown does not yet exist, create it following the same Alpine.js pattern as the
Inventory dropdown.

The full Setup dropdown should contain these three items in order:

```blade
<a href="{{ route('setup.tenants.index') }}"
   class="{{ request()->routeIs('setup.tenants.*') ? 'is-active' : '' }}"
   wire:navigate @click="open = false">
    {{ __('common.nav_tenants') }}
</a>
<a href="{{ route('setup.warehouses.index') }}"
   class="{{ request()->routeIs('setup.warehouses.*') ? 'is-active' : '' }}"
   wire:navigate @click="open = false">
    {{ __('common.nav_warehouses') }}
</a>
<a href="{{ route('setup.locations.index') }}"
   class="{{ request()->routeIs('setup.locations.*') ? 'is-active' : '' }}"
   wire:navigate @click="open = false">
    {{ __('common.nav_locations') }}
</a>
```

If `nav_setup`, `nav_tenants`, `nav_warehouses`, `nav_locations` keys are not yet present in the
lang files, add them to all four: `lang/en/common.php`, `lang/ja/common.php`,
`lang/zh_TW/common.php`, `lang/zh_CN/common.php`.

```php
'nav_setup'      => 'Setup',
'nav_tenants'    => 'Tenants',
'nav_warehouses' => 'Warehouses',
'nav_locations'  => 'Locations',
```

---

## Lang: `lang/en/setup.php`

```php
<?php

return [
    // Tenants
    'tenants_page_title'          => 'Tenants',
    'tenants_page_subtitle'       => 'Manage tenant accounts and their contact details.',
    'tenant_create_page_title'    => 'Create Tenant',
    'tenant_create_page_subtitle' => 'Add a new tenant to the platform.',
    'field_code'                  => 'Code',
    'field_code_hint'             => 'Short unique identifier, e.g. ABC. Stored uppercase.',
    'field_name'                  => 'Name',
    'field_contact_name'          => 'Contact name',
    'field_contact_email'         => 'Contact email',
    'field_contact_phone'         => 'Contact phone',
    'field_billing_terms'         => 'Billing terms',
    'field_status'                => 'Status',
    'field_notes'                 => 'Notes',
    'tenant_created'              => 'Tenant created.',
    'tenant_col_code'             => 'Code',
    'tenant_col_name'             => 'Name',
    'tenant_col_contact'          => 'Contact',
    'tenant_col_billing'          => 'Billing terms',
    'tenant_col_status'           => 'Status',
    'tenant_col_actions'          => 'Actions',
    'tenant_empty_state'          => 'No tenants match the current filters.',

    // Warehouses
    'warehouses_page_title'          => 'Warehouses',
    'warehouses_page_subtitle'       => 'Manage warehouse locations and their addresses.',
    'warehouse_create_page_title'    => 'Create Warehouse',
    'warehouse_create_page_subtitle' => 'Add a new warehouse to the platform.',
    'field_country_code'             => 'Country code',
    'field_country_code_hint'        => '2-letter ISO code, e.g. JP, HK, SG.',
    'field_timezone'                 => 'Timezone',
    'field_postal_code'              => 'Postal code',
    'field_state'                    => 'State / Prefecture',
    'field_city'                     => 'City',
    'field_address_line1'            => 'Address line 1',
    'field_address_line2'            => 'Address line 2',
    'field_phone'                    => 'Phone',
    'warehouse_created'              => 'Warehouse created.',
    'warehouse_col_code'             => 'Code',
    'warehouse_col_name'             => 'Name',
    'warehouse_col_location'         => 'Location',
    'warehouse_col_timezone'         => 'Timezone',
    'warehouse_col_status'           => 'Status',
    'warehouse_col_actions'          => 'Actions',
    'warehouse_empty_state'          => 'No warehouses match the current filters.',

    // Shared
    'btn_create_tenant'    => 'Create tenant',
    'btn_create_warehouse' => 'Create warehouse',
    'btn_back_tenants'     => 'Back to tenants',
    'btn_back_warehouses'  => 'Back to warehouses',
    'btn_cancel'           => 'Cancel',
    'btn_activate'         => 'Activate',
    'btn_deactivate'       => 'Deactivate',
    'status_active'        => 'Active',
    'status_inactive'      => 'Inactive',
    'all_statuses'         => 'All statuses',
    'search_label'         => 'Search',
    'search_tenants_placeholder'    => 'Code, name, contact...',
    'search_warehouses_placeholder' => 'Code, name, city...',
];
```

Also create `lang/ja/setup.php`, `lang/zh_TW/setup.php`, `lang/zh_CN/setup.php`
with identical content and a `// TODO: translate this file. English values are placeholders.` comment.

---

## Blade Views

### `resources/views/livewire/tenant-index.blade.php`

Structure:
1. Flash message (session 'status') at top
2. Page actions row: "Create tenant" button linking to `route('setup.tenants.create')` with `wire:navigate`
3. Toolbar: status filter select, search input
4. Table columns: Code, Name, Contact (name + email stacked), Billing terms, Status badge, Actions
5. Actions: if active show "Deactivate" button, if inactive show "Activate" button; both call `wire:click="toggleStatus({{ $tenant->id }})"`
6. Status badge: active = success, inactive = zinc
7. Empty state, pagination

### `resources/views/livewire/tenant-create.blade.php`

Structure:
1. Two panels side by side (or stacked on mobile):
   - Panel 1: Code, Name, Status
   - Panel 2: Contact name, Contact email, Contact phone, Billing terms
2. Full-width panel: Notes textarea
3. Sticky footer: back link, submit button
4. Show validation errors below each field

### `resources/views/livewire/warehouse-index.blade.php`

Structure:
1. Flash message at top
2. Page actions row: "Create warehouse" button
3. Toolbar: status filter, search input
4. Table columns: Code, Name, Location (city + country stacked), Timezone, Status badge, Actions
5. Same activate/deactivate pattern as tenant-index
6. Empty state, pagination

### `resources/views/livewire/warehouse-create.blade.php`

Structure:
1. Panel 1 -- Identity: Code, Name, Status
2. Panel 2 -- Region: Country code, Timezone, City, State, Postal code
3. Panel 3 -- Address: Address line 1, Address line 2, Phone
4. Sticky footer: back link, submit button
5. Country code hint shown below field
6. Show validation errors below each field

---

## Tests: `tests/Feature/TenantWarehouseSetupTest.php`

```
test_create_tenant_succeeds()
```
- Internal user creates tenant with code 'XYZ', name 'XYZ Corp'
- Assert redirect to `setup.tenants.index`
- Assert `Tenant` exists with `code = 'XYZ'`, `status = 'active'`

```
test_create_tenant_rejects_duplicate_code()
```
- Tenant with code 'ABC' already exists
- Try to create another with code 'abc' (case varies)
- Assert `assertHasErrors(['code'])`

Note: `save()` normalizes `$this->code = strtoupper(trim($this->code))` before calling
`validateInput()`, so submitting `abc` is checked and stored as `ABC`.

```
test_create_tenant_uppercases_code()
```
- Submit tenant with code `xyz`, name `XYZ Corp`
- Assert stored `code = 'XYZ'`

```
test_create_warehouse_uppercases_code_and_country_code()
```
- Submit warehouse with code `jp-osk-01`, country_code `jp`, timezone `Asia/Tokyo`
- Assert stored `code = 'JP-OSK-01'`
- Assert stored `country_code = 'JP'`

```
test_toggle_tenant_status()
```
- Active tenant -> call `toggleStatus()` -> assert inactive
- Call again -> assert active

```
test_create_warehouse_succeeds()
```
- Internal user creates warehouse with code 'JP-OSK-01', country_code 'JP', timezone 'Asia/Tokyo'
- Assert redirect to `setup.warehouses.index`
- Assert `Warehouse` record with `code = 'JP-OSK-01'`, `country_code = 'JP'`

```
test_create_warehouse_rejects_invalid_timezone()
```
- Submit with `timezone = 'Not/ATimezone'`
- Assert `assertHasErrors(['timezone'])`

```
test_create_warehouse_rejects_invalid_country_code()
```
- Submit with `country_code = 'JPN'` (3 letters)
- Assert `assertHasErrors(['country_code'])`

```
test_toggle_warehouse_status()
```
- Same pattern as tenant status toggle

```
test_non_internal_user_cannot_access_setup_pages()
```
- Tenant user visits /setup/tenants, /setup/tenants/create, /setup/warehouses, /setup/warehouses/create
- All four assert 403

```
test_setup_routes_render()
```
- GET /setup/tenants, /setup/tenants/create, /setup/warehouses, /setup/warehouses/create all return 200

---

## Implementation rules

- No new migrations -- `tenants` and `warehouses` tables already exist
- No delete action on either index -- deactivate only
- All wire properties must be `string` type
- Normalise `code` to uppercase before validation: `$this->code = strtoupper(trim($this->code))` at start of `save()`
- Normalise `countryCode` to uppercase before validation: `$this->countryCode = strtoupper(trim($this->countryCode))` at start of `WarehouseCreate::save()`
- The `Tenant` model uses `LogsActivity` -- do NOT remove this trait; activity logging happens automatically
- `country_code` validation: `'regex:/^[A-Z]{2}$/'` -- rejects digits; `'size:2'` is insufficient
- `timezone` validation: use Laravel's built-in `'timezone'` rule
- ASCII punctuation only in all strings
- Copy `isInternalUser()` with the `// TODO: remove unauthenticated fallback when auth is implemented` comment
- Use `Rule::unique(...)` not the string `'unique:table,column'` syntax
