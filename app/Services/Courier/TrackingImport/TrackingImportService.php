<?php

namespace App\Services\Courier\TrackingImport;

use App\Models\OutboundOrder;
use App\Models\SalesOrder;
use App\Models\User;
use App\Support\TrackingNumber;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TrackingImportService
{
    public function __construct(private readonly TrackingImportParser $parser) {}

    /**
     * @param  list<int>  $allowedTenantIds
     * @return array{updated: int}
     */
    public function importFulfillmentGroups(string $contents, ?string $sourceFileName, User $user, array $allowedTenantIds): array
    {
        $parsed = $this->parser->parse($contents);

        $updated = DB::transaction(function () use ($parsed, $sourceFileName, $user, $allowedTenantIds): int {
            $updated = 0;

            foreach (collect($parsed['rows'])->groupBy('row_no') as $rowCandidates) {
                $match = $rowCandidates
                    ->filter(fn (array $row): bool => $row['status'] === TrackingImportParser::STATUS_READY)
                    ->map(function (array $row) use ($allowedTenantIds): array {
                        return [
                            'row' => $row,
                            'outboundOrders' => $this->findOutboundOrders((array) $row['order_tokens'], $allowedTenantIds),
                        ];
                    })
                    ->filter(fn (array $candidate): bool => $candidate['outboundOrders']->count() === 1)
                    ->values();

                if ($match->count() !== 1) {
                    continue;
                }

                $row = $match->first()['row'];
                $outboundOrders = $match->first()['outboundOrders'];

                $outbound = OutboundOrder::query()
                    ->whereIn('tenant_id', $allowedTenantIds)
                    ->where('status', '!=', OutboundOrder::STATUS_CANCELLED)
                    ->whereKey($outboundOrders->first()->id)
                    ->with([
                        'salesOrders:id,tenant_id,platform_order_id,tracking_no',
                        'fulfillmentGroup:id,reference_no',
                        'fulfillmentGroup.groupOrders:id,fulfillment_group_id,sales_order_id,tracking_no',
                    ])
                    ->lockForUpdate()
                    ->first();

                if (! $outbound) {
                    continue;
                }

                $newTrackingNo = TrackingNumber::normalize(mb_substr((string) $row['tracking_no'], 0, 255));

                if ($newTrackingNo === null) {
                    continue;
                }

                $oldTrackingNo = (string) ($outbound->tracking_no ?: $outbound->salesOrders->pluck('tracking_no')->filter()->first() ?: '');

                if ($oldTrackingNo === $newTrackingNo) {
                    continue;
                }

                $outbound->update(['tracking_no' => $newTrackingNo]);
                $outbound->fulfillmentGroup?->groupOrders()->update(['tracking_no' => $newTrackingNo]);

                SalesOrder::query()
                    ->whereIn('id', $outbound->salesOrders->pluck('id'))
                    ->update(['tracking_no' => $newTrackingNo]);

                foreach ($outbound->salesOrders as $salesOrder) {
                    activity('sales_order')
                        ->performedOn($salesOrder)
                        ->causedBy($user)
                        ->event('tracking_imported')
                        ->withProperties([
                            'old_tracking_no' => $oldTrackingNo,
                            'new_tracking_no' => $newTrackingNo,
                            'source_file_name' => $sourceFileName,
                            'row_no' => $row['row_no'],
                            'outbound_order_id' => $outbound->id,
                            'outbound_order_ref' => $outbound->ref,
                            'fulfillment_group_id' => $outbound->fulfillmentGroup?->id,
                            'fulfillment_group_reference_no' => $outbound->fulfillmentGroup?->reference_no,
                        ])
                        ->log('tracking_imported');
                }

                if ($outbound->salesOrders->isEmpty()) {
                    activity('outbound_order')
                        ->performedOn($outbound)
                        ->causedBy($user)
                        ->event('tracking_imported')
                        ->withProperties([
                            'old_tracking_no' => $oldTrackingNo,
                            'new_tracking_no' => $newTrackingNo,
                            'source_file_name' => $sourceFileName,
                            'row_no' => $row['row_no'],
                            'outbound_order_id' => $outbound->id,
                            'outbound_order_ref' => $outbound->ref,
                            'fulfillment_group_id' => $outbound->fulfillmentGroup?->id,
                            'fulfillment_group_reference_no' => $outbound->fulfillmentGroup?->reference_no,
                        ])
                        ->log('tracking_imported');
                }

                $updated++;
            }

            return $updated;
        });

        return [
            'updated' => $updated,
        ];
    }

    /**
     * @param  array<int, string>  $tokens
     * @param  list<int>  $allowedTenantIds
     * @return Collection<int, OutboundOrder>
     */
    private function findOutboundOrders(array $tokens, array $allowedTenantIds): Collection
    {
        $tokens = collect($tokens)
            ->map(fn (string $token): string => trim($token))
            ->filter()
            ->unique()
            ->values();

        if ($tokens->isEmpty() || $allowedTenantIds === []) {
            return collect();
        }

        $exactMatches = OutboundOrder::query()
            ->whereIn('tenant_id', $allowedTenantIds)
            ->where('status', '!=', OutboundOrder::STATUS_CANCELLED)
            ->whereIn('ref', $tokens->all())
            ->get(['id', 'tenant_id', 'ref', 'status']);

        if ($exactMatches->isNotEmpty()) {
            return $exactMatches;
        }

        $suffixTokens = $tokens
            ->filter(fn (string $token): bool => mb_strlen($token) === 15)
            ->values();

        if ($suffixTokens->isEmpty()) {
            return collect();
        }

        return OutboundOrder::query()
            ->whereIn('tenant_id', $allowedTenantIds)
            ->where('status', '!=', OutboundOrder::STATUS_CANCELLED)
            ->where(function ($query) use ($suffixTokens): void {
                foreach ($suffixTokens as $token) {
                    $query->orWhereRaw('substr(ref, ?) = ?', [-15, $token]);
                }
            })
            ->get(['id', 'tenant_id', 'ref', 'status']);
    }
}
