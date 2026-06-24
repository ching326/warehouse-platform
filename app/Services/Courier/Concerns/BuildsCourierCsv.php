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
            ->map(fn ($line) => $this->normalizeCourierLine($line))
            ->filter()
            ->map(fn (object $line) => trim((string) ($line->sku?->name ?: $line->sku?->stockItem?->short_name ?: $line->sku?->stockItem?->name ?: $line->sku?->sku)))
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
            ->map(fn ($line) => $this->normalizeCourierLine($line))
            ->filter()
            ->map(fn (object $line) => $line->quantity.' x '.($line->sku?->sku ?? 'SKU'))
            ->implode(' | ');
    }

    private function normalizeCourierLine($line): ?object
    {
        if ($line instanceof \App\Models\SalesOrderLine && $line->line_status !== \App\Models\SalesOrderLine::STATUS_READY) {
            return null;
        }

        $quantity = (int) ($line->quantity ?? $line->qty ?? 0);

        if ($quantity <= 0) {
            return null;
        }

        return (object) [
            'sku' => $line->sku,
            'quantity' => $quantity,
        ];
    }
}
