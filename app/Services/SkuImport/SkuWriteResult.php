<?php

namespace App\Services\SkuImport;

use App\Models\Sku;

class SkuWriteResult
{
    public function __construct(
        public readonly string $status,
        public readonly ?Sku $sku = null,
    ) {}

    public function isCreated(): bool
    {
        return $this->status === 'created';
    }

    public function isUpdated(): bool
    {
        return $this->status === 'updated';
    }

    public function isSkipped(): bool
    {
        return $this->status === 'skipped';
    }
}
