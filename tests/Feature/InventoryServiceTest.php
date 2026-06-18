<?php

namespace Tests\Feature;

use App\Models\InventoryMovement;
use App\Models\StockItem;
use App\Models\Tenant;
use App\Models\Warehouse;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class InventoryServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_inventory_service_records_all_core_stock_movements(): void
    {
        $tenant = Tenant::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $stockItem = StockItem::factory()->for($tenant)->create();
        $service = app(InventoryService::class);

        $receive = $service->receiveStock($tenant->id, $warehouse->id, $stockItem->id, 100, [
            'ref_type' => 'purchase_order',
            'ref_id' => 'PO-1001',
        ]);

        $this->assertSame(100, $receive->quantity_delta);
        $this->assertSame(100, $receive->balance_after);
        $this->assertMovementBuckets($receive, onHandDelta: 100, reservedDelta: 0, availableDelta: 100, holdDelta: 0, damagedDelta: 0);
        $this->assertMovementSnapshot($receive, onHand: 100, reserved: 0, available: 100, hold: 0, damaged: 0);
        $this->assertBalance($stockItem, onHand: 100, reserved: 0, available: 100, hold: 0, damaged: 0);

        $reserve = $service->reserveStock($tenant->id, $warehouse->id, $stockItem->id, 20, [
            'ref_type' => 'order',
            'ref_id' => 'ORDER-1001',
        ]);

        $this->assertSame(-20, $reserve->quantity_delta);
        $this->assertSame(80, $reserve->balance_after);
        $this->assertMovementBuckets($reserve, onHandDelta: 0, reservedDelta: 20, availableDelta: -20, holdDelta: 0, damagedDelta: 0);
        $this->assertMovementSnapshot($reserve, onHand: 100, reserved: 20, available: 80, hold: 0, damaged: 0);
        $this->assertBalance($stockItem, onHand: 100, reserved: 20, available: 80, hold: 0, damaged: 0);

        $releaseReserve = $service->releaseReserve($tenant->id, $warehouse->id, $stockItem->id, 5);

        $this->assertSame(5, $releaseReserve->quantity_delta);
        $this->assertSame(85, $releaseReserve->balance_after);
        $this->assertMovementBuckets($releaseReserve, onHandDelta: 0, reservedDelta: -5, availableDelta: 5, holdDelta: 0, damagedDelta: 0);
        $this->assertMovementSnapshot($releaseReserve, onHand: 100, reserved: 15, available: 85, hold: 0, damaged: 0);
        $this->assertBalance($stockItem, onHand: 100, reserved: 15, available: 85, hold: 0, damaged: 0);

        $ship = $service->shipReservedStock($tenant->id, $warehouse->id, $stockItem->id, 10, [
            'ref_type' => 'shipment',
            'ref_id' => 'SHIP-1001',
        ]);

        $this->assertSame(-10, $ship->quantity_delta);
        $this->assertSame(90, $ship->balance_after);
        $this->assertMovementBuckets($ship, onHandDelta: -10, reservedDelta: -10, availableDelta: 0, holdDelta: 0, damagedDelta: 0);
        $this->assertMovementSnapshot($ship, onHand: 90, reserved: 5, available: 85, hold: 0, damaged: 0);
        $this->assertBalance($stockItem, onHand: 90, reserved: 5, available: 85, hold: 0, damaged: 0);

        $hold = $service->placeHold($tenant->id, $warehouse->id, $stockItem->id, 10);

        $this->assertSame(-10, $hold->quantity_delta);
        $this->assertSame(75, $hold->balance_after);
        $this->assertMovementBuckets($hold, onHandDelta: 0, reservedDelta: 0, availableDelta: -10, holdDelta: 10, damagedDelta: 0);
        $this->assertMovementSnapshot($hold, onHand: 90, reserved: 5, available: 75, hold: 10, damaged: 0);
        $this->assertBalance($stockItem, onHand: 90, reserved: 5, available: 75, hold: 10, damaged: 0);

        $releaseHold = $service->releaseHold($tenant->id, $warehouse->id, $stockItem->id, 4);

        $this->assertSame(4, $releaseHold->quantity_delta);
        $this->assertSame(79, $releaseHold->balance_after);
        $this->assertMovementBuckets($releaseHold, onHandDelta: 0, reservedDelta: 0, availableDelta: 4, holdDelta: -4, damagedDelta: 0);
        $this->assertMovementSnapshot($releaseHold, onHand: 90, reserved: 5, available: 79, hold: 6, damaged: 0);
        $this->assertBalance($stockItem, onHand: 90, reserved: 5, available: 79, hold: 6, damaged: 0);

        $damaged = $service->markDamaged($tenant->id, $warehouse->id, $stockItem->id, 9);

        $this->assertSame(-9, $damaged->quantity_delta);
        $this->assertSame(70, $damaged->balance_after);
        $this->assertMovementBuckets($damaged, onHandDelta: 0, reservedDelta: 0, availableDelta: -9, holdDelta: 0, damagedDelta: 9);
        $this->assertMovementSnapshot($damaged, onHand: 90, reserved: 5, available: 70, hold: 6, damaged: 9);
        $this->assertBalance($stockItem, onHand: 90, reserved: 5, available: 70, hold: 6, damaged: 9);

        $adjustment = $service->adjustStock($tenant->id, $warehouse->id, $stockItem->id, -5, [
            'ref_type' => 'manual',
            'ref_id' => 'ADJ-1001',
        ]);

        $this->assertSame(-5, $adjustment->quantity_delta);
        $this->assertSame(85, $adjustment->balance_after);
        $this->assertMovementBuckets($adjustment, onHandDelta: -5, reservedDelta: 0, availableDelta: -5, holdDelta: 0, damagedDelta: 0);
        $this->assertMovementSnapshot($adjustment, onHand: 85, reserved: 5, available: 65, hold: 6, damaged: 9);
        $this->assertBalance($stockItem, onHand: 85, reserved: 5, available: 65, hold: 6, damaged: 9);

        $this->assertSame(8, InventoryMovement::count());
    }

    public function test_inventory_service_rejects_reserving_more_than_available_stock(): void
    {
        $tenant = Tenant::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $stockItem = StockItem::factory()->for($tenant)->create();
        $service = app(InventoryService::class);

        $service->receiveStock($tenant->id, $warehouse->id, $stockItem->id, 4);

        $this->expectException(InvalidArgumentException::class);

        $service->reserveStock($tenant->id, $warehouse->id, $stockItem->id, 5);
    }

    private function assertBalance(
        StockItem $stockItem,
        int $onHand,
        int $reserved,
        int $available,
        int $hold,
        int $damaged,
    ): void {
        $balance = $stockItem->inventoryBalances()->firstOrFail()->refresh();

        $this->assertSame($onHand, $balance->on_hand_qty);
        $this->assertSame($reserved, $balance->reserved_qty);
        $this->assertSame($available, $balance->available_qty);
        $this->assertSame($hold, $balance->hold_qty);
        $this->assertSame($damaged, $balance->damaged_qty);
    }

    private function assertMovementBuckets(
        InventoryMovement $movement,
        int $onHandDelta,
        int $reservedDelta,
        int $availableDelta,
        int $holdDelta,
        int $damagedDelta,
    ): void {
        $movement->refresh();

        $this->assertSame($onHandDelta, $movement->on_hand_delta);
        $this->assertSame($reservedDelta, $movement->reserved_delta);
        $this->assertSame($availableDelta, $movement->available_delta);
        $this->assertSame(0, $movement->inbound_delta);
        $this->assertSame($holdDelta, $movement->hold_delta);
        $this->assertSame($damagedDelta, $movement->damaged_delta);
    }

    private function assertMovementSnapshot(
        InventoryMovement $movement,
        int $onHand,
        int $reserved,
        int $available,
        int $hold,
        int $damaged,
    ): void {
        $movement->refresh();

        $this->assertSame($onHand, $movement->on_hand_after);
        $this->assertSame($reserved, $movement->reserved_after);
        $this->assertSame($available, $movement->available_after);
        $this->assertSame(0, $movement->inbound_after);
        $this->assertSame($hold, $movement->hold_after);
        $this->assertSame($damaged, $movement->damaged_after);
    }
}
