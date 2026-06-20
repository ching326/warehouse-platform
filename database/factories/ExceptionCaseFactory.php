<?php

namespace Database\Factories;

use App\Models\ExceptionCase;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ExceptionCase>
 */
class ExceptionCaseFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'sales_order_id' => null,
            'case_no' => 'EC-PENDING-'.Str::uuid(),
            'case_type' => ExceptionCase::TYPE_MISSING,
            'status' => ExceptionCase::STATUS_OPEN,
            'reported_at' => now(),
            'reported_by' => 'customer',
            'note' => fake()->optional()->sentence(),
            'created_by_user_id' => User::factory(),
            'updated_by_user_id' => null,
        ];
    }
}
