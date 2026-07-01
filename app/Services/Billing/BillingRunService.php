<?php

namespace App\Services\Billing;

use App\Models\FeeRate;
use App\Models\InboundReceipt;
use App\Models\InventoryMovement;
use App\Models\Invoice;
use App\Models\OutboundOrder;
use App\Models\OutboundOrderLine;
use App\Models\ReturnOrderCost;
use App\Models\ReturnOrderLine;
use App\Models\StockItem;
use App\Models\Tenant;
use App\Models\Warehouse;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class BillingRunService
{
    /**
     * @var array<string, array{code:string,message:string,ids:array<int|string, true>}>
     */
    private array $warnings = [];

    /**
     * @var array<int, Warehouse>
     */
    private array $warehouses = [];

    public function generate(Tenant $tenant, string $period): Invoice
    {
        $this->warnings = [];
        $this->warehouses = $this->loadWarehouses();

        [$periodStartDate, $periodEndDate] = $this->periodDates($period);
        $rates = $this->applicableRates($tenant->id, $periodStartDate, $periodEndDate);

        if ($rates->isEmpty()) {
            throw new BillingRunException(__('billing.error_no_rates_configured'));
        }

        $currencies = $rates->pluck('currency')->unique()->values();
        if ($currencies->count() !== 1) {
            throw new BillingRunException(__('billing.error_rate_currency_mismatch'));
        }

        $currency = (string) $currencies->first();
        $events = $this->collectBillableEvents($tenant, $period);
        $this->guardSourceCurrencies($events, $currency);

        return DB::transaction(function () use ($tenant, $period, $currency, $events): Invoice {
            $invoice = $this->loadOrCreateInvoice($tenant, $period, $currency);

            if ($invoice->status === Invoice::STATUS_FINALIZED) {
                throw new BillingRunException(__('billing.error_finalized_invoice'));
            }

            $invoice->lines()->delete();
            $total = 0.0;

            foreach ($this->linePayloads($tenant->id, $events, $currency) as $payload) {
                $sources = $payload['sources'];
                unset($payload['sources']);

                $line = $invoice->lines()->create($payload);
                foreach ($sources as $source) {
                    $line->sources()->create($source);
                }

                $total += (float) $line->amount;
            }

            $invoice->update([
                'currency' => $currency,
                'status' => Invoice::STATUS_DRAFT,
                'total' => $this->roundMoney($total, $currency),
                'warnings' => $this->warningRows(),
                'generated_by_user_id' => Auth::id(),
            ]);

            return $invoice->refresh()->load('lines.sources', 'tenant');
        });
    }

    public function finalize(Invoice $invoice): Invoice
    {
        if ($invoice->status !== Invoice::STATUS_DRAFT) {
            throw new BillingRunException(__('billing.error_finalize_non_draft'));
        }

        $invoice->update([
            'status' => Invoice::STATUS_FINALIZED,
            'finalized_at' => now(),
        ]);

        return $invoice->refresh()->load('lines.sources', 'tenant');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function linePayloads(int $tenantId, array $events, string $currency): array
    {
        $groups = [];

        foreach ($events as $event) {
            $rate = FeeRate::resolveForDate($tenantId, $event['fee_type'], $event['source_date']);

            if (! $rate instanceof FeeRate) {
                $this->addWarning('no_rate', __('billing.warning_no_rate'), [$event['fee_type'].':'.$event['source_id']]);

                continue;
            }

            $key = implode('|', [$event['fee_type'], $rate->id]);
            $groups[$key] ??= [
                'rate' => $rate,
                'fee_type' => $event['fee_type'],
                'unit' => $rate->unit,
                'quantity' => 0.0,
                'cost_base' => 0.0,
                'source_dates' => [],
                'sources' => [],
            ];

            $quantity = (float) ($event['quantity'] ?? 0);
            $amountBasis = $event['amount_basis'] ?? null;
            $groups[$key]['quantity'] += $quantity;
            $groups[$key]['cost_base'] += $amountBasis === null ? 0.0 : (float) $amountBasis;
            $groups[$key]['source_dates'][] = $event['source_date'];
            $groups[$key]['sources'][] = [
                'source_type' => $event['source_type'],
                'source_id' => $event['source_id'],
                'warehouse_id' => $event['warehouse_id'] ?? null,
                'source_date' => $event['source_date'],
                'quantity' => $quantity === 0.0 ? null : $quantity,
                'amount_basis' => $amountBasis,
            ];
        }

        $payloads = [];
        foreach ($groups as $group) {
            $rate = $group['rate'];
            $usesMarkup = FeeRate::isPercentFeeType($rate->fee_type);
            $costBase = $usesMarkup ? (float) $group['cost_base'] : null;
            $amount = $usesMarkup
                ? ((float) $costBase) * (1 + ((float) $rate->markup_pct / 100))
                : (float) $group['quantity'] * (float) $rate->rate;

            $payloads[] = [
                'fee_type' => $rate->fee_type,
                'unit' => $rate->unit,
                'quantity' => $usesMarkup ? 1 : $group['quantity'],
                'rate' => $usesMarkup ? null : $rate->rate,
                'markup_pct' => $usesMarkup ? $rate->markup_pct : null,
                'cost_base' => $costBase,
                'rate_from' => min($group['source_dates']),
                'rate_to' => max($group['source_dates']),
                'amount' => $this->roundMoney($amount, $currency),
                'source_summary' => $this->sourceSummary($rate->fee_type, count($group['sources'])),
                'sources' => $group['sources'],
            ];
        }

        return collect($payloads)
            ->sortBy([['fee_type', 'asc'], ['rate_from', 'asc']])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function collectBillableEvents(Tenant $tenant, string $period): array
    {
        return [
            ...$this->storageEvents($tenant, $period),
            ...$this->inboundEvents($tenant, $period),
            ...$this->outboundOrderEvents($tenant, $period),
            ...$this->outboundUnitEvents($tenant, $period),
            ...$this->qcEvents($tenant, $period),
            ...$this->returnShippingEvents($tenant, $period),
            ...$this->postageEvents($tenant, $period),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function storageEvents(Tenant $tenant, string $period): array
    {
        $events = [];
        [$periodStartDate] = $this->periodDates($period);
        $daysInMonth = $periodStartDate->daysInMonth;
        $stockItems = StockItem::query()->where('tenant_id', $tenant->id)->get()->keyBy('id');
        $ratesByDay = $this->storageRatesByDay($tenant->id, $periodStartDate);

        foreach ($this->warehouses as $warehouse) {
            [$startUtc, $endUtc] = $this->warehouseUtcWindow($period, $warehouse);
            $pairs = $this->storagePairs($tenant->id, $warehouse->id, $startUtc, $endUtc);

            foreach ($pairs as $pair) {
                $stockItem = $stockItems->get($pair['stock_item_id']);
                if (! $stockItem instanceof StockItem) {
                    continue;
                }

                $balance = $pair['opening_on_hand'];
                $movementsByDay = $this->storageMovementsByDay($tenant->id, $warehouse, $stockItem->id, $startUtc, $endUtc);

                for ($day = 1; $day <= $daysInMonth; $day++) {
                    $localDate = $periodStartDate->setDay($day)->toDateString();
                    $balance += (int) ($movementsByDay[$localDate] ?? 0);

                    if ($balance <= 0) {
                        continue;
                    }

                    $rate = $ratesByDay[$localDate] ?? null;
                    if (! $rate instanceof FeeRate) {
                        $this->addWarning('no_rate', __('billing.warning_no_rate'), [FeeRate::TYPE_STORAGE.':'.$stockItem->id]);

                        continue;
                    }

                    $dailyQuantity = $this->storageDailyQuantity($stockItem, $balance, $rate->unit, $daysInMonth);
                    if ($dailyQuantity <= 0) {
                        continue;
                    }

                    $events[] = [
                        'fee_type' => FeeRate::TYPE_STORAGE,
                        'source_type' => 'stock_item',
                        'source_id' => $stockItem->id,
                        'warehouse_id' => $warehouse->id,
                        'source_date' => $localDate,
                        'quantity' => $dailyQuantity,
                        'amount_basis' => null,
                    ];
                }
            }
        }

        return $events;
    }

    private function storageDailyQuantity(StockItem $stockItem, int $onHand, string $unit, int $daysInMonth): float
    {
        if ($unit === FeeRate::UNIT_PER_UNIT_MONTH) {
            return $onHand / $daysInMonth;
        }

        $volume = $this->stockItemVolumeM3($stockItem);
        if ($volume === null) {
            $this->addWarning('missing_dimensions', __('billing.warning_missing_dimensions'), [$stockItem->id]);

            return 0.0;
        }

        return ($onHand * $volume) / $daysInMonth;
    }

    private function stockItemVolumeM3(StockItem $stockItem): ?float
    {
        if ($stockItem->length_value === null || $stockItem->width_value === null || $stockItem->height_value === null) {
            return null;
        }

        $factor = match ($stockItem->dimension_unit) {
            'mm' => 0.001,
            'cm' => 0.01,
            'm' => 1.0,
            default => null,
        };

        if ($factor === null) {
            return null;
        }

        return (float) $stockItem->length_value * $factor
            * (float) $stockItem->width_value * $factor
            * (float) $stockItem->height_value * $factor;
    }

    /**
     * @return array<int, array{stock_item_id:int,opening_on_hand:int}>
     */
    private function storagePairs(int $tenantId, int $warehouseId, CarbonInterface $startUtc, CarbonInterface $endUtc): array
    {
        $stockItemIds = InventoryMovement::query()
            ->where('tenant_id', $tenantId)
            ->where('warehouse_id', $warehouseId)
            ->where('created_at', '<', $endUtc)
            ->pluck('stock_item_id')
            ->unique()
            ->values();

        $pairs = [];
        foreach ($stockItemIds as $stockItemId) {
            $opening = InventoryMovement::query()
                ->where('tenant_id', $tenantId)
                ->where('warehouse_id', $warehouseId)
                ->where('stock_item_id', (int) $stockItemId)
                ->where('created_at', '<', $startUtc)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->first();

            $openingOnHand = $opening instanceof InventoryMovement ? (int) $opening->on_hand_after : 0;
            $hasInWindowMovement = InventoryMovement::query()
                ->where('tenant_id', $tenantId)
                ->where('warehouse_id', $warehouseId)
                ->where('stock_item_id', (int) $stockItemId)
                ->where('created_at', '>=', $startUtc)
                ->where('created_at', '<', $endUtc)
                ->exists();

            if ($openingOnHand > 0 || $hasInWindowMovement) {
                $pairs[] = ['stock_item_id' => (int) $stockItemId, 'opening_on_hand' => $openingOnHand];
            }
        }

        return $pairs;
    }

    /**
     * @return array<string, int>
     */
    private function storageMovementsByDay(int $tenantId, Warehouse $warehouse, int $stockItemId, CarbonInterface $startUtc, CarbonInterface $endUtc): array
    {
        $days = [];
        InventoryMovement::query()
            ->where('tenant_id', $tenantId)
            ->where('warehouse_id', $warehouse->id)
            ->where('stock_item_id', $stockItemId)
            ->where('created_at', '>=', $startUtc)
            ->where('created_at', '<', $endUtc)
            ->orderBy('created_at')
            ->get()
            ->each(function (InventoryMovement $movement) use (&$days, $warehouse): void {
                $date = CarbonImmutable::parse($movement->created_at)->setTimezone($warehouse->timezone)->toDateString();
                $days[$date] = ($days[$date] ?? 0) + (int) $movement->on_hand_delta;
            });

        return $days;
    }

    /**
     * @return array<string, FeeRate|null>
     */
    private function storageRatesByDay(int $tenantId, CarbonImmutable $periodStartDate): array
    {
        $rates = [];
        for ($day = 1; $day <= $periodStartDate->daysInMonth; $day++) {
            $date = $periodStartDate->setDay($day)->toDateString();
            $rates[$date] = FeeRate::resolveForDate($tenantId, FeeRate::TYPE_STORAGE, $date);
        }

        return $rates;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function inboundEvents(Tenant $tenant, string $period): array
    {
        return InboundReceipt::query()
            ->where('tenant_id', $tenant->id)
            ->get()
            ->filter(fn (InboundReceipt $receipt): bool => $this->timestampInWarehousePeriod($receipt->received_at, $receipt->warehouse_id, $period))
            ->map(fn (InboundReceipt $receipt): array => [
                'fee_type' => FeeRate::TYPE_HANDLING_INBOUND,
                'source_type' => 'inbound_receipt',
                'source_id' => $receipt->id,
                'warehouse_id' => $receipt->warehouse_id,
                'source_date' => $this->localDate($receipt->received_at, $receipt->warehouse_id),
                'quantity' => (int) $receipt->received_qty,
                'amount_basis' => null,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function outboundOrderEvents(Tenant $tenant, string $period): array
    {
        return $this->shippedOutboundOrders($tenant, $period)
            ->map(fn (OutboundOrder $order): array => [
                'fee_type' => FeeRate::TYPE_HANDLING_OUTBOUND_ORDER,
                'source_type' => 'outbound_order',
                'source_id' => $order->id,
                'warehouse_id' => $order->warehouse_id,
                'source_date' => $this->localDate($order->shipped_at, $order->warehouse_id),
                'quantity' => 1,
                'amount_basis' => null,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function outboundUnitEvents(Tenant $tenant, string $period): array
    {
        $orders = $this->shippedOutboundOrders($tenant, $period)->keyBy('id');

        return OutboundOrderLine::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('outbound_order_id', $orders->keys())
            ->whereNotNull('stock_item_id')
            ->get()
            ->map(function (OutboundOrderLine $line) use ($orders): array {
                $order = $orders->get($line->outbound_order_id);

                return [
                    'fee_type' => FeeRate::TYPE_HANDLING_OUTBOUND_UNIT,
                    'source_type' => 'outbound_order',
                    'source_id' => $line->outbound_order_id,
                    'warehouse_id' => $order?->warehouse_id,
                    'source_date' => $this->localDate($order?->shipped_at, $order?->warehouse_id),
                    'quantity' => (int) $line->qty,
                    'amount_basis' => null,
                ];
            })
            ->filter(fn (array $event): bool => is_string($event['source_date']))
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, OutboundOrder>
     */
    private function shippedOutboundOrders(Tenant $tenant, string $period): Collection
    {
        return OutboundOrder::query()
            ->where('tenant_id', $tenant->id)
            ->where('status', OutboundOrder::STATUS_SHIPPED)
            ->whereIn('reason', OutboundOrder::fulfillableReasons())
            ->get()
            ->filter(fn (OutboundOrder $order): bool => $this->timestampInWarehousePeriod($order->shipped_at, $order->warehouse_id, $period))
            ->values();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function qcEvents(Tenant $tenant, string $period): array
    {
        return ReturnOrderLine::query()
            ->where('tenant_id', $tenant->id)
            ->whereNotNull('inspected_at')
            ->get()
            ->map(function (ReturnOrderLine $line) use ($period): ?array {
                $warehouseId = $this->returnOrderWarehouseId($line->return_order_id);
                if (! $this->timestampInWarehousePeriod($line->inspected_at, $warehouseId, $period)) {
                    return null;
                }

                return [
                    'fee_type' => FeeRate::TYPE_QC,
                    'source_type' => 'return_order_line',
                    'source_id' => $line->id,
                    'warehouse_id' => $warehouseId,
                    'source_date' => $this->localDate($line->inspected_at, $warehouseId),
                    'quantity' => (int) $line->received_qty,
                    'amount_basis' => null,
                ];
            })
            ->filter()
            ->filter(fn (array $event): bool => is_string($event['source_date']))
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function returnShippingEvents(Tenant $tenant, string $period): array
    {
        return ReturnOrderCost::query()
            ->where('tenant_id', $tenant->id)
            ->where('cost_type', ReturnOrderCost::COST_FREIGHT_COLLECT)
            ->get()
            ->map(function (ReturnOrderCost $cost) use ($period): ?array {
                $warehouseId = $this->returnOrderWarehouseId($cost->return_order_id);
                if (! $this->timestampInWarehousePeriod($cost->cost_incurred_at, $warehouseId, $period)) {
                    return null;
                }

                return [
                    'fee_type' => FeeRate::TYPE_RETURN_SHIPPING,
                    'source_type' => 'return_order_cost',
                    'source_id' => $cost->id,
                    'warehouse_id' => $warehouseId,
                    'source_date' => $this->localDate($cost->cost_incurred_at, $warehouseId),
                    'quantity' => 1,
                    'amount_basis' => (float) $cost->amount,
                    'currency' => $cost->currency,
                ];
            })
            ->filter()
            ->filter(fn (array $event): bool => is_string($event['source_date']))
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function postageEvents(Tenant $tenant, string $period): array
    {
        $events = [];

        foreach ($this->shippedOutboundOrders($tenant, $period) as $order) {
            if ($order->courier_cost === null) {
                $this->addWarning('missing_courier_cost', __('billing.warning_missing_courier_cost'), [$order->id]);

                continue;
            }

            $events[] = [
                'fee_type' => FeeRate::TYPE_POSTAGE,
                'source_type' => 'outbound_order',
                'source_id' => $order->id,
                'warehouse_id' => $order->warehouse_id,
                'source_date' => $this->localDate($order->shipped_at, $order->warehouse_id),
                'quantity' => 1,
                'amount_basis' => (float) $order->courier_cost,
                'currency' => $order->courier_cost_currency,
            ];
        }

        return $events;
    }

    private function guardSourceCurrencies(array $events, string $currency): void
    {
        $offenders = collect($events)
            ->filter(fn (array $event): bool => isset($event['currency']) && $event['currency'] !== $currency)
            ->map(fn (array $event): string => $event['source_type'].'#'.$event['source_id'])
            ->values();

        if ($offenders->isNotEmpty()) {
            throw new BillingRunException(__('billing.error_source_currency_mismatch', [
                'sources' => $offenders->implode(', '),
            ]));
        }
    }

    private function loadOrCreateInvoice(Tenant $tenant, string $period, string $currency): Invoice
    {
        $invoice = Invoice::query()
            ->where('tenant_id', $tenant->id)
            ->where('period', $period)
            ->lockForUpdate()
            ->first();

        if ($invoice instanceof Invoice) {
            return $invoice;
        }

        try {
            return Invoice::query()->create([
                'tenant_id' => $tenant->id,
                'period' => $period,
                'status' => Invoice::STATUS_DRAFT,
                'currency' => $currency,
                'total' => 0,
                'generated_by_user_id' => Auth::id(),
            ]);
        } catch (QueryException) {
            return Invoice::query()
                ->where('tenant_id', $tenant->id)
                ->where('period', $period)
                ->lockForUpdate()
                ->firstOrFail();
        }
    }

    private function returnOrderWarehouseId(?int $returnOrderId): ?int
    {
        if ($returnOrderId === null) {
            return null;
        }

        $warehouseId = DB::table('return_orders')
            ->where('id', $returnOrderId)
            ->value('warehouse_id');

        return $warehouseId === null ? null : (int) $warehouseId;
    }

    private function timestampInWarehousePeriod(mixed $timestamp, ?int $warehouseId, string $period): bool
    {
        if ($timestamp === null || $warehouseId === null) {
            return false;
        }

        $warehouse = $this->warehouses[$warehouseId] ?? null;
        if (! $warehouse instanceof Warehouse) {
            return false;
        }

        [$startUtc, $endUtc] = $this->warehouseUtcWindow($period, $warehouse);
        $time = CarbonImmutable::parse($timestamp)->utc();

        return $time->greaterThanOrEqualTo($startUtc) && $time->lessThan($endUtc);
    }

    private function localDate(mixed $timestamp, ?int $warehouseId): ?string
    {
        if ($timestamp === null || $warehouseId === null) {
            return null;
        }

        $warehouse = $this->warehouses[$warehouseId] ?? null;
        if (! $warehouse instanceof Warehouse) {
            return null;
        }

        return CarbonImmutable::parse($timestamp)->setTimezone($warehouse->timezone)->toDateString();
    }

    /**
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    private function warehouseUtcWindow(string $period, Warehouse $warehouse): array
    {
        $start = CarbonImmutable::createFromFormat('Y-m-d H:i:s', $period.'-01 00:00:00', $warehouse->timezone);

        return [
            $start->utc(),
            $start->addMonthNoOverflow()->utc(),
        ];
    }

    /**
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    private function periodDates(string $period): array
    {
        $start = CarbonImmutable::createFromFormat('Y-m-d', $period.'-01', 'UTC')->startOfDay();

        return [$start, $start->endOfMonth()];
    }

    private function applicableRates(int $tenantId, CarbonImmutable $periodStart, CarbonImmutable $periodEnd): Collection
    {
        return FeeRate::query()
            ->where('tenant_id', $tenantId)
            ->where('effective_from', '<=', $periodEnd->toDateString())
            ->where(fn ($query) => $query
                ->whereNull('effective_to')
                ->orWhere('effective_to', '>=', $periodStart->toDateString()))
            ->get();
    }

    /**
     * @return array<int, Warehouse>
     */
    private function loadWarehouses(): array
    {
        return Warehouse::query()
            ->get()
            ->keyBy('id')
            ->all();
    }

    private function roundMoney(float $amount, string $currency): float
    {
        return round($amount, $this->currencyMinorUnit($currency), PHP_ROUND_HALF_UP);
    }

    private function currencyMinorUnit(string $currency): int
    {
        return match (strtoupper($currency)) {
            'JPY', 'KRW' => 0,
            default => 2,
        };
    }

    /**
     * @param  array<int|string>  $ids
     */
    private function addWarning(string $code, string $message, array $ids): void
    {
        $this->warnings[$code] ??= ['code' => $code, 'message' => $message, 'ids' => []];

        foreach ($ids as $id) {
            $this->warnings[$code]['ids'][$id] = true;
        }
    }

    /**
     * @return array<int, array{code:string,message:string,count:int,ids:array<int, int|string>}>
     */
    private function warningRows(): array
    {
        return collect($this->warnings)
            ->map(fn (array $warning): array => [
                'code' => $warning['code'],
                'message' => $warning['message'],
                'count' => count($warning['ids']),
                'ids' => array_keys($warning['ids']),
            ])
            ->values()
            ->all();
    }

    private function sourceSummary(string $feeType, int $count): string
    {
        return __('billing.source_summary', [
            'fee_type' => __('billing.fee_types.'.$feeType),
            'count' => $count,
        ]);
    }
}
