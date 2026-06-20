<?php

namespace App\Services\MarketplaceShippingNotice;

use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class AmazonShippingNoticeBuilder
{
    public const HEADER_META = [
        'TemplateType=OrderFulfillment',
        'Version=2011.1102',
        'Amazon shipment confirmation feed',
    ];

    public const HEADER_COLUMNS = [
        'order-id',
        'order-item-id',
        'quantity',
        'ship-date',
        'carrier-code',
        'carrier-name',
        'tracking-number',
        'ship-method',
    ];

    public function build(Collection $orders, array $mappings, CarbonImmutable $japanNow): string
    {
        $rows = [
            $this->tsvRow(self::HEADER_META),
            $this->tsvRow(self::HEADER_COLUMNS),
        ];
        $shipDate = $japanNow->timezone('Asia/Tokyo')->toAtomString();

        foreach ($orders as $order) {
            /** @var SalesOrder $order */
            $mapping = $mappings[$order->id] ?? null;

            foreach ($order->lines->where('line_status', SalesOrderLine::STATUS_READY) as $line) {
                $rows[] = $this->tsvRow([
                    $order->platform_order_id,
                    $line->platform_line_id,
                    $line->quantity,
                    $shipDate,
                    $mapping?->carrier_code,
                    $mapping?->carrier_name,
                    $order->tracking_no,
                    $mapping?->service_name ?: $order->shippingMethod?->name,
                ]);
            }
        }

        return mb_convert_encoding(implode("\r\n", $rows)."\r\n", 'SJIS-win', 'UTF-8');
    }

    public function lineCount(Collection $orders): int
    {
        return $orders
            ->sum(fn (SalesOrder $order): int => $order->lines
                ->where('line_status', SalesOrderLine::STATUS_READY)
                ->count());
    }

    private function tsvRow(array $fields): string
    {
        return implode("\t", array_map([$this, 'cleanField'], $fields));
    }

    private function cleanField(mixed $value): string
    {
        return str_replace(["\t", "\r", "\n"], ' ', trim((string) $value));
    }
}
