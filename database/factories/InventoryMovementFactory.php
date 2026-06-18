<?php

namespace Database\Factories;

use App\Models\InventoryMovement;
use App\Models\StockItem;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryMovement>
 */
class InventoryMovementFactory extends Factory
{
    public function definition(): array
    {
        $quantity = fake()->numberBetween(1, 20);

        $balanceAfter = fake()->numberBetween($quantity, 300);

        return [
            'tenant_id' => Tenant::factory(),
            'warehouse_id' => Warehouse::factory(),
            'stock_item_id' => StockItem::factory(),
            'movement_type' => InventoryMovement::TYPE_RECEIVE,
            'quantity_delta' => $quantity,
            'balance_after' => $balanceAfter,
            'on_hand_delta' => $quantity,
            'reserved_delta' => 0,
            'available_delta' => $quantity,
            'inbound_delta' => 0,
            'hold_delta' => 0,
            'damaged_delta' => 0,
            'on_hand_after' => $balanceAfter,
            'reserved_after' => 0,
            'available_after' => $balanceAfter,
            'inbound_after' => 0,
            'hold_after' => 0,
            'damaged_after' => 0,
            'ref_type' => fake()->optional()->randomElement(['purchase_order', 'order', 'manual']),
            'ref_id' => fake()->optional()->bothify('REF-####'),
            'user_id' => User::factory(),
            'note' => fake()->optional()->sentence(),
            'created_at' => now(),
        ];
    }
}
