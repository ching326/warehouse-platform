<?php

namespace App\Services\Courier\Concerns;

trait BuildsCourierCsv
{
    private function encodeRows(array $rows): string
    {
        $output = '';

        foreach ($rows as $row) {
            $stream = fopen('php://temp', 'r+');
            fputcsv($stream, $row, ',', '"', '');
            rewind($stream);
            $line = rtrim((string) stream_get_contents($stream), "\r\n");
            fclose($stream);

            $output .= mb_convert_encoding($line."\r\n", 'SJIS-win', 'UTF-8');
        }

        return $output;
    }

    private function senderName(string $fallback): string
    {
        return trim((string) config('courier.sender.name')) ?: $fallback;
    }

    private function itemNames($order, int $limit, int $length): array
    {
        return $order->lines
            ->where('line_status', \App\Models\SalesOrderLine::STATUS_READY)
            ->map(fn ($line) => trim((string) ($line->sku?->name ?: $line->sku?->stockItem?->short_name ?: $line->sku?->stockItem?->name ?: $line->sku?->sku)))
            ->filter()
            ->unique()
            ->take($limit)
            ->map(fn (string $name) => mb_substr($name, 0, $length))
            ->values()
            ->all();
    }

    private function itemSummary($order): string
    {
        return $order->lines
            ->where('line_status', \App\Models\SalesOrderLine::STATUS_READY)
            ->map(fn ($line) => $line->quantity.' x '.($line->sku?->sku ?? 'SKU'))
            ->implode(' | ');
    }
}
