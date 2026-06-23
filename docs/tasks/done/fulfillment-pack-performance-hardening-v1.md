# Fulfillment Pack Performance Hardening v1

## Goal

Keep the pack/check screen fast as fulfillment groups, scan logs, SKU aliases, and order lines grow.

The pack station is an operational hot path. Staff expects each scan to respond immediately. This task should reduce repeated queries and avoid per-line scan-count queries while preserving the exact current behavior.

## Scope

Improve:

1. Pack line progress query efficiency.
2. Scan matching eager loading after barcode aliases.
3. Fulfillment pack scan indexes.
4. Targeted tests around behavior preservation.

Do not build:

- barcode coverage report
- Packed vs Shipped split
- new pack workflow
- new scan result types
- scan history export
- UI redesign

## Current Problem

`FulfillmentPackService::packLinesWithProgress()` calls `acceptedScanQuantity()` for each pack line.

That means:

- one query to build pack lines
- then one `sum(quantity)` query per line
- on a group with many lines, every scan/render repeats many queries

This is fine for demo data, but it will slow down in real packing.

## Requirements

### 1. Aggregate Accepted Scan Quantities Once

Replace per-line `acceptedScanQuantity()` calls with a single grouped query per fulfillment group.

Current behavior to preserve:

- normal SKU line progress is keyed by:
  - `sku_id`
  - `stock_item_id`
- virtual bundle component line progress is keyed by:
  - `sku_id = null`
  - `stock_item_id`
- only `result = accepted` counts
- sum `quantity`, not row count

Suggested implementation:

```php
private function acceptedScanQuantitiesByLine(FulfillmentGroup $group): array
{
    return FulfillmentPackScan::query()
        ->where('fulfillment_group_id', $group->id)
        ->where('result', FulfillmentPackScan::RESULT_ACCEPTED)
        ->selectRaw('sku_id, stock_item_id, SUM(quantity) as scanned_qty')
        ->groupBy('sku_id', 'stock_item_id')
        ->get()
        ->mapWithKeys(fn ($row) => [
            $this->scanQuantityKey($row->sku_id, $row->stock_item_id) => (int) $row->scanned_qty,
        ])
        ->all();
}
```

Then `packLinesWithProgress()` should:

1. build the pack lines
2. load the grouped scan quantities once
3. attach `scanned_qty`, `remaining_qty`, and `status` from the map

Keep `acceptedScanQuantity()` only if still used elsewhere; otherwise remove it or make it a thin wrapper for tests.

### 2. Use a Stable Progress Key

Add a private helper:

```php
private function scanQuantityKey(?int $skuId, ?int $stockItemId): string
{
    return 'sku:'.($skuId ?? 'null').':stock:'.($stockItemId ?? 'null');
}
```

Use the same helper for:

- grouped query map
- each pack line lookup

Do not use display line key directly unless it exactly maps to the scan rows. The existing display keys (`sku:{id}:stock:{id}`, `component:{stock_item_id}`) are not identical to scan row grouping.

### 3. Preserve Shared Stock Behavior

Do not change the logic that prefers a matching line with `remaining_qty > 0`.

Existing behavior must stay:

- if two SKU lines share a stock item/barcode, scan should fill the first remaining matching line before over-scan
- if all matching lines are complete, record over-scan

### 4. Preserve Barcode Alias Matching

After barcode aliases, pack lines preload:

- SKU aliases
- linked stock item aliases
- virtual bundle component stock item aliases

Keep this eager loading. Do not reintroduce alias N+1 queries.

When possible, limit eager-loaded columns:

```php
barcodeAliases:id,tenant_id,model_type,model_id,normalized_barcode,is_active
```

Only include fields needed for scan matching.

### 5. Add Index for Accepted Quantity Lookup

Add migration to support the grouped accepted-scan query.

Recommended index:

```php
$table->index(
    ['fulfillment_group_id', 'result', 'sku_id', 'stock_item_id'],
    'pack_scans_group_result_item_idx'
);
```

Keep index name short enough for MySQL.

Do not remove existing indexes.

### 6. Tracking Lookup

Do not change tracking lookup behavior in this task.

Current expectation:

- tracking numbers are normalized when saved
- staff scans printed label barcode
- pack start page filters by warehouse and shipping method before lookup
- exact normalized tracking match is acceptable

This task is about pack-page render/scan performance, not pack-start lookup redesign.

## Tests

Add targeted tests only.

Required behavior tests:

1. normal SKU accepted quantity still sums by quantity
2. virtual bundle component accepted quantity still sums by stock item with `sku_id = null`
3. shared-stock scan still prefers a line with remaining quantity before over-scan
4. inactive barcode alias still does not match
5. SKU alias still matches
6. stock item alias still matches
7. existing direct SKU barcode / stock item barcode / SKU code still match

Recommended performance-oriented test:

8. `packLinesWithProgress()` returns correct progress for multiple lines without calling one query per line.

Do not make query-count tests too brittle. If using query-count assertions, keep them broad and focused on obvious regression, not exact framework query counts.

Do not rerun the full suite by default. Run targeted fulfillment pack tests only unless a broad regression concern appears.

## Acceptance Criteria

- Pack page behavior is unchanged for users.
- Accepted scan quantities are loaded with one grouped query per fulfillment group.
- Barcode aliases still work.
- Shared-stock matching still works.
- New index exists for grouped scan lookup.
- Targeted tests pass.

