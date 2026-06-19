<?php

namespace App\Support;

class CourierCarrier
{
    public const YAMATO = 'yamato';
    public const SAGAWA = 'sagawa';

    public static function values(): array
    {
        return [self::YAMATO, self::SAGAWA];
    }
}
