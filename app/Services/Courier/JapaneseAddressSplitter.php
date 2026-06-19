<?php

namespace App\Services\Courier;

class JapaneseAddressSplitter
{
    public function split(?string $state, ?string $city, ?string $line1, ?string $line2): array
    {
        $base = $this->normalize(trim(implode('', array_filter([
            $state,
            $city,
            $line1,
        ], fn ($part) => trim((string) $part) !== ''))));

        $line2 = $this->normalize((string) $line2);

        if ($base === '') {
            return [
                'address1' => '',
                'address2' => $line2,
                'address3' => '',
            ];
        }

        [$address1, $address2] = $this->splitByLength($base, 32);

        if ($line2 !== '') {
            $address3 = $line2;
        } else {
            [$address2, $address3] = $this->splitByLength($address2, 32);
        }

        return [
            'address1' => $address1,
            'address2' => $address2,
            'address3' => $address3,
        ];
    }

    private function normalize(string $value): string
    {
        return trim(mb_convert_kana($value, 'asKV', 'UTF-8'));
    }

    private function splitByLength(string $value, int $length): array
    {
        if (mb_strlen($value) <= $length) {
            return [$value, ''];
        }

        return [
            mb_substr($value, 0, $length),
            mb_substr($value, $length),
        ];
    }
}
