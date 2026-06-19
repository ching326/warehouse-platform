<?php

namespace App\Services\Courier;

use App\Services\Courier\Concerns\BuildsCourierCsv;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class SagawaCsvBuilder
{
    use BuildsCourierCsv;

    public const HEADER = [
        'お届け先コード取得区分', 'お届け先コード', 'お届け先電話番号', 'お届け先郵便番号', 'お届け先住所１', 'お届け先住所２',
        'お届け先住所３', 'お届け先名称１', 'お届け先名称２', 'お客様管理番号', 'お客様コード', '部署ご担当者コード取得区分',
        '部署ご担当者コード', '部署ご担当者名称', '荷送人電話番号', 'ご依頼主コード取得区分', 'ご依頼主コード',
        'ご依頼主電話番号', 'ご依頼主郵便番号', 'ご依頼主住所１', 'ご依頼主住所２', 'ご依頼主名称１', 'ご依頼主名称２',
        '荷姿', '品名１', '品名２', '品名３', '品名４', '品名５', '荷札荷姿', '荷札品名１', '荷札品名２', '荷札品名３',
        '荷札品名４', '荷札品名５', '荷札品名６', '荷札品名７', '荷札品名８', '荷札品名９', '荷札品名１０', '荷札品名１１',
        '出荷個数', 'スピード指定', 'クール便指定', '配達日', '配達指定時間帯', '配達指定時間（時分）', '代引金額',
        '消費税', '決済種別', '保険金額', '指定シール１', '指定シール２', '指定シール３', '営業所受取', 'SRC区分',
        '営業所受取営業所コード', '元着区分', 'メールアドレス', 'ご不在時連絡先', '出荷日', 'お問い合せ送り状No.',
        '出荷場印字区分', '集約解除指定', '編集０１', '編集０２', '編集０３', '編集０４', '編集０５', '編集０６',
        '編集０７', '編集０８', '編集０９', '編集１０',
    ];

    public function __construct(private JapaneseAddressSplitter $splitter)
    {
    }

    public function build(Collection $orders, CarbonImmutable $now): string
    {
        $rows = [self::HEADER];
        $shipDate = $now->timezone('Asia/Tokyo')->format('Ymd');

        foreach ($orders as $order) {
            $address = $this->splitter->split(
                $order->recipient_state,
                $order->recipient_city,
                $order->recipient_address_line1,
                $order->recipient_address_line2,
            );
            $items = $this->itemNames($order, 5, 16);

            $row = array_fill(0, count(self::HEADER), '');
            $row[2] = $order->recipient_phone;
            $row[3] = $order->recipient_postal_code;
            $row[4] = $address['address1'];
            $row[5] = $address['address2'];
            $row[6] = $address['address3'];
            $row[7] = $order->recipient_name;
            $row[9] = mb_substr((string) $order->platform_order_id, -15);
            $row[14] = config('courier.sender.phone');
            $row[17] = config('courier.sender.phone');
            $row[18] = config('courier.sender.postal_code');
            $row[19] = config('courier.sender.address1');
            $row[20] = config('courier.sender.address2');
            $row[21] = $this->senderName($order->shop?->name ?? '');
            $row[23] = '箱';
            foreach ($items as $index => $item) {
                $row[24 + $index] = $item;
            }
            $row[41] = '1';
            $row[60] = $shipDate;

            $rows[] = $row;
        }

        return $this->encodeRows($rows);
    }
}
