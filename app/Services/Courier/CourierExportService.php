<?php

namespace App\Services\Courier;

use App\Models\CourierExportBatch;
use App\Models\OutboundOrder;
use App\Models\SalesOrder;
use App\Models\User;
use App\Support\CourierCarrier;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class CourierExportService
{
    public function __construct(
        private YamatoCsvBuilder $yamatoCsvBuilder,
        private SagawaCsvBuilder $sagawaCsvBuilder,
    ) {
    }

    public function validateOrderExport(array $outboundOrderIds, string $carrier, array $allowedTenantIds): CourierExportValidationResult
    {
        $ids = $this->normalizeIds($outboundOrderIds);
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
            ->filter(fn (OutboundOrder $order): bool => $order->status !== OutboundOrder::STATUS_PENDING
                || $order->salesOrders->contains(fn (SalesOrder $so): bool => in_array($so->order_status, $blockedStatuses, true)))
            ->pluck('id')
            ->values()
            ->all();
        $wrongCarrierOrderIds = $orders
            ->filter(fn (OutboundOrder $order): bool => ! $this->orderCarrierMatches($order, $carrier))
            ->pluck('id')
            ->values()
            ->all();
        $unsupportedCourierOrderIds = $orders
            ->filter(fn (OutboundOrder $order): bool => $order->shippingMethod?->supports_courier_csv === false)
            ->pluck('id')
            ->values()
            ->all();
        $noReadyLinesOrderIds = $orders
            ->filter(fn (OutboundOrder $order): bool => $order->leafLines->where('qty', '>', 0)->isEmpty())
            ->pluck('id')
            ->values()
            ->all();
        $alreadyExportedOrderIds = $orders
            ->filter(fn (OutboundOrder $order): bool => $order->courier_csv_exported_at !== null)
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

    public function exportOrders(
        array $outboundOrderIds,
        string $carrier,
        array $allowedTenantIds,
        ?User $user,
        bool $confirmedReExport = false,
    ): CourierExportBatch {
        $carrier = $this->normalizeCarrier($carrier);
        $validation = $this->validateOrderExport($outboundOrderIds, $carrier, $allowedTenantIds);

        if ($validation->hasHardBlock()) {
            throw new RuntimeException($validation->message);
        }

        if ($validation->requiresConfirmation && ! $confirmedReExport) {
            throw new RuntimeException($validation->message);
        }

        $orders = $this->loadOrders($this->normalizeIds($outboundOrderIds), $allowedTenantIds);
        $tenantId = (int) $orders->pluck('tenant_id')->unique()->first();
        $japanNow = CarbonImmutable::now('Asia/Tokyo');
        $fileName = $carrier.'_'.$japanNow->format('Ymd_Hi').'.csv';
        $path = 'courier_exports/'.$carrier.'/'.$japanNow->format('Y/m').'/'.$fileName;
        $temporaryPath = 'tmp/courier_exports/'.Str::uuid().'.csv';
        $csv = $this->buildCsv($carrier, $orders->map(fn (OutboundOrder $order) => $this->orderShipmentRow($order)), $japanNow);

        Storage::disk('local')->put($temporaryPath, $csv);

        try {
            return DB::transaction(function () use ($orders, $tenantId, $carrier, $fileName, $path, $temporaryPath, $user, $confirmedReExport) {
                Storage::disk('local')->move($temporaryPath, $path);

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
                    $order->update(['courier_csv_exported_at' => $exportedAt]);
                    $salesOrders = $order->salesOrders;

                    if ($salesOrders->isEmpty()) {
                        $batch->batchOrders()->create([
                            'sales_order_id' => null,
                            'outbound_order_id' => $order->id,
                            'platform_order_id' => $order->ref,
                            'carrier' => $carrier,
                            'exported_at' => $exportedAt,
                        ]);

                        continue;
                    }

                    foreach ($salesOrders as $so) {
                        $batch->batchOrders()->create([
                            'sales_order_id' => $so->id,
                            'outbound_order_id' => $order->id,
                            'platform_order_id' => $so->platform_order_id,
                            'carrier' => $carrier,
                            'exported_at' => $exportedAt,
                        ]);

                        $so->update(['courier_csv_exported_at' => $exportedAt]);

                        $properties = [
                            'carrier' => $carrier,
                            'batch_id' => $batch->id,
                            'file_name' => $fileName,
                            'outbound_order_id' => $order->id,
                            'outbound_order_ref' => $order->ref,
                            're_export' => $confirmedReExport,
                        ];
                        if ($order->fulfillment_group_id !== null) {
                            $properties['fulfillment_group_id'] = $order->fulfillment_group_id;
                            $properties['fulfillment_group_reference_no'] = $order->ref;
                        }

                        activity('sales_order')
                            ->performedOn($so)
                            ->causedBy($user)
                            ->event('courier_exported')
                            ->withProperties($properties)
                            ->log('courier_exported');
                    }
                }

                return $batch;
            });
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete([$temporaryPath, $path]);

            throw $exception;
        }
    }

    private function loadOrders(array $ids, array $allowedTenantIds): Collection
    {
        return OutboundOrder::query()
            ->whereIn('id', $ids)
            ->whereIn('tenant_id', $allowedTenantIds)
            ->with([
                'shippingMethod.carrier',
                'leafLines.sku.stockItem',
                'salesOrders.shop.tenant',
                'salesOrders.lines.sku.stockItem',
            ])
            ->get()
            ->sortBy(fn (OutboundOrder $order) => array_search($order->id, $ids, true))
            ->values();
    }

    private function normalizeIds(array $ids): array
    {
        return array_values(array_unique(array_filter(
            array_map('intval', $ids),
            fn (int $id) => $id > 0,
        )));
    }

    private function orderCarrierMatches(OutboundOrder $order, string $carrier): bool
    {
        return CourierCarrier::normalize($order->shippingMethod?->carrier?->code) === $carrier;
    }

    private function normalizeCarrier(string $carrier): string
    {
        $carrier = CourierCarrier::normalize($carrier);

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

    private function orderShipmentRow(OutboundOrder $order): object
    {
        $linkedOrders = $order->salesOrders;
        $firstOrder = $linkedOrders->first();
        $lines = $linkedOrders->isNotEmpty()
            ? $linkedOrders->flatMap(fn (SalesOrder $so) => $so->lines)->values()
            : $order->leafLines->values();

        return (object) [
            'platform_order_id' => $order->ref,
            'recipient_phone' => $order->recipient_phone,
            'recipient_postal_code' => $order->recipient_postal_code,
            'recipient_state' => $order->recipient_state,
            'recipient_city' => $order->recipient_city,
            'recipient_address_line1' => $order->recipient_address_line1,
            'recipient_address_line2' => $order->recipient_address_line2,
            'recipient_name' => $order->recipient_name,
            'package_count' => $order->package_count,
            'package_weight_g' => $order->package_weight_g,
            'tracking_no' => $order->tracking_no,
            'shop' => $firstOrder?->shop,
            'lines' => $lines,
        ];
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
