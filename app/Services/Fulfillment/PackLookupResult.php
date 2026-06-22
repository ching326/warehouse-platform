<?php

namespace App\Services\Fulfillment;

use App\Models\FulfillmentGroup;

class PackLookupResult
{
    public function __construct(
        public readonly string $status,
        public readonly ?FulfillmentGroup $group = null,
    ) {}

    public static function found(FulfillmentGroup $group): self
    {
        return new self('found', $group);
    }

    public static function notFound(): self
    {
        return new self('not_found');
    }

    public static function multiple(): self
    {
        return new self('multiple');
    }

    public static function alreadyShipped(FulfillmentGroup $group): self
    {
        return new self('already_shipped', $group);
    }

    public static function cancelled(FulfillmentGroup $group): self
    {
        return new self('cancelled', $group);
    }
}
