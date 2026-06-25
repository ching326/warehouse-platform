<?php

namespace App\Support;

class AmazonSpapiRegion
{
    public const NA = 'na';

    public const EU = 'eu';

    public const FE = 'fe';

    public static function options(): array
    {
        return [
            self::NA => [
                'label' => 'North America',
                'endpoint' => 'https://sellingpartnerapi-na.amazon.com',
            ],
            self::EU => [
                'label' => 'Europe',
                'endpoint' => 'https://sellingpartnerapi-eu.amazon.com',
            ],
            self::FE => [
                'label' => 'Far East',
                'endpoint' => 'https://sellingpartnerapi-fe.amazon.com',
            ],
        ];
    }

    public static function values(): array
    {
        return array_keys(self::options());
    }

    public static function label(string $region): string
    {
        return self::options()[$region]['label'] ?? $region;
    }

    public static function endpoint(string $region): string
    {
        return self::options()[$region]['endpoint'] ?? self::options()[self::FE]['endpoint'];
    }

    public static function marketplaceOptions(): array
    {
        return [
            'JP' => 'A1VC38T7YXB528',
            'US' => 'ATVPDKIKX0DER',
            'UK' => 'A1F83G8C2ARO7P',
            'DE' => 'A1PA6795UKMFR9',
            'FR' => 'A13V1IB3VIYZZH',
            'IT' => 'APJ6JRA9NG5V4',
            'ES' => 'A1RKKUPIHCS9HS',
            'CA' => 'A2EUQ1WTGCTBG2',
            'MX' => 'A1AM78C64UM0Y8',
            'AU' => 'A39IBJ37TRP1C6',
            'SG' => 'A19VAU5U5O7RUS',
        ];
    }

    public static function marketplaceIdForLabel(?string $label): ?string
    {
        $label = self::normalizeMarketplaceLabel($label);

        return $label === null ? null : (self::marketplaceOptions()[$label] ?? null);
    }

    public static function regionForMarketplaceId(?string $marketplaceId): ?string
    {
        return match ($marketplaceId) {
            'ATVPDKIKX0DER', 'A2EUQ1WTGCTBG2', 'A1AM78C64UM0Y8' => self::NA,
            'A1F83G8C2ARO7P', 'A1PA6795UKMFR9', 'A13V1IB3VIYZZH', 'APJ6JRA9NG5V4', 'A1RKKUPIHCS9HS' => self::EU,
            'A1VC38T7YXB528', 'A39IBJ37TRP1C6', 'A19VAU5U5O7RUS' => self::FE,
            default => null,
        };
    }

    public static function normalizeMarketplaceLabel(?string $label): ?string
    {
        $label = strtoupper(trim((string) $label));

        if ($label === '') {
            return null;
        }

        if (str_contains($label, '_')) {
            $label = substr($label, strrpos($label, '_') + 1);
        }

        return $label === '' ? null : $label;
    }
}
