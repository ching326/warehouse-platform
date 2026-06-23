# Fulfillment Pack Start Queue v1

## Goal

Improve `/fulfillment/pack` so warehouse staff can start packing even when a tracking barcode cannot be scanned.

Today the pack start page depends mainly on scanning tracking number after selecting:

- warehouse
- shipping method

That is good for normal flow. But in real work:

- the printed label may not scan
- tracking number may not have imported correctly
- staff may need to see all orders waiting at a station
- staff may need to open by fulfillment reference or sales order number

This task adds a small station queue and manual lookup fallback.

## Scope

Build:

1. Waiting-to-pack queue on `/fulfillment/pack`.
2. Manual search fallback by fulfillment reference / tracking / order number / recipient.
3. Quick link from queue row to pack screen.
4. Counts for selected station.

Do not build:

- new packing rules
- barcode coverage report
- Packed vs Shipped split
- picking workflow
- batch open / batch pack
- route optimization
- courier label printing

## Existing Files

Main files:

```text
app/Livewire/FulfillmentPackStart.php
resources/views/livewire/fulfillment-pack-start.blade.php
app/Services/Fulfillment/FulfillmentPackService.php
tests/Feature/FulfillmentGroupTest.php
```

## Page Behavior

The page should still start with:

- warehouse dropdown
- shipping method dropdown
- large tracking scan input

Once both station filters are selected, show a queue under the scan panel.

If filters are not ready, show no queue.

## Queue Query

Show fulfillment groups that are ready for pack station work:

```text
status = reserved
warehouse_id = selected warehouse
shipping_method_id = selected shipping method
tenant_id in allowed tenant ids
```

Sort:

1. oldest `created_at`
2. `id`

Paginate:

- 25 rows

Do not load all groups.

## Queue Columns

Columns:

1. Fulfillment ref
2. Tenant
3. Recipient
4. Tracking no.
5. Sales order(s)
6. Qty
7. Progress
8. Action

Details:

- Fulfillment ref links to pack page.
- Action button: `Pack`
- Sales order(s):
  - show first 1-2 platform order ids
  - if more, show `+N`
- Qty:
  - total required qty from pack lines
- Progress:
  - show scanned / required qty
  - use current scan progress from `FulfillmentPackService::packLinesWithProgress()`

Performance note:

- The queue only shows 25 rows.
- It is acceptable in v1 to compute progress per visible row.
- Do not compute progress for all matching groups.

## Manual Search

Add a small search field near the queue:

```text
Search queue...
```

It should search within the selected station queue.

Search matches:

- fulfillment group `reference_no`
- fulfillment group `tracking_no`
- outbound order `tracking_no`
- fulfillment group order `tracking_no`
- sales order `tracking_no`
- sales order `platform_order_id`
- recipient name
- recipient phone

Use normal `LIKE` search. No advanced search syntax required.

Search should not bypass warehouse / shipping method filters.

## Scan Input Behavior

Keep current scan behavior:

- scan exact tracking number
- find matching group in selected warehouse + shipping method
- redirect to pack page if found
- show error if not found / shipped / cancelled / multiple

Do not change `findGroupForTrackingNo()` in this task unless needed for a bug.

## Station Summary

When filters are ready, show compact summary cards:

- Waiting groups
- Waiting orders
- Required qty
- Exception scans today

Definitions:

- Waiting groups = count of queue groups matching selected station filters
- Waiting orders = sum/order count through group orders or orders relation
- Required qty = sum required qty for visible page or selected station
  - For v1, visible-page total is acceptable if station-wide total is too expensive
  - Label clearly if it is page total
- Exception scans today = count `fulfillment_pack_scans` where:
  - result != accepted
  - fulfillment group belongs to selected station
  - created today

If station-wide required qty would be too expensive, use:

```text
Required qty on this page
```

Do not create slow all-station pack-line aggregation.

## UI Style

Keep it operational and compact.

Recommended layout:

1. Station scan panel at top
2. Station summary row
3. Queue search
4. Queue table

The scan input must remain visually primary.

The queue is a fallback / visibility aid, not a replacement for scanning.

## Tenant Scope / Auth

Pack start remains internal-user only for now.

Rules:

- guest gets 403
- tenant user gets 403
- internal user can see allowed tenant groups
- never show groups outside allowed tenant ids

Use `Auth::user()?->user_type === 'internal'`.

Do not reintroduce guest-as-internal fallback.

## Tests

Add targeted tests.

Required tests:

1. queue hidden until warehouse and shipping method are selected
2. queue shows reserved groups for selected warehouse + shipping method
3. queue does not show shipped/cancelled groups
4. queue does not show groups from another warehouse
5. queue does not show groups from another shipping method
6. search matches fulfillment reference
7. search matches sales order platform order id
8. search matches tracking number
9. queue Pack link goes to `/fulfillment-groups/{group}/pack`
10. tenant user cannot access pack start page
11. station summary shows waiting group count

Run targeted fulfillment pack/start tests only.

Do not rerun the full suite by default unless a broad regression concern appears.

## Acceptance Criteria

- Staff can still scan tracking number as before.
- Staff can also see a waiting queue after selecting station filters.
- Staff can manually search and open a group if scanning fails.
- Queue is station-scoped and tenant-safe.
- No packing rule or inventory behavior changes.
- Targeted tests pass.

