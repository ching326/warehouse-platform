<?php

namespace App\Services\Amazon;

class AmazonAccessTokenResult
{
    public function __construct(
        public readonly string $accessToken,
        public readonly int $expiresIn,
        public readonly string $tokenType,
    ) {}
}
