<?php

namespace Database\Factories;

use App\Models\FulfillmentGroup;
use App\Models\Tenant;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FulfillmentGroup>
 */
class FulfillmentGroupFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'warehouse_id' => Warehouse::factory(),
            'reference_no' => 'FG-ABC-'.now()->format('ymd').'-'.fake()->unique()->numerify('#####'),
            'status' => FulfillmentGroup::STATUS_RESERVED,
            'ship_together_key' => fake()->md5(),
            'recipient_name' => fake()->name(),
            'recipient_country_code' => 'JP',
            'recipient_city' => fake()->city(),
            'recipient_address_line1' => fake()->streetAddress(),
            'created_by_user_id' => null,
        ];
    }
}
