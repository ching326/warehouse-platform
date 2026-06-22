<?php

namespace App\Actions;

use App\Support\TrackingNumber;
use Illuminate\Support\Facades\DB;

class BackfillNormalizedTrackingNumbers
{
    private const TARGETS = [
        'fulfillment_groups',
        'fulfillment_group_orders',
        'sales_orders',
        'outbound_orders',
        'return_orders',
    ];

    public function handle(): void
    {
        foreach (self::TARGETS as $table) {
            $this->backfillTable($table);
        }
    }

    private function backfillTable(string $table): void
    {
        DB::table($table)
            ->whereNotNull('tracking_no')
            ->select(['id', 'tracking_no'])
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($table): void {
                foreach ($rows as $row) {
                    $normalized = TrackingNumber::normalize($row->tracking_no);

                    if ($row->tracking_no === $normalized) {
                        continue;
                    }

                    DB::table($table)
                        ->where('id', $row->id)
                        ->update(['tracking_no' => $normalized]);
                }
            });
    }
}
