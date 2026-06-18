<?php

namespace Database\Factories;

use App\Models\StockItem;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockItem>
 */
class StockItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'code' => 'STK-'.fake()->unique()->numerify('######'),
            'name' => fake()->words(3, true),
            'short_name' => fake()->words(2, true),
            'brand' => fake()->optional()->company(),
            'model_number' => fake()->optional()->bothify('MDL-####'),
            'variation_code' => fake()->optional()->bothify('VAR-??'),
            'color' => fake()->optional()->safeColorName(),
            'size' => fake()->optional()->randomElement(['S', 'M', 'L', 'XL']),
            'barcode' => fake()->optional()->ean13(),
            'barcode_type' => 'jan',
            'product_type' => 'normal',
            'is_dangerous_goods' => false,
            'requires_expiry_tracking' => false,
            'requires_lot_tracking' => false,
            'description' => fake()->optional()->sentence(),
            'note' => fake()->optional()->sentence(),
            'handling_note' => fake()->optional()->sentence(),
            'weight_value' => fake()->randomFloat(3, 50, 2000),
            'weight_unit' => 'g',
            'length_value' => fake()->randomFloat(2, 5, 60),
            'width_value' => fake()->randomFloat(2, 5, 40),
            'height_value' => fake()->randomFloat(2, 1, 30),
            'dimension_unit' => 'cm',
            'status' => 'active',
        ];
    }
}
