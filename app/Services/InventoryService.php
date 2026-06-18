<?php

namespace App\Services;

use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class InventoryService
{
    public function recordMovement(
        InventoryBalance $balance,
        string $movementType,
        int $quantityDelta,
        ?int $balanceAfter = null,
        array $context = [],
        array $bucketDeltas = [],
        ?array $afterSnapshot = null,
    ): InventoryMovement {
        $afterSnapshot ??= $this->bucketSnapshot($balance);

        return InventoryMovement::create([
            'tenant_id' => $balance->tenant_id,
            'warehouse_id' => $balance->warehouse_id,
            'stock_item_id' => $balance->stock_item_id,
            'movement_type' => $movementType,
            'quantity_delta' => $quantityDelta,
            'balance_after' => $balanceAfter,
            'on_hand_delta' => $bucketDeltas['on_hand'] ?? 0,
            'reserved_delta' => $bucketDeltas['reserved'] ?? 0,
            'available_delta' => $bucketDeltas['available'] ?? 0,
            'inbound_delta' => $bucketDeltas['inbound'] ?? 0,
            'hold_delta' => $bucketDeltas['hold'] ?? 0,
            'damaged_delta' => $bucketDeltas['damaged'] ?? 0,
            'on_hand_after' => $afterSnapshot['on_hand'],
            'reserved_after' => $afterSnapshot['reserved'],
            'available_after' => $afterSnapshot['available'],
            'inbound_after' => $afterSnapshot['inbound'],
            'hold_after' => $afterSnapshot['hold'],
            'damaged_after' => $afterSnapshot['damaged'],
            'ref_type' => $context['ref_type'] ?? null,
            'ref_id' => $context['ref_id'] ?? null,
            'user_id' => $context['user_id'] ?? null,
            'note' => $context['note'] ?? null,
            'created_at' => $context['created_at'] ?? now(),
        ]);
    }

    public function adjustStock(
        int $tenantId,
        int $warehouseId,
        int $stockItemId,
        int $quantityDelta,
        array $context = [],
    ): InventoryMovement {
        if ($quantityDelta === 0) {
            throw new InvalidArgumentException('quantityDelta cannot be zero.');
        }

        return $this->changeBalance(
            $tenantId,
            $warehouseId,
            $stockItemId,
            InventoryMovement::TYPE_ADJUST,
            $quantityDelta,
            function (InventoryBalance $balance) use ($quantityDelta): int {
                $newOnHand = $balance->on_hand_qty + $quantityDelta;
                $this->assertNonNegative($newOnHand, 'on_hand_qty');

                $balance->on_hand_qty = $newOnHand;

                return $balance->on_hand_qty;
            },
            $context,
        );
    }

    public function receiveStock(
        int $tenantId,
        int $warehouseId,
        int $stockItemId,
        int $quantity,
        array $context = [],
    ): InventoryMovement {
        $this->assertPositive($quantity, 'quantity');

        return $this->changeBalance(
            $tenantId,
            $warehouseId,
            $stockItemId,
            InventoryMovement::TYPE_RECEIVE,
            $quantity,
            function (InventoryBalance $balance) use ($quantity): int {
                $balance->on_hand_qty += $quantity;

                return $balance->on_hand_qty;
            },
            $context,
        );
    }

    public function reserveStock(
        int $tenantId,
        int $warehouseId,
        int $stockItemId,
        int $quantity,
        array $context = [],
    ): InventoryMovement {
        $this->assertPositive($quantity, 'quantity');

        return $this->changeBalance(
            $tenantId,
            $warehouseId,
            $stockItemId,
            InventoryMovement::TYPE_RESERVE,
            -$quantity,
            function (InventoryBalance $balance) use ($quantity): int {
                $this->assertEnough($balance->available_qty, $quantity, 'available stock');
                $balance->reserved_qty += $quantity;

                return $this->availableQty($balance);
            },
            $context,
        );
    }

    public function releaseReserve(
        int $tenantId,
        int $warehouseId,
        int $stockItemId,
        int $quantity,
        array $context = [],
    ): InventoryMovement {
        $this->assertPositive($quantity, 'quantity');

        return $this->changeBalance(
            $tenantId,
            $warehouseId,
            $stockItemId,
            InventoryMovement::TYPE_RELEASE_RESERVE,
            $quantity,
            function (InventoryBalance $balance) use ($quantity): int {
                $this->assertEnough($balance->reserved_qty, $quantity, 'reserved stock');
                $balance->reserved_qty -= $quantity;

                return $this->availableQty($balance);
            },
            $context,
        );
    }

    public function shipReservedStock(
        int $tenantId,
        int $warehouseId,
        int $stockItemId,
        int $quantity,
        array $context = [],
    ): InventoryMovement {
        $this->assertPositive($quantity, 'quantity');

        return $this->changeBalance(
            $tenantId,
            $warehouseId,
            $stockItemId,
            InventoryMovement::TYPE_SHIP,
            -$quantity,
            function (InventoryBalance $balance) use ($quantity): int {
                $this->assertEnough($balance->reserved_qty, $quantity, 'reserved stock');
                $this->assertEnough($balance->on_hand_qty, $quantity, 'on-hand stock');

                $balance->on_hand_qty -= $quantity;
                $balance->reserved_qty -= $quantity;

                return $balance->on_hand_qty;
            },
            $context,
        );
    }

    public function placeHold(
        int $tenantId,
        int $warehouseId,
        int $stockItemId,
        int $quantity,
        array $context = [],
    ): InventoryMovement {
        $this->assertPositive($quantity, 'quantity');

        return $this->changeBalance(
            $tenantId,
            $warehouseId,
            $stockItemId,
            InventoryMovement::TYPE_HOLD,
            -$quantity,
            function (InventoryBalance $balance) use ($quantity): int {
                $this->assertEnough($balance->available_qty, $quantity, 'available stock');
                $balance->hold_qty += $quantity;

                return $this->availableQty($balance);
            },
            $context,
        );
    }

    public function releaseHold(
        int $tenantId,
        int $warehouseId,
        int $stockItemId,
        int $quantity,
        array $context = [],
    ): InventoryMovement {
        $this->assertPositive($quantity, 'quantity');

        return $this->changeBalance(
            $tenantId,
            $warehouseId,
            $stockItemId,
            InventoryMovement::TYPE_RELEASE_HOLD,
            $quantity,
            function (InventoryBalance $balance) use ($quantity): int {
                $this->assertEnough($balance->hold_qty, $quantity, 'held stock');
                $balance->hold_qty -= $quantity;

                return $this->availableQty($balance);
            },
            $context,
        );
    }

    public function markDamaged(
        int $tenantId,
        int $warehouseId,
        int $stockItemId,
        int $quantity,
        array $context = [],
    ): InventoryMovement {
        $this->assertPositive($quantity, 'quantity');

        return $this->changeBalance(
            $tenantId,
            $warehouseId,
            $stockItemId,
            InventoryMovement::TYPE_MARK_DAMAGED,
            -$quantity,
            function (InventoryBalance $balance) use ($quantity): int {
                $this->assertEnough($balance->available_qty, $quantity, 'available stock');
                $balance->damaged_qty += $quantity;

                return $this->availableQty($balance);
            },
            $context,
        );
    }

    /**
     * @param callable(InventoryBalance): int|null $mutate
     */
    private function changeBalance(
        int $tenantId,
        int $warehouseId,
        int $stockItemId,
        string $movementType,
        int $quantityDelta,
        callable $mutate,
        array $context = [],
    ): InventoryMovement {
        return DB::transaction(function () use ($tenantId, $warehouseId, $stockItemId, $movementType, $quantityDelta, $mutate, $context) {
            $balance = InventoryBalance::query()
                ->where('tenant_id', $tenantId)
                ->where('warehouse_id', $warehouseId)
                ->where('stock_item_id', $stockItemId)
                ->lockForUpdate()
                ->first();

            if (! $balance) {
                $balance = InventoryBalance::create([
                    'tenant_id' => $tenantId,
                    'warehouse_id' => $warehouseId,
                    'stock_item_id' => $stockItemId,
                ]);
            }

            $beforeSnapshot = $this->bucketSnapshot($balance);
            $balanceAfter = $mutate($balance);
            $balance->available_qty = $this->availableQty($balance);
            $this->assertNonNegative($balance->available_qty, 'available_qty');
            $balance->save();
            $afterSnapshot = $this->bucketSnapshot($balance);
            $bucketDeltas = $this->bucketDeltas($beforeSnapshot, $afterSnapshot);

            return $this->recordMovement($balance, $movementType, $quantityDelta, $balanceAfter, $context, $bucketDeltas, $afterSnapshot);
        });
    }

    /**
     * @return array{on_hand:int,reserved:int,available:int,inbound:int,hold:int,damaged:int}
     */
    private function bucketSnapshot(InventoryBalance $balance): array
    {
        return [
            'on_hand' => $balance->on_hand_qty,
            'reserved' => $balance->reserved_qty,
            'available' => $balance->available_qty,
            'inbound' => $balance->inbound_qty,
            'hold' => $balance->hold_qty,
            'damaged' => $balance->damaged_qty,
        ];
    }

    /**
     * @param array{on_hand:int,reserved:int,available:int,inbound:int,hold:int,damaged:int} $before
     * @param array{on_hand:int,reserved:int,available:int,inbound:int,hold:int,damaged:int} $after
     * @return array{on_hand:int,reserved:int,available:int,inbound:int,hold:int,damaged:int}
     */
    private function bucketDeltas(array $before, array $after): array
    {
        return [
            'on_hand' => $after['on_hand'] - $before['on_hand'],
            'reserved' => $after['reserved'] - $before['reserved'],
            'available' => $after['available'] - $before['available'],
            'inbound' => $after['inbound'] - $before['inbound'],
            'hold' => $after['hold'] - $before['hold'],
            'damaged' => $after['damaged'] - $before['damaged'],
        ];
    }

    private function availableQty(InventoryBalance $balance): int
    {
        return $balance->on_hand_qty - $balance->reserved_qty - $balance->hold_qty - $balance->damaged_qty;
    }

    private function assertPositive(int $value, string $field): void
    {
        if ($value <= 0) {
            throw new InvalidArgumentException("{$field} must be greater than zero.");
        }
    }

    private function assertNonNegative(int $value, string $field): void
    {
        if ($value < 0) {
            throw new InvalidArgumentException("{$field} cannot be negative.");
        }
    }

    private function assertEnough(int $available, int $requested, string $label): void
    {
        if ($requested > $available) {
            throw new InvalidArgumentException("Not enough {$label}.");
        }
    }
}
