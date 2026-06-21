<?php

namespace App\Services\Courier\TrackingImport;

use App\Models\SalesOrder;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TrackingImportService
{
    public function __construct(private readonly TrackingImportParser $parser) {}

    /**
     * @param  list<int>  $allowedTenantIds
     * @return array{courier: string, updated: int, error: ?string}
     */
    public function import(string $contents, ?string $sourceFileName, User $user, array $allowedTenantIds): array
    {
        $parsed = $this->parser->parse($contents);

        if ($parsed['error'] === 'unknown_courier') {
            return [
                'courier' => 'unknown',
                'updated' => 0,
                'error' => 'unknown_courier',
            ];
        }

        $updated = DB::transaction(function () use ($parsed, $sourceFileName, $user, $allowedTenantIds): int {
            $updated = 0;

            foreach ($parsed['rows'] as $row) {
                if ($row['status'] !== TrackingImportParser::STATUS_READY) {
                    continue;
                }

                $orders = $this->findOrders((array) $row['order_tokens'], $allowedTenantIds);

                if ($orders->count() !== 1) {
                    continue;
                }

                $order = SalesOrder::query()
                    ->whereIn('tenant_id', $allowedTenantIds)
                    ->whereKey($orders->first()->id)
                    ->lockForUpdate()
                    ->first();

                if (! $order) {
                    continue;
                }

                $oldTrackingNo = $order->tracking_no;
                $newTrackingNo = mb_substr((string) $row['tracking_no'], 0, 255);

                if ($oldTrackingNo === $newTrackingNo) {
                    continue;
                }

                $order->update(['tracking_no' => $newTrackingNo]);

                activity('sales_order')
                    ->performedOn($order)
                    ->causedBy($user)
                    ->event('tracking_imported')
                    ->withProperties([
                        'courier' => $parsed['courier'],
                        'old_tracking_no' => $oldTrackingNo,
                        'new_tracking_no' => $newTrackingNo,
                        'source_file_name' => $sourceFileName,
                        'row_no' => $row['row_no'],
                    ])
                    ->log('tracking_imported');

                $updated++;
            }

            return $updated;
        });

        return [
            'courier' => $parsed['courier'],
            'updated' => $updated,
            'error' => null,
        ];
    }

    /**
     * @param  array<int, string>  $tokens
     * @param  list<int>  $allowedTenantIds
     * @return Collection<int, SalesOrder>
     */
    private function findOrders(array $tokens, array $allowedTenantIds): Collection
    {
        $tokens = collect($tokens)
            ->map(fn (string $token): string => trim($token))
            ->filter()
            ->unique()
            ->values();

        if ($tokens->isEmpty() || $allowedTenantIds === []) {
            return collect();
        }

        $exactMatches = SalesOrder::query()
            ->whereIn('tenant_id', $allowedTenantIds)
            ->whereIn('platform_order_id', $tokens->all())
            ->get(['id', 'tenant_id', 'platform_order_id', 'tracking_no']);

        if ($exactMatches->isNotEmpty()) {
            return $exactMatches;
        }

        $suffixTokens = $tokens
            ->filter(fn (string $token): bool => mb_strlen($token) === 15)
            ->values();

        if ($suffixTokens->isEmpty()) {
            return collect();
        }

        return SalesOrder::query()
            ->whereIn('tenant_id', $allowedTenantIds)
            ->where(function ($query) use ($suffixTokens): void {
                foreach ($suffixTokens as $token) {
                    $query->orWhereRaw('substr(platform_order_id, ?) = ?', [-15, $token]);
                }
            })
            ->get(['id', 'tenant_id', 'platform_order_id', 'tracking_no']);
    }
}
