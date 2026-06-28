# Task: FBA Warehouse CRUD v1

## Stack

Laravel 13, Livewire 4 (class-based components, not Volt), Flux UI (`livewire/flux ^2.14`),
SQLite (dev), PHP 8.3. Plain Blade views only -- no TypeScript.

---

## Goal

Add a Setup page for maintaining Amazon FBA warehouse destination addresses.

For v1, this is only a master-data page. It does not create FBA shipments, call Amazon APIs,
or change inventory. It exists so future workflows can choose an FBA destination without
staff retyping the address every time.

Expected future consumers:

- Return Orders: disposition "send to FBA"
- Outbound / Ship Order: non-platform shipment "ship to FBA"
- Courier export / label creation address autofill
- Future Amazon FBA inbound / removal workflows

---

## What this task covers

Create a new internal-only Setup module:

- `GET /setup/fba-warehouses` -- `FbaWarehouseIndex`
- `GET /setup/fba-warehouses/create` -- `FbaWarehouseCreate`
- `GET /setup/fba-warehouses/{fbaWarehouse}/edit` -- `FbaWarehouseEdit`

Also add it to the Setup sub-navigation.

**Internal users only.** Tenant users must receive 403 for all pages.

**No hard delete.** FBA warehouses may be referenced by future return/outbound records.
Use `status = inactive` to hide old destinations from new dropdowns.

**Japan only for v1 UI.** `country_code` should be a dropdown with only `JP` for now,
but the schema must support other countries later.

---

## Data Model

Create table `fba_warehouses`.

```php
Schema::create('fba_warehouses', function (Blueprint $table) {
    $table->id();
    $table->string('country_code', 2)->default('JP');
    $table->string('code');
    $table->string('name');
    $table->string('postal_code')->nullable();
    $table->string('state')->nullable();
    $table->string('city')->nullable();
    $table->string('address_line1')->nullable();
    $table->string('address_line2')->nullable();
    $table->string('phone')->nullable();
    $table->string('status')->default('active'); // active | inactive
    $table->text('note')->nullable();
    $table->timestamps();

    $table->unique(['country_code', 'code']);
    $table->index(['country_code', 'status']);
});
```

### Field meaning

- `country_code`: ISO country code. v1 UI only allows `JP`.
- `code`: FBA fulfillment center / destination code or internal short code. Store uppercase.
  Examples: `NRT1`, `KIX2`, `HND9`, `FBA-JP-01`.
- `name`: human-readable name, e.g. `Amazon FBA NRT1`.
- `state`: for JP, this is prefecture.
- `city`, `address_line1`, `address_line2`: address parts.
- `phone`: optional. Some courier formats may require a phone later; allow blank for now.
- `status`: `active` or `inactive`.
- `note`: internal operational note.

No tenant_id in v1. These are global destinations maintained by internal staff.

---

## Model

Create `app/Models/FbaWarehouse.php`.

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FbaWarehouse extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';

    protected $fillable = [
        'country_code',
        'code',
        'name',
        'postal_code',
        'state',
        'city',
        'address_line1',
        'address_line2',
        'phone',
        'status',
        'note',
    ];
}
```

Add a factory for tests.

Activity log is optional for v1. If nearby setup models are using activity logging,
follow that existing pattern; otherwise do not add it only for this task.

---

## UI / Pages

### Setup navigation

Add `FBA Warehouse` to the Setup sub-nav in `resources/views/inventory.blade.php`.

Recommended order:

`Tenant | Shop | Warehouse | FBA Warehouse | Shipping Method | Location | Packaging | Other Setting`

Route active state:

```php
request()->routeIs('setup.fba-warehouses.*')
```

Add lang key in `lang/en/common.php`:

```php
'nav_fba_warehouses' => 'FBA Warehouse',
```

Locale files may use fallback if the project pattern is `return [];`.

---

## Index Page

Component: `app/Livewire/FbaWarehouseIndex.php`

View: `resources/views/livewire/fba-warehouse-index.blade.php`

Route: `/setup/fba-warehouses`

### Filters

- Country: dropdown. v1 options: `All countries`, `JP`.
- Status: dropdown. `All statuses`, `Active`, `Inactive`.
- Search: code, name, postal code, state, city, address, phone, note.

Use URL-bound properties:

```php
#[Url(as: 'country', except: '')]
public string $countryCode = '';

#[Url(as: 'status', except: '')]
public string $statusFilter = '';

#[Url(as: 'q', except: '')]
public string $search = '';
```

Use `WithPagination`. Reset pagination on filter changes.

### Columns

- Code
- Name
- Country
- Postal code
- Address
- Phone
- Status
- Note
- Actions

Address display:

```text
Tokyo Shibuya-ku
1-2-3 Example Building 4F
```

Keep the table readable. Truncate long address/note text with ellipsis or two-line clamp.

### Actions

- Create FBA warehouse button aligned right.
- Edit link/button.
- Inline activate/deactivate button is acceptable, matching other Setup pages.

No delete action.

---

## Create / Edit Pages

Components:

- `app/Livewire/FbaWarehouseCreate.php`
- `app/Livewire/FbaWarehouseEdit.php`

Views:

- `resources/views/livewire/fba-warehouse-create.blade.php`
- `resources/views/livewire/fba-warehouse-edit.blade.php`

Fields:

- Country code: dropdown, v1 only `JP`
- Code: required
- Name: required
- Postal code
- State / Prefecture
- City
- Address line 1
- Address line 2
- Phone
- Status
- Note

Use compact operational form styling consistent with Warehouse / Shop setup pages.

### Japan postal code autofill

If the app already has reusable JP postal-code autofill code, use it.
If not, do not build a new external API integration in this task.

For v1, manual entry is acceptable.

---

## Validation

Create:

```php
country_code: required|string|size:2|in:JP
code: required|string|max:50|unique:fba_warehouses,code,NULL,id,country_code,JP
name: required|string|max:255
postal_code: nullable|string|max:30
state: nullable|string|max:100
city: nullable|string|max:100
address_line1: nullable|string|max:255
address_line2: nullable|string|max:255
phone: nullable|string|max:50
status: required|in:active,inactive
note: nullable|string|max:2000
```

Edit:

Use the same validation, but ignore the current row for the unique `(country_code, code)` check.

Normalize before save:

- `country_code` uppercase
- `code` uppercase and trimmed
- empty strings -> `null` for optional fields

---

## Authorization / Tenant Scope

All three pages are internal-only:

```php
private function isInternalUser(): bool
{
    return auth()->user()?->user_type === 'internal';
}
```

In `mount()`, abort(403) if not internal.

Do not use the old unsafe pattern where a guest can be treated as internal.

---

## Routes

Add inside the authenticated route group:

```php
Route::get('/setup/fba-warehouses', FbaWarehouseIndex::class)->name('setup.fba-warehouses.index');
Route::get('/setup/fba-warehouses/create', FbaWarehouseCreate::class)->name('setup.fba-warehouses.create');
Route::get('/setup/fba-warehouses/{fbaWarehouse}/edit', FbaWarehouseEdit::class)->name('setup.fba-warehouses.edit');
```

Use route-model binding for edit, then still enforce internal-only access in `mount()`.

---

## Language Keys

Add keys to `lang/en/setup.php` or another existing setup lang file used by nearby pages.

Suggested keys:

```php
'fba_warehouses_page_title' => 'FBA Warehouse',
'fba_warehouses_page_subtitle' => 'Manage FBA destination addresses for outbound and return workflows.',
'fba_warehouse_create_page_title' => 'Create FBA Warehouse',
'fba_warehouse_edit_page_title' => 'Edit FBA Warehouse',
'fba_warehouse_created' => 'FBA warehouse created.',
'fba_warehouse_updated' => 'FBA warehouse updated.',
'fba_warehouse_code' => 'Code',
'fba_warehouse_name' => 'Name',
'fba_warehouse_country' => 'Country',
'fba_warehouse_postal_code' => 'Postal code',
'fba_warehouse_state' => 'State / Prefecture',
'fba_warehouse_city' => 'City',
'fba_warehouse_address_line1' => 'Address line 1',
'fba_warehouse_address_line2' => 'Address line 2',
'fba_warehouse_phone' => 'Phone',
'fba_warehouse_note' => 'Note',
'fba_warehouse_empty' => 'No FBA warehouses found.',
```

Use existing common status keys if available.

---

## Seed Data

Optional but useful for local/demo:

Seed one or two JP FBA warehouse examples only if the app has a setup/demo seeder pattern.

If seeded, use realistic-looking dummy data, not actual sensitive/customer data.

Example:

```php
FbaWarehouse::updateOrCreate(
    ['country_code' => 'JP', 'code' => 'FBA-JP-DEMO'],
    [
        'name' => 'Amazon FBA Japan Demo',
        'postal_code' => '272-0000',
        'state' => 'Chiba',
        'city' => 'Ichikawa',
        'address_line1' => 'Demo address 1-1-1',
        'status' => 'active',
    ],
);
```

Seed is optional. Tests should not depend on seed data.

---

## Tests

Create `tests/Feature/FbaWarehouseSetupTest.php`.

Required tests:

1. Internal user can open index page.
2. Tenant user gets 403 for index/create/edit.
3. Create page creates an active JP FBA warehouse.
4. Create normalizes code to uppercase.
5. Duplicate `(country_code, code)` is rejected with validation error.
6. Edit page updates address/status/note.
7. Index filters by country.
8. Index filters by status.
9. Index search matches code/name/address/phone.
10. Inactive FBA warehouse remains visible when status filter = inactive.
11. There is no delete action in the rendered HTML.
12. Setup nav contains FBA Warehouse and highlights it on setup FBA routes.

Run targeted tests:

```bash
php artisan test tests/Feature/FbaWarehouseSetupTest.php
```

If nearby setup tests are affected, run their targeted files too.

---

## Acceptance Criteria

- Internal users can create, edit, search, filter, activate, and deactivate FBA warehouses.
- Tenant users cannot access the pages.
- v1 UI only allows `JP`, but the schema supports other countries later.
- Setup sub-nav includes `FBA Warehouse`.
- No hard delete exists.
- Code is unique per country and stored uppercase.
- The page follows existing Setup UI density and button style.
- Targeted tests pass.

---

## Out of Scope

- Amazon API integration.
- FBA shipment creation.
- Upload/import official Amazon FBA fulfillment center list.
- Tenant-specific FBA warehouse overrides.
- Inventory movement.
- Courier label export changes.
- Return Order / Outbound integration. This task only creates the master-data page.

