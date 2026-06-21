<?php

namespace App\Services\Courier\TrackingImport;

use SplTempFileObject;

class TrackingImportParser
{
    public const STATUS_READY = 'ready';
    public const STATUS_MISSING_ORDER = 'missing_order';
    public const STATUS_MISSING_TRACKING = 'missing_tracking';
    public const STATUS_SKIPPED = 'skipped';

    /**
     * @return array{delimiter: string, rows: array<int, array<string, mixed>>}
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

        return [
            'delimiter' => $delimiter,
            'rows' => $this->normalizeRows($rows, $this->formatsForRows($rows)),
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

        $encoding = mb_detect_encoding($contents, ['UTF-8', 'SJIS-win', 'CP932', 'Shift-JIS'], true);

        if ($encoding) {
            return $this->stripBom((string) mb_convert_encoding($contents, 'UTF-8', $encoding));
        }

        foreach (['SJIS-win', 'CP932', 'Shift-JIS', 'UTF-8'] as $fallbackEncoding) {
            $converted = @mb_convert_encoding($contents, 'UTF-8', $fallbackEncoding);

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
     * @return list<string>
     */
    private function formatsForRows(array $rows): array
    {
        $text = collect(array_slice($rows, 0, 10))
            ->flatten()
            ->filter()
            ->map(fn (mixed $value): string => $this->normalizeValue($value))
            ->implode(' ');

        if ($this->containsAny($text, ['注文番号', '伝票番号'])) {
            return ['yamato'];
        }

        if ($this->containsAny($text, ['お問い合せ送り状No', 'お問い合わせ送り状No', 'お客様管理番号'])) {
            return ['sagawa'];
        }

        return ['yamato', 'sagawa'];
    }

    /**
     * @param  array<int, array<int, string|null>>  $rows
     * @param  list<string>  $formats
     * @return array<int, array<string, mixed>>
     */
    private function normalizeRows(array $rows, array $formats): array
    {
        $normalized = [];

        foreach ($rows as $index => $row) {
            if ($this->isHeaderRow($row)) {
                continue;
            }

            foreach ($this->formatCandidates($row, $formats) as $candidate) {
                $orderValue = $this->normalizeValue($candidate['order_value']);
                $trackingNo = $this->normalizeValue($candidate['tracking_no']);

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
        }

        return $normalized;
    }

    /**
     * @param  array<int, string|null>  $row
     * @param  list<string>  $formats
     * @return array<int, array{order_value: mixed, tracking_no: mixed}>
     */
    private function formatCandidates(array $row, array $formats): array
    {
        $candidates = [];

        if (in_array('yamato', $formats, true)) {
            $candidates[] = ['order_value' => $row[0] ?? '', 'tracking_no' => $row[3] ?? ''];
        }

        if (in_array('sagawa', $formats, true)) {
            $candidates[] = ['order_value' => $row[1] ?? '', 'tracking_no' => $row[0] ?? ''];
        }

        return $candidates;
    }

    /**
     * @param  array<int, string|null>  $row
     */
    private function isHeaderRow(array $row): bool
    {
        $text = collect($row)
            ->filter()
            ->map(fn (mixed $value): string => $this->normalizeValue($value))
            ->implode(' ');

        return $this->containsAny($text, [
            'order-id',
            'platform_order_id',
            '注文番号',
            '伝票番号',
            'お問い合せ送り状No',
            'お問い合わせ送り状No',
            'お客様管理番号',
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
        $tokens = preg_split('/[\s,|\/、，]+/u', $orderValue, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $tokens[] = $orderValue;

        return collect($tokens)
            ->map(fn (string $token): string => $this->normalizeValue($token))
            ->filter()
            ->unique()
            ->values()
            ->all();
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
