<?php

namespace App\Services\Amazon;

use App\Models\SalesOrder;
use App\Models\ShippingMethod;
use App\Models\Shop;
use App\Models\Sku;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class AmazonOrderMapper
{
    /**
     * @param array<int,array<string,mixed>> $orders
     * @param array<string,array<int,array<string,mixed>>> $itemsByOrder
     * @return array<int,array<string,mixed>>
     */
    public function map(Shop $shop, array $orders, array $itemsByOrder): array
    {
        $skuMap = $this->skuMapForShop($shop);
        $existing = $this->existingOrders($shop);
        $rows = [];
        $rowNo = 1;

        foreach ($orders as $order) {
            $amazonOrderId = trim((string) ($order['AmazonOrderId'] ?? ''));
            if ($amazonOrderId === '') {
                continue;
            }

            $status = (string) ($order['OrderStatus'] ?? '');
            $cancelRequested = $this->cancelRequested($order);
            $existingOrder = $existing->get($amazonOrderId);

            if ($status === 'Pending') {
                $rows[] = $this->singlePreviewRow($shop, $order, $rowNo++, 'not_actionable', __('amazon_spapi_import.pending_not_actionable'));
                continue;
            }

            if ($status === 'Shipped') {
                $rows[] = $this->singlePreviewRow($shop, $order, $rowNo++, 'not_actionable', __('amazon_spapi_import.shipped_not_actionable'));
                continue;
            }

            if ($existingOrder && $cancelRequested) {
                $rows[] = $this->singlePreviewRow($shop, $order, $rowNo++, 'existing_cancel_requested', __('amazon_spapi_import.status_existing_cancel_requested'));
                continue;
            }

            if ($existingOrder) {
                $rows[] = $this->singlePreviewRow($shop, $order, $rowNo++, 'duplicate', __('amazon_spapi_import.status_duplicate'));
                continue;
            }

            $mappedOrderStatus = $this->mappedOrderStatus($status, $cancelRequested);
            if ($mappedOrderStatus === null) {
                $rows[] = $this->singlePreviewRow($shop, $order, $rowNo++, 'api_warning', __('amazon_spapi_import.unknown_status', ['status' => $status]));
                continue;
            }

            $address = $order['ShippingAddress'] ?? [];
            if ($mappedOrderStatus === SalesOrder::ORDER_STATUS_PENDING
                && (! is_array($address) || trim((string) ($address['AddressLine1'] ?? '')) === '')) {
                throw new AmazonSpapiApiException(__('amazon_spapi_import.pii_missing'));
            }
            $address = is_array($address) ? $address : [];

            $items = $itemsByOrder[$amazonOrderId] ?? [];
            if ($items === []) {
                $rows[] = $this->singlePreviewRow($shop, $order, $rowNo++, 'api_warning', __('amazon_spapi_import.no_items'));
                continue;
            }

            foreach ($items as $item) {
                $skuCode = trim((string) ($item['SellerSKU'] ?? ''));
                $quantity = max(0, (int) ($item['QuantityOrdered'] ?? 0));
                $skuId = $skuMap->get($skuCode);
                $shippingMethod = $this->shippingMethod((string) ($order['ShipmentServiceLevelCategory'] ?? $order['ShipServiceLevel'] ?? ''));
                $errors = [];
                $skuNotFound = false;

                if ($skuCode === '') {
                    $skuNotFound = true;
                    $errors[] = __('sales_orders.import_missing_sku');
                } elseif (! $skuId) {
                    $skuNotFound = true;
                    $errors[] = __('sales_orders.import_unknown_sku', ['sku' => $skuCode]);
                }

                if ($quantity < 1) {
                    $errors[] = __('sales_orders.import_bad_quantity');
                }

                $rows[] = [
                    'row' => $rowNo++,
                    'preview_status' => $skuNotFound ? 'missing_sku' : 'ready',
                    'is_duplicate' => false,
                    'sku_not_found' => $skuNotFound,
                    'tenant_id' => $shop->tenant_id,
                    'shop_id' => $shop->id,
                    'source' => SalesOrder::SOURCE_API,
                    'platform_order_id' => $amazonOrderId,
                    'platform_ordered_at' => $this->parseDate($order['PurchaseDate'] ?? null),
                    'latest_ship_at' => $this->parseDate($order['LatestShipDate'] ?? null),
                    'order_status' => $mappedOrderStatus,
                    'shipping_method' => $shippingMethod?->carrier?->code,
                    'shipping_method_id' => $shippingMethod?->id,
                    'recipient_name' => trim((string) ($address['Name'] ?? $order['BuyerInfo']['BuyerName'] ?? '')),
                    'recipient_phone' => trim((string) ($address['Phone'] ?? $order['BuyerInfo']['BuyerPhoneNumber'] ?? '')),
                    'recipient_country_code' => strtoupper(trim((string) ($address['CountryCode'] ?? ''))),
                    'recipient_postal_code' => trim((string) ($address['PostalCode'] ?? '')),
                    'recipient_state' => trim((string) ($address['StateOrRegion'] ?? '')),
                    'recipient_city' => trim((string) ($address['City'] ?? '')),
                    'recipient_address_line1' => trim((string) ($address['AddressLine1'] ?? '')),
                    'recipient_address_line2' => trim(implode(' ', array_filter([
                        trim((string) ($address['AddressLine2'] ?? '')),
                        trim((string) ($address['AddressLine3'] ?? '')),
                    ], fn ($value) => $value !== ''))),
                    'sku' => $skuCode,
                    'sku_id' => $skuId,
                    'quantity' => $quantity,
                    'platform_line_id' => trim((string) ($item['OrderItemId'] ?? '')),
                    'platform_product_name' => trim((string) ($item['Title'] ?? '')),
                    'unit_price' => $this->unitPrice($item['ItemPrice']['Amount'] ?? null, $quantity),
                    'currency' => isset($item['ItemPrice']['CurrencyCode']) ? strtoupper((string) $item['ItemPrice']['CurrencyCode']) : null,
                    'line_note' => '',
                    'order_note' => $cancelRequested ? __('amazon_spapi_import.cancel_requested_note') : '',
                    'errors' => $errors,
                ];
            }
        }

        return $rows;
    }

    private function mappedOrderStatus(string $amazonStatus, bool $cancelRequested): ?string
    {
        if ($cancelRequested) {
            return SalesOrder::ORDER_STATUS_CANCEL_REQUESTED;
        }

        return match ($amazonStatus) {
            'Canceled', 'Cancelled' => SalesOrder::ORDER_STATUS_CANCELLED,
            'Unshipped', 'PartiallyShipped' => SalesOrder::ORDER_STATUS_PENDING,
            default => null,
        };
    }

    private function cancelRequested(array $order): bool
    {
        $value = $order['BuyerRequestedCancel']['IsBuyerRequestedCancel']
            ?? $order['IsBuyerRequestedCancellation']
            ?? false;

        return in_array(strtolower((string) $value), ['true', '1', 'yes'], true);
    }

    private function singlePreviewRow(Shop $shop, array $order, int $rowNo, string $status, string $note): array
    {
        return [
            'row' => $rowNo,
            'preview_status' => $status,
            'is_duplicate' => $status === 'duplicate',
            'sku_not_found' => false,
            'tenant_id' => $shop->tenant_id,
            'shop_id' => $shop->id,
            'source' => SalesOrder::SOURCE_API,
            'platform_order_id' => trim((string) ($order['AmazonOrderId'] ?? '')),
            'platform_ordered_at' => $this->parseDate($order['PurchaseDate'] ?? null),
            'latest_ship_at' => $this->parseDate($order['LatestShipDate'] ?? null),
            'order_status' => $status === 'existing_cancel_requested' ? SalesOrder::ORDER_STATUS_CANCEL_REQUESTED : SalesOrder::ORDER_STATUS_PENDING,
            'shipping_method' => null,
            'shipping_method_id' => null,
            'recipient_name' => trim((string) ($order['ShippingAddress']['Name'] ?? '')),
            'recipient_phone' => trim((string) ($order['ShippingAddress']['Phone'] ?? '')),
            'recipient_country_code' => strtoupper(trim((string) ($order['ShippingAddress']['CountryCode'] ?? ''))),
            'recipient_postal_code' => trim((string) ($order['ShippingAddress']['PostalCode'] ?? '')),
            'recipient_state' => trim((string) ($order['ShippingAddress']['StateOrRegion'] ?? '')),
            'recipient_city' => trim((string) ($order['ShippingAddress']['City'] ?? '')),
            'recipient_address_line1' => trim((string) ($order['ShippingAddress']['AddressLine1'] ?? '')),
            'recipient_address_line2' => trim((string) ($order['ShippingAddress']['AddressLine2'] ?? '')),
            'sku' => '',
            'sku_id' => null,
            'quantity' => 0,
            'platform_line_id' => '',
            'platform_product_name' => '',
            'unit_price' => null,
            'currency' => null,
            'line_note' => '',
            'order_note' => $note,
            'errors' => [],
        ];
    }

    private function skuMapForShop(Shop $shop): Collection
    {
        return Sku::query()
            ->where('tenant_id', $shop->tenant_id)
            ->where('shop_id', $shop->id)
            ->where('status', 'active')
            ->where(fn ($query) => $query
                ->where('sku_type', 'virtual_bundle')
                ->orWhereNotNull('stock_item_id'))
            ->pluck('id', 'sku');
    }

    private function existingOrders(Shop $shop): Collection
    {
        return SalesOrder::query()
            ->where('tenant_id', $shop->tenant_id)
            ->where('shop_id', $shop->id)
            ->whereNotNull('platform_order_id')
            ->get()
            ->keyBy('platform_order_id');
    }

    private function parseDate(mixed $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        return Carbon::parse($value)->utc()->toDateTimeString();
    }

    private function unitPrice(mixed $amount, int $quantity): ?string
    {
        if ($amount === null || $quantity < 1 || ! is_numeric((string) $amount)) {
            return null;
        }

        return number_format(((float) $amount) / $quantity, 2, '.', '');
    }

    private function shippingMethod(string $serviceLevel): ?ShippingMethod
    {
        $normalized = strtolower(str_replace(['-', '_'], ' ', trim($serviceLevel)));
        $methodCode = match (true) {
            str_contains($normalized, 'nekopos'),
            str_contains($normalized, 'yamato neko') => 'yamato_nekopos',
            str_contains($normalized, 'tqb'),
            str_contains($normalized, 'takkyubin'),
            str_contains($normalized, 'yamato') => 'yamato_tqb',
            str_contains($normalized, 'sagawa'),
            str_contains($normalized, 'thb') => 'sagawa_thb',
            default => null,
        };

        if ($methodCode === null) {
            return null;
        }

        return ShippingMethod::query()
            ->where('code', $methodCode)
            ->with('carrier:id,code')
            ->first();
    }
}
