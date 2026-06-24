<?php

namespace App\Services\Courier;

use App\Models\CourierExportBatch;
use App\Models\FulfillmentGroup;
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

    public function validateGroupExport(array $fulfillmentGroupIds, string $carrier, array $allowedTenantIds): CourierExportValidationResult
    {
        $ids = $this->normalizeIds($fulfillmentGroupIds);
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

        $groups = $this->loadGroups($ids, $allowedTenantIds);
        $foundIds = $groups->pluck('id')->all();
        $missingIds = array_values(array_diff($ids, $foundIds));
        $tenantIds = $groups->pluck('tenant_id')->unique()->values();
        $mixedTenantGroupIds = $tenantIds->count() > 1 ? $foundIds : [];

        $blockedStatuses = [
            SalesOrder::ORDER_STATUS_ON_HOLD,
            SalesOrder::ORDER_STATUS_CANCEL_REQUESTED,
            SalesOrder::ORDER_STATUS_CANCELLED,
        ];

        $blockedStatusGroupIds = $groups
            ->filter(fn (FulfillmentGroup $group): bool => $group->status !== FulfillmentGroup::STATUS_RESERVED
                || ($group->outboundOrder?->salesOrders->contains(fn (SalesOrder $order): bool => in_array($order->order_status, $blockedStatuses, true)) ?? false))
            ->pluck('id')
            ->values()
            ->all();
        $wrongCarrierGroupIds = $groups
            ->filter(fn (FulfillmentGroup $group): bool => ! $this->groupCarrierMatches($group, $carrier))
            ->pluck('id')
            ->values()
            ->all();
        $unsupportedCourierGroupIds = $groups
            ->filter(fn (FulfillmentGroup $group): bool => $group->outboundOrder?->shippingMethod?->supports_courier_csv === false)
            ->pluck('id')
            ->values()
            ->all();
        $noReadyLinesGroupIds = $groups
            ->filter(fn (FulfillmentGroup $group): bool => $group->outboundOrder === null
                || $group->outboundOrder->leafLines->where('qty', '>', 0)->isEmpty())
            ->pluck('id')
            ->values()
            ->all();
        $alreadyExportedGroupIds = $groups
            ->filter(fn (FulfillmentGroup $group): bool => $group->outboundOrder?->courier_csv_exported_at !== null)
            ->pluck('id')
            ->values()
            ->all();
        $hardBlocks = $missingIds !== []
            || $blockedStatusGroupIds !== []
            || $wrongCarrierGroupIds !== []
            || $unsupportedCourierGroupIds !== []
            || $mixedTenantGroupIds !== []
            || $noReadyLinesGroupIds !== [];
        $requiresConfirmation = ! $hardBlocks && $alreadyExportedGroupIds !== [];

        return new CourierExportValidationResult(
            ok: ! $hardBlocks && ! $requiresConfirmation,
            requiresConfirmation: $requiresConfirmation,
            validOrderIds: array_values(array_diff($foundIds, array_merge(
                $blockedStatusGroupIds,
                $wrongCarrierGroupIds,
                $unsupportedCourierGroupIds,
                $mixedTenantGroupIds,
                $noReadyLinesGroupIds,
            ))),
            missingOrderIds: $missingIds,
            blockedStatusOrderIds: $blockedStatusGroupIds,
            wrongCarrierOrderIds: $wrongCarrierGroupIds,
            unsupportedCourierOrderIds: $unsupportedCourierGroupIds,
            mixedTenantOrderIds: $mixedTenantGroupIds,
            alreadyExportedOrderIds: $alreadyExportedGroupIds,
            noReadyLinesOrderIds: $noReadyLinesGroupIds,
            message: $this->messageFor($hardBlocks, $requiresConfirmation),
        );
    }

    public function exportGroups(
        array $fulfillmentGroupIds,
        string $carrier,
        array $allowedTenantIds,
        ?User $user,
        bool $confirmedReExport = false,
    ): CourierExportBatch {
        $carrier = $this->normalizeCarrier($carrier);
        $validation = $this->validateGroupExport($fulfillmentGroupIds, $carrier, $allowedTenantIds);

        if ($validation->hasHardBlock()) {
            throw new RuntimeException($validation->message);
        }

        if ($validation->requiresConfirmation && ! $confirmedReExport) {
            throw new RuntimeException($validation->message);
        }

        $groups = $this->loadGroups($this->normalizeIds($fulfillmentGroupIds), $allowedTenantIds);
        $tenantId = (int) $groups->pluck('tenant_id')->unique()->first();
        $japanNow = CarbonImmutable::now('Asia/Tokyo');
        $fileName = $carrier.'_'.$japanNow->format('Ymd_Hi').'.csv';
        $path = 'courier_exports/'.$carrier.'/'.$japanNow->format('Y/m').'/'.$fileName;
        $temporaryPath = 'tmp/courier_exports/'.Str::uuid().'.csv';
        $csv = $this->buildCsv($carrier, $groups->map(fn (FulfillmentGroup $group) => $this->groupShipmentRow($group)), $japanNow);

        Storage::disk('local')->put($temporaryPath, $csv);

        try {
            return DB::transaction(function () use ($groups, $tenantId, $carrier, $fileName, $path, $temporaryPath, $user, $confirmedReExport) {
                Storage::disk('local')->move($temporaryPath, $path);

                $exportedAt = now();
                $batch = CourierExportBatch::create([
                    'tenant_id' => $tenantId,
                    'carrier' => $carrier,
                    'file_name' => $fileName,
                    'disk' => 'local',
                    'path' => $path,
                    'order_count' => $groups->count(),
                    'exported_by_user_id' => $user?->id,
                    'exported_at' => $exportedAt,
                ]);

                foreach ($groups as $group) {
                    $outbound = $group->outboundOrder;

                    if (! $outbound) {
                        continue;
                    }

                    $outbound->update(['courier_csv_exported_at' => $exportedAt]);
                    $orders = $outbound->salesOrders;

                    if ($orders->isEmpty()) {
                        $batch->batchOrders()->create([
                            'sales_order_id' => null,
                            'outbound_order_id' => $outbound->id,
                            'platform_order_id' => $outbound->ref,
                            'carrier' => $carrier,
                            'exported_at' => $exportedAt,
                        ]);

                        continue;
                    }

                    foreach ($orders as $order) {
                        $batch->batchOrders()->create([
                            'sales_order_id' => $order->id,
                            'outbound_order_id' => $outbound->id,
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
                                'fulfillment_group_id' => $group->id,
                                'fulfillment_group_reference_no' => $group->reference_no,
                                're_export' => $confirmedReExport,
                            ])
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

    private function loadGroups(array $ids, array $allowedTenantIds): Collection
    {
        return FulfillmentGroup::query()
            ->whereIn('id', $ids)
            ->whereIn('tenant_id', $allowedTenantIds)
            ->with([
                'outboundOrder.shippingMethod.carrier',
                'outboundOrder.leafLines.sku.stockItem',
                'outboundOrder.salesOrders.shop.tenant',
                'outboundOrder.salesOrders.lines.sku.stockItem',
            ])
            ->get()
            ->sortBy(fn (FulfillmentGroup $group) => array_search($group->id, $ids, true))
            ->values();
    }

    private function normalizeIds(array $salesOrderIds): array
    {
        return array_values(array_unique(array_filter(
            array_map('intval', $salesOrderIds),
            fn (int $id) => $id > 0,
        )));
    }

    private function groupCarrierMatches(FulfillmentGroup $group, string $carrier): bool
    {
        return CourierCarrier::normalize($group->outboundOrder?->shippingMethod?->carrier?->code) === $carrier;
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

    private function groupShipmentRow(FulfillmentGroup $group): object
    {
        $outbound = $group->outboundOrder;
        $linkedOrders = $outbound?->salesOrders ?? collect();
        $firstOrder = $linkedOrders->first();
        $lines = $linkedOrders->isNotEmpty()
            ? $linkedOrders->flatMap(fn (SalesOrder $order) => $order->lines)->values()
            : ($outbound?->leafLines ?? collect())->values();

        return (object) [
            'platform_order_id' => $group->reference_no,
            'recipient_phone' => $outbound?->recipient_phone,
            'recipient_postal_code' => $outbound?->recipient_postal_code,
            'recipient_state' => $outbound?->recipient_state,
            'recipient_city' => $outbound?->recipient_city,
            'recipient_address_line1' => $outbound?->recipient_address_line1,
            'recipient_address_line2' => $outbound?->recipient_address_line2,
            'recipient_name' => $outbound?->recipient_name,
            'package_count' => $outbound?->package_count,
            'package_weight_g' => $outbound?->package_weight_g,
            'tracking_no' => $outbound?->tracking_no,
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
