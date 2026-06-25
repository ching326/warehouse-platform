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
        // short_name behaves like brand/model_number: one value, no per-locale
        // variants, and it wins over the localized full name regardless of locale.
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

        // No zh_CN override -> base name. Source locale (en) -> base name.
        app()->setLocale('zh_CN');
        $this->assertSame('Base Name', $item->displayName());

        app()->setLocale('en');
        $this->assertSame('Base Name', $item->displayName());
    }

    public function test_sku_display_name_delegates_to_stock_item_then_own_localized_name(): void
    {
        $item = StockItem::factory()->create([
            'name' => 'Item Name',
            'short_name' => 'Item Short',
        ]);
        $sku = Sku::factory()->for($item, 'stockItem')->create([
            'name' => 'Sku Name',
            'name_ja' => 'SKUジャパン',
        ]);

        // Stock item short_name wins (language-neutral).
        app()->setLocale('ja');
        $this->assertSame('Item Short', $sku->displayName());

        // With no linked stock item, the SKU uses its own localized name.
        $orphan = Sku::factory()->create([
            'stock_item_id' => null,
            'name' => 'Orphan Sku',
            'name_ja' => 'みなしSKU',
        ]);

        $this->assertSame('みなしSKU', $orphan->displayName());
        $this->assertSame('みなしSKU', $orphan->localizedName());
    }

    public function test_sku_display_name_prefers_own_name_over_stock_item_full_name(): void
    {
        // When the stock item has no short name, the SKU list ordering shows the
        // SKU's own name ahead of the stock item's verbose full name.
        $item = StockItem::factory()->create([
            'name' => 'Stock Item Verbose Name',
            'short_name' => null,
        ]);
        $sku = Sku::factory()->for($item, 'stockItem')->create(['name' => 'Concise Sku Name']);

        $this->assertSame('Concise Sku Name', $sku->displayName());
    }
}
