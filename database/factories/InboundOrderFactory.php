<?php

namespace Database\Factories;

use App\Models\InboundOrder;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InboundOrder>
 */
class InboundOrderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'warehouse_id' => Warehouse::factory(),
            'ref' => fake()->optional()->bothify('IB-####'),
            'status' => InboundOrder::STATUS_PENDING,
            'expected_at' => fake()->optional()->dateTimeBetween('now', '+30 days'),
            'note' => fake()->optional()->sentence(),
            'created_by_user_id' => User::factory(),
        ];
    }
}
