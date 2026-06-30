# SKU View Settings - Stock Item Code Display v1

## Goal

Add a small **View Settings** control on the SKU index page so each user can choose how stock item codes are displayed.

This is a user preference, not a tenant-wide setting.

For v1, only support stock item code display:

- System code
- Tenant code
- Both

Future versions may add show/hide column preferences in the same View Settings modal.

## Background

Stock items now have two useful identifiers:

- `stock_items.code` - system-generated stable code, for example `CN001-XIA-000088`
- `stock_items.tenant_item_code` - tenant/customer-defined item code, for example `ABC-ITEM-0001`

Different users may prefer different display formats:

- Warehouse staff may prefer the system code.
- Tenant-facing operators may prefer tenant item code.
- Admin/debug workflows may want both.

## Scope

In scope:

- SKU index page only: `/skus`
- Add a View Settings button/icon
- Add a modal/panel for the setting
- Persist preference in `users.preferences`
- Add display helper on `StockItem`
- Update SKU index stock item code display
- Add tests

Out of scope:

- Inventory page
- Fulfillment page
- Outbound page
- Import/export behavior
- Tenant-wide default setting
- Column show/hide preferences

## Preference

Store in `users.preferences`:

```json
{
  "stock_item_code_display": "system"
}
```

Allowed values:

- `system`
- `tenant`
- `both`

Default:

- `system`

Do not store large UI state in `preferences`. This is only a small personal setting.

## Display Rules

Add a helper on `App\Models\StockItem`, for example:

```php
public function displayCode(string $mode = 'system'): string
```

Rules:

| Mode | Result |
| --- | --- |
| `system` | `code` |
| `tenant` | `tenant_item_code` if present, otherwise `code` |
| `both` | `tenant_item_code / code` if tenant item code is present, otherwise `code` |

Examples:

| System code | Tenant code | Mode | Display |
| --- | --- | --- | --- |
| `CN001-XIA-000088` | `ABC-ITEM-0001` | `system` | `CN001-XIA-000088` |
| `CN001-XIA-000088` | `ABC-ITEM-0001` | `tenant` | `ABC-ITEM-0001` |
| `CN001-XIA-000088` | `ABC-ITEM-0001` | `both` | `ABC-ITEM-0001 / CN001-XIA-000088` |
| `CN001-XIA-000088` | `null` | `tenant` | `CN001-XIA-000088` |
| `CN001-XIA-000088` | `null` | `both` | `CN001-XIA-000088` |

If an invalid preference value is found, fall back to `system`.

## UI

### SKU Index

Add a compact button near the SKU view tabs / top controls:

```text
Detailed  Catalog  Marketplace  Logistics        [View settings]
```

Button style:

- Use the existing setup/tab/link-button visual language.
- Keep it quiet; it should not look like a primary action.
- Icon is optional. If using an icon, use a settings/sliders icon if already available in the project.

### Modal

Clicking `View settings` opens a modal/panel.

Content:

```text
View settings

Stock item code
( ) System code
( ) Tenant code
( ) Both

[Save]
```

Notes:

- For v1, do not add column show/hide controls yet.
- The modal should use the shared modal style already used by SKU barcode/image modals.
- Saving should show a success toast.
- The table should update immediately after save.

## Component Changes

In `App\Livewire\SkusIndex`:

- Add a property for the modal open state.
- Add a property for `stockItemCodeDisplay`.
- On mount, load:

```php
$this->stockItemCodeDisplay = Auth::user()?->preference('stock_item_code_display', 'system') ?? 'system';
```

- Normalize invalid values to `system`.
- Add methods:

```php
public function openViewSettings(): void
public function saveViewSettings(): void
```

Save:

- Validate value is one of `system`, `tenant`, `both`.
- Save to user preference.
- Close modal.
- Flash success toast.

Guest users:

- Routes are authenticated, but still guard defensively.
- If no user, do not save.

## Blade Changes

Update SKU index stock item display to use the new helper.

Where code currently shows:

```php
$stockItem->code
```

replace the user-visible display with:

```php
$stockItem->displayCode($stockItemCodeDisplay)
```

Keep hidden IDs / links / internal logic unchanged.

Important:

- This is display only.
- Do not change search logic.
- Do not change import/export logic.
- Do not change `stock_items.code`.

## Language Keys

Add language keys for EN, JA, zh_TW, zh_CN.

Suggested EN:

```php
'view_settings' => 'View settings',
'view_settings_title' => 'View settings',
'stock_item_code_display' => 'Stock item code',
'stock_item_code_display_system' => 'System code',
'stock_item_code_display_tenant' => 'Tenant code',
'stock_item_code_display_both' => 'Both',
'view_settings_saved' => 'View settings saved.',
```

Use natural translations for other locales.

## Tests

Add/update tests in `tests/Feature/SkuManagementTest.php`.

Required tests:

1. Default mode shows system stock item code.
2. Tenant mode shows tenant item code.
3. Both mode shows `tenant item code / system code`.
4. Tenant mode falls back to system code when `tenant_item_code` is blank.
5. Saving View Settings persists preference to `users.preferences`.
6. Invalid stored preference falls back to system code.
7. Search still works with tenant item code; do not regress existing tenant-code search test.

Run targeted tests:

```bash
php artisan test tests/Feature/SkuManagementTest.php --filter='tenant_item_code|stock_item_code_display|view_settings'
vendor/bin/pint --dirty
```

## Acceptance Criteria

- SKU index has a quiet View Settings control.
- User can choose System code / Tenant code / Both.
- Preference persists after reload/login.
- Stock item code display changes according to the preference.
- Missing tenant item code safely falls back to system code.
- No import/export behavior changes.
- No database migration is needed.
