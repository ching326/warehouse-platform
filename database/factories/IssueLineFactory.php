<?php

namespace Database\Factories;

use App\Models\Issue;
use App\Models\IssueLine;
use App\Models\Sku;
use App\Models\StockItem;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IssueLine>
 */
class IssueLineFactory extends Factory
{
    public function definition(): array
    {
        return [
            'issue_id' => Issue::factory(),
            'tenant_id' => Tenant::factory(),
            'sku_id' => Sku::factory(),
            'stock_item_id' => StockItem::factory(),
            'qty' => 1,
            'condition' => IssueLine::CONDITION_UNKNOWN,
            'action' => IssueLine::ACTION_INVESTIGATE,
            'note' => fake()->optional()->sentence(),
        ];
    }
}
