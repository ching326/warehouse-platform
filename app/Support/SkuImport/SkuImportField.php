<?php

namespace App\Support\SkuImport;

class SkuImportField
{
    public function __construct(
        public readonly string $key,
        public readonly string $labelKey,
        public readonly string $target,
        public readonly bool $required,
        public readonly array $rules,
        public readonly string $cast,
        public readonly array $aliases,
    ) {}
}
