<?php

namespace Database\Factories;

use App\Models\Issue;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Issue>
 */
class IssueFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'sales_order_id' => null,
            'issue_no' => 'ISS-PENDING-'.Str::uuid(),
            'issue_type' => Issue::TYPE_MISSING,
            'status' => Issue::STATUS_OPEN,
            'reported_at' => now(),
            'reported_by' => 'customer',
            'note' => fake()->optional()->sentence(),
            'created_by_user_id' => User::factory(),
            'updated_by_user_id' => null,
        ];
    }
}
