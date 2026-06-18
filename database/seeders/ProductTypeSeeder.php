<?php

namespace Database\Seeders;

use App\Models\ProductType;
use Illuminate\Database\Seeder;

class ProductTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            ['slug' => 'normal',       'name' => 'Normal',       'sort_order' => 0,  'translations' => ['en' => 'Normal',       'zh_TW' => '一般',   'zh_CN' => '普通',   'ja' => '一般']],
            ['slug' => 'with_battery', 'name' => 'With Battery', 'sort_order' => 10, 'translations' => ['en' => 'With Battery', 'zh_TW' => '含電池', 'zh_CN' => '含电池', 'ja' => 'バッテリー内蔵']],
            ['slug' => 'is_battery',   'name' => 'Is Battery',   'sort_order' => 20, 'translations' => ['en' => 'Is Battery',   'zh_TW' => '電池',   'zh_CN' => '电池',   'ja' => 'バッテリー単品']],
            ['slug' => 'food',         'name' => 'Food',         'sort_order' => 30, 'translations' => ['en' => 'Food',         'zh_TW' => '食品',   'zh_CN' => '食品',   'ja' => '食品']],
        ];

        foreach ($types as $type) {
            ProductType::updateOrCreate(['slug' => $type['slug']], $type);
        }
    }
}
