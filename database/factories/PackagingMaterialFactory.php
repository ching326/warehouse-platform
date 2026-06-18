<?php

namespace Database\Factories;

use App\Models\PackagingMaterial;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PackagingMaterial>
 */
class PackagingMaterialFactory extends Factory
{
    public function definition(): array
    {
        $type = fake()->randomElement(['envelope', 'box', 'mailer', 'polybag', 'bubble_wrap', 'other']);

        return [
            'code' => strtoupper($type).'-'.fake()->unique()->bothify('??-###'),
            'name' => ucfirst(str_replace('_', ' ', $type)).' '.fake()->randomElement(['S', 'M', 'L']),
            'type' => $type,
            'length_value' => fake()->randomFloat(2, 10, 60),
            'width_value' => fake()->randomFloat(2, 10, 40),
            'height_value' => fake()->randomFloat(2, 1, 30),
            'dimension_unit' => 'cm',
            'weight_value' => fake()->randomFloat(3, 5, 500),
            'weight_unit' => 'g',
            'cost' => fake()->randomFloat(2, 10, 600),
            'currency' => 'JPY',
            'status' => 'active',
            'note' => fake()->optional()->sentence(),
        ];
    }
}
