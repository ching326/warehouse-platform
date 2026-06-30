<?php

namespace App\Services\Barcode;

use Picqer\Barcode\BarcodeGeneratorSVG;

class BarcodeImageService
{
    /**
     * @var array<string, string>
     */
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

        $generator = new BarcodeGeneratorSVG;

        return $this->memo[$key] = $generator->getBarcode(
            $value,
            $generator::TYPE_CODE_128,
            1.2,
            $height,
        );
    }
}
