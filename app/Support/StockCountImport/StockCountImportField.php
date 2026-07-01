<?php

namespace App\Support\StockCountImport;

class StockCountImportField
{
    public function __construct(
        public readonly string $key,
        public readonly string $labelKey,
        public readonly bool $required,
        public readonly array $aliases,
    ) {}
}
