<?php

namespace App\Services\Labels;

use App\Models\OutboundOrder;
use App\Models\OutboundOrderLine;
use App\Models\Shop;
use App\Models\Sku;
use App\Models\StockItem;
use App\Models\Tenant;
use App\Services\Fulfillment\FulfillmentItemCodeResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class AddressLabelDataBuilder
{
    public function __construct(private FulfillmentItemCodeResolver $itemCodeResolver) {}

    /**
     * @param  Collection<int, OutboundOrder>  $orders
     * @return array<int, array<string, mixed>>
     */
    public function build(Collection $orders): array
    {
        return $orders
            ->map(fn (OutboundOrder $order): array => $this->labelForOrder($order))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function labelForOrder(OutboundOrder $order): array
    {
        $lines = $order->leafLines
            ->filter(fn (OutboundOrderLine $line): bool => (int) $line->qty > 0)
            ->values();
        $firstSalesOrder = $order->salesOrders->first();
        $shop = $firstSalesOrder?->shop;
        $tenant = $order->tenant;

        return [
            'postal_code' => $this->postalCode((string) ($order->recipient_postal_code ?? '')),
            'address_line1' => trim(implode(' ', array_filter([
                $order->recipient_state,
                $order->recipient_city,
                $order->recipient_address_line1,
            ], fn ($part): bool => trim((string) $part) !== ''))),
            'address_line2' => (string) ($order->recipient_address_line2 ?? ''),
            'recipient_name' => (string) ($order->recipient_name ?? ''),
            'recipient_phone' => (string) ($order->recipient_phone ?? ''),
            'show_phone' => filled($order->recipient_phone),
            'items_line' => $this->itemsLine($order, $lines),
            'description_line' => $this->descriptionLine($lines),
            'shipper_name' => $this->shipperName($shop, $tenant),
            'shipper_address' => $this->shipperAddress(),
            'total_weight' => $this->totalWeight($order, $lines),
        ];
    }

    private function postalCode(string $postalCode): string
    {
        $trimmed = trim($postalCode);
        $digits = preg_replace('/\D+/', '', $trimmed) ?? '';

        if (strlen($digits) === 7) {
            return substr($digits, 0, 3).'-'.substr($digits, 3);
        }

        return $trimmed;
    }

    /**
     * @param  Collection<int, OutboundOrderLine>  $lines
     */
    private function itemsLine(OutboundOrder $order, Collection $lines): string
    {
        $codes = $lines
            ->map(fn (OutboundOrderLine $line): array => [
                'qty' => (int) $line->qty,
                'code' => $this->lineCode($order, $line),
            ])
            ->filter(fn (array $line): bool => $line['code'] !== '')
            ->values();

        $markers = [];

        if ($order->salesOrders->count() > 1) {
            $markers[] = __('fulfillment.address_label_marker_consolidated');
        }

        if ($codes->count() > 1) {
            $markers[] = __('fulfillment.address_label_marker_multiple');
        }

        if ($codes->contains(fn (array $line): bool => $line['qty'] > 1)) {
            $markers[] = __('fulfillment.address_label_marker_qty');
        }

        $items = $codes
            ->map(fn (array $line): string => $line['qty'] > 1 ? $line['qty'].' x '.$line['code'] : $line['code'])
            ->implode(', ');
        $prefix = $markers === [] ? '' : implode(' ', $markers).': ';

        return Str::limit($prefix.$items, 72);
    }

    private function lineCode(OutboundOrder $order, OutboundOrderLine $line): string
    {
        $tenant = $order->tenant;
        $sku = $line->sku;

        if (! $tenant instanceof Tenant || ! $sku instanceof Sku) {
            return '';
        }

        return $this->itemCodeResolver->resolve($tenant, $sku, $line->stockItem);
    }

    /**
     * @param  Collection<int, OutboundOrderLine>  $lines
     */
    private function descriptionLine(Collection $lines): string
    {
        $line = $lines->first();
        $stockItem = $line?->stockItem;

        if (! $stockItem instanceof StockItem) {
            return '';
        }

        return Str::limit(trim((string) ($stockItem->short_name ?: $stockItem->displayName())), 36);
    }

    private function shipperName(?Shop $shop, ?Tenant $tenant): string
    {
        return trim((string) ($shop?->name ?: config('courier.sender.name') ?: $tenant?->name ?: $tenant?->code ?: ''));
    }

    private function shipperAddress(): string
    {
        return trim(implode(' ', array_filter([
            config('courier.sender.address1'),
            config('courier.sender.address2'),
        ], fn ($part): bool => trim((string) $part) !== '')));
    }

    /**
     * @param  Collection<int, OutboundOrderLine>  $lines
     */
    private function totalWeight(OutboundOrder $order, Collection $lines): ?int
    {
        if ((int) ($order->package_weight_g ?? 0) > 0) {
            return (int) $order->package_weight_g;
        }

        $total = 0;
        $hasWeight = false;

        foreach ($lines as $line) {
            $weight = $this->stockItemWeightGrams($line->stockItem);

            if ($weight === null) {
                continue;
            }

            $hasWeight = true;
            $total += $weight * (int) $line->qty;
        }

        return $hasWeight ? (int) round($total) : null;
    }

    private function stockItemWeightGrams(?StockItem $stockItem): ?float
    {
        if (! $stockItem instanceof StockItem || $stockItem->weight_value === null) {
            return null;
        }

        $value = (float) $stockItem->weight_value;

        return match ($stockItem->weight_unit) {
            'kg' => $value * 1000,
            default => $value,
        };
    }
}
