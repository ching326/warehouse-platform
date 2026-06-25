<?php

namespace App\Support;

class CourierCarrier
{
    public const YAMATO = 'yamato';

    public const SAGAWA = 'sagawa';

    private const ALIASES = [
        'ymt' => self::YAMATO,
        'sgw' => self::SAGAWA,
        'jpo' => 'japan_post',
        'oth' => 'other',
    ];

    public static function values(): array
    {
        return [self::YAMATO, self::SAGAWA];
    }

    public static function normalize(?string $carrier): ?string
    {
        if ($carrier === null || $carrier === '') {
            return $carrier;
        }

        return self::ALIASES[$carrier] ?? $carrier;
    }
}
