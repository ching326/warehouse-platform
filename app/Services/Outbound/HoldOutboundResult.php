<?php

namespace App\Services\Outbound;

class HoldOutboundResult
{
    public function __construct(
        public readonly bool $held,
        public readonly bool $requiresConfirmation = false,
    ) {}

    public static function held(): self
    {
        return new self(true);
    }

    public static function noOp(): self
    {
        return new self(false);
    }

    public static function requiresConfirmation(): self
    {
        return new self(false, true);
    }
}
