<?php

namespace Database\Factories;

use App\Models\OutboundOrder;
use App\Models\OutboundOrderLine;
use App\Models\Sku;
use App\Models\StockItem;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OutboundOrderLine>
 */
class OutboundOrderLineFactory extends Factory
{
    public function definition(): array
    {
        return [
            'outbound_order_id' => OutboundOrder::factory(),
            'parent_line_id' => null,
            'tenant_id' => Tenant::factory(),
            'sku_id' => Sku::factory(),
            'stock_item_id' => StockItem::factory(),
            'qty' => fake()->numberBetween(1, 10),
            'inventory_movement_id' => null,
            'note' => fake()->optional()->sentence(),
        ];
    }
}
