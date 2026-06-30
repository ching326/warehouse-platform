<?php

namespace App\Services\Barcode;

use Picqer\Barcode\BarcodeGeneratorSVG;

class BarcodeImageService
{
    /**
     * @var array<string, string>
     */
    private array $memo = [];

    public function code128Svg(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        if (isset($this->memo[$value])) {
            return $this->memo[$value];
        }

        $generator = new BarcodeGeneratorSVG;

        return $this->memo[$value] = $generator->getBarcode(
            $value,
            $generator::TYPE_CODE_128,
            1.2,
            36,
        );
    }
}
