# Task: Warehouse Location CRUD v1

## Stack

Laravel 13, Livewire 4 (class-based components, not Volt), Flux UI (`livewire/flux ^2.14`),
SQLite (dev), PHP 8.3. Plain Blade views only -- no TypeScript.

---

## What this task covers

Two pages and two Livewire components:

- `GET /setup/locations` -- WarehouseLocationIndex: list, filter, activate/deactivate
- `GET /setup/locations/create` -- WarehouseLocationCreate: create new location

**No new migration is needed if `warehouse_locations` already exists (created by the inbound-order-v1 migration 000013).
If it does not exist yet, implement the `warehouse_locations` migration from `inbound-order-v1.md` first before running this task.**
**No delete.** Locations may be referenced by `inbound_receipts.warehouse_location_id`. Deactivate instead.
**Internal users only.** Both pages must guard against non-internal users in `mount()`.

---

## Existing table schema (DO NOT modify)

```
warehouse_locations
  id
  warehouse_id        FK -> warehouses (restrictOnDelete)
  code                string, unique per warehouse  (unique constraint: warehouse_id + code)
  name                string nullable
  type                string default 'storage'      -- storage | receiving | qc | packing | shipping | hold | damaged | other
  status              string default 'active'       -- active | inactive
  note                text nullable
  created_at
  updated_at
```

---

## Model: `app/Models/WarehouseLocation.php`

This model is created by the inbound-order-v1 task. If it does not already exist, create it:

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WarehouseLocation extends Model
{
    use HasFactory;

    protected $fillable = [
        'warehouse_id', 'code', 'name', 'type', 'status', 'note',
    ];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function inboundReceipts(): HasMany
    {
        return $this->hasMany(InboundReceipt::class);
    }
}
```

If it already exists, do NOT redefine it — only add missing relationships if any are absent.

---

## Livewire Components

### `app/Livewire/WarehouseLocationIndex.php`

Use the `WithPagination` trait from Livewire:

```php
use Livewire\WithPagination;

class WarehouseLocationIndex extends Component
{
    use WithPagination;
    // ...
}
```

Add `updated*()` hooks to reset pagination whenever a filter changes:

```php
public function updatedWarehouseId(): void  { $this->resetPage(); }
public function updatedTypeFilter(): void   { $this->resetPage(); }
public function updatedStatusFilter(): void { $this->resetPage(); }
public function updatedSearch(): void       { $this->resetPage(); }
```

#### Wire properties

```php
#[Url(as: 'warehouse_id', except: '')]
public string $warehouseId = '';

#[Url(as: 'type', except: '')]
public string $typeFilter = '';

#[Url(as: 'status', except: '')]
public string $statusFilter = '';

#[Url(as: 'q', except: '')]
public string $search = '';
```

#### `mount()`

Guard: if not internal user, abort(403).

```php
public function mount(): void
{
    if (! $this->isInternalUser()) {
        abort(403);
    }
}
```

#### `toggleStatus(int $locationId)`

```php
public function toggleStatus(int $locationId): void
{
    $location = WarehouseLocation::findOrFail($locationId);
    $location->status = $location->status === 'active' ? 'inactive' : 'active';
    $location->save();
}
```

No confirmation needed -- reversible action.

#### `render()`

```php
public function render()
{
    $locations = WarehouseLocation::query()
        ->with('warehouse:id,code,name')
        ->when($this->warehouseId !== '', fn ($q) => $q->where('warehouse_id', (int) $this->warehouseId))
        ->when($this->typeFilter !== '', fn ($q) => $q->where('type', $this->typeFilter))
        ->when($this->statusFilter !== '', fn ($q) => $q->where('status', $this->statusFilter))
        ->when($this->search !== '', function ($q) {
            $like = '%' . $this->search . '%';
            $q->where(fn ($q) => $q
                ->where('code', 'like', $like)
                ->orWhere('name', 'like', $like)
            );
        })
        ->orderBy('warehouse_id')
        ->orderBy('code')
        ->paginate(50);

    return view('livewire.warehouse-location-index', [
        'locations' => $locations,
        'warehouses'=> Warehouse::query()->orderBy('name')->get(['id', 'code', 'name']),
        'types'     => $this->locationTypes(),
    ])->layout('inventory', [
        'title'    => __('locations.page_title'),
        'subtitle' => __('locations.page_subtitle'),
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

private function locationTypes(): array
{
    return [
        'storage'   => __('locations.type_storage'),
        'receiving' => __('locations.type_receiving'),
        'qc'        => __('locations.type_qc'),
        'packing'   => __('locations.type_packing'),
        'shipping'  => __('locations.type_shipping'),
        'hold'      => __('locations.type_hold'),
        'damaged'   => __('locations.type_damaged'),
        'other'     => __('locations.type_other'),
    ];
}
```

---

### `app/Livewire/WarehouseLocationCreate.php`

#### Wire properties

```php
#[Url(as: 'warehouse_id', except: '')]
public string $warehouseId = '';

public string $code   = '';
public string $name   = '';
public string $type   = 'storage';
public string $note   = '';
```

All properties that may receive numeric input must be `string` type.

#### `mount()`

Guard: if not internal user, abort(403). URL pre-fill of `$warehouseId` is preserved.

#### `save()`

```php
public function save()
{
    $code = $this->validateInput();

    WarehouseLocation::create([
        'warehouse_id' => (int) $this->warehouseId,
        'code'         => $code,
        'name'         => $this->nullableString($this->name),
        'type'         => $this->type,
        'status'       => 'active',
        'note'         => $this->nullableString($this->note),
    ]);

    session()->flash('status', __('locations.location_created'));

    return redirect()->route('setup.locations.index');
}
```

#### `validateInput(): string`

Returns the normalized code so `save()` can reuse the same value without re-normalizing.

```php
private function validateInput(): string
{
    $code = strtoupper(trim($this->code));

    validator([
        'warehouse_id' => $this->warehouseId,
        'code'         => $code,
        'name'         => $this->name,
        'type'         => $this->type,
        'note'         => $this->note,
    ], [
        'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')],
        'code'         => [
            'required', 'string', 'max:50',
            Rule::unique('warehouse_locations', 'code')
                ->where('warehouse_id', (int) $this->warehouseId),
        ],
        'name'         => ['nullable', 'string', 'max:255'],
        'type'         => ['required', 'string', Rule::in(['storage', 'receiving', 'qc', 'packing', 'shipping', 'hold', 'damaged', 'other'])],
        'note'         => ['nullable', 'string', 'max:1000'],
    ])->validate();

    return $code;
}
```

#### `render()`

```php
public function render()
{
    return view('livewire.warehouse-location-create', [
        'warehouses' => Warehouse::query()
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'code', 'name']),
        'types' => [
            'storage'   => __('locations.type_storage'),
            'receiving' => __('locations.type_receiving'),
            'qc'        => __('locations.type_qc'),
            'packing'   => __('locations.type_packing'),
            'shipping'  => __('locations.type_shipping'),
            'hold'      => __('locations.type_hold'),
            'damaged'   => __('locations.type_damaged'),
            'other'     => __('locations.type_other'),
        ],
    ])->layout('inventory', [
        'title'    => __('locations.create_page_title'),
        'subtitle' => __('locations.create_page_subtitle'),
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

## Routes

Add to `routes/web.php`:

```php
use App\Livewire\WarehouseLocationCreate;
use App\Livewire\WarehouseLocationIndex;

Route::get('/setup/locations', WarehouseLocationIndex::class)->name('setup.locations.index');
Route::get('/setup/locations/create', WarehouseLocationCreate::class)->name('setup.locations.create');
```

---

## Navigation

In `resources/views/components/layout/navigation.blade.php`, add a Setup section after the
Inbound/Outbound links.

**The existing navigation already uses Alpine.js dropdowns** (e.g. the Inventory dropdown uses
`x-data`, `@click.outside`, `x-show`, `x-cloak`). Follow that exact same pattern to add a new
Setup dropdown containing a Locations link:

```blade
@php
    $setupActive = request()->routeIs('setup.*');
@endphp

<div
    class="top-nav-item"
    x-data="{ open: false }"
    @click.outside="open = false"
    @keydown.escape.window="open = false"
>
    <button
        type="button"
        class="top-nav-btn {{ $setupActive ? 'is-active' : '' }}"
        @click="open = !open"
        :aria-expanded="open"
    >
        {{ __('common.nav_setup') }}
        <svg class="top-nav-chevron" :class="{ 'is-open': open }" viewBox="0 0 12 12" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
            <path d="M2.5 4.5L6 8L9.5 4.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
    </button>
    <div class="top-nav-dropdown" x-show="open" x-cloak>
        <a
            href="{{ route('setup.locations.index') }}"
            class="{{ request()->routeIs('setup.locations.*') ? 'is-active' : '' }}"
            wire:navigate
            @click="open = false"
        >
            {{ __('common.nav_locations') }}
        </a>
    </div>
</div>
```

Add to all four lang files (`lang/en/common.php`, `lang/ja/common.php`, `lang/zh_TW/common.php`,
`lang/zh_CN/common.php`) after the `nav_inbound` line:

```php
'nav_setup'      => 'Setup',
'nav_locations'  => 'Locations',
'nav_tenants'    => 'Tenants',
'nav_warehouses' => 'Warehouses',
```

Add all four keys even though Tenants and Warehouses pages do not exist yet.
A future task will add those links to the same Setup dropdown.

---

## Lang: `lang/en/locations.php`

```php
<?php

return [
    'page_title'          => 'Warehouse Locations',
    'page_subtitle'       => 'Manage storage locations within each warehouse.',
    'create_page_title'   => 'Create Location',
    'create_page_subtitle'=> 'Add a new storage location to a warehouse.',
    'field_warehouse'     => 'Warehouse',
    'field_code'          => 'Location code',
    'field_code_hint'     => 'Short identifier, e.g. A-01-02. Unique within the warehouse.',
    'field_name'          => 'Name',
    'field_type'          => 'Type',
    'field_status'        => 'Status',
    'field_note'          => 'Note',
    'btn_create'          => 'Create location',
    'btn_back'            => 'Back to locations',
    'btn_cancel'          => 'Cancel',
    'btn_submit'          => 'Create location',
    'btn_activate'        => 'Activate',
    'btn_deactivate'      => 'Deactivate',
    'type_storage'        => 'Storage',
    'type_receiving'      => 'Receiving',
    'type_qc'             => 'QC',
    'type_packing'        => 'Packing',
    'type_shipping'       => 'Shipping',
    'type_hold'           => 'Hold',
    'type_damaged'        => 'Damaged',
    'type_other'          => 'Other',
    'status_active'       => 'Active',
    'status_inactive'     => 'Inactive',
    'col_warehouse'       => 'Warehouse',
    'col_code'            => 'Code',
    'col_name'            => 'Name',
    'col_type'            => 'Type',
    'col_status'          => 'Status',
    'col_note'            => 'Note',
    'col_actions'         => 'Actions',
    'empty_state'         => 'No locations match the current filters.',
    'location_created'    => 'Location created.',
    'all_warehouses'      => 'All warehouses',
    'all_types'           => 'All types',
    'all_statuses'        => 'All statuses',
    'search_label'        => 'Search locations',
    'search_placeholder'  => 'Code or name...',
    'duplicate_code'      => 'This location code already exists in the selected warehouse.',
];
```

Also create `lang/ja/locations.php`, `lang/zh_TW/locations.php`, `lang/zh_CN/locations.php`
with identical content and a `// TODO: translate this file. English values are placeholders.` comment.

---

## Blade Views

### `resources/views/livewire/warehouse-location-index.blade.php`

Structure:
1. Flash message (session 'status') at top
2. Page actions row: "Create location" button linking to `route('setup.locations.create')` with `wire:navigate`
3. Toolbar: warehouse filter select, type filter select, status filter select, search input
4. Table columns: Warehouse, Code, Name, Type, Status badge, Note, Actions
5. Actions per row: if status is 'active', show "Deactivate" button calling `wire:click="toggleStatus({{ $location->id }})"`;
   if status is 'inactive', show "Activate" button calling the same method
6. Status badge: active = success color, inactive = zinc/muted color
7. Empty state row when no results
8. Pagination

### `resources/views/livewire/warehouse-location-create.blade.php`

Structure:
1. Single form panel
2. Fields: warehouse select (required), code input (required, uppercase hint), name input (optional),
   type select (storage/receiving/qc/packing/shipping/hold/damaged/other), note textarea (optional)
3. Sticky footer: back link to `route('setup.locations.index')`, submit button
4. Show validation errors with `<p class="form-error">` below each field

---

## Tests: `tests/Feature/WarehouseLocationTest.php`

```
test_create_location_succeeds()
```
- Internal user creates a location for a warehouse
- Assert redirect to `setup.locations.index`
- Assert `WarehouseLocation` record exists with correct code (uppercased), warehouse_id, type, status='active'

```
test_create_rejects_duplicate_code_in_same_warehouse()
```
- Create a location with code 'A-01', then try to create another with the same warehouse + code
- Assert `assertHasErrors(['code'])`

```
test_create_allows_same_code_in_different_warehouse()
```
- Create code 'A-01' in warehouse A, then create 'A-01' in warehouse B
- Assert both created successfully (count = 2)

```
test_toggle_status_switches_active_to_inactive()
```
- Create active location, call `toggleStatus()` from index component
- Assert `status === 'inactive'`

```
test_toggle_status_switches_inactive_to_active()
```
- Create inactive location, call `toggleStatus()`, assert `status === 'active'`

```
test_non_internal_user_cannot_access_location_pages()
```
- Tenant user (user_type = 'tenant') visits /setup/locations and /setup/locations/create
- Assert both return 403

```
test_location_routes_render()
```
- GET /setup/locations returns 200
- GET /setup/locations/create returns 200

```
test_warehouse_filter_shows_only_matching_warehouse()
```
- Create two locations in different warehouses (W1 code 'A-01', W2 code 'B-01')
- Set `warehouseId` to W1's id on the index component
- Assert only the W1 location appears in the rendered output
- Assert the W2 location does not appear

```
test_type_filter_shows_only_matching_type()
```
- Create two locations: one type='storage', one type='qc'
- Set `typeFilter = 'qc'` on the index component
- Assert only the qc location appears; storage location does not

```
test_status_filter_shows_only_matching_status()
```
- Create one active and one inactive location
- Set `statusFilter = 'inactive'`
- Assert only the inactive location appears

```
test_search_filters_by_code_and_name()
```
- Create location code='ALPHA-01', name='Alpha shelf'
- Create location code='BETA-02', name='Beta shelf'
- Set `search = 'ALPHA'`
- Assert ALPHA-01 appears; BETA-02 does not
- Set `search = 'Beta'`
- Assert BETA-02 appears; ALPHA-01 does not

---

## Implementation rules

- No new migration if `warehouse_locations` already exists; if not, run the migration from inbound-order-v1.md first
- No delete action -- locations may be referenced by `inbound_receipts`
- All wire properties must be `string` type
- `code` is stored uppercased: `strtoupper(trim($this->code))`
- Unique validation for `code` must be scoped to `warehouse_id`: `Rule::unique('warehouse_locations', 'code')->where('warehouse_id', (int) $this->warehouseId)`
- ASCII punctuation only in all strings -- no Unicode dashes, curly quotes, or garbled characters
- Use `app(Auth::class)` or `Auth::user()` for auth checks; never `auth()->user()` via global helper
- Copy `isInternalUser()` with the `// TODO: remove unauthenticated fallback when auth is implemented` comment
