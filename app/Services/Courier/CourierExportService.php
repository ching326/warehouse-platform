<?php

namespace App\Services\Courier;

use App\Models\CourierExportBatch;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\User;
use App\Support\CourierCarrier;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;

class CourierExportService
{
    public function __construct(
        private YamatoCsvBuilder $yamatoCsvBuilder,
        private SagawaCsvBuilder $sagawaCsvBuilder,
    ) {
    }

    public function validateExport(array $salesOrderIds, string $carrier, array $allowedTenantIds): CourierExportValidationResult
    {
        $ids = $this->normalizeIds($salesOrderIds);
        $carrier = $this->normalizeCarrier($carrier);

        if ($ids === []) {
            return new CourierExportValidationResult(
                ok: false,
                requiresConfirmation: false,
                validOrderIds: [],
                missingOrderIds: [],
                blockedStatusOrderIds: [],
                wrongCarrierOrderIds: [],
                unsupportedCourierOrderIds: [],
                mixedTenantOrderIds: [],
                alreadyExportedOrderIds: [],
                noReadyLinesOrderIds: [],
                message: __('sales_orders.courier_export_no_selection'),
            );
        }

        $orders = $this->loadOrders($ids, $allowedTenantIds);
        $foundIds = $orders->pluck('id')->all();
        $missingIds = array_values(array_diff($ids, $foundIds));
        $tenantIds = $orders->pluck('tenant_id')->unique()->values();
        $mixedTenantOrderIds = $tenantIds->count() > 1 ? $foundIds : [];

        $blockedStatuses = [
            SalesOrder::ORDER_STATUS_ON_HOLD,
            SalesOrder::ORDER_STATUS_CANCEL_REQUESTED,
            SalesOrder::ORDER_STATUS_CANCELLED,
        ];

        $blockedStatusOrderIds = $orders
            ->whereIn('order_status', $blockedStatuses)
            ->pluck('id')
            ->values()
            ->all();
        $wrongCarrierOrderIds = $orders
            ->filter(fn (SalesOrder $order) => ! $this->carrierMatches($order, $carrier))
            ->pluck('id')
            ->values()
            ->all();
        $unsupportedCourierOrderIds = $orders
            ->filter(fn (SalesOrder $order) => $order->shippingMethod?->supports_courier_csv === false)
            ->pluck('id')
            ->values()
            ->all();
        $noReadyLinesOrderIds = $orders
            ->filter(fn (SalesOrder $order) => $order->lines->where('line_status', SalesOrderLine::STATUS_READY)->isEmpty())
            ->pluck('id')
            ->values()
            ->all();
        $alreadyExportedOrderIds = $orders
            ->filter(fn (SalesOrder $order) => $order->courier_csv_exported_at !== null)
            ->pluck('id')
            ->values()
            ->all();
        $hardBlocks = $missingIds !== []
            || $blockedStatusOrderIds !== []
            || $wrongCarrierOrderIds !== []
            || $unsupportedCourierOrderIds !== []
            || $mixedTenantOrderIds !== []
            || $noReadyLinesOrderIds !== [];
        $requiresConfirmation = ! $hardBlocks && $alreadyExportedOrderIds !== [];

        return new CourierExportValidationResult(
            ok: ! $hardBlocks && ! $requiresConfirmation,
            requiresConfirmation: $requiresConfirmation,
            validOrderIds: array_values(array_diff($foundIds, array_merge(
                $blockedStatusOrderIds,
                $wrongCarrierOrderIds,
                $unsupportedCourierOrderIds,
                $mixedTenantOrderIds,
                $noReadyLinesOrderIds,
            ))),
            missingOrderIds: $missingIds,
            blockedStatusOrderIds: $blockedStatusOrderIds,
            wrongCarrierOrderIds: $wrongCarrierOrderIds,
            unsupportedCourierOrderIds: $unsupportedCourierOrderIds,
            mixedTenantOrderIds: $mixedTenantOrderIds,
            alreadyExportedOrderIds: $alreadyExportedOrderIds,
            noReadyLinesOrderIds: $noReadyLinesOrderIds,
            message: $this->messageFor($hardBlocks, $requiresConfirmation),
        );
    }

    public function export(
        array $salesOrderIds,
        string $carrier,
        array $allowedTenantIds,
        ?User $user,
        bool $confirmedReExport = false,
    ): CourierExportBatch {
        $carrier = $this->normalizeCarrier($carrier);
        $validation = $this->validateExport($salesOrderIds, $carrier, $allowedTenantIds);

        if ($validation->hasHardBlock()) {
            throw new RuntimeException($validation->message);
        }

        if ($validation->requiresConfirmation && ! $confirmedReExport) {
            throw new RuntimeException($validation->message);
        }

        $orders = $this->loadOrders($this->normalizeIds($salesOrderIds), $allowedTenantIds);
        $tenantId = (int) $orders->pluck('tenant_id')->unique()->first();
        $japanNow = CarbonImmutable::now('Asia/Tokyo');
        $fileName = $carrier.'_'.$japanNow->format('Ymd_Hi').'.csv';
        $path = 'courier_exports/'.$carrier.'/'.$japanNow->format('Y/m').'/'.$fileName;
        $csv = $this->buildCsv($carrier, $orders, $japanNow);

        Storage::disk('local')->put($path, $csv);

        return DB::transaction(function () use ($orders, $tenantId, $carrier, $fileName, $path, $user, $confirmedReExport) {
            $exportedAt = now();
            $batch = CourierExportBatch::create([
                'tenant_id' => $tenantId,
                'carrier' => $carrier,
                'file_name' => $fileName,
                'disk' => 'local',
                'path' => $path,
                'order_count' => $orders->count(),
                'exported_by_user_id' => $user?->id,
                'exported_at' => $exportedAt,
            ]);

            foreach ($orders as $order) {
                $batch->batchOrders()->create([
                    'sales_order_id' => $order->id,
                    'platform_order_id' => $order->platform_order_id,
                    'carrier' => $carrier,
                    'exported_at' => $exportedAt,
                ]);

                $order->update(['courier_csv_exported_at' => $exportedAt]);

                activity('sales_order')
                    ->performedOn($order)
                    ->causedBy($user)
                    ->event('courier_exported')
                    ->withProperties([
                        'carrier' => $carrier,
                        'batch_id' => $batch->id,
                        'file_name' => $fileName,
                        're_export' => $confirmedReExport,
                    ])
                    ->log('courier_exported');
            }

            return $batch;
        });
    }

    private function loadOrders(array $ids, array $allowedTenantIds): Collection
    {
        return SalesOrder::query()
            ->whereIn('id', $ids)
            ->whereIn('tenant_id', $allowedTenantIds)
            ->with(['shop.tenant', 'shippingMethod.carrier', 'lines.sku.stockItem'])
            ->get()
            ->sortBy(fn (SalesOrder $order) => array_search($order->id, $ids, true))
            ->values();
    }

    private function normalizeIds(array $salesOrderIds): array
    {
        return array_values(array_unique(array_filter(
            array_map('intval', $salesOrderIds),
            fn (int $id) => $id > 0,
        )));
    }

    private function carrierMatches(SalesOrder $order, string $carrier): bool
    {
        return $order->shippingMethod?->carrier?->code === $carrier
            || $order->shipping_method === $carrier;
    }

    private function normalizeCarrier(string $carrier): string
    {
        if (! in_array($carrier, CourierCarrier::values(), true)) {
            throw new InvalidArgumentException('Unsupported courier carrier.');
        }

        return $carrier;
    }

    private function buildCsv(string $carrier, Collection $orders, CarbonImmutable $japanNow): string
    {
        return match ($carrier) {
            CourierCarrier::YAMATO => $this->yamatoCsvBuilder->build($orders, $japanNow),
            CourierCarrier::SAGAWA => $this->sagawaCsvBuilder->build($orders, $japanNow),
        };
    }

    private function messageFor(bool $hardBlocks, bool $requiresConfirmation): string
    {
        if ($hardBlocks) {
            return __('sales_orders.courier_export_blocked');
        }

        if ($requiresConfirmation) {
            return __('sales_orders.courier_export_requires_confirmation');
        }

        return __('sales_orders.courier_export_ready');
    }
}
