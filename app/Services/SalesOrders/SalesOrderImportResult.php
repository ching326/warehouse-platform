<?php

namespace App\Services\SalesOrders;

class SalesOrderImportResult
{
    public function __construct(
        public readonly int $importedOrders,
        public readonly int $importedLines,
        public readonly int $skippedDuplicates,
    ) {}
}
