<?php

namespace Database\Factories;

use App\Models\InventoryBalance;
use App\Models\StockItem;
use App\Models\Tenant;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryBalance>
 */
class InventoryBalanceFactory extends Factory
{
    public function definition(): array
    {
        $onHand = fake()->numberBetween(0, 300);
        $reserved = fake()->numberBetween(0, min(40, $onHand));
        $hold = fake()->numberBetween(0, min(10, $onHand - $reserved));
        $damaged = fake()->numberBetween(0, min(5, $onHand - $reserved - $hold));

        return [
            'tenant_id' => Tenant::factory(),
            'warehouse_id' => Warehouse::factory(),
            'stock_item_id' => StockItem::factory(),
            'on_hand_qty' => $onHand,
            'reserved_qty' => $reserved,
            'available_qty' => $onHand - $reserved - $hold - $damaged,
            'inbound_qty' => fake()->numberBetween(0, 120),
            'hold_qty' => $hold,
            'damaged_qty' => $damaged,
        ];
    }
}
