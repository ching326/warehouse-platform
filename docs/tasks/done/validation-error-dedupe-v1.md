# Validation Error Dedupe v1

## Goal

Remove duplicated validation error messages across the system.

When a Flux field already renders its own validation error under the input/select/textarea
with the warning triangle icon, do not also render a manual `@error(...)` message for the
same field.

The final UI should show only one error message per field.

## Problem

Some pages show the same validation error twice:

- one message rendered by the Flux field component, with the triangle icon
- one extra manual Blade message, usually:

```blade
@error('fieldName') <p class="form-error">{{ $message }}</p> @enderror
```

Example:

```text
The reship reason field is required.
The reship reason field is required.
```

## Desired Rule

### Flux fields

For these field types, **remove the manual `@error(...)` line** for the same model:

- `<flux:input wire:model="...">`
- `<flux:select wire:model="...">`
- `<flux:textarea wire:model="...">`
- Flux fields using `wire:model.live`, `wire:model.blur`, etc.

Keep the Flux-rendered error only.

### Non-Flux / Custom fields

Keep manual `@error(...)` when the field is not a Flux field, for example:

- plain `<input>`
- plain `<select>`
- plain `<textarea>`
- custom components such as `<x-searchable-select>`
- hidden fields
- array row inputs in custom grids/tables
- whole-form or group errors, e.g. `reshipLines`, `selectedIds`, `lines`, `items`

These need manual errors because the component may not render them automatically.

## Scope

Search all Blade files under:

- `resources/views/livewire`
- `resources/views/components`
- `resources/views`

Find every `@error(` and decide whether it duplicates a nearby Flux field.

Do not change validation rules.
Do not change error wording.
Do not change form layout except removing duplicate manual messages.

## Specific Known Example

In the Sales Order reship modal:

- `reshipReason` is a `<flux:select>`, so remove the manual:

```blade
@error('reshipReason') <p class="form-error">{{ $message }}</p> @enderror
```

Do the same for other nearby Flux fields if they duplicate.

But keep manual errors for:

- custom grid reship qty inputs
- additional SKU searchable select row errors
- group error `reshipLines`

## Implementation Steps

1. Search:

```bash
rg -n "@error\\(" resources/views
```

2. For each hit, inspect the surrounding field.

3. Remove manual `@error(...)` only when the same field is already a Flux field.

4. Keep manual errors for non-Flux fields and group-level errors.

5. After edits, run targeted render/feature tests for touched pages only.

Suggested targeted tests, depending on touched files:

```bash
php artisan test tests/Feature/ReshipSalesOrderTest.php
php artisan test tests/Feature/SalesOrderTest.php --filter=validation
php artisan test tests/Feature/OutboundOrderTest.php --filter=validation
```

Run broader tests only if the touched files are broad enough to justify it.

## Cleanup Pass

Before committing:

- Confirm no CJK locale files were touched.
- Confirm no validation logic changed.
- Confirm no unrelated UI/style changes were made.
- Confirm each removed manual error has a matching Flux field.
- Check `git diff` for accidental encoding or line-ending churn.

## Language / Encoding Rule

Do not edit `lang/ja`, `lang/zh_TW`, or `lang/zh_CN`.

If a new English string is somehow needed, add it to `lang/en` only and record it in
`docs/translation-backlog.md`.

Do not rewrite files with PowerShell `Set-Content`, `Out-File`, or shell redirects.
Use surgical edits only.

## Acceptance Criteria

- Duplicate field-level validation messages are removed.
- Flux field errors keep the triangle icon and remain visible.
- Custom/non-Flux field errors still show correctly.
- No CJK locale files changed.
- Targeted tests pass.
