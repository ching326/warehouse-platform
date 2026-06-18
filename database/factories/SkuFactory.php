<?php

namespace Database\Factories;

use App\Models\PackagingMaterial;
use App\Models\Shop;
use App\Models\Sku;
use App\Models\StockItem;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Sku>
 */
class SkuFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'shop_id' => Shop::factory(),
            'stock_item_id' => StockItem::factory(),
            'sku' => 'SKU-'.fake()->unique()->bothify('??####'),
            'name' => fake()->words(3, true),
            'platform_sku' => fake()->optional()->bothify('PLT-??####'),
            'platform_product_id' => fake()->optional()->bothify('ASIN########'),
            'platform_variant_id' => fake()->optional()->bothify('VAR########'),
            'platform_variant_name' => fake()->optional()->randomElement(['Black / M', 'White / L', 'Default']),
            'platform_label_code' => fake()->optional()->bothify('FNSKU########'),
            'sku_type' => 'single',
            'default_packaging_material_id' => PackagingMaterial::factory(),
            'status' => 'active',
            'note' => fake()->optional()->sentence(),
        ];
    }

    public function virtualBundle(): static
    {
        return $this->state(fn (array $attributes) => [
            'stock_item_id' => null,
            'sku_type' => 'virtual_bundle',
        ]);
    }
}
