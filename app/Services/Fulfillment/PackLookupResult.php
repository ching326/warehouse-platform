<?php

namespace App\Services\Fulfillment;

use App\Models\OutboundOrder;

class PackLookupResult
{
    public function __construct(
        public readonly string $status,
        public readonly ?OutboundOrder $order = null,
    ) {}

    public static function found(OutboundOrder $order): self
    {
        return new self('found', $order);
    }

    public static function notFound(): self
    {
        return new self('not_found');
    }

    public static function multiple(): self
    {
        return new self('multiple');
    }

    public static function alreadyShipped(OutboundOrder $order): self
    {
        return new self('already_shipped', $order);
    }

    public static function cancelled(OutboundOrder $order): self
    {
        return new self('cancelled', $order);
    }
}
