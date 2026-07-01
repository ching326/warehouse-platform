<?php

namespace App\Services\Courier;

use App\Services\Courier\Concerns\BuildsCourierCsv;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class YamatoCsvBuilder
{
    use BuildsCourierCsv;

    public const HEADER = [
        '注文番号', '配送方法', 'クール区分', '伝票番号', '出荷予定日', 'お届け予定日', '配達時間帯', 'お届け先コード',
        'お届け先電話番号', 'お届け先電話番号枝番', 'お届け先郵便番号', 'お届け先住所', 'お届け先アパートマンション名',
        '会社', '部門', 'お届け先名', 'お届け先名(ｶﾅ)', '敬称', 'ご依頼主コード', 'ご依頼主電話番号',
        'ご依頼主電話番号枝番', 'ご依頼主郵便番号', 'ご依頼主住所', 'ご依頼主アパートマンション', 'ご依頼主名',
        'ご依頼主名(ｶﾅ)', '品名コード１', '品名１', '品名コード２', '品名２', '荷扱い１', '荷扱い２', '記事',
        'ｺﾚｸﾄ代金引換額（税込)', '内消費税額等', '止置き', '営業所コード', '発行枚数', '個数口表示フラグ',
        '請求先顧客コード', '請求先分類コード', '運賃管理番号',
    ];

    public function __construct(private JapaneseAddressSplitter $splitter) {}

    public function build(Collection $orders, CarbonImmutable $now): string
    {
        $rows = [self::HEADER];
        $shipDate = $now->timezone('Asia/Tokyo')->format('Y/m/d');

        foreach ($orders as $order) {
            $address = $this->splitter->split(
                $order->recipient_state,
                $order->recipient_city,
                $order->recipient_address_line1,
                $order->recipient_address_line2,
            );
            $items = $this->itemNames($order, 2, 25);

            $rows[] = [
                $order->platform_order_id,
                '', '', '', $shipDate, '', '', '',
                $order->recipient_phone,
                '',
                $order->recipient_postal_code,
                $address['address1'],
                $address['address2'],
                $address['address3'],
                '',
                $order->recipient_name,
                '',
                '様',
                '',
                $this->senderPhone($order),
                '',
                $this->senderPostcode($order),
                $this->senderAddress1($order),
                $this->senderAddress2($order),
                $this->senderName($order->shop?->name ?? ''),
                '',
                '',
                $items[0] ?? '',
                '',
                $items[1] ?? '',
                '', '', mb_substr($this->itemSummary($order), 0, 80),
                '', '', '', '', '1', '', '', '', '',
            ];
        }

        return $this->encodeRows($rows);
    }
}
