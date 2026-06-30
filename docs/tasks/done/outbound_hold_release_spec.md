# Outbound Hold / Release v1

## Goal

Replace the current "cancel outbound and recreate later" hold flow with a cleaner outbound hold model.

The main goal is to avoid creating rubbish cancelled outbound records every time a tenant or warehouse staff temporarily holds an order.

This v1 deliberately avoids complicated line-level splitting. Current outbound lines are aggregated by SKU / stock item, so splitting one sales order out of a grouped outbound by moving lines is unsafe. When a grouped shipment must be split, v1 cancels and rebuilds instead of trying to move partial lines.

## Locked Decisions

| Question | Decision |
|---|---|
| Does `on_hold` still reserve stock? | Yes |
| Show held outbound in pick summary? | No |
| Allow courier export while held? | No |
| Allow scan pack while held? | No |
| Allow bulk mark shipped while held? | No |
| Sales Order page can hold printed outbound? | No, keep blocked |
| Fulfillment page can hold printed outbound? | Yes, but only after warning confirmation |
| Pack screen can hold printed outbound? | Yes, but only after warning confirmation |

## Scope

In scope:

- Add outbound-level hold status.
- Hold / release hold from Sales Order page.
- Hold / release hold from Fulfillment page.
- Hold / release hold from Pack screen.
- Keep reservation when outbound is held.
- Block pick summary, courier export, scan pack, and mark shipped for held outbound orders.
- For grouped outbound from Sales Order page, let user choose how to handle the group.

Out of scope:

- Partial line-level split of aggregated outbound lines.
- Auto-merge split shipments back together.
- Releasing stock while on hold.
- Inventory quantity changes during hold/release.

## Data Model

### `outbound_orders`

Add columns:

| Column | Type | Notes |
|---|---|---|
| `hold_status` | string | `active` or `on_hold`, default `active` |
| `held_at` | datetime nullable | When hold was applied |
| `held_by_user_id` | foreign id nullable | User who applied hold |
| `held_from` | string nullable | `sales_order`, `fulfillment`, or `pack` |
| `hold_reason` | text nullable | Optional note, can be added in UI later |
| `released_at` | datetime nullable | Last release timestamp |
| `released_by_user_id` | foreign id nullable | User who released hold |

Model constants:

```php
public const HOLD_STATUS_ACTIVE = 'active';
public const HOLD_STATUS_ON_HOLD = 'on_hold';
```

Do not add `is_printed`. Printed state must continue to use `courier_csv_exported_at !== null`.

Add indexes:

- `['tenant_id', 'hold_status']`
- `['status', 'hold_status']`

## Status Meaning

`status` continues to mean outbound lifecycle:

- `pending`
- `shipped`
- `cancelled`

`hold_status` means whether fulfillment work is paused:

- `active` = can proceed normally
- `on_hold` = stock remains reserved, but warehouse work is blocked

An outbound can be:

- `status = pending`, `hold_status = active`
- `status = pending`, `hold_status = on_hold`
- `status = shipped`, `hold_status = active`
- `status = cancelled`, `hold_status = active`

Do not allow `shipped` or `cancelled` outbound orders to be put on hold.

## Core Rules

### Holding an outbound

When hold is applied:

- Set `outbound_orders.hold_status = on_hold`.
- Set `held_at`, `held_by_user_id`, `held_from`.
- Keep outbound `status = pending`.
- Keep reserved stock exactly as-is.
- Do not call `InventoryService::releaseReserve()`.
- Do not delete or rebuild outbound lines.

### Releasing hold

When hold is released:

- Set `outbound_orders.hold_status = active`.
- Set `released_at`, `released_by_user_id`.
- Keep reservation as-is.
- Do not re-reserve stock.
- Do not rebuild outbound lines.

### Linked sales orders

When a whole outbound is held:

- All linked sales orders should show `order_status = on_hold`.
- Keep their `fulfillment_status` arranged if they are still attached to the outbound.

When a whole outbound is released:

- All linked sales orders should return to `order_status = pending`.
- Keep their `fulfillment_status` arranged.

Rationale: the outbound still exists and stock is still reserved, so the sales orders are still arranged, just paused.

## Entry Points

### Sales Order page

Sales Order page hold is source-driven. The user is holding one selected sales order.

#### Single-order outbound

If the sales order belongs to a single-order outbound:

- If outbound is not printed: hold outbound in place.
- If outbound is printed: block.

Error message:

```text
This outbound has already been printed and cannot be held from the Sales Order page.
```

#### Grouped outbound

If the sales order belongs to a grouped outbound, show a choice:

```text
This order is part of a consolidated outbound shipment.

Choose how to continue:

[Hold whole shipment]
[Split and rebuild shipment]
[Cancel]
```

Option A: Hold whole shipment

- Hold the whole outbound in place.
- All linked sales orders become `on_hold`.
- Reservation remains.
- No outbound is cancelled.

Option B: Split and rebuild shipment

- Cancel the current grouped outbound.
- Release all reserved stock from that outbound.
- Target sales order becomes `order_status = on_hold`, `fulfillment_status = unfulfilled`.
- Other linked sales orders become `order_status = pending`, `fulfillment_status = ready` if they are still shippable.
- User must mark them ready again to create a new outbound.

After Option B, show warning:

```text
The shipment was split. Other orders were returned to unfulfilled status.
Please mark them ready to ship again.
```

Do not attempt to move partial outbound lines in Option B.

### Fulfillment page

Fulfillment page hold is outbound-driven. Staff is holding the whole outbound order.

Add row / bulk actions:

- Hold
- Release hold

Rules:

- Holding a non-printed outbound proceeds immediately.
- Holding a printed outbound shows warning confirmation first.
- Releasing hold proceeds immediately.
- `shipped` / `cancelled` outbound orders cannot be held.

Printed warning:

```text
This outbound has already been printed.
Before holding it, throw away the printed label and do not use the exported courier file.

Continue?
```

Buttons:

- Cancel
- Hold outbound

### Pack screen

Pack screen should also allow warehouse staff to hold / release the current outbound.

Rules are same as Fulfillment page:

- Printed outbound requires warning confirmation.
- Staff must be told to throw away the printed label.
- Held outbound cannot continue scanning.

If staff holds from the pack screen:

- Stop scan actions immediately after hold.
- Redirect back to fulfillment page or show read-only held state.

## Blocking Rules

### Pick summary

Exclude held outbound orders:

```php
where('status', OutboundOrder::STATUS_PENDING)
where('hold_status', OutboundOrder::HOLD_STATUS_ACTIVE)
```

### Courier export

Courier export must hard-block held outbound orders.

Example message:

```text
Orders cannot be exported because they are on hold.
Release hold before exporting courier CSV.
```

### Scan pack

Pack lookup and direct pack page must block held outbound orders.

If staff scans a tracking number for a held outbound:

```text
This outbound is on hold. Release hold before packing.
```

### Bulk mark shipped

Held outbound orders must not be shipped.

For bulk mark shipped:

- Process active selected rows.
- Skip held rows.
- Show summary:

```text
X outbound order(s) marked shipped. Y skipped because they are on hold.
```

For single mark shipped:

- Block with error.

## Service Design

Create `App\Services\Outbound\HoldOutboundOrderService`.

### Methods

```php
public function holdOutbound(
    OutboundOrder $outbound,
    string $source,
    bool $confirmedPrinted = false,
    ?string $reason = null,
): HoldOutboundResult;

public function releaseOutbound(
    OutboundOrder $outbound,
    string $source,
): void;

public function splitAndRebuildForSalesOrderHold(
    SalesOrder $targetOrder,
): void;
```

### `holdOutbound()` rules

Inside a DB transaction:

1. Lock outbound row.
2. Confirm tenant scope is handled before service call, or pass allowed tenant ids into service.
3. Reject if outbound status is `shipped` or `cancelled`.
4. If already `on_hold`, return no-op success.
5. If printed and source is `sales_order`, reject.
6. If printed and source is `fulfillment` or `pack`, return `requires_confirmation` unless confirmed.
7. Set outbound `hold_status = on_hold`.
8. Update linked sales orders to `order_status = on_hold`.
9. Keep fulfillment status arranged.

### `releaseOutbound()` rules

Inside a DB transaction:

1. Lock outbound row.
2. Reject if outbound is not `on_hold`.
3. Set outbound `hold_status = active`.
4. Update linked sales orders to `order_status = pending`.
5. Keep fulfillment status arranged.

### `splitAndRebuildForSalesOrderHold()` rules

This is only used by Sales Order page grouped-outbound Option B.

Inside a DB transaction:

1. Lock target sales order.
2. Lock active outbound linked to target sales order.
3. Reject if outbound is printed.
4. Confirm outbound has more than one linked sales order.
5. Release all reserved stock for the outbound using existing outbound leaf lines.
6. Cancel the outbound.
7. Detach linked sales orders if needed.
8. Target order:
   - `order_status = on_hold`
   - `fulfillment_status = unfulfilled`
9. Other linked orders:
   - `order_status = pending`
   - `fulfillment_status = ready` if all lines are still shippable
   - otherwise `fulfillment_status = unfulfilled`
10. Do not create a new outbound automatically.

Use the same inventory context style as existing outbound cancel/release logic:

```php
[
    'ref_type' => 'outbound_order',
    'ref_id' => (string) $outbound->id,
    'user_id' => Auth::id(),
]
```

## Tenant Scope

All Livewire actions must scope outbound orders through the same allowed tenant pattern used by the current page.

Do not rely on route model binding alone.

Guest users must not be treated as internal users.

## UI Requirements

### Fulfillment index

Add:

- `on_hold` badge.
- Hold button when active.
- Release hold button when held.
- Disable export / scan / mark shipped controls for held rows.

Button text:

- `Hold`
- `Release hold`

### Sales Order index/detail

Keep current printed block behavior.

When grouped outbound is detected, show the choice modal / warning.

### Pack screen

Add hold / release hold control near order actions.

When held:

- Disable scan input.
- Show clear warning.

```text
This outbound is on hold.
Release hold before packing.
```

## Language Keys

Add language keys under the existing relevant files:

- `outbound.hold`
- `outbound.release_hold`
- `outbound.on_hold`
- `outbound.hold_printed_sales_blocked`
- `outbound.hold_printed_confirm_title`
- `outbound.hold_printed_confirm_body`
- `outbound.hold_grouped_choice_title`
- `outbound.hold_whole_shipment`
- `outbound.split_and_rebuild_shipment`
- `outbound.split_rebuild_done`
- `outbound.cannot_ship_on_hold`
- `outbound.cannot_export_on_hold`
- `outbound.cannot_pack_on_hold`

Locale fallback should follow the current project pattern.

## Tests

Add targeted tests.

### Model / service tests

1. Single-order outbound can be held in place.
2. Holding outbound keeps reserved stock.
3. Releasing outbound keeps reservation and reactivates the outbound.
4. Shipped outbound cannot be held.
5. Cancelled outbound cannot be held.
6. Printed outbound from Sales Order source is blocked.
7. Printed outbound from Fulfillment source returns confirmation requirement.
8. Printed outbound from Fulfillment source can be held after confirmation.

### Sales Order page tests

9. Single-order non-printed sales order hold holds outbound in place.
10. Single-order printed sales order hold is blocked.
11. Grouped outbound shows hold choice.
12. Hold whole shipment marks all linked sales orders on hold.
13. Split and rebuild cancels grouped outbound and returns other orders to ready/unfulfilled.

### Fulfillment page tests

14. Hold button holds selected outbound.
15. Release hold button releases selected outbound.
16. Printed hold shows warning before action.
17. Held outbound is excluded from pick summary.
18. Held outbound is blocked from courier export.
19. Held outbound is skipped by bulk mark shipped.

### Pack screen tests

20. Held outbound cannot be found / opened for scan.
21. Existing open pack page blocks scan when outbound becomes held.
22. Pack screen hold on printed outbound requires confirmation.

### Tenant scope tests

23. Tenant user cannot hold another tenant's outbound.
24. Tenant user cannot release another tenant's outbound.

## Acceptance Criteria

- Holding an outbound no longer creates cancelled outbound records in the normal single-outbound case.
- Held outbound keeps stock reserved.
- Held outbound does not appear in pick summary.
- Held outbound cannot be courier exported.
- Held outbound cannot be packed or marked shipped.
- Sales Order page still blocks hold if outbound was printed.
- Fulfillment page and Pack screen can hold printed outbound only after warning confirmation.
- Grouped outbound from Sales Order page gives the user a clear choice:
  - hold whole shipment, or
  - split and rebuild shipment.
- No partial movement of aggregated outbound lines is attempted.
