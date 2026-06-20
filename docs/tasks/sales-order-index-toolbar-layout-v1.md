# Task: Sales Orders Index Toolbar + Action Layout v1

## Goal

Reorganize the Sales Orders index toolbar so filters, page-level actions, and selection actions are
clearly separated by intent, and consolidate the export sprawl.

This task is layout / information-architecture / button-grouping only. It does not change filter query
behavior (that lives in `docs/tasks/sales-order-index-filters-ui-v1.md`) and does not change courier
export logic (that lives in the courier export task).

Reserve a slot for a future `Mark Shipped` bulk action in the selection action bar.

---

## Current State (problems)

The current toolbar mixes three different intents on the same surface:

1. Filters and a page action are mixed: `Create order` sits at the end of the filter row next to
   Shop / Fulfillment / Order status / Search.
2. Export is everywhere. There are six export entry points on one screen:
   - Export CSV
   - Export XLSX
   - Export selected (CSV)
   - Export selected (XLSX)
   - Export Yamato CSV
   - Export Sagawa CSV
   `Export CSV` (all) and `Export selected (CSV)` are easy to confuse and click by mistake.
3. `Import CSV` stretches to full row width and looks broken.
4. The selection/bulk row mixes two intents: status changes (Mark Ready / Hold / Release / Cancel)
   and data export (selected CSV/XLSX, Yamato, Sagawa) on one line.
5. Button variants are inconsistent: bare ghost text links, outline, and solid are mixed, so there is
   no clear visual hierarchy and no single obvious primary action.

---

## Design Principle: three intent zones

Separate the toolbar into three zones by intent:

```text
[ Find orders / Filters ]     -> only finds orders, never changes anything
[ Page actions ]              -> Create / Import / Export all
[ Selection actions ]         -> act on the currently selected rows; active only when selection > 0
```

A control should live in exactly one zone. Do not place create/import/export in the filter zone, and
do not place row-level bulk actions in the page-action zone.

---

## Layout

### Row 1: Filters (find orders only)

```text
Shop  Fulfillment  Order status  [ future: Platform / Shipping / Date ] ............ [ Search ]
```

- Filters only. Search sits on the right and should feel like a wide global search.
- Do not put Create / Import / Export in this row.
- The exact set of filters and their behavior are owned by
  `docs/tasks/sales-order-index-filters-ui-v1.md`. This task only fixes where they sit and that the
  row contains filters and nothing else.

### Row 2: Page actions (right-aligned cluster)

```text
.................................................. [ Import v ] [ Export v ] [ + Create order ]
```

- `Create order` is the single primary action (solid teal). Place it at the far right as the clear CTA.
- `Export v` is a single menu that replaces the two bare links:
  - Export all (CSV)
  - Export all (XLSX)
  - These carry the current filter set in the URL, same as today.
- `Import` keeps the outline style but at normal button width. Do not stretch it to full row width.
- A native `<details>` menu is acceptable for v1 if it is styled as a compact button/menu and does not
  look like a raw browser disclosure widget.
- Do not use bare ghost text links for export actions.

### Row 3: Order date filter

- Owned by the filters task. Keep it as its own row directly under the filters.
- This task only reserves the row position so the date control is not crammed into Row 1.

### Selection action bar (contextual)

Active only when `selected > 0`. Split into two clearly separated groups with a visual divider:

```text
[ N selected ]   Status: [ Mark Ready ] [ Mark Shipped ] [ Hold ] [ Release ] [ Cancel ]   |   Export: [ Selected v ] [ Courier v ]
```

- Status group: status transitions on the selected orders.
  - `Mark Ready`, `Mark Shipped` (new, see below), `Hold`, `Release hold`.
  - `Cancel` uses the danger variant and sits at the far right of the status group, visually separated
    from the safe actions to avoid mis-clicks.
- Export group:
  - `Selected v` menu: Selected (CSV) / Selected (XLSX).
  - `Courier v` menu: Export Yamato CSV / Export Sagawa CSV.

Net effect on the bulk row: from eight loose buttons down to the status buttons plus two menus.

---

## Export consolidation (the main win)

Collapse six export entry points into three menus:

| Current | After |
| --- | --- |
| Export CSV / Export XLSX | `Export v` (page, Row 2) |
| Export selected (CSV) / (XLSX) | `Selected v` (selection bar) |
| Export Yamato / Sagawa | `Courier v` (selection bar) |

Rules:

- The page `Export v` exports the current filtered list (existing behavior, filters in URL).
- The selection `Selected v` and `Courier v` act on selected ids only.
- Menus may be implemented with styled native `<details>` elements for v1; keep the menu labels and
  menu items visually consistent with the existing Flux-style buttons.
- Keep the existing v4 order-id export guard: empty `ids=` must not export everything as selected, and
  selected ids take priority over filters.
- Courier export is a daily action on a shipping surface; keeping it in the selection bar (not buried
  in the page menu) keeps it prominent while removing the two extra loose buttons.

---

## New action: Mark Shipped

A future bulk action will be added to the Status group. Reserve its slot now.

Naming:

- Use `Mark Shipped` for consistency with the existing `Mark Ready` button, so the status group reads
  as a consistent verb set: Mark Ready / Mark Shipped / Hold / Release / Cancel.
- Alternatives considered: `Confirm Shipment`, `Mark as Shipped`. Prefer `Mark Shipped`.

Scope of this task:

- Reserve the button position in the Status group between `Mark Ready` and `Hold`.
- Ensure the group does not overflow on a 12-inch laptop when this button is present.
- Do not implement the shipped transition logic in this layout task.

Expected behavior for the later implementation task (informational, not built here):

- Bulk transition selected orders to `fulfillment_status = shipped`.
- Likely eligible from `ready` / `in_group` only; block `unfulfilled`, `cancelled`, and already
  `shipped`.
- May require or record a tracking number and a shipped timestamp.
- Should follow the same bulk pattern as `bulkMarkReady` (tenant-scoped, clears selection, flashes a
  result). Define the full state-machine rules in a dedicated `mark-shipped` task.

---

## Button Variant Rules

- Exactly one solid primary on the page: `Create order`.
- `Cancel` uses the danger variant.
- Everything else uses outline / secondary or menu buttons.
- Do not use bare ghost text as a button (the current `Export CSV` / `Export XLSX` links).
- Disabled bulk/selection actions when `selected = 0` keep the current disabled + `aria-disabled`
  treatment.

---

## Selection Bar Visibility

- When `selected = 0`, the selection bar may collapse to a thin hint such as
  `Select orders to ship or export`, or remain visible with all actions disabled (current behavior is
  acceptable).
- Reason: free vertical space for the table, since the filters task adds a date row and a default
  `All dates + active orders` view.
- Whichever is chosen, the selected-count badge must stay visible so users always know the selection
  state.

---

## Coordination with other tasks

- `docs/tasks/sales-order-index-filters-ui-v1.md` owns Row 1 / Row 3 filter behavior, the multi-select
  filters, the date presets, and the active-only default. This task must not redefine that behavior;
  it only fixes zone placement and button grouping.
- After the rendered filters UI review, apply this toolbar pass together with the compact dropdown
  filter polish so the top of the page does not regress into stacked raw controls.
- The courier export task owns Yamato / Sagawa CSV generation. This task only moves those two buttons
  into the `Courier v` menu; it does not change export content.
- Recommended order: ship the filters task and the courier export task first, then apply this layout
  pass so the final arrangement is done once, not twice.

---

## Constraints

- Class-based Livewire only.
- No Volt.
- No TypeScript.
- Keep tenant scoping server-side; UI grouping is presentation only and is not a security boundary.
- Do not remove any existing action (Mark Ready, Hold, Release hold, Cancel, all export entry points).
  Consolidate them into menus; do not drop functionality.
- Do not change export query behavior or courier export content.
- Do not break the v4 order-id export guard.
- Do not use `wire:navigate` for file downloads.
- Keep the interface dense and operational, not marketing-like.
- Must remain usable on a 12-inch laptop without horizontal overflow in the toolbar.

---

## Tests

Light, since this is mostly presentation. Add/update in `tests/Feature/SalesOrderTest.php`.

1. `test_sales_order_index_toolbar_groups_actions_by_zone`
   - Assert Create order, Import, and Export-all controls render in the page-action area.
   - Assert bulk status actions and export-selected/courier actions render in the selection bar.

2. `test_sales_order_index_export_menus_present`
   - Assert page `Export` menu exposes CSV and XLSX (all).
   - Assert selection `Selected` menu exposes CSV and XLSX (selected).
   - Assert `Courier` menu exposes Yamato and Sagawa.

3. `test_sales_order_index_selection_actions_disabled_without_selection`
   - With no selection, bulk status and export-selected/courier actions are disabled.

4. `test_sales_order_index_export_selected_still_carries_selected_ids`
   - With selection, the selected export links carry the selected ids and respect the v4 empty-ids
     guard.

5. `test_sales_order_index_reserves_mark_shipped_slot` (only once the Mark Shipped button exists)
   - Assert `Mark Shipped` appears in the status group between Mark Ready and Hold.

Run:

```bash
php artisan test
```

If `php` is not globally available:

```bash
C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan test
```

---

## Follow-up Tasks

1. Mark Shipped bulk action: full fulfillment_status transition rules, eligibility, tracking/shipped
   timestamp handling.
2. Saved filter views / quick presets (e.g. "To ship today", "Backlog").
3. Keyboard shortcuts for the most common bulk actions.
