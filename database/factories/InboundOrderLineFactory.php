<?php

namespace Database\Factories;

use App\Models\InboundOrder;
use App\Models\InboundOrderLine;
use App\Models\Sku;
use App\Models\StockItem;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InboundOrderLine>
 */
class InboundOrderLineFactory extends Factory
{
    public function definition(): array
    {
        $expectedQty = fake()->numberBetween(1, 50);

        return [
            'inbound_order_id' => InboundOrder::factory(),
            'tenant_id' => Tenant::factory(),
            'sku_id' => Sku::factory(),
            'stock_item_id' => StockItem::factory(),
            'expected_qty' => $expectedQty,
            'received_qty' => 0,
            'note' => fake()->optional()->sentence(),
        ];
    }
}
