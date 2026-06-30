<?php

namespace Tests\Feature;

use App\Livewire\SkuLabelPrint;
use App\Models\BarcodeAlias;
use App\Models\Shop;
use App\Models\Sku;
use App\Models\StockItem;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\Labels\SkuLabelPdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SkuLabelPrintTest extends TestCase
{
    use RefreshDatabase;

    public function test_content_options_include_fnsku_and_primary_stock_item_barcode_types(): void
    {
        [$sku, $stockItem] = $this->skuWithStockItem(['platform_label_code' => 'FNSKU123']);
        $this->primaryAlias($stockItem, 'jan', '4901234567890');
        $this->primaryAlias($stockItem, 'supplier_label', 'SUP-001');
        $this->inactiveAlias($stockItem, 'ean', '1234567890123');

        Livewire::actingAs($this->internalUser())
            ->test(SkuLabelPrint::class, ['sku' => $sku])
            ->assertSee('FNSKU')
            ->assertSee('Barcode (JAN)')
            ->assertSee('Barcode (Supplier label)')
            ->assertDontSee('Barcode (EAN)');
    }

    public function test_apply_to_all_sets_entry_quantities(): void
    {
        [$sku] = $this->skuWithStockItem();

        Livewire::actingAs($this->internalUser())
            ->test(SkuLabelPrint::class, ['sku' => $sku])
            ->call('addEntry')
            ->set('applyQty', 7)
            ->call('applyQtyToAll')
            ->assertSet('entries.0.qty', 7)
            ->assertSet('entries.1.qty', 7);
    }

    public function test_generate_redirects_and_download_returns_inline_pdf(): void
    {
        [$sku] = $this->skuWithStockItem();
        $user = $this->internalUser();

        Livewire::actingAs($user)
            ->test(SkuLabelPrint::class, ['sku' => $sku])
            ->set('entries.0.content', 'sku')
            ->set('entries.0.qty', 1)
            ->call('generate')
            ->assertRedirect(route('skus.label.download'));

        $response = $this->actingAs($user)->get(route('skus.label.download'));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('inline; filename="sku-labels-'.$sku->sku.'-', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF', (string) $response->getContent());
    }

    public function test_expired_session_redirects_to_skus_index(): void
    {
        $this->actingAs($this->internalUser())
            ->get(route('skus.label.download'))
            ->assertRedirect(route('skus.index'))
            ->assertSessionHas('error', __('skus.label_session_expired'));
    }

    public function test_quantity_expands_labels_and_skip_cells_are_passed_to_service(): void
    {
        [$sku] = $this->skuWithStockItem();
        $fake = new FakeSkuLabelPdfService;
        $this->app->instance(SkuLabelPdfService::class, $fake);

        $this->actingAs($this->internalUser())
            ->withSession([
                SkuLabelPrint::SESSION_KEY => [
                    'layoutKey' => '40up_a4',
                    'entries' => [
                        ['sku_id' => $sku->id, 'content' => 'sku', 'qty' => 10],
                    ],
                    'skipCells' => [0, 1, 2],
                    'includeName' => false,
                ],
            ])
            ->get(route('skus.label.download'))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->assertSame('40up_a4', $fake->layoutKey);
        $this->assertCount(10, $fake->labels);
        $this->assertSame(['value' => $sku->sku, 'code_text' => $sku->sku, 'name' => null], $fake->labels[0]);
        $this->assertSame([0, 1, 2], $fake->skipCells);
    }

    public function test_validation_fails_when_no_content_is_chosen(): void
    {
        [$sku] = $this->skuWithStockItem();

        Livewire::actingAs($this->internalUser())
            ->test(SkuLabelPrint::class, ['sku' => $sku])
            ->call('generate')
            ->assertHasErrors(['entries.0.content']);
    }

    public function test_tenant_user_cannot_open_or_download_labels(): void
    {
        [$sku] = $this->skuWithStockItem();
        $user = User::factory()->create(['user_type' => 'tenant', 'is_active' => true]);
        TenantUser::factory()->create(['tenant_id' => $sku->tenant_id, 'user_id' => $user->id, 'status' => 'active']);

        Livewire::actingAs($user)
            ->test(SkuLabelPrint::class, ['sku' => $sku])
            ->assertForbidden();

        $this->actingAs($user)
            ->withSession([
                SkuLabelPrint::SESSION_KEY => [
                    'layoutKey' => '40up_a4',
                    'entries' => [['sku_id' => $sku->id, 'content' => 'sku', 'qty' => 1]],
                    'skipCells' => [],
                    'includeName' => true,
                ],
            ])
            ->get(route('skus.label.download'))
            ->assertForbidden();
    }

    public function test_download_rejects_missing_chosen_value_without_fallback(): void
    {
        [$sku] = $this->skuWithStockItem(['platform_label_code' => null]);

        $this->actingAs($this->internalUser())
            ->withSession([
                SkuLabelPrint::SESSION_KEY => [
                    'layoutKey' => '40up_a4',
                    'entries' => [['sku_id' => $sku->id, 'content' => 'fnsku', 'qty' => 1]],
                    'skipCells' => [],
                    'includeName' => true,
                ],
            ])
            ->get(route('skus.label.download'))
            ->assertNotFound();
    }

    /**
     * @param  array<string, mixed>  $skuAttributes
     * @return array{0: Sku, 1: StockItem}
     */
    private function skuWithStockItem(array $skuAttributes = []): array
    {
        $tenant = Tenant::factory()->create();
        $shop = Shop::factory()->for($tenant)->create();
        $stockItem = StockItem::factory()->for($tenant)->create();
        $sku = Sku::factory()->for($tenant)->for($shop)->for($stockItem)->create(array_merge([
            'sku' => 'SKU-LABEL-'.fake()->unique()->numberBetween(1000, 9999),
            'platform_label_code' => null,
        ], $skuAttributes));

        return [$sku, $stockItem];
    }

    private function primaryAlias(StockItem $stockItem, string $type, string $barcode): BarcodeAlias
    {
        return BarcodeAlias::query()->create([
            'tenant_id' => $stockItem->tenant_id,
            'model_type' => BarcodeAlias::MODEL_TYPE_STOCK_ITEM,
            'model_id' => $stockItem->id,
            'barcode' => $barcode,
            'normalized_barcode' => BarcodeAlias::normalize($barcode),
            'barcode_type' => $type,
            'label' => null,
            'is_primary' => true,
            'is_active' => true,
            'source' => BarcodeAlias::SOURCE_MANUAL,
        ]);
    }

    private function inactiveAlias(StockItem $stockItem, string $type, string $barcode): BarcodeAlias
    {
        return BarcodeAlias::query()->create([
            'tenant_id' => $stockItem->tenant_id,
            'model_type' => BarcodeAlias::MODEL_TYPE_STOCK_ITEM,
            'model_id' => $stockItem->id,
            'barcode' => $barcode,
            'normalized_barcode' => BarcodeAlias::normalize($barcode),
            'barcode_type' => $type,
            'label' => null,
            'is_primary' => true,
            'is_active' => false,
            'source' => BarcodeAlias::SOURCE_MANUAL,
        ]);
    }

    private function internalUser(): User
    {
        return User::factory()->create(['user_type' => 'internal', 'is_active' => true]);
    }
}

class FakeSkuLabelPdfService extends SkuLabelPdfService
{
    public string $layoutKey = '';

    public array $labels = [];

    public array $skipCells = [];

    public function render(string $layoutKey, array $labels, array $skipCells = []): string
    {
        $this->layoutKey = $layoutKey;
        $this->labels = $labels;
        $this->skipCells = $skipCells;

        return '%PDF-fake';
    }
}
