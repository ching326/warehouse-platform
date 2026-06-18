<?php

namespace Database\Factories;

use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Warehouse>
 */
class WarehouseFactory extends Factory
{
    public function definition(): array
    {
        $countryCode = fake()->randomElement(['JP', 'CN', 'US']);

        return [
            'code' => $countryCode.'-'.strtoupper(fake()->city()).'-'.fake()->unique()->numberBetween(1, 99),
            'name' => fake()->city().' Warehouse',
            'country_code' => $countryCode,
            'timezone' => match ($countryCode) {
                'CN' => 'Asia/Shanghai',
                'US' => 'America/Los_Angeles',
                default => 'Asia/Tokyo',
            },
            'postal_code' => fake()->postcode(),
            'state' => fake()->state(),
            'city' => fake()->city(),
            'address_line1' => fake()->streetAddress(),
            'address_line2' => fake()->optional()->secondaryAddress(),
            'phone' => fake()->phoneNumber(),
            'status' => 'active',
        ];
    }
}
