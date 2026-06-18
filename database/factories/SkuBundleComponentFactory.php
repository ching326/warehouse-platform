<?php

namespace Database\Factories;

use App\Models\Sku;
use App\Models\SkuBundleComponent;
use App\Models\StockItem;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SkuBundleComponent>
 */
class SkuBundleComponentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'bundle_sku_id' => Sku::factory()->virtualBundle(),
            'component_stock_item_id' => StockItem::factory(),
            'quantity' => fake()->numberBetween(1, 5),
        ];
    }
}
