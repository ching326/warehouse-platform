<?php

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->company();

        return [
            'code' => strtoupper(Str::substr(Str::slug($name, ''), 0, 3)).fake()->unique()->numberBetween(100, 999),
            'name' => $name,
            'contact_name' => fake()->name(),
            'contact_email' => fake()->companyEmail(),
            'contact_phone' => fake()->phoneNumber(),
            'billing_terms' => fake()->randomElement(['monthly', 'prepaid', 'net_30']),
            'status' => 'active',
            'notes' => fake()->optional()->sentence(),
        ];
    }
}
