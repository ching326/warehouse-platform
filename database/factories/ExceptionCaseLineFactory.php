<?php

namespace Database\Factories;

use App\Models\ExceptionCase;
use App\Models\ExceptionCaseLine;
use App\Models\Sku;
use App\Models\StockItem;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExceptionCaseLine>
 */
class ExceptionCaseLineFactory extends Factory
{
    public function definition(): array
    {
        return [
            'exception_case_id' => ExceptionCase::factory(),
            'tenant_id' => Tenant::factory(),
            'sku_id' => Sku::factory(),
            'stock_item_id' => StockItem::factory(),
            'qty' => 1,
            'condition' => ExceptionCaseLine::CONDITION_UNKNOWN,
            'action' => ExceptionCaseLine::ACTION_INVESTIGATE,
            'note' => fake()->optional()->sentence(),
        ];
    }
}
