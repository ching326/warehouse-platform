<?php

namespace App\Services\SalesOrders;

use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\Shop;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SalesOrderImporter
{
    public function __construct(
        private readonly SkuDefaultShippingMethodResolver $shippingMethodResolver,
    ) {}

    /**
     * @param  array<int,array<string,mixed>>  $rows
     */
    public function import(Shop $shop, array $rows, ?int $userId = null): SalesOrderImportResult
    {
        $groups = $this->importableGroups($rows);
        $previewDuplicateCount = $this->previewDuplicateCount($rows);

        if ($groups === []) {
            return new SalesOrderImportResult(0, 0, $previewDuplicateCount);
        }

        $duplicates = SalesOrder::query()
            ->where('tenant_id', $shop->tenant_id)
            ->where('shop_id', $shop->id)
            ->whereIn('platform_order_id', array_keys($groups))
            ->pluck('platform_order_id')
            ->all();

        $duplicateLookup = array_flip($duplicates);
        $groups = array_filter(
            $groups,
            fn (string $platformOrderId) => ! isset($duplicateLookup[$platformOrderId]),
            ARRAY_FILTER_USE_KEY
        );

        if ($groups === []) {
            return new SalesOrderImportResult(0, 0, $previewDuplicateCount + count($duplicates));
        }

        $importedOrders = 0;
        $importedLines = 0;
        $userId ??= Auth::id();

        DB::transaction(function () use ($shop, $groups, $userId, &$importedOrders, &$importedLines): void {
            foreach ($groups as $platformOrderId => $rows) {
                $first = $rows[0];
                $shippingMethod = $this->resolvedShippingMethod($shop->tenant_id, $rows, $first);

                $order = SalesOrder::create([
                    'tenant_id' => $shop->tenant_id,
                    'shop_id' => $shop->id,
                    'source' => $first['source'] ?? SalesOrder::SOURCE_CSV,
                    'platform_order_id' => $platformOrderId,
                    'platform_ordered_at' => $this->nullableDate($first['platform_ordered_at'] ?? null),
                    'latest_ship_at' => $this->nullableDate($first['latest_ship_at'] ?? null),
                    'order_status' => $first['order_status'] ?? SalesOrder::ORDER_STATUS_PENDING,
                    'fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_UNFULFILLED,
                    'shipping_method' => $shippingMethod['shipping_method'],
                    'shipping_method_id' => $shippingMethod['shipping_method_id'],
                    'recipient_name' => $this->nullableString($first['recipient_name'] ?? ''),
                    'recipient_phone' => $this->nullableString($first['recipient_phone'] ?? ''),
                    'recipient_country_code' => $this->nullableString($first['recipient_country_code'] ?? ''),
                    'recipient_postal_code' => $this->nullableString($first['recipient_postal_code'] ?? ''),
                    'recipient_state' => $this->nullableString($first['recipient_state'] ?? ''),
                    'recipient_city' => $this->nullableString($first['recipient_city'] ?? ''),
                    'recipient_address_line1' => $this->nullableString($first['recipient_address_line1'] ?? ''),
                    'recipient_address_line2' => $this->nullableString($first['recipient_address_line2'] ?? ''),
                    'note' => $this->nullableString($first['order_note'] ?? ''),
                    'created_by_user_id' => $userId,
                ]);

                foreach ($rows as $line) {
                    $order->lines()->create([
                        'platform_line_id' => $this->nullableString($line['platform_line_id'] ?? ''),
                        'platform_product_name' => $this->nullableString($line['platform_product_name'] ?? ''),
                        'sku_id' => $line['sku_id'],
                        'quantity' => $line['quantity'],
                        'unit_price' => $line['unit_price'] ?? null,
                        'currency' => $line['currency'] ?? null,
                        'line_status' => SalesOrderLine::STATUS_READY,
                        'note' => $this->nullableString($line['line_note'] ?? ''),
                    ]);

                    $importedLines++;
                }

                $importedOrders++;
            }
        });

        return new SalesOrderImportResult($importedOrders, $importedLines, $previewDuplicateCount + count($duplicates));
    }

    /**
     * @param  array<int,array<string,mixed>>  $rows
     */
    public function assertImportable(array $rows): void
    {
        foreach ($rows as $row) {
            if (($row['is_duplicate'] ?? false) || ($row['preview_status'] ?? '') === 'duplicate') {
                continue;
            }

            if (($row['errors'] ?? []) !== []) {
                throw ValidationException::withMessages([
                    'import' => __('sales_orders.import_has_errors'),
                ]);
            }
        }
    }

    public function isDuplicateOrderConstraintViolation(QueryException $exception): bool
    {
        $message = $exception->getMessage();

        return in_array($exception->getCode(), ['23000', '23505'], true)
            || str_contains($message, 'sales_orders_tenant_shop_platform_order_unique')
            || str_contains($message, 'UNIQUE constraint failed: sales_orders.tenant_id, sales_orders.shop_id, sales_orders.platform_order_id');
    }

    /**
     * @param  array<int,array<string,mixed>>  $rows
     * @return array<string,array<int,array<string,mixed>>>
     */
    private function importableGroups(array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $platformOrderId = (string) ($row['platform_order_id'] ?? '');
            if ($platformOrderId !== '') {
                $groups[$platformOrderId][] = $row;
            }
        }

        return array_filter(
            $groups,
            fn (array $rows): bool => ! collect($rows)->contains(fn ($row) => ($row['is_duplicate'] ?? false)
                || ($row['preview_status'] ?? '') === 'duplicate'
                || ($row['preview_status'] ?? '') === 'existing_cancel_requested'
                || ($row['preview_status'] ?? '') === 'not_actionable'
                || ($row['preview_status'] ?? '') === 'api_warning')
        );
    }

    /**
     * @param  array<int,array<string,mixed>>  $rows
     */
    private function previewDuplicateCount(array $rows): int
    {
        return collect($rows)
            ->filter(fn ($row) => (string) ($row['platform_order_id'] ?? '') !== '')
            ->groupBy('platform_order_id')
            ->filter(fn ($rows) => $rows->contains(fn ($row) => ($row['is_duplicate'] ?? false)
                || ($row['preview_status'] ?? '') === 'duplicate'))
            ->count();
    }

    /**
     * @param  array<int,array<string,mixed>>  $rows
     * @param  array<string,mixed>  $first
     * @return array{shipping_method_id: ?int, shipping_method: ?string}
     */
    private function resolvedShippingMethod(int $tenantId, array $rows, array $first): array
    {
        $skuIds = collect($rows)
            ->pluck('sku_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $resolved = $this->shippingMethodResolver->resolve($tenantId, $skuIds);

        return match ($resolved['status']) {
            'winner', 'tie' => [
                'shipping_method_id' => $resolved['shipping_method_id'],
                'shipping_method' => $resolved['shipping_method'],
            ],
            default => [
                'shipping_method_id' => $first['shipping_method_id'] ?? null,
                'shipping_method' => $first['shipping_method'] ?? null,
            ],
        };
    }

    private function nullableDate(mixed $value): ?Carbon
    {
        $value = trim((string) $value);

        return $value === '' ? null : Carbon::parse($value);
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
