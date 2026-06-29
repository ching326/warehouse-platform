<?php

namespace Tests\Feature;

use App\Models\Sku;
use App\Models\StockItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocalizedProductNameTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        app()->setLocale('en');

        parent::tearDown();
    }

    public function test_stock_item_display_name_prefers_short_name_then_name(): void
    {
        $item = StockItem::factory()->create([
            'name' => 'Full Name',
            'short_name' => 'Short',
        ]);

        $this->assertSame('Short', $item->displayName());

        $item->update(['short_name' => null]);

        $this->assertSame('Full Name', $item->fresh()->displayName());
    }

    public function test_stock_item_short_name_is_language_neutral(): void
    {
        $item = StockItem::factory()->create([
            'name' => 'Base Name',
            'name_ja' => 'ジャパン名称',
            'short_name' => 'SHRT',
        ]);

        app()->setLocale('ja');
        $this->assertSame('SHRT', $item->displayName());

        app()->setLocale('zh_TW');
        $this->assertSame('SHRT', $item->displayName());
    }

    public function test_stock_item_name_uses_locale_override_then_falls_back(): void
    {
        $item = StockItem::factory()->create([
            'name' => 'Base Name',
            'name_ja' => 'ジャパン名称',
            'name_zh_tw' => '繁中名稱',
            'short_name' => null,
        ]);

        app()->setLocale('ja');
        $this->assertSame('ジャパン名称', $item->displayName());

        app()->setLocale('zh_TW');
        $this->assertSame('繁中名稱', $item->displayName());

        app()->setLocale('zh_CN');
        $this->assertSame('Base Name', $item->displayName());

        app()->setLocale('en');
        $this->assertSame('Base Name', $item->displayName());
    }

    public function test_english_override_is_used_when_base_language_is_not_english(): void
    {
        $item = StockItem::factory()->create([
            'name' => 'Base Japanese Name',
            'name_en' => 'English Override Name',
            'name_ja' => null,
            'short_name' => null,
        ]);

        $this->assertSame('English Override Name', $item->localizedName('en'));
        $this->assertSame('Base Japanese Name', $item->localizedName('ja'));
    }

    public function test_default_english_base_still_falls_back_to_name_for_every_locale(): void
    {
        $item = StockItem::factory()->create([
            'name' => 'Base Name',
            'name_en' => null,
            'name_ja' => null,
            'name_zh_tw' => null,
            'name_zh_cn' => null,
            'short_name' => null,
        ]);

        $this->assertSame('Base Name', $item->localizedName('en'));
        $this->assertSame('Base Name', $item->localizedName('ja'));
        $this->assertSame('Base Name', $item->localizedName('zh_TW'));
        $this->assertSame('Base Name', $item->localizedName('zh_CN'));
    }

    public function test_sku_display_name_delegates_to_stock_item(): void
    {
        $item = StockItem::factory()->create([
            'name' => 'Item Name',
            'short_name' => 'Item Short',
        ]);
        $sku = Sku::factory()->for($item, 'stockItem')->create();

        app()->setLocale('ja');
        $this->assertSame('Item Short', $sku->displayName());

        $item->update(['short_name' => null, 'name_ja' => '商品日本語名']);

        $this->assertSame('商品日本語名', $sku->refresh()->displayName());
    }

    public function test_sku_without_stock_item_has_no_product_name(): void
    {
        $sku = Sku::factory()->create(['stock_item_id' => null]);

        $this->assertSame('', $sku->displayName());
    }
}
