<?php

namespace Database\Factories;

use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\Sku;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SalesOrderLine>
 */
class SalesOrderLineFactory extends Factory
{
    public function definition(): array
    {
        return [
            'sales_order_id' => SalesOrder::factory(),
            'sku_id' => Sku::factory(),
            'quantity' => fake()->numberBetween(1, 5),
            'unit_price' => null,
            'currency' => null,
            'line_status' => SalesOrderLine::STATUS_READY,
            'note' => fake()->optional()->sentence(),
        ];
    }
}
