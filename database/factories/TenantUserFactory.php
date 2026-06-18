<?php

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TenantUser>
 */
class TenantUserFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'user_id' => User::factory(),
            'role' => fake()->randomElement(['owner', 'admin', 'staff', 'viewer']),
            'status' => 'active',
            'invited_at' => now()->subDays(fake()->numberBetween(7, 60)),
            'joined_at' => now()->subDays(fake()->numberBetween(1, 6)),
        ];
    }
}
