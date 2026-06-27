<?php

namespace Database\Factories;

use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WarehouseLocation>
 */
class WarehouseLocationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'warehouse_id' => Warehouse::factory(),
            'code' => fake()->unique()->bothify('LOC-###'),
            'name' => fake()->words(2, true),
            'zone_type' => 'storage',
            'storage_unit_type' => 'bin',
            'status' => 'active',
            'note' => fake()->optional()->sentence(),
        ];
    }
}
