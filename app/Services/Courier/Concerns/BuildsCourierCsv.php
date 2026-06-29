<?php

namespace App\Services\Courier\Concerns;

use App\Models\SalesOrderLine;
use App\Models\Sku;
use App\Models\Tenant;
use App\Services\Fulfillment\FulfillmentItemCodeResolver;

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
            ->map(fn (object $line) => trim((string) ($line->sku?->displayName() ?: $line->sku?->sku)))
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
            ->map(fn (object $line) => $line->quantity.' x '.$this->fulfillmentItemCode($order, $line))
            ->implode(' | ');
    }

    private function fulfillmentItemCode($order, object $line): string
    {
        $tenant = $order->tenant ?? $order->shop->tenant ?? null;
        $sku = $line->sku ?? null;

        if (! $tenant instanceof Tenant || ! $sku instanceof Sku) {
            return $sku?->sku ?? 'SKU';
        }

        return app(FulfillmentItemCodeResolver::class)->resolve($tenant, $sku, $sku->stockItem);
    }

    private function normalizeCourierLine($line): ?object
    {
        if ($line instanceof SalesOrderLine && $line->line_status !== SalesOrderLine::STATUS_READY) {
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
