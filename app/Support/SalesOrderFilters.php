<?php

namespace App\Support;

use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class SalesOrderFilters
{
    public const EMPTY_SHIPPING = '__empty__';

    public const OTHER_MULTI_ITEM = 'multi_item';

    public const OTHER_PRINTED = 'printed';

    public const OTHER_NOT_PRINTED = 'not_printed';

    public const DATE_ALL = 'all';

    public const DATE_TODAY = 'today';

    public const DATE_LAST_3_DAYS = 'last_3_days';

    public const DATE_LAST_7_DAYS = 'last_7_days';

    public const DATE_LAST_30_DAYS = 'last_30_days';

    public const DATE_LAST_3_MONTHS = 'last_3_months';

    public const DATE_LAST_1_YEAR = 'last_1_year';

    public const DATE_CUSTOM = 'custom';

    public static function normalize(array $input): array
    {
        $dateRange = (string) ($input['date_range'] ?? self::DATE_ALL);
        if (! in_array($dateRange, self::dateRanges(), true)) {
            $dateRange = self::DATE_ALL;
        }

        return [
            'allowed_tenant_ids' => array_values(array_filter(array_map('intval', self::arrayValue($input['allowed_tenant_ids'] ?? [])))),
            'has_order_id_filter' => (bool) ($input['has_order_id_filter'] ?? false),
            'order_ids' => array_values(array_filter(array_map('intval', self::arrayValue($input['order_ids'] ?? [])), fn (int $id) => $id > 0)),
            'shop_filter_allowed' => (bool) ($input['shop_filter_allowed'] ?? true),
            'platforms' => self::stringArray(self::firstFilled($input, ['platforms', 'platform'])),
            'shops' => self::numericStringArray(self::firstFilled($input, ['shops', 'shop_ids', 'shop_id', 'shop'])),
            'fulfillment' => self::onlyKnown(self::stringArray($input['fulfillment'] ?? $input['fulfillment_status'] ?? []), self::fulfillmentStatuses()),
            'order_status' => self::onlyKnown(self::stringArray($input['order_status'] ?? []), self::orderStatuses()),
            'shipping' => self::shippingArray($input['shipping'] ?? $input['shipping_methods'] ?? $input['shipping_method'] ?? []),
            'others' => self::onlyKnown(self::stringArray($input['others'] ?? $input['other_filter'] ?? []), self::otherFilters()),
            'date_range' => $dateRange,
            'date_from' => trim((string) ($input['date_from'] ?? '')),
            'date_to' => trim((string) ($input['date_to'] ?? '')),
            'active_only' => self::boolValue($input['active_only'] ?? true),
            'search' => trim((string) ($input['search'] ?? $input['q'] ?? '')),
        ];
    }

    public static function applyToOrderQuery(Builder $query, array $filters, bool $applyExportIdFilter = false): Builder
    {
        if (! ($filters['shop_filter_allowed'] ?? true)) {
            return $query->whereRaw('1 = 0');
        }

        $query->whereIn('tenant_id', $filters['allowed_tenant_ids'] ?? []);

        if ($applyExportIdFilter && ($filters['has_order_id_filter'] ?? false)) {
            $ids = $filters['order_ids'] ?? [];
            $ids === []
                ? $query->whereRaw('1 = 0')
                : $query->whereIn('id', $ids);
        }

        $query
            ->when(($filters['platforms'] ?? []) !== [], fn ($query) => $query
                ->whereHas('shop', fn ($shopQuery) => $shopQuery->whereIn('platform', $filters['platforms'])))
            ->when(($filters['shops'] ?? []) !== [], fn ($query) => $query->whereIn('shop_id', array_map('intval', $filters['shops'])))
            ->when(($filters['fulfillment'] ?? []) !== [], fn ($query) => $query->whereIn('fulfillment_status', $filters['fulfillment']))
            ->when(($filters['order_status'] ?? []) !== [], fn ($query) => $query->whereIn('order_status', $filters['order_status']));

        if (($filters['shipping'] ?? []) !== []) {
            $query->where(function ($query) use ($filters) {
                $shipping = array_values(array_diff($filters['shipping'], [self::EMPTY_SHIPPING]));
                $methodIds = array_values(array_filter($shipping, fn (string $value): bool => ctype_digit($value)));
                $carrierCodes = array_values(array_diff($shipping, $methodIds));
                $hasShippingFilter = false;

                if ($methodIds !== []) {
                    $query->whereIn('shipping_method_id', array_map('intval', $methodIds));
                    $hasShippingFilter = true;
                }

                if ($carrierCodes !== []) {
                    $hasShippingFilter
                        ? $query->orWhereIn('shipping_method', $carrierCodes)
                        : $query->whereIn('shipping_method', $carrierCodes);
                    $hasShippingFilter = true;
                }

                if (in_array(self::EMPTY_SHIPPING, $filters['shipping'], true)) {
                    ! $hasShippingFilter
                        ? $query->whereNull('shipping_method')
                        : $query->orWhereNull('shipping_method');
                }
            });
        }

        if (($filters['others'] ?? []) !== []) {
            if (in_array(self::OTHER_MULTI_ITEM, $filters['others'], true)) {
                self::applyMultiItem($query);
            }

            if (in_array(self::OTHER_PRINTED, $filters['others'], true)) {
                $query->whereHas('activeOutboundOrders', fn ($outbound) => $outbound->whereNotNull('outbound_orders.courier_csv_exported_at'));
            }

            if (in_array(self::OTHER_NOT_PRINTED, $filters['others'], true)) {
                $query->whereDoesntHave('activeOutboundOrders', fn ($outbound) => $outbound->whereNotNull('outbound_orders.courier_csv_exported_at'));
            }
        }

        if (($filters['fulfillment'] ?? []) === [] && ($filters['order_status'] ?? []) === [] && ($filters['active_only'] ?? true)) {
            self::applyActiveOnly($query);
        }

        self::applyDateRange($query, $filters);

        if (($filters['search'] ?? '') !== '') {
            self::applySearch($query, $filters['search']);
        }

        return $query;
    }

    public static function applySearch(Builder $query, string $search): void
    {
        $like = '%'.$search.'%';

        $query->where(function ($query) use ($like) {
            $query
                ->where('platform_order_id', 'like', $like)
                ->orWhere('recipient_name', 'like', $like)
                ->orWhere('recipient_phone', 'like', $like)
                ->orWhere('recipient_postal_code', 'like', $like)
                ->orWhere('recipient_state', 'like', $like)
                ->orWhere('recipient_city', 'like', $like)
                ->orWhere('recipient_address_line1', 'like', $like)
                ->orWhere('recipient_address_line2', 'like', $like)
                ->orWhere('tracking_no', 'like', $like)
                ->orWhere('note', 'like', $like)
                ->orWhereHas('lines', fn ($lineQuery) => $lineQuery->where('note', 'like', $like))
                ->orWhereHas('lines.sku', fn ($skuQuery) => $skuQuery
                    ->where('sku', 'like', $like)
                    ->orWhere('name', 'like', $like))
                ->orWhereHas('lines.sku.stockItem', fn ($stockItemQuery) => $stockItemQuery
                    ->where('short_name', 'like', $like)
                    ->orWhere('name', 'like', $like));
        });
    }

    public static function dateRangeError(array $filters): ?string
    {
        if (($filters['date_range'] ?? self::DATE_ALL) !== self::DATE_CUSTOM) {
            return null;
        }

        [$from, $toExclusive] = self::customDateWindow($filters);

        if ($from && $toExclusive && $from->diffInDays($toExclusive) > 365) {
            return __('sales_orders.date_range_too_wide');
        }

        return null;
    }

    public static function hasHistoricalStatus(array $filters): bool
    {
        return array_intersect($filters['fulfillment'] ?? [], [
            SalesOrder::FULFILLMENT_STATUS_SHIPPED,
            SalesOrder::FULFILLMENT_STATUS_CANCELLED,
        ]) !== []
            || array_intersect($filters['order_status'] ?? [], [
                SalesOrder::ORDER_STATUS_COMPLETED,
                SalesOrder::ORDER_STATUS_CANCELLED,
            ]) !== [];
    }

    /**
     * True when a filter-based export needs an explicit date range because
     * an all-dates result could include historical orders.
     *
     * @param  array<string,mixed>  $filters
     */
    public static function requiresExplicitDateRange(array $filters): bool
    {
        if (($filters['date_range'] ?? self::DATE_ALL) !== self::DATE_ALL) {
            return false;
        }

        $hasStatusFilter = self::hasExplicitStatusFilter($filters);

        return self::hasHistoricalStatus($filters)
            || (! $hasStatusFilter && ! ($filters['active_only'] ?? true));
    }

    public static function hasExplicitStatusFilter(array $filters): bool
    {
        return ($filters['fulfillment'] ?? []) !== [] || ($filters['order_status'] ?? []) !== [];
    }

    public static function dateRanges(): array
    {
        return [
            self::DATE_ALL,
            self::DATE_TODAY,
            self::DATE_LAST_3_DAYS,
            self::DATE_LAST_7_DAYS,
            self::DATE_LAST_30_DAYS,
            self::DATE_LAST_3_MONTHS,
            self::DATE_LAST_1_YEAR,
            self::DATE_CUSTOM,
        ];
    }

    public static function fulfillmentStatuses(): array
    {
        return [
            SalesOrder::FULFILLMENT_STATUS_UNFULFILLED,
            SalesOrder::FULFILLMENT_STATUS_READY,
            SalesOrder::FULFILLMENT_STATUS_ARRANGED,
            SalesOrder::FULFILLMENT_STATUS_SHIPPED,
            SalesOrder::FULFILLMENT_STATUS_CANCELLED,
        ];
    }

    public static function orderStatuses(): array
    {
        return [
            SalesOrder::ORDER_STATUS_PENDING,
            SalesOrder::ORDER_STATUS_ON_HOLD,
            SalesOrder::ORDER_STATUS_BACKORDER,
            SalesOrder::ORDER_STATUS_CANCEL_REQUESTED,
            SalesOrder::ORDER_STATUS_CANCELLED,
            SalesOrder::ORDER_STATUS_COMPLETED,
        ];
    }

    public static function shippingMethods(): array
    {
        return ['yamato', 'sagawa', 'japan_post', 'other', self::EMPTY_SHIPPING];
    }

    public static function otherFilters(): array
    {
        return [self::OTHER_MULTI_ITEM, self::OTHER_PRINTED, self::OTHER_NOT_PRINTED];
    }

    private static function applyActiveOnly(Builder $query): void
    {
        $query
            ->whereNotIn('order_status', [
                SalesOrder::ORDER_STATUS_COMPLETED,
                SalesOrder::ORDER_STATUS_CANCELLED,
            ])
            ->whereNotIn('fulfillment_status', [
                SalesOrder::FULFILLMENT_STATUS_SHIPPED,
                SalesOrder::FULFILLMENT_STATUS_CANCELLED,
            ]);
    }

    private static function applyMultiItem(Builder $query): void
    {
        $query->where(function (Builder $query): void {
            $query
                ->whereHas('lines', fn (Builder $lineQuery) => $lineQuery
                    ->where('line_status', SalesOrderLine::STATUS_READY)
                    ->where('quantity', '>', 1))
                ->orWhere(function (Builder $query): void {
                    $query
                        ->whereHas('lines', fn (Builder $lineQuery) => $lineQuery
                            ->where('line_status', SalesOrderLine::STATUS_READY), '>=', 2);
                });
        });
    }

    private static function applyDateRange(Builder $query, array $filters): void
    {
        [$from, $toExclusive] = self::dateWindow($filters);

        if ($from) {
            $query->where('order_date', '>=', $from);
        }

        if ($toExclusive) {
            $query->where('order_date', '<', $toExclusive);
        }
    }

    private static function dateWindow(array $filters): array
    {
        return match ($filters['date_range'] ?? self::DATE_ALL) {
            self::DATE_TODAY => [now()->startOfDay(), now()->addDay()->startOfDay()],
            self::DATE_LAST_3_DAYS => [now()->subDays(3)->startOfDay(), null],
            self::DATE_LAST_7_DAYS => [now()->subDays(7)->startOfDay(), null],
            self::DATE_LAST_30_DAYS => [now()->subDays(30)->startOfDay(), null],
            self::DATE_LAST_3_MONTHS => [now()->subMonths(3)->startOfDay(), null],
            self::DATE_LAST_1_YEAR => [now()->subYear()->startOfDay(), null],
            self::DATE_CUSTOM => self::customDateWindow($filters),
            default => [null, null],
        };
    }

    private static function customDateWindow(array $filters): array
    {
        $from = self::parseDate($filters['date_from'] ?? '');
        $to = self::parseDate($filters['date_to'] ?? '');

        return [$from?->startOfDay(), $to?->addDay()->startOfDay()];
    }

    private static function parseDate(string $value): ?CarbonInterface
    {
        if (trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function stringArray(mixed $value): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn ($item) => trim((string) $item),
            self::arrayValue($value),
        ), fn (string $item) => $item !== '')));
    }

    private static function numericStringArray(mixed $value): array
    {
        return array_values(array_filter(self::stringArray($value), fn (string $item) => ctype_digit($item) && (int) $item > 0));
    }

    private static function shippingArray(mixed $value): array
    {
        return array_values(array_filter(
            self::stringArray($value),
            fn (string $item) => in_array($item, self::shippingMethods(), true) || (ctype_digit($item) && (int) $item > 0),
        ));
    }

    private static function arrayValue(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if ($value === null || $value === '') {
            return [];
        }

        return str_contains((string) $value, ',')
            ? explode(',', (string) $value)
            : [$value];
    }

    private static function firstFilled(array $input, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $input)) {
                continue;
            }

            if (self::arrayValue($input[$key]) !== []) {
                return $input[$key];
            }
        }

        return [];
    }

    private static function onlyKnown(array $values, array $known): array
    {
        return array_values(array_intersect($values, $known));
    }

    private static function boolValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return ! in_array($value, ['0', 0, 'false', 'off', false], true);
    }
}
