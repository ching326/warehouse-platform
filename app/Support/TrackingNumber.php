<?php

namespace App\Support;

class TrackingNumber
{
    public static function normalize(?string $value): ?string
    {
        $value = strtoupper(trim((string) $value));
        $value = preg_replace('/[\s\-\._\/\\\\:;|]+/', '', $value) ?? '';

        return $value === '' ? null : $value;
    }
}
