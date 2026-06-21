<?php

namespace App\Services\Courier\TrackingImport;

use App\Support\CourierCarrier;
use SplTempFileObject;

class TrackingImportParser
{
    public const STATUS_READY = 'ready';
    public const STATUS_MISSING_ORDER = 'missing_order';
    public const STATUS_MISSING_TRACKING = 'missing_tracking';
    public const STATUS_SKIPPED = 'skipped';

    /**
     * @return array{courier: string, delimiter: string, rows: array<int, array<string, mixed>>, error: ?string}
     */
    public function parse(string $contents): array
    {
        $utf8 = $this->decode($contents);
        $rows = $this->parseDelimited($utf8, ',');
        $delimiter = ',';

        if ($this->shouldRetryAsTab($rows)) {
            $rows = $this->parseDelimited($utf8, "\t");
            $delimiter = "\t";
        }

        $courier = $this->detectCourier($rows);

        if (! in_array($courier, [CourierCarrier::YAMATO, CourierCarrier::SAGAWA], true)) {
            return [
                'courier' => 'unknown',
                'delimiter' => $delimiter,
                'rows' => [],
                'error' => 'unknown_courier',
            ];
        }

        return [
            'courier' => $courier,
            'delimiter' => $delimiter,
            'rows' => $this->normalizeRows($rows, $courier),
            'error' => null,
        ];
    }

    private function decode(string $contents): string
    {
        if (str_starts_with($contents, "\xFF\xFE")) {
            return $this->stripBom((string) mb_convert_encoding($contents, 'UTF-8', 'UTF-16LE'));
        }

        if (str_starts_with($contents, "\xFE\xFF")) {
            return $this->stripBom((string) mb_convert_encoding($contents, 'UTF-8', 'UTF-16BE'));
        }

        foreach (['UTF-8', 'SJIS-win', 'CP932', 'Shift-JIS'] as $encoding) {
            $converted = @mb_convert_encoding($contents, 'UTF-8', $encoding);

            if (is_string($converted) && mb_check_encoding($converted, 'UTF-8')) {
                return $this->stripBom($converted);
            }
        }

        return $this->stripBom((string) mb_convert_encoding($contents, 'UTF-8', 'UTF-8'));
    }

    private function stripBom(string $value): string
    {
        return preg_replace('/^\xEF\xBB\xBF|\x{FEFF}/u', '', $value) ?? $value;
    }

    /**
     * @return array<int, array<int, string|null>>
     */
    private function parseDelimited(string $contents, string $delimiter): array
    {
        $contents = str_replace(["\r\n", "\r"], "\n", $contents);
        $file = new SplTempFileObject();
        $file->fwrite($contents);
        $file->rewind();
        $file->setFlags(SplTempFileObject::READ_CSV | SplTempFileObject::SKIP_EMPTY);
        $file->setCsvControl($delimiter, '"', '');

        $rows = [];

        foreach ($file as $row) {
            if (! is_array($row)) {
                continue;
            }

            if (count($row) === 1 && ($row[0] === null || trim((string) $row[0]) === '')) {
                continue;
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param  array<int, array<int, string|null>>  $rows
     */
    private function shouldRetryAsTab(array $rows): bool
    {
        if ($rows === []) {
            return false;
        }

        $sample = array_slice($rows, 0, 10);

        return collect($sample)->every(fn (array $row): bool => count($row) <= 1)
            && collect($sample)->contains(fn (array $row): bool => str_contains((string) ($row[0] ?? ''), "\t"));
    }

    /**
     * @param  array<int, array<int, string|null>>  $rows
     */
    private function detectCourier(array $rows): string
    {
        $sampleRows = array_slice($rows, 0, 20);
        $text = collect($sampleRows)
            ->flatten()
            ->filter()
            ->implode(' ');

        if ($this->containsAny($text, [
            '注文番号',
            '伝票番号',
            '豕ｨ譁・分蜿ｷ',
            '莨晉･ｨ逡ｪ蜿ｷ',
        ])) {
            return CourierCarrier::YAMATO;
        }

        if ($this->containsAny($text, [
            'お問い合わせ送り状No',
            'お問い合せ送り状No',
            'お客様管理番号',
            '縺雁撫縺・粋縺幃√ｊ迥ｶNo',
        ])) {
            return CourierCarrier::SAGAWA;
        }

        $yamatoScore = 0;
        $sagawaScore = 0;

        foreach ($sampleRows as $row) {
            if ($this->looksLikeOrder($this->normalizeValue($row[0] ?? '')) && $this->looksLikeTracking($this->normalizeValue($row[3] ?? ''))) {
                $yamatoScore++;
            }

            if ($this->looksLikeTracking($this->normalizeValue($row[0] ?? '')) && $this->looksLikeOrder($this->normalizeValue($row[1] ?? ''))) {
                $sagawaScore++;
            }
        }

        return match (true) {
            $yamatoScore > 0 && $sagawaScore === 0 => CourierCarrier::YAMATO,
            $sagawaScore > 0 && $yamatoScore === 0 => CourierCarrier::SAGAWA,
            default => 'unknown',
        };
    }

    /**
     * @param  array<int, array<int, string|null>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function normalizeRows(array $rows, string $courier): array
    {
        $normalized = [];
        $orderIndex = $courier === CourierCarrier::YAMATO ? 0 : 1;
        $trackingIndex = $courier === CourierCarrier::YAMATO ? 3 : 0;

        foreach ($rows as $index => $row) {
            if ($this->isHeaderRow($row)) {
                continue;
            }

            $orderValue = $this->normalizeValue($row[$orderIndex] ?? '');
            $trackingNo = $this->normalizeValue($row[$trackingIndex] ?? '');

            if ($orderValue === '' && $trackingNo === '') {
                $status = self::STATUS_SKIPPED;
            } elseif ($orderValue === '') {
                $status = self::STATUS_MISSING_ORDER;
            } elseif ($trackingNo === '') {
                $status = self::STATUS_MISSING_TRACKING;
            } else {
                $status = self::STATUS_READY;
            }

            $normalized[] = [
                'row_no' => $index + 1,
                'status' => $status,
                'order_value' => $orderValue,
                'order_tokens' => $this->splitOrderTokens($orderValue),
                'tracking_no' => $trackingNo,
            ];
        }

        return $normalized;
    }

    /**
     * @param  array<int, string|null>  $row
     */
    private function isHeaderRow(array $row): bool
    {
        $text = collect($row)->filter()->implode(' ');

        return $this->containsAny($text, [
            'order-id',
            'platform_order_id',
            '注文番号',
            '伝票番号',
            'お問い合わせ送り状No',
            'お客様管理番号',
            '豕ｨ譁・分蜿ｷ',
            '縺雁撫縺・粋縺幃√ｊ迥ｶNo',
        ]);
    }

    private function normalizeValue(mixed $value): string
    {
        $value = trim((string) $value);

        if (preg_match('/^="(.*)"$/s', $value, $matches)) {
            $value = $matches[1];
        }

        $value = trim($value, " \t\n\r\0\x0B\"'");
        $value = mb_convert_kana($value, 'asKV', 'UTF-8');

        return trim($value);
    }

    /**
     * @return array<int, string>
     */
    private function splitOrderTokens(string $orderValue): array
    {
        $tokens = preg_split('/[\s,|\/、，。；;]+/u', $orderValue, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $tokens[] = $orderValue;

        return collect($tokens)
            ->map(fn (string $token): string => $this->normalizeValue($token))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function looksLikeTracking(string $value): bool
    {
        return (bool) preg_match('/^[0-9\-]{8,}$/', str_replace(' ', '', $value));
    }

    private function looksLikeOrder(string $value): bool
    {
        return $value !== ''
            && mb_strlen($value) >= 3
            && (bool) preg_match('/[A-Za-z0-9]/', $value);
    }

    /**
     * @param  array<int, string>  $needles
     */
    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }
}
