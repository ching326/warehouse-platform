<?php

namespace App\Services\Courier\TrackingImport;

use App\Models\FulfillmentGroup;
use App\Models\SalesOrder;
use App\Models\User;
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
                            'groups' => $this->findGroups((array) $row['order_tokens'], $allowedTenantIds),
                        ];
                    })
                    ->filter(fn (array $candidate): bool => $candidate['groups']->count() === 1)
                    ->values();

                if ($match->count() !== 1) {
                    continue;
                }

                $row = $match->first()['row'];
                $groups = $match->first()['groups'];

                $group = FulfillmentGroup::query()
                    ->whereIn('tenant_id', $allowedTenantIds)
                    ->whereKey($groups->first()->id)
                    ->with('groupOrders:id,fulfillment_group_id,sales_order_id,tracking_no')
                    ->lockForUpdate()
                    ->first();

                if (! $group) {
                    continue;
                }

                $newTrackingNo = mb_substr((string) $row['tracking_no'], 0, 255);
                $oldTrackingNo = (string) ($group->groupOrders->pluck('tracking_no')->filter()->first() ?? '');

                if ($oldTrackingNo === $newTrackingNo) {
                    continue;
                }

                $group->groupOrders()->update(['tracking_no' => $newTrackingNo]);

                SalesOrder::query()
                    ->whereIn('id', $group->groupOrders->pluck('sales_order_id'))
                    ->update(['tracking_no' => $newTrackingNo]);

                foreach ($group->groupOrders as $groupOrder) {
                    $salesOrder = SalesOrder::find($groupOrder->sales_order_id);

                    if (! $salesOrder) {
                        continue;
                    }

                    activity('sales_order')
                        ->performedOn($salesOrder)
                        ->causedBy($user)
                        ->event('tracking_imported')
                        ->withProperties([
                            'old_tracking_no' => $oldTrackingNo,
                            'new_tracking_no' => $newTrackingNo,
                            'source_file_name' => $sourceFileName,
                            'row_no' => $row['row_no'],
                            'fulfillment_group_id' => $group->id,
                            'fulfillment_group_reference_no' => $group->reference_no,
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
     * @return Collection<int, FulfillmentGroup>
     */
    private function findGroups(array $tokens, array $allowedTenantIds): Collection
    {
        $tokens = collect($tokens)
            ->map(fn (string $token): string => trim($token))
            ->filter()
            ->unique()
            ->values();

        if ($tokens->isEmpty() || $allowedTenantIds === []) {
            return collect();
        }

        $exactMatches = FulfillmentGroup::query()
            ->whereIn('tenant_id', $allowedTenantIds)
            ->whereIn('reference_no', $tokens->all())
            ->get(['id', 'tenant_id', 'reference_no']);

        if ($exactMatches->isNotEmpty()) {
            return $exactMatches;
        }

        $suffixTokens = $tokens
            ->filter(fn (string $token): bool => mb_strlen($token) === 15)
            ->values();

        if ($suffixTokens->isEmpty()) {
            return collect();
        }

        return FulfillmentGroup::query()
            ->whereIn('tenant_id', $allowedTenantIds)
            ->where(function ($query) use ($suffixTokens): void {
                foreach ($suffixTokens as $token) {
                    $query->orWhereRaw('substr(reference_no, ?) = ?', [-15, $token]);
                }
            })
            ->get(['id', 'tenant_id', 'reference_no']);
    }
}
