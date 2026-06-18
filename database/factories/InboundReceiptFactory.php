<?php

namespace Database\Factories;

use App\Models\InboundOrder;
use App\Models\InboundOrderLine;
use App\Models\InboundReceipt;
use App\Models\InventoryMovement;
use App\Models\Sku;
use App\Models\StockItem;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InboundReceipt>
 */
class InboundReceiptFactory extends Factory
{
    public function definition(): array
    {
        return [
            'inbound_order_id' => InboundOrder::factory(),
            'inbound_order_line_id' => InboundOrderLine::factory(),
            'tenant_id' => Tenant::factory(),
            'warehouse_id' => Warehouse::factory(),
            'warehouse_location_id' => WarehouseLocation::factory(),
            'sku_id' => Sku::factory(),
            'stock_item_id' => StockItem::factory(),
            'inventory_movement_id' => InventoryMovement::factory(),
            'received_qty' => fake()->numberBetween(1, 25),
            'received_by_user_id' => User::factory(),
            'received_at' => now(),
            'note' => fake()->optional()->sentence(),
        ];
    }
}
