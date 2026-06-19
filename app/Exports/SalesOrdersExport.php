<?php

namespace App\Exports;

use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Support\SalesOrderFilters;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class SalesOrdersExport implements FromQuery, WithHeadings, WithMapping
{
    public const IMPORT_HEADINGS = [
        'platform_order_id',
        'sku',
        'quantity',
        'line_note',
        'recipient_name',
        'recipient_phone',
        'recipient_country_code',
        'recipient_postal_code',
        'recipient_state',
        'recipient_city',
        'recipient_address_line1',
        'recipient_address_line2',
        'order_note',
    ];

    /**
     * @param array<string,mixed> $filters
     */
    public function __construct(private array $filters)
    {
        $this->filters = SalesOrderFilters::normalize($filters);
    }

    public function query(): Builder
    {
        $filters = $this->filters;

        return SalesOrderLine::query()
            ->with(['sku:id,sku,stock_item_id,name', 'sku.stockItem:id,short_name,name', 'salesOrder'])
            ->when(! $filters['shop_filter_allowed'], fn ($query) => $query->whereRaw('1 = 0'))
            ->whereHas('salesOrder', function (Builder $query) use ($filters) {
                SalesOrderFilters::applyToOrderQuery($query, $filters, applyExportIdFilter: true);
            })
            ->orderByDesc(
                SalesOrder::select('order_date')
                    ->whereColumn('sales_orders.id', 'sales_order_lines.sales_order_id')
            )
            ->orderByDesc('sales_order_id')
            ->orderBy('id');
    }

    public function headings(): array
    {
        return [
            ...self::IMPORT_HEADINGS,
            'order_status',
            'fulfillment_status',
            'source',
            'created_at',
        ];
    }

    /**
     * @param SalesOrderLine $line
     */
    public function map($line): array
    {
        $order = $line->salesOrder;

        return [
            (string) ($order->platform_order_id ?? ''),
            (string) ($line->sku->sku ?? ''),
            (int) $line->quantity,
            (string) ($line->note ?? ''),
            (string) ($order->recipient_name ?? ''),
            (string) ($order->recipient_phone ?? ''),
            (string) ($order->recipient_country_code ?? ''),
            (string) ($order->recipient_postal_code ?? ''),
            (string) ($order->recipient_state ?? ''),
            (string) ($order->recipient_city ?? ''),
            (string) ($order->recipient_address_line1 ?? ''),
            (string) ($order->recipient_address_line2 ?? ''),
            (string) ($order->note ?? ''),
            (string) $order->order_status,
            (string) $order->fulfillment_status,
            (string) $order->source,
            optional($order->created_at)->toIso8601String() ?? '',
        ];
    }
}
