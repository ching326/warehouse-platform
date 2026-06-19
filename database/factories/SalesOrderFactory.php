<?php

namespace Database\Factories;

use App\Models\SalesOrder;
use App\Models\Shop;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SalesOrder>
 */
class SalesOrderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'shop_id' => Shop::factory(),
            'source' => SalesOrder::SOURCE_MANUAL,
            'platform_order_id' => fake()->optional()->bothify('SO-####??'),
            'order_status' => SalesOrder::ORDER_STATUS_PENDING,
            'fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_UNFULFILLED,
            'recipient_name' => fake()->name(),
            'recipient_phone' => fake()->phoneNumber(),
            'recipient_country_code' => 'JP',
            'recipient_postal_code' => fake()->postcode(),
            'recipient_state' => fake()->state(),
            'recipient_city' => fake()->city(),
            'recipient_address_line1' => fake()->streetAddress(),
            'recipient_address_line2' => fake()->optional()->secondaryAddress(),
            'note' => fake()->optional()->sentence(),
            'created_by_user_id' => User::factory(),
        ];
    }
}
