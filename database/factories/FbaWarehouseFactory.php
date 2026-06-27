<?php

namespace Database\Factories;

use App\Models\FbaWarehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FbaWarehouse>
 */
class FbaWarehouseFactory extends Factory
{
    protected $model = FbaWarehouse::class;

    public function definition(): array
    {
        return [
            'country_code' => 'JP',
            'code' => 'FBA-JP-'.fake()->unique()->numberBetween(100, 999),
            'name' => 'Amazon FBA '.fake()->city(),
            'postal_code' => fake()->postcode(),
            'state' => fake()->state(),
            'city' => fake()->city(),
            'address_line1' => fake()->streetAddress(),
            'address_line2' => fake()->optional()->secondaryAddress(),
            'phone' => fake()->optional()->phoneNumber(),
            'status' => FbaWarehouse::STATUS_ACTIVE,
            'note' => fake()->optional()->sentence(),
        ];
    }
}
