<?php

namespace App\Support\StockAdjustmentImport;

class StockAdjustmentImportField
{
    public function __construct(
        public readonly string $key,
        public readonly string $labelKey,
        public readonly bool $required,
        public readonly array $aliases,
    ) {}
}
