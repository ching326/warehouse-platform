<?php

namespace Database\Factories;

use App\Models\Shop;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Shop>
 */
class ShopFactory extends Factory
{
    public function definition(): array
    {
        $platform = fake()->randomElement(['amazon', 'rakuten', 'shopify', 'manual']);
        $marketplace = fake()->randomElement(Shop::marketplaceOptions());

        return [
            'tenant_id' => Tenant::factory(),
            'platform' => $platform,
            'marketplace' => $marketplace,
            'code' => strtoupper($platform).'-'.fake()->unique()->numberBetween(100, 999),
            'name' => ucfirst($platform).' '.($marketplace ?? 'Global'),
            'consolidation_mode' => Shop::CONSOLIDATION_SAME_SHOP,
            'contact_name' => fake()->optional()->name(),
            'contact_email' => fake()->optional()->safeEmail(),
            'status' => 'active',
            'note' => fake()->optional()->sentence(),
        ];
    }
}
