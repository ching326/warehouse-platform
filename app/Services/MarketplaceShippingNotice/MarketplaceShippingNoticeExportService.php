<?php

namespace App\Services\MarketplaceShippingNotice;

use App\Models\MarketplaceShippingNoticeBatch;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\ShippingMethodMarketplaceMapping;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class MarketplaceShippingNoticeExportService
{
    public const PLATFORM_AMAZON = 'amazon';
    public const PLATFORM_RAKUTEN = 'rakuten';

    public function __construct(
        private AmazonShippingNoticeBuilder $amazonBuilder,
        private RakutenShippingNoticeBuilder $rakutenBuilder,
    ) {
    }

    public function validateExport(array $salesOrderIds, string $platform, array $allowedTenantIds): MarketplaceShippingNoticeValidationResult
    {
        $ids = $this->normalizeIds($salesOrderIds);
        $platform = $this->normalizePlatform($platform);

        if ($ids === []) {
            return new MarketplaceShippingNoticeValidationResult(
                ok: false,
                requiresConfirmation: false,
                noSelection: true,
                validOrderIds: [],
                missingOrderIds: [],
                mixedTenantOrderIds: [],
                mixedPlatformOrderIds: [],
                wrongPlatformOrderIds: [],
                blockedStatusOrderIds: [],
                missingPlatformOrderIds: [],
                missingShippingMethodOrderIds: [],
                missingTrackingOrderIds: [],
                missingMappingOrderIds: [],
                missingCarrierCodeOrderIds: [],
                noReadyLinesOrderIds: [],
                alreadyExportedOrderIds: [],
                message: __('sales_orders.marketplace_notice_export_no_selection'),
            );
        }

        $orders = $this->loadOrders($ids, $allowedTenantIds);
        $foundIds = $orders->pluck('id')->all();
        $missingIds = array_values(array_diff($ids, $foundIds));
        $tenantIds = $orders->pluck('tenant_id')->unique()->values();
        $platforms = $orders->map(fn (SalesOrder $order): string => strtolower((string) $order->shop?->platform))->unique()->values();

        $mixedTenantOrderIds = $tenantIds->count() > 1 ? $foundIds : [];
        $mixedPlatformOrderIds = $platforms->count() > 1 ? $foundIds : [];
        $wrongPlatformOrderIds = $orders
            ->filter(fn (SalesOrder $order): bool => strtolower((string) $order->shop?->platform) !== $platform)
            ->pluck('id')
            ->values()
            ->all();

        $blockedStatusOrderIds = $orders
            ->filter(fn (SalesOrder $order): bool => $this->hasBlockedStatus($order))
            ->pluck('id')
            ->values()
            ->all();
        $missingPlatformOrderIds = $orders
            ->filter(fn (SalesOrder $order): bool => trim((string) $order->platform_order_id) === '')
            ->pluck('id')
            ->values()
            ->all();
        $missingShippingMethodOrderIds = $orders
            ->filter(fn (SalesOrder $order): bool => $order->shipping_method_id === null || $order->shippingMethod === null)
            ->pluck('id')
            ->values()
            ->all();
        $missingTrackingOrderIds = $orders
            ->filter(fn (SalesOrder $order): bool => $order->shippingMethod?->is_trackable === true && trim((string) $order->tracking_no) === '')
            ->pluck('id')
            ->values()
            ->all();
        $noReadyLinesOrderIds = $orders
            ->filter(fn (SalesOrder $order): bool => $order->lines->where('line_status', SalesOrderLine::STATUS_READY)->isEmpty())
            ->pluck('id')
            ->values()
            ->all();

        [$missingMappingOrderIds, $missingCarrierCodeOrderIds] = $this->mappingValidationFailures($orders, $platform);
        $alreadyExportedOrderIds = $orders
            ->filter(fn (SalesOrder $order): bool => $order->marketplace_shipping_notice_exported_at !== null)
            ->pluck('id')
            ->values()
            ->all();

        $hardBlocks = $missingIds !== []
            || $mixedTenantOrderIds !== []
            || $mixedPlatformOrderIds !== []
            || $wrongPlatformOrderIds !== []
            || $blockedStatusOrderIds !== []
            || $missingPlatformOrderIds !== []
            || $missingShippingMethodOrderIds !== []
            || $missingTrackingOrderIds !== []
            || $missingMappingOrderIds !== []
            || $missingCarrierCodeOrderIds !== []
            || $noReadyLinesOrderIds !== [];
        $requiresConfirmation = ! $hardBlocks && $alreadyExportedOrderIds !== [];

        return new MarketplaceShippingNoticeValidationResult(
            ok: ! $hardBlocks && ! $requiresConfirmation,
            requiresConfirmation: $requiresConfirmation,
            noSelection: false,
            validOrderIds: array_values(array_diff($foundIds, array_merge(
                $mixedTenantOrderIds,
                $mixedPlatformOrderIds,
                $wrongPlatformOrderIds,
                $blockedStatusOrderIds,
                $missingPlatformOrderIds,
                $missingShippingMethodOrderIds,
                $missingTrackingOrderIds,
                $missingMappingOrderIds,
                $missingCarrierCodeOrderIds,
                $noReadyLinesOrderIds,
            ))),
            missingOrderIds: $missingIds,
            mixedTenantOrderIds: $mixedTenantOrderIds,
            mixedPlatformOrderIds: $mixedPlatformOrderIds,
            wrongPlatformOrderIds: $wrongPlatformOrderIds,
            blockedStatusOrderIds: $blockedStatusOrderIds,
            missingPlatformOrderIds: $missingPlatformOrderIds,
            missingShippingMethodOrderIds: $missingShippingMethodOrderIds,
            missingTrackingOrderIds: $missingTrackingOrderIds,
            missingMappingOrderIds: $missingMappingOrderIds,
            missingCarrierCodeOrderIds: $missingCarrierCodeOrderIds,
            noReadyLinesOrderIds: $noReadyLinesOrderIds,
            alreadyExportedOrderIds: $alreadyExportedOrderIds,
            message: $this->messageFor($platform, $hardBlocks, $requiresConfirmation, $mixedPlatformOrderIds, $wrongPlatformOrderIds),
        );
    }

    public function export(
        array $salesOrderIds,
        string $platform,
        array $allowedTenantIds,
        ?User $user,
        bool $confirmedReExport = false,
    ): MarketplaceShippingNoticeBatch {
        $platform = $this->normalizePlatform($platform);
        $validation = $this->validateExport($salesOrderIds, $platform, $allowedTenantIds);

        if ($validation->hasHardBlock()) {
            throw new RuntimeException($validation->message);
        }

        if ($validation->requiresConfirmation && ! $confirmedReExport) {
            throw new RuntimeException($validation->message);
        }

        $orders = $this->loadOrders($this->normalizeIds($salesOrderIds), $allowedTenantIds);
        $mappings = $this->resolvedMappings($orders, $platform);
        $tenantId = (int) $orders->pluck('tenant_id')->unique()->first();
        $marketplace = (string) $orders->map(fn (SalesOrder $order): string => (string) $order->shop?->marketplace)->unique()->first();
        $japanNow = CarbonImmutable::now('Asia/Tokyo');
        $fileName = $this->fileName($platform, $japanNow);
        $path = 'marketplace_shipping_notices/'.$platform.'/'.$japanNow->format('Y/m').'/'.$fileName;
        $temporaryPath = 'tmp/marketplace_shipping_notices/'.Str::uuid().'.tmp';
        $lineCount = $this->lineCount($platform, $orders);
        $content = $this->buildContent($platform, $orders, $mappings, $japanNow);

        Storage::disk('local')->put($temporaryPath, $content);

        try {
            return DB::transaction(function () use ($orders, $tenantId, $marketplace, $platform, $fileName, $path, $temporaryPath, $user, $confirmedReExport, $lineCount) {
                Storage::disk('local')->move($temporaryPath, $path);

                $exportedAt = now();
                $batch = MarketplaceShippingNoticeBatch::create([
                    'tenant_id' => $tenantId,
                    'platform' => $platform,
                    'marketplace' => $marketplace,
                    'file_name' => $fileName,
                    'disk' => 'local',
                    'path' => $path,
                    'order_count' => $orders->count(),
                    'line_count' => $lineCount,
                    'exported_by_user_id' => $user?->id,
                    'exported_at' => $exportedAt,
                ]);

                foreach ($orders as $order) {
                    $batch->batchOrders()->create([
                        'sales_order_id' => $order->id,
                        'platform_order_id' => $order->platform_order_id,
                        'tracking_no' => $order->tracking_no,
                        'shipping_method_id' => $order->shipping_method_id,
                        'exported_at' => $exportedAt,
                    ]);

                    $order->update(['marketplace_shipping_notice_exported_at' => $exportedAt]);

                    activity('sales_order')
                        ->performedOn($order)
                        ->causedBy($user)
                        ->event('marketplace_shipping_notice_exported')
                        ->withProperties([
                            'platform' => $platform,
                            'batch_id' => $batch->id,
                            'file_name' => $fileName,
                            're_export' => $confirmedReExport,
                        ])
                        ->log('marketplace_shipping_notice_exported');
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
        return SalesOrder::query()
            ->whereIn('id', $ids)
            ->whereIn('tenant_id', $allowedTenantIds)
            ->with(['shop.tenant', 'shippingMethod.carrier', 'shippingMethod.marketplaceMappings', 'lines.sku.stockItem'])
            ->get()
            ->sortBy(fn (SalesOrder $order): int|false => array_search($order->id, $ids, true))
            ->values();
    }

    private function normalizeIds(array $salesOrderIds): array
    {
        return array_values(array_unique(array_filter(
            array_map('intval', $salesOrderIds),
            fn (int $id): bool => $id > 0,
        )));
    }

    private function normalizePlatform(string $platform): string
    {
        $platform = strtolower(trim($platform));

        if (! in_array($platform, [self::PLATFORM_AMAZON, self::PLATFORM_RAKUTEN], true)) {
            throw new InvalidArgumentException('Unsupported marketplace notice platform.');
        }

        return $platform;
    }

    private function hasBlockedStatus(SalesOrder $order): bool
    {
        return in_array($order->order_status, [
            SalesOrder::ORDER_STATUS_ON_HOLD,
            SalesOrder::ORDER_STATUS_BACKORDER,
            SalesOrder::ORDER_STATUS_CANCEL_REQUESTED,
            SalesOrder::ORDER_STATUS_CANCELLED,
        ], true) || $order->fulfillment_status === SalesOrder::FULFILLMENT_STATUS_CANCELLED;
    }

    private function mappingValidationFailures(Collection $orders, string $platform): array
    {
        $missingMapping = [];
        $missingCarrierCode = [];

        foreach ($orders as $order) {
            if ($order->shippingMethod === null) {
                continue;
            }

            $mapping = $this->resolveMapping($order, $platform);

            if (! $mapping) {
                $missingMapping[] = $order->id;

                continue;
            }

            if (trim((string) $mapping->carrier_code) === '') {
                $missingCarrierCode[] = $order->id;
            }
        }

        return [$missingMapping, $missingCarrierCode];
    }

    private function resolvedMappings(Collection $orders, string $platform): array
    {
        $mappings = [];

        foreach ($orders as $order) {
            $mappings[$order->id] = $this->resolveMapping($order, $platform);
        }

        return $mappings;
    }

    private function resolveMapping(SalesOrder $order, string $platform): ?ShippingMethodMarketplaceMapping
    {
        $marketplace = (string) $order->shop?->marketplace;

        return $order->shippingMethod?->marketplaceMappings
            ->where('platform', $platform)
            ->sortBy(fn (ShippingMethodMarketplaceMapping $mapping): int => $mapping->marketplace === $marketplace ? 0 : 1)
            ->first(fn (ShippingMethodMarketplaceMapping $mapping): bool => in_array($mapping->marketplace, [$marketplace, ''], true));
    }

    private function fileName(string $platform, CarbonImmutable $japanNow): string
    {
        $prefix = $platform === self::PLATFORM_AMAZON
            ? 'amazon-shipping-notice'
            : 'rakuten-shipping-notice';
        $extension = $platform === self::PLATFORM_AMAZON ? 'txt' : 'csv';

        return $prefix.'-'.$japanNow->format('Ymd-His').'.'.$extension;
    }

    private function lineCount(string $platform, Collection $orders): int
    {
        return $platform === self::PLATFORM_AMAZON
            ? $this->amazonBuilder->lineCount($orders)
            : $this->rakutenBuilder->lineCount($orders);
    }

    private function buildContent(string $platform, Collection $orders, array $mappings, CarbonImmutable $japanNow): string
    {
        return $platform === self::PLATFORM_AMAZON
            ? $this->amazonBuilder->build($orders, $mappings, $japanNow)
            : $this->rakutenBuilder->build($orders, $mappings, $japanNow);
    }

    private function messageFor(string $platform, bool $hardBlocks, bool $requiresConfirmation, array $mixedPlatformOrderIds, array $wrongPlatformOrderIds): string
    {
        if ($mixedPlatformOrderIds !== []) {
            return __('sales_orders.marketplace_notice_export_mixed_platforms');
        }

        if ($wrongPlatformOrderIds !== []) {
            return __('sales_orders.marketplace_notice_export_wrong_platform', ['platform' => ucfirst($platform)]);
        }

        if ($hardBlocks) {
            return __('sales_orders.marketplace_notice_export_blocked');
        }

        if ($requiresConfirmation) {
            return __('sales_orders.marketplace_notice_export_confirm_reexport');
        }

        return __('sales_orders.marketplace_notice_exported');
    }
}
