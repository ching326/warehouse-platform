<?php

namespace App\Services\Outbound;

use App\Models\Issue;
use App\Models\OutboundOrder;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\Sku;
use App\Models\Tenant;
use App\Models\Warehouse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ReshipSalesOrderService
{
    public function __construct(private readonly OutboundLineBuilder $lineBuilder) {}

    /**
     * @param  array<int, array{sales_order_line_id: int, qty: int}>  $lines
     * @param  array<int, array{sku_id: int, qty: int}>  $extraLines
     * @param  array<string, mixed>  $recipient
     */
    public function reship(
        SalesOrder $salesOrder,
        OutboundOrder $originalOutbound,
        ?int $warehouseId,
        string $issueType,
        array $lines,
        ?string $note,
        array $recipient = [],
        array $extraLines = [],
    ): OutboundOrder {
        return DB::transaction(function () use ($salesOrder, $originalOutbound, $warehouseId, $issueType, $lines, $note, $recipient, $extraLines): OutboundOrder {
            $salesOrder = SalesOrder::query()
                ->lockForUpdate()
                ->findOrFail($salesOrder->id);
            $originalOutbound = OutboundOrder::query()
                ->lockForUpdate()
                ->findOrFail($originalOutbound->id);

            $this->validateSource($salesOrder, $originalOutbound);
            $this->validateIssueType($issueType);
            $warehouseId = $this->resolveWarehouseId($warehouseId, $originalOutbound);
            $selectedLines = $this->validatedLines($salesOrder, $lines);
            $extraSkus = $this->validatedExtraLines($salesOrder, $extraLines);

            if ($selectedLines === [] && $extraSkus === []) {
                throw ValidationException::withMessages(['reshipLines' => __('outbound.reship_no_lines_selected')]);
            }

            $recipient = $this->recipientFields($originalOutbound, $recipient);

            $issue = Issue::query()->create([
                'tenant_id' => $salesOrder->tenant_id,
                'sales_order_id' => $salesOrder->id,
                'outbound_order_id' => $originalOutbound->id,
                'issue_no' => 'ISS-PENDING-'.Str::uuid(),
                'issue_type' => $issueType,
                'status' => Issue::STATUS_OPEN,
                'reported_at' => now(),
                'note' => $this->nullableString($note),
                'created_by_user_id' => Auth::id(),
            ]);
            $issue->update(['issue_no' => Issue::buildIssueNo((int) $issue->id)]);

            $reship = OutboundOrder::query()->create([
                'reason' => OutboundOrder::REASON_RE_SHIP,
                'status' => OutboundOrder::STATUS_RESERVED,
                'source_sales_order_id' => $salesOrder->id,
                'reship_of_outbound_id' => $originalOutbound->id,
                'issue_id' => $issue->id,
                'tenant_id' => $salesOrder->tenant_id,
                'warehouse_id' => $warehouseId,
                'shipping_method_id' => $originalOutbound->shipping_method_id,
                'ref' => 'OB-PENDING-'.Str::uuid(),
                'recipient_name' => $recipient['recipient_name'],
                'recipient_phone' => $recipient['recipient_phone'],
                'recipient_country_code' => $recipient['recipient_country_code'],
                'recipient_postal_code' => $recipient['recipient_postal_code'],
                'recipient_state' => $recipient['recipient_state'],
                'recipient_city' => $recipient['recipient_city'],
                'recipient_address_line1' => $recipient['recipient_address_line1'],
                'recipient_address_line2' => $recipient['recipient_address_line2'],
                'courier' => $originalOutbound->courier,
                'package_count' => $originalOutbound->package_count,
                'package_weight_g' => $originalOutbound->package_weight_g,
                'ship_note' => null,
                'note' => $this->nullableString($note),
                'created_by_user_id' => Auth::id(),
            ]);

            $reship->salesOrders()->attach($salesOrder->id, ['arranged_at' => now()]);

            foreach ($selectedLines as $index => $linePayload) {
                $line = $linePayload['line'];
                $line->loadMissing('sku.bundleComponents');
                $sku = $line->sku;

                if (! $sku instanceof Sku) {
                    throw ValidationException::withMessages(["reshipLines.{$index}.sku_id" => __('outbound.sku_not_shippable')]);
                }

                $this->lineBuilder->addLine(
                    order: $reship,
                    tenantId: (int) $salesOrder->tenant_id,
                    warehouseId: $warehouseId,
                    sku: $sku,
                    qty: $linePayload['qty'],
                    note: null,
                    reserve: true,
                    errorKey: "reshipLines.{$index}",
                );
            }

            foreach ($extraSkus as $index => $extra) {
                $this->lineBuilder->addLine(
                    order: $reship,
                    tenantId: (int) $salesOrder->tenant_id,
                    warehouseId: $warehouseId,
                    sku: $extra['sku'],
                    qty: $extra['qty'],
                    note: $extra['note'],
                    reserve: true,
                    errorKey: "reshipExtraLines.{$index}",
                );
            }

            $tenantCode = Tenant::query()
                ->whereKey($salesOrder->tenant_id)
                ->value('code');

            $reship->update(['ref' => OutboundOrder::buildRef((int) $reship->id, (string) $tenantCode)]);

            return $reship->refresh();
        });
    }

    public static function reshipReasonOptions(): array
    {
        return [
            Issue::TYPE_MISSING => __('outbound.reship_reason_missing'),
            Issue::TYPE_DAMAGED => __('outbound.reship_reason_defect'),
            Issue::TYPE_WRONG_ADDRESS => __('outbound.reship_reason_wrong_address'),
            Issue::TYPE_OTHER => __('outbound.reship_reason_other'),
        ];
    }

    /**
     * @param  array<string, mixed>  $recipient
     * @return array<string, ?string>
     */
    private function recipientFields(OutboundOrder $originalOutbound, array $recipient): array
    {
        $fields = [
            'recipient_name',
            'recipient_phone',
            'recipient_country_code',
            'recipient_postal_code',
            'recipient_state',
            'recipient_city',
            'recipient_address_line1',
            'recipient_address_line2',
        ];

        $resolved = [];

        foreach ($fields as $field) {
            $value = array_key_exists($field, $recipient)
                ? $recipient[$field]
                : $originalOutbound->{$field};

            $resolved[$field] = $this->nullableString(is_scalar($value) ? (string) $value : null);
        }

        if ($resolved['recipient_country_code'] !== null) {
            $resolved['recipient_country_code'] = strtoupper($resolved['recipient_country_code']);
        }

        return $resolved;
    }

    private function validateSource(SalesOrder $salesOrder, OutboundOrder $originalOutbound): void
    {
        if ((int) $salesOrder->tenant_id !== (int) $originalOutbound->tenant_id) {
            throw ValidationException::withMessages(['reshipSourceOutboundId' => __('outbound.reship_not_shipped')]);
        }

        if ($originalOutbound->status !== OutboundOrder::STATUS_SHIPPED) {
            throw ValidationException::withMessages(['reshipSourceOutboundId' => __('outbound.reship_not_shipped')]);
        }

        $linked = (int) $originalOutbound->source_sales_order_id === (int) $salesOrder->id
            || $originalOutbound->salesOrders()->whereKey($salesOrder->id)->exists();

        if (! $linked) {
            throw ValidationException::withMessages(['reshipSourceOutboundId' => __('outbound.reship_not_shipped')]);
        }
    }

    private function validateIssueType(string $issueType): void
    {
        if (! array_key_exists($issueType, self::reshipReasonOptions())) {
            throw ValidationException::withMessages(['reshipReason' => __('validation.in')]);
        }
    }

    private function resolveWarehouseId(?int $warehouseId, OutboundOrder $originalOutbound): int
    {
        $warehouseId ??= (int) $originalOutbound->warehouse_id;

        if ($warehouseId <= 0 || ! Warehouse::query()->where('status', 'active')->whereKey($warehouseId)->exists()) {
            throw ValidationException::withMessages(['reshipWarehouseId' => __('validation.exists')]);
        }

        return $warehouseId;
    }

    /**
     * @param  array<int, array{sales_order_line_id: int, qty: int}>  $lines
     * @return array<int, array{line: SalesOrderLine, qty: int}>
     */
    private function validatedLines(SalesOrder $salesOrder, array $lines): array
    {
        $lineIds = collect($lines)
            ->pluck('sales_order_line_id')
            ->map(fn ($id): int => (int) $id)
            ->filter()
            ->unique()
            ->values();

        $salesOrderLines = SalesOrderLine::query()
            ->where('sales_order_id', $salesOrder->id)
            ->whereIn('id', $lineIds)
            ->with('sku.bundleComponents')
            ->get()
            ->keyBy('id');

        $selectedByLine = [];

        foreach ($lines as $index => $lineInput) {
            $lineId = (int) $lineInput['sales_order_line_id'];
            $qty = (int) $lineInput['qty'];

            if ($qty <= 0) {
                continue;
            }

            $line = $salesOrderLines->get($lineId);

            if (! $line) {
                throw ValidationException::withMessages(["reshipLines.{$index}.qty" => __('validation.exists')]);
            }

            if ($qty > (int) $line->quantity) {
                throw ValidationException::withMessages(["reshipLines.{$index}.qty" => __('outbound.reship_qty_exceeds_line')]);
            }

            if (! $line->sku) {
                throw ValidationException::withMessages(["reshipLines.{$index}.sku_id" => __('outbound.sku_not_shippable')]);
            }

            if (! isset($selectedByLine[$lineId])) {
                $selectedByLine[$lineId] = ['line' => $line, 'qty' => 0, 'index' => $index];
            }

            $selectedByLine[$lineId]['qty'] += $qty;
        }

        foreach ($selectedByLine as $linePayload) {
            if ($linePayload['qty'] > (int) $linePayload['line']->quantity) {
                throw ValidationException::withMessages(["reshipLines.{$linePayload['index']}.qty" => __('outbound.reship_qty_exceeds_line')]);
            }
        }

        return array_values(array_map(
            fn (array $linePayload): array => ['line' => $linePayload['line'], 'qty' => $linePayload['qty']],
            $selectedByLine,
        ));
    }

    /**
     * Added SKUs that were not on the original order. Any active tenant SKU, no qty cap.
     *
     * @param  array<int, array{sku_id: int, qty: int, note?: string}>  $extraLines
     * @return array<int, array{sku: Sku, qty: int, note: ?string}>
     */
    private function validatedExtraLines(SalesOrder $salesOrder, array $extraLines): array
    {
        $validated = [];

        foreach ($extraLines as $index => $lineInput) {
            $skuId = (int) $lineInput['sku_id'];
            $qty = (int) $lineInput['qty'];

            if ($skuId <= 0 || $qty <= 0) {
                continue;
            }

            $sku = Sku::query()
                ->where('tenant_id', $salesOrder->tenant_id)
                ->where('status', 'active')
                ->with('bundleComponents')
                ->find($skuId);

            if (! $sku instanceof Sku) {
                throw ValidationException::withMessages(["reshipExtraLines.{$index}.sku_id" => __('validation.exists')]);
            }

            $validated[] = ['sku' => $sku, 'qty' => $qty, 'note' => $this->nullableString($lineInput['note'] ?? null)];
        }

        return $validated;
    }

    private function nullableString(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
