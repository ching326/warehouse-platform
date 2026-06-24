<?php

namespace Database\Factories;

use App\Models\OutboundOrder;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OutboundOrder>
 */
class OutboundOrderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'warehouse_id' => Warehouse::factory(),
            'ref' => fake()->optional()->bothify('OB-####'),
            'status' => OutboundOrder::STATUS_PENDING,
            'reason' => null,
            'ship_mode' => OutboundOrder::SHIP_MODE_PARCEL,
            'note' => fake()->optional()->sentence(),
            'created_by_user_id' => User::factory(),
        ];
    }
}
