<?php

namespace App\Services\MarketplaceShippingNotice;

use App\Models\SalesOrder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class RakutenShippingNoticeBuilder
{
    public const HEADER = [
        '豕ｨ譁・分蜿ｷ',
        '騾∽ｻ伜・ID',
        '逋ｺ騾∵・邏ｰID',
        '縺願差迚ｩ莨晉･ｨ逡ｪ蜿ｷ',
        '驟埼∽ｼ夂､ｾ',
        '逋ｺ騾∵律',
    ];

    public function build(Collection $orders, array $mappings, CarbonImmutable $japanNow): string
    {
        $rows = [self::HEADER];
        $shipDate = $japanNow->timezone('Asia/Tokyo')->format('Y-m-d');

        foreach ($orders as $order) {
            /** @var SalesOrder $order */
            $mapping = $mappings[$order->id] ?? null;
            $rows[] = [
                $order->platform_order_id,
                '',
                '',
                $order->tracking_no,
                $mapping?->carrier_code,
                $shipDate,
            ];
        }

        return mb_convert_encoding($this->csvRows($rows), 'SJIS-win', 'UTF-8');
    }

    public function lineCount(Collection $orders): int
    {
        return $orders->count();
    }

    private function csvRows(array $rows): string
    {
        $output = '';

        foreach ($rows as $row) {
            $stream = fopen('php://temp', 'r+');
            fputcsv($stream, $row, ',', '"', '');
            rewind($stream);
            $output .= rtrim((string) stream_get_contents($stream), "\r\n")."\r\n";
            fclose($stream);
        }

        return $output;
    }
}
