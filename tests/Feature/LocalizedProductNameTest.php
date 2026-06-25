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

    public function test_stock_item_display_name_uses_locale_override_when_present(): void
    {
        $item = StockItem::factory()->create([
            'name' => 'Base Name',
            'short_name' => 'Base Short',
            'short_name_ja' => 'ジャパン略称',
            'name_zh_tw' => '繁中名稱',
        ]);

        app()->setLocale('ja');
        $this->assertSame('ジャパン略称', $item->displayName());

        app()->setLocale('zh_TW');
        // No zh_TW short_name override, so localized short_name falls back to base short_name.
        $this->assertSame('Base Short', $item->displayName());
    }

    public function test_stock_item_display_name_falls_back_to_base_when_override_empty(): void
    {
        $item = StockItem::factory()->create([
            'name' => 'Base Name',
            'short_name' => 'Base Short',
        ]);

        app()->setLocale('zh_CN');
        $this->assertSame('Base Short', $item->displayName());

        // Unknown/source locale (en) always uses the base column.
        app()->setLocale('en');
        $this->assertSame('Base Short', $item->displayName());
    }

    public function test_sku_display_name_delegates_to_stock_item_then_own_name(): void
    {
        $item = StockItem::factory()->create([
            'name' => 'Item Name',
            'short_name' => 'Item Short',
            'short_name_ja' => 'アイテム略称',
        ]);
        $sku = Sku::factory()->for($item, 'stockItem')->create([
            'name' => 'Sku Name',
            'name_ja' => 'SKUジャパン',
        ]);

        app()->setLocale('ja');
        $this->assertSame('アイテム略称', $sku->displayName());

        // With no linked stock item, the SKU falls back to its own localized name.
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
