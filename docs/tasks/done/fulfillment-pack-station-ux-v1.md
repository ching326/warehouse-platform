# Fulfillment Pack Station UX v1

## Goal

Improve the `/fulfillment-groups/{group}/pack` screen for real warehouse packing work.

The pack/check logic already exists:

- scan tracking number to open a fulfillment group
- scan product barcode
- normal / strict scan modes
- quantity prompt
- wrong item / over-scan / blocked-status scan records
- issue creation links
- scan history page

This task should not change the packing rules. It only improves the operator experience so staff can scan faster and notice mistakes immediately.

## Scope

Build:

1. A clearer pack station layout.
2. Large scan feedback.
3. Last scanned row highlight.
4. Better complete / remaining visual states.
5. Stronger scanner focus behavior.
6. Mobile / small-screen readability.

Do not build:

- barcode coverage report
- Packed vs Shipped split
- new inventory logic
- new scan result types
- scan history export
- sound/beep using hardware integration
- camera scanning

## Current Page

Main files:

```text
app/Livewire/FulfillmentGroupPack.php
resources/views/livewire/fulfillment-group-pack.blade.php
lang/en/fulfillment_pack.php
tests/Feature/FulfillmentGroupTest.php
```

The existing component already owns the scan state. Prefer extending the current component instead of creating a second pack component.

## UI Requirements

### 1. Pack Header

At the top, show a compact operational header:

- fulfillment group reference
- tenant code
- recipient name
- tracking number
- shipping method
- overall progress, e.g. `4 / 6 scanned`

Keep this compact. Do not make a hero/banner.

### 2. Sticky Scan Panel

The scan input and current feedback should stay visible near the top while scrolling.

Use a sticky panel inside the page content, not a browser-level fixed overlay.

Panel contents:

- pack mode toggle
- barcode input
- latest feedback
- quantity prompt if active

Behavior:

- input stays focused after successful scan
- input stays focused after wrong item / over-scan
- input regains focus after quantity confirm/cancel
- clicking empty space in the scan panel should focus the barcode input

### 3. Large Feedback

The current feedback is too subtle for pack station use.

Make feedback visually clear:

- success: green panel
- wrong item / over-scan / blocked status: red panel
- quantity prompt: blue panel
- idle: muted panel

Feedback text should be large enough to read from a standing packing position.

Recommended:

- font size around 18px to 22px
- bold text
- enough height that the message does not jump the table

Do not use toast for scan feedback. Toast is too easy to miss during repetitive scanning.

### 4. Last Scanned Row Highlight

After an accepted scan, highlight the matched row.

Rules:

- store the matched line key in component state, e.g. `$lastScannedLineKey`
- accepted scan sets `$lastScannedLineKey`
- wrong item does not change it
- over-scan may highlight the completed matched line if available
- quantity confirm sets it to the confirmed line
- switching fulfillment group resets it

Visual:

- last scanned row gets a soft green background
- if the row becomes complete, combine with complete styling
- highlight should not rely only on color; add a small "Last scan" badge or text

### 5. Complete / Remaining Row States

The pack line table should make remaining work obvious.

For each row:

- complete rows should look visually complete
- in-progress rows should stand out gently
- not-started rows should be neutral
- strict-only rows should stay clearly marked

Suggested display:

- Required / Scanned / Remaining columns stay numeric
- Remaining `0` should be green or muted
- Remaining `> 0` should be visually stronger
- Completed rows can have a light green tint

Do not hide completed rows in v1. Staff may need to see what has already been scanned.

### 6. Progress Summary

Add a compact progress summary above the table:

```text
Lines complete: 2 / 4
Qty scanned: 5 / 8
Remaining: 3
Exceptions: 1
```

Definitions:

- Lines complete = count of pack lines where remaining qty is 0
- Qty scanned = sum scanned qty from current line progress
- Required qty = sum required qty
- Remaining = sum remaining qty
- Exceptions = scan count for this group where result != accepted

The exception count can query `fulfillment_pack_scans` for the current group.

### 7. Action Area

Keep `Mark shipped` at the bottom, but make the disabled reason clearer.

If not all lines complete:

```text
Scan all items before marking shipped.
```

If quantity prompt is active:

```text
Confirm or cancel the quantity before marking shipped.
```

If complete:

```text
Ready to mark shipped.
```

Button:

- teal primary
- disabled until allowed
- no accidental cancel/destructive action on this page

### 8. Mobile / Small Screen

The pack page should be usable on a phone or small tablet.

At narrow widths:

- keep scan panel at top
- table may become stacked cards if needed
- barcode/product/qty/status should remain readable
- action buttons should not overflow

No text should overlap.

## Component Changes

In `FulfillmentGroupPack`:

Add state:

```php
public ?string $lastScannedLineKey = null;
```

Set it when:

- `acceptScan()` succeeds
- quantity confirm succeeds

Clear it when:

- pack mode changes
- pending quantity is cancelled
- component mounts a different group

Add computed data in `render()` or a private helper:

```php
$progress = [
    'lines_complete' => ...,
    'lines_total' => ...,
    'qty_scanned' => ...,
    'qty_required' => ...,
    'qty_remaining' => ...,
    'exceptions' => ...,
];
```

Pass `$progress` to the Blade view.

## Scan Audit Constraint

Do not change how `FulfillmentPackScan` rows are written except if needed to support existing state display.

This task is a UI/UX task, not a scan model rewrite.

## Tests

Add or update targeted tests only.

Required tests:

1. accepted scan sets the last scanned line key and the page renders the "Last scan" marker
2. wrong item scan does not mark a product line as last scanned
3. quantity confirm marks the line as last scanned
4. progress summary shows correct line and quantity totals
5. exception count includes wrong item / over-scan scans for the current group only
6. mark shipped disabled text changes when quantity prompt is active
7. strict-only row still shows strict scan marker after UI changes
8. shipped/cancelled group remains read-only

Do not rerun the full suite by default. Run targeted fulfillment pack tests unless a broad regression concern appears.

## Acceptance Criteria

- Pack page is clearer for staff using barcode scanner.
- Scan input focus remains reliable.
- Latest scan feedback is large and easy to see.
- Last scanned row is visible.
- Progress summary is correct.
- No packing rule or inventory behavior changes.
- Targeted tests pass.

