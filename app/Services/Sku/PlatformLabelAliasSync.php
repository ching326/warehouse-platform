<?php

namespace App\Services\Sku;

use App\Models\Sku;
use App\Services\BarcodeAliasService;

class PlatformLabelAliasSync
{
    public function __construct(private readonly BarcodeAliasService $barcodeAliases) {}

    public function sync(Sku $sku): void
    {
        $this->barcodeAliases->setSkuPlatformLabel($sku, $sku->platform_label_code);
    }
}
