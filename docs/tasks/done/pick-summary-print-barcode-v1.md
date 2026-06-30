# Pick Summary Print Barcode Images v1

## Goal

When staff print the Pick Summary page, the Barcode column should show a real scannable barcode image under/near the barcode text. This is for printed pick sheets only. The normal on-screen Pick Summary table can keep showing text barcodes to avoid visual clutter.

Use Code 128 barcodes for v1.

## Background

The old system at `C:\laragon\www\order-manage\label\includes\generate.php` uses `picqer/php-barcode-generator` with `BarcodeGeneratorPNG` to generate Code 128 images.

For the new Laravel system, use the same package family, but prefer SVG output instead of PNG:

- SVG prints sharper at any scale.
- No GD or Imagick dependency.
- Pure PHP is simpler to deploy.
- Code 128 works well for SKU codes, FNSKU, tenant item codes, and other alphanumeric warehouse barcodes.

## Decisions

- Add `picqer/php-barcode-generator:^2.4`.
- Generate Code 128 for all Pick Summary print barcodes in v1.
- Use SVG, not PNG.
- Render barcode images only in the print table.
- Use the first barcode from the existing ordered barcode list when deciding which barcode gets the image.
- Keep barcode text visible as escaped text below the image.
- If a row has multiple barcodes, print one barcode image for the selected print barcode and keep the full text list below it.
- If there is no barcode, show `-`.

## Explicit Non-Goals

- Do not generate PDF files in this task.
- Do not add QR codes.
- Do not add retail-specific EAN-13 / UPC-A rendering yet.
- Do not change barcode alias CRUD.
- Do not change scan-pack matching logic.
- Do not add barcode images to the screen table.

## Package

Install:

```bash
composer require picqer/php-barcode-generator:^2.4
```

Use the v2 API deliberately. Pin the package so the sample below matches the installed signature:

Use:

```php
Picqer\Barcode\BarcodeGeneratorSVG
```

Use `TYPE_CODE_128` for v1.

## New Service

Create:

`app/Services/Barcode/BarcodeImageService.php`

Suggested API:

```php
namespace App\Services\Barcode;

use Picqer\Barcode\BarcodeGeneratorSVG;

class BarcodeImageService
{
    /** @var array<string, string> */
    private array $memo = [];

    public function code128Svg(string $value, int $height = 36): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        $key = $value.'|'.$height;

        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        $generator = new BarcodeGeneratorSVG();

        return $this->memo[$key] = $generator->getBarcode(
            $value,
            $generator::TYPE_CODE_128,
            1.2,
            $height
        );
    }
}
```

Notes:

- Memoization is request-local only and avoids regenerating the same barcode many times on large pick sheets.
- Return an empty string for blank input.
- Do not accept raw SVG from user input. The service must only return Picqer-generated SVG.

## Pick Summary Data Changes

Current file:

`app/Livewire/FulfillmentPickSummary.php`

Current behavior:

- `availableBarcodes()` returns all active alias barcode strings from stock item and SKU aliases.
- The print table currently renders `implode(', ', $row['barcodes'])`.

Update row data to include a single barcode for print image generation:

```php
'barcodes' => $this->availableBarcodes($line),
'print_barcode' => $this->printBarcode($line),
```

Add helper:

```php
private function printBarcode(array $line): ?string
```

Selection rule:

1. Reuse the same order as `availableBarcodes()`.
2. That means active stock item aliases are considered before SKU aliases.
3. Within each group, `is_primary = true` still sorts first.
4. Use the first barcode from that ordered list.
5. If none exists, return `null`.

Reason:

- The pick sheet is for shelf/product picking, so the stock item physical barcode should win over SKU/platform labels when both exist.
- This keeps the print barcode consistent with the existing barcode list and avoids duplicating slightly different ordering logic.
- SKU barcode is still available in the text list and can be used as the print image when there is no stock item barcode.

## View Changes

Current file:

`resources/views/livewire/fulfillment-pick-summary.blade.php`

Only update the print table barcode cell.

Inject/resolve the service in the Livewire component and pass pre-rendered SVG into rows, or call a small component/helper from the view. Preferred: prepare SVG in the component so the Blade stays simple and testable.

Suggested row key:

```php
'print_barcode_svg' => $printBarcode ? app(BarcodeImageService::class)->code128Svg($printBarcode) : '',
```

Print cell behavior:

```blade
<td>
    @if ($row['print_barcode_svg'])
        <div class="print-barcode-image">{!! $row['print_barcode_svg'] !!}</div>
    @endif

    @if (count($row['barcodes']) > 0)
        <div class="print-barcode-text">
            @foreach ($row['barcodes'] as $barcode)
                <div>{{ $barcode }}</div>
            @endforeach
        </div>
    @else
        -
    @endif
</td>
```

Safety:

- The SVG uses `{!! !!}` because it is generated by `BarcodeImageService`.
- The human-readable barcode text must stay escaped with `{{ }}`.

## Print CSS

Add print-only styling:

```css
@media print {
    .print-barcode-image {
        display: block;
        width: auto;
        max-width: 100%;
        margin-bottom: 3px;
    }

    .print-barcode-image svg {
        display: block;
        width: auto;
        max-width: 100%;
        height: 36px;
    }

    .print-barcode-text {
        font-size: 9px;
        line-height: 1.2;
        word-break: break-all;
    }
}
```

Keep row height reasonably compact. Do not render more than one barcode image per row. Do not force long barcodes into a fixed width; let the generated SVG keep enough module width to remain scannable, while using `max-width: 100%` to prevent table overflow.

## Tests

Add focused tests only.

### Service Tests

Create or update a service test:

- `BarcodeImageService::code128Svg('X00ABC123')` returns a string containing `<svg`.
- It returns an empty string for blank input.
- It does not echo the raw text directly as unsafe HTML.

### Pick Summary Tests

Update:

`tests/Feature/FulfillmentPickSummaryTest.php`

Add/adjust tests:

1. Print table includes an SVG barcode image when a row has an active barcode alias.
2. Human-readable barcode text is still present.
3. If stock item and SKU aliases both exist, the stock item alias is used for the image.
4. If a stock item has multiple aliases, its primary active alias is used first.
5. Inactive aliases are not used.
6. If no barcode exists, barcode cell still shows `-`.

Existing test to be aware of:

- `test_pick_summary_shows_available_barcodes_instead_of_alias_count`

Update it if necessary so it still confirms barcode text display while allowing the SVG image.

## Acceptance Criteria

- Pick Summary screen view is not cluttered with barcode images.
- Browser print / print preview shows barcode images in the Barcode column.
- One barcode image per pick row.
- The image uses the same first barcode as the ordered barcode list: stock item active aliases first, primary first within that group.
- Barcode text remains visible under the image.
- Blank/no-barcode rows still render cleanly.
- Targeted Pick Summary tests pass.
