<?php

namespace App\Services\Labels;

use App\Models\CourierExportBatch;
use App\Models\OutboundOrder;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\Courier\CourierExportValidationResult;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class AddressLabelExportService
{
    public const EXPORT_TYPE_LABEL10 = 'label10_2x5';

    public function __construct(
        private AddressLabelDataBuilder $dataBuilder,
        private AddressLabelPdfService $pdfService,
    ) {}

    /**
     * @param  array<int, int|string>  $outboundOrderIds
     * @param  array<int, int>  $allowedTenantIds
     */
    public function validateOrderExport(array $outboundOrderIds, array $allowedTenantIds): CourierExportValidationResult
    {
        $ids = $this->normalizeIds($outboundOrderIds);

        if ($ids === []) {
            return new CourierExportValidationResult(
                ok: false,
                requiresConfirmation: false,
                validOrderIds: [],
                missingOrderIds: [],
                blockedStatusOrderIds: [],
                heldOrderIds: [],
                wrongCarrierOrderIds: [],
                unsupportedCourierOrderIds: [],
                mixedTenantOrderIds: [],
                alreadyExportedOrderIds: [],
                noReadyLinesOrderIds: [],
                message: __('fulfillment.address_label_no_selection'),
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
            ->filter(fn (OutboundOrder $order): bool => $order->status !== OutboundOrder::STATUS_RESERVED
                || ! in_array($order->reason, OutboundOrder::fulfillableReasons(), true)
                || $order->salesOrders->contains(fn (SalesOrder $so): bool => in_array($so->order_status, $blockedStatuses, true)))
            ->pluck('id')
            ->values()
            ->all();
        $heldOrderIds = $orders
            ->filter(fn (OutboundOrder $order): bool => $order->hold_status === OutboundOrder::HOLD_STATUS_ON_HOLD)
            ->pluck('id')
            ->values()
            ->all();
        $wrongCarrierOrderIds = $orders
            ->filter(fn (OutboundOrder $order): bool => $order->shippingMethod?->carrier?->code !== 'japan_post')
            ->pluck('id')
            ->values()
            ->all();
        $noReadyLinesOrderIds = $orders
            ->filter(fn (OutboundOrder $order): bool => $order->leafLines->where('qty', '>', 0)->isEmpty())
            ->pluck('id')
            ->values()
            ->all();
        $alreadyExportedOrderIds = $orders
            ->filter(fn (OutboundOrder $order): bool => $order->courier_label_exported_at !== null)
            ->pluck('id')
            ->values()
            ->all();
        $hardBlocks = $missingIds !== []
            || $blockedStatusOrderIds !== []
            || $heldOrderIds !== []
            || $wrongCarrierOrderIds !== []
            || $mixedTenantOrderIds !== []
            || $noReadyLinesOrderIds !== [];
        $requiresConfirmation = ! $hardBlocks && $alreadyExportedOrderIds !== [];

        return new CourierExportValidationResult(
            ok: ! $hardBlocks && ! $requiresConfirmation,
            requiresConfirmation: $requiresConfirmation,
            validOrderIds: array_values(array_diff($foundIds, array_merge(
                $blockedStatusOrderIds,
                $heldOrderIds,
                $mixedTenantOrderIds,
                $noReadyLinesOrderIds,
            ))),
            missingOrderIds: $missingIds,
            blockedStatusOrderIds: $blockedStatusOrderIds,
            heldOrderIds: $heldOrderIds,
            wrongCarrierOrderIds: $wrongCarrierOrderIds,
            unsupportedCourierOrderIds: [],
            mixedTenantOrderIds: $mixedTenantOrderIds,
            alreadyExportedOrderIds: $alreadyExportedOrderIds,
            noReadyLinesOrderIds: $noReadyLinesOrderIds,
            message: $this->messageFor($hardBlocks, $requiresConfirmation),
        );
    }

    /**
     * @param  array<int, int|string>  $outboundOrderIds
     * @param  array<int, int>  $allowedTenantIds
     * @param  array<int, int>  $skipCells
     */
    public function exportOrders(
        array $outboundOrderIds,
        array $allowedTenantIds,
        ?User $user,
        bool $confirmedReExport = false,
        array $skipCells = [],
    ): CourierExportBatch {
        $validation = $this->validateOrderExport($outboundOrderIds, $allowedTenantIds);

        if ($validation->hasHardBlock()) {
            throw new RuntimeException($validation->message);
        }

        if ($validation->requiresConfirmation && ! $confirmedReExport) {
            throw new RuntimeException($validation->message);
        }

        $orders = $this->loadOrders($this->normalizeIds($outboundOrderIds), $allowedTenantIds);
        $tenantId = (int) $orders->pluck('tenant_id')->unique()->first();
        $japanNow = CarbonImmutable::now('Asia/Tokyo');
        $fileName = 'label10_'.$japanNow->format('Ymd_Hi').'.pdf';
        $path = 'address_labels/label10/'.$japanNow->format('Y/m').'/'.$fileName;
        $temporaryPath = 'tmp/address_labels/'.Str::uuid().'.pdf';
        $pdf = $this->pdfService->render($this->dataBuilder->build($orders), $skipCells);

        Storage::disk('local')->put($temporaryPath, $pdf);

        try {
            return DB::transaction(function () use ($orders, $tenantId, $fileName, $path, $temporaryPath, $user, $confirmedReExport, $skipCells) {
                Storage::disk('local')->move($temporaryPath, $path);

                $exportedAt = now();
                $batch = CourierExportBatch::create([
                    'tenant_id' => $tenantId,
                    'carrier' => self::EXPORT_TYPE_LABEL10,
                    'file_name' => $fileName,
                    'disk' => 'local',
                    'path' => $path,
                    'order_count' => $orders->count(),
                    'exported_by_user_id' => $user?->id,
                    'exported_at' => $exportedAt,
                ]);

                foreach ($orders as $order) {
                    $order->update(['courier_label_exported_at' => $exportedAt]);

                    if ($order->salesOrders->isEmpty()) {
                        $batch->batchOrders()->create([
                            'sales_order_id' => null,
                            'outbound_order_id' => $order->id,
                            'platform_order_id' => $order->ref,
                            'carrier' => self::EXPORT_TYPE_LABEL10,
                            'exported_at' => $exportedAt,
                        ]);

                        continue;
                    }

                    foreach ($order->salesOrders as $salesOrder) {
                        $batch->batchOrders()->create([
                            'sales_order_id' => $salesOrder->id,
                            'outbound_order_id' => $order->id,
                            'platform_order_id' => $salesOrder->platform_order_id,
                            'carrier' => self::EXPORT_TYPE_LABEL10,
                            'exported_at' => $exportedAt,
                        ]);

                        activity('sales_order')
                            ->performedOn($salesOrder)
                            ->causedBy($user)
                            ->event('courier_label_exported')
                            ->withProperties([
                                'type' => self::EXPORT_TYPE_LABEL10,
                                'file_name' => $fileName,
                                'outbound_order_id' => $order->id,
                                'outbound_order_ref' => $order->ref,
                                're_export' => $confirmedReExport,
                                'skip_cells' => $this->normalizeSkipCells($skipCells),
                            ])
                            ->log('courier_label_exported');
                    }
                }

                return $batch;
            });
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete([$temporaryPath, $path]);

            throw $exception;
        }
    }

    /**
     * @param  array<int, int|string>  $ids
     * @return array<int, int>
     */
    private function normalizeIds(array $ids): array
    {
        return array_values(array_unique(array_filter(
            array_map('intval', $ids),
            fn (int $id): bool => $id > 0,
        )));
    }

    /**
     * @param  array<int, int>  $ids
     * @param  array<int, int>  $allowedTenantIds
     * @return Collection<int, OutboundOrder>
     */
    private function loadOrders(array $ids, array $allowedTenantIds): Collection
    {
        return OutboundOrder::query()
            ->whereIn('id', $ids)
            ->whereIn('tenant_id', $allowedTenantIds)
            ->with([
                'tenant',
                'shippingMethod.carrier',
                'leafLines.sku.stockItem',
                'leafLines.stockItem',
                'salesOrders.shop',
                'salesOrders.lines.sku.stockItem',
            ])
            ->get()
            ->sortBy(function (OutboundOrder $order) use ($ids): int {
                $position = array_search($order->id, $ids, true);

                return $position === false ? PHP_INT_MAX : $position;
            })
            ->values();
    }

    /**
     * @param  array<int, int>  $skipCells
     * @return array<int, int>
     */
    private function normalizeSkipCells(array $skipCells): array
    {
        return collect($skipCells)
            ->map(fn ($cell): int => (int) $cell)
            ->filter(fn (int $cell): bool => $cell >= 1 && $cell <= 30)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    private function messageFor(bool $hardBlocks, bool $requiresConfirmation): string
    {
        if ($hardBlocks) {
            return __('fulfillment.address_label_blocked');
        }

        if ($requiresConfirmation) {
            return __('fulfillment.address_label_requires_confirmation');
        }

        return __('fulfillment.address_label_ready');
    }
}
