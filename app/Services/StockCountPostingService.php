<?php

namespace App\Services;

use App\Models\InventoryBalance;
use App\Models\StockCountLine;
use App\Models\StockCountRun;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class StockCountPostingService
{
    public function __construct(private readonly InventoryService $inventoryService) {}

    public function postManual(
        int $tenantId,
        int $warehouseId,
        int $stockItemId,
        int $countedQty,
        ?string $note,
        ?int $userId,
    ): StockCountRun {
        return DB::transaction(function () use ($tenantId, $warehouseId, $stockItemId, $countedQty, $note, $userId): StockCountRun {
            $run = StockCountRun::create([
                'tenant_id' => $tenantId,
                'warehouse_id' => $warehouseId,
                'source' => StockCountRun::SOURCE_MANUAL,
                'total_lines' => 1,
                'note' => $note,
                'created_by_user_id' => $userId,
                'posted_at' => now(),
            ]);

            $line = $this->postLine($run, [
                'stock_item_id' => $stockItemId,
                'identifier_raw' => null,
                'counted_qty' => $countedQty,
                'line_note' => $note,
                'reference_no' => null,
            ], $userId);

            $this->updateRunTotals($run, collect([$line])->all());

            return $run->refresh();
        });
    }

    /**
     * @param  list<array{stock_item_id:int,identifier_raw:?string,counted_qty:int,line_note:?string,reference_no:?string}>  $rows
     */
    public function postImport(
        int $tenantId,
        int $warehouseId,
        ?string $fileName,
        ?string $note,
        array $rows,
        ?int $userId,
    ): StockCountRun {
        return DB::transaction(function () use ($tenantId, $warehouseId, $fileName, $note, $rows, $userId): StockCountRun {
            $run = StockCountRun::create([
                'tenant_id' => $tenantId,
                'warehouse_id' => $warehouseId,
                'source' => StockCountRun::SOURCE_IMPORT,
                'file_name' => $fileName,
                'total_lines' => count($rows),
                'note' => $note,
                'created_by_user_id' => $userId,
                'posted_at' => now(),
            ]);

            $lines = [];

            foreach ($rows as $row) {
                $lines[] = $this->postLine($run, $row, $userId);
            }

            $this->updateRunTotals($run, $lines);

            return $run->refresh();
        });
    }

    /**
     * @param  array{stock_item_id:int,identifier_raw:?string,counted_qty:int,line_note:?string,reference_no:?string}  $row
     */
    private function postLine(StockCountRun $run, array $row, ?int $userId): StockCountLine
    {
        $balance = InventoryBalance::query()
            ->where('tenant_id', $run->tenant_id)
            ->where('warehouse_id', $run->warehouse_id)
            ->where('stock_item_id', $row['stock_item_id'])
            ->lockForUpdate()
            ->first();

        $previousOnHand = (int) ($balance?->on_hand_qty ?? 0);
        $reservedHoldDamaged = (int) ($balance?->reserved_qty ?? 0)
            + (int) ($balance?->hold_qty ?? 0)
            + (int) ($balance?->damaged_qty ?? 0);

        if ($row['counted_qty'] < $reservedHoldDamaged) {
            throw new InvalidArgumentException(__('stock_counts.error_counted_below_committed'));
        }

        $deltaQty = $row['counted_qty'] - $previousOnHand;
        $movement = null;
        $status = StockCountLine::STATUS_NO_CHANGE;

        if ($deltaQty !== 0) {
            $movement = $this->inventoryService->adjustStock(
                tenantId: (int) $run->tenant_id,
                warehouseId: (int) $run->warehouse_id,
                stockItemId: $row['stock_item_id'],
                quantityDelta: $deltaQty,
                context: [
                    'ref_type' => 'stock_count',
                    'ref_id' => (string) $run->id,
                    'user_id' => $userId,
                    'note' => $this->movementNote($run, $row),
                ],
            );
            $status = StockCountLine::STATUS_ADJUSTED;
        }

        return StockCountLine::create([
            'stock_count_run_id' => $run->id,
            'tenant_id' => $run->tenant_id,
            'warehouse_id' => $run->warehouse_id,
            'stock_item_id' => $row['stock_item_id'],
            'identifier_raw' => $row['identifier_raw'],
            'counted_qty' => $row['counted_qty'],
            'previous_on_hand_qty' => $previousOnHand,
            'delta_qty' => $deltaQty,
            'movement_id' => $movement?->id,
            'line_note' => $row['line_note'],
            'reference_no' => $row['reference_no'],
            'status' => $status,
        ]);
    }

    /**
     * @param  list<StockCountLine>  $lines
     */
    private function updateRunTotals(StockCountRun $run, array $lines): void
    {
        $run->update([
            'adjusted_lines' => collect($lines)->where('status', StockCountLine::STATUS_ADJUSTED)->count(),
            'no_change_lines' => collect($lines)->where('status', StockCountLine::STATUS_NO_CHANGE)->count(),
            'failed_lines' => collect($lines)->where('status', StockCountLine::STATUS_FAILED)->count(),
        ]);
    }

    /**
     * @param  array{line_note:?string,reference_no:?string}  $row
     */
    private function movementNote(StockCountRun $run, array $row): string
    {
        $parts = [__('stock_counts.movement_note_base')];

        foreach ([$run->note, $row['line_note'], $row['reference_no']] as $part) {
            $part = trim((string) $part);

            if ($part !== '') {
                $parts[] = $part;
            }
        }

        return implode(' | ', $parts);
    }
}
