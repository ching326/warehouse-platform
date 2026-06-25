<?php

namespace App\Livewire;

use App\Models\FulfillmentPackScan;
use App\Models\OutboundOrder;
use App\Models\Tenant;
use App\Services\Fulfillment\FulfillmentPackService;
use App\Services\Outbound\ShipOutboundOrderService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Livewire\Component;

class FulfillmentPack extends Component
{
    public int $outboundOrderId = 0;

    public string $barcode = '';

    public ?string $feedbackMessage = null;

    public string $feedbackType = 'success';

    public string $packMode = 'normal';

    public ?array $pendingQuantityScan = null;

    public int $pendingQuantity = 1;

    public ?string $lastScannedLineKey = null;

    private bool $allowedTenantIdsResolved = false;

    private array $allowedTenantIdsCache = [];

    public function mount(OutboundOrder $order): void
    {
        $this->authorizeInternalUser();

        $this->outboundOrderId = OutboundOrder::query()
            ->where('reason', OutboundOrder::REASON_CUSTOMER_ORDER)
            ->whereIn('tenant_id', $this->allowedTenantIds())
            ->findOrFail($order->id)
            ->id;

        $this->lastScannedLineKey = null;
    }

    public function scan(FulfillmentPackService $service): void
    {
        $this->authorizeInternalUser();

        if ($this->pendingQuantityScan !== null) {
            $this->clearPendingQuantity();
        }

        $order = $this->loadOrder();
        $barcodeScanned = $this->barcode;
        $normalized = $service->normalizeProductBarcode($barcodeScanned);
        $this->barcode = '';

        if ($normalized === '') {
            $this->focusScanner();

            return;
        }

        if ($order->status !== OutboundOrder::STATUS_PENDING) {
            $this->writeScan($order, [
                'barcode_scanned' => $barcodeScanned,
                'normalized_barcode' => $normalized,
                'result' => FulfillmentPackScan::RESULT_BLOCKED_STATUS,
                'message' => $order->status === OutboundOrder::STATUS_SHIPPED
                    ? __('fulfillment_pack.already_shipped')
                    : __('fulfillment_pack.cancelled_group'),
            ]);
            $this->error($order->status === OutboundOrder::STATUS_SHIPPED
                ? __('fulfillment_pack.already_shipped')
                : __('fulfillment_pack.cancelled_group'));
            $this->focusScanner();

            return;
        }

        $lines = $service->packLinesWithProgress($order);
        $matchedLines = collect($lines)
            ->filter(fn (array $line): bool => $service->lineMatchesScan($line, $normalized))
            ->values();

        if ($matchedLines->isEmpty()) {
            $message = __('fulfillment_pack.wrong_item');
            $this->writeScan($order, [
                'barcode_scanned' => $barcodeScanned,
                'normalized_barcode' => $normalized,
                'result' => FulfillmentPackScan::RESULT_WRONG_ITEM,
                'message' => $message,
            ]);
            $this->error($message);
            $this->focusScanner();

            return;
        }

        $matchedLine = $matchedLines->first(fn (array $line): bool => $line['remaining_qty'] > 0);

        if (! $matchedLine) {
            $message = __('fulfillment_pack.over_scan');
            $completedLine = $matchedLines->first();
            $this->lastScannedLineKey = $completedLine['key'];
            $this->writeScan($order, [
                'sku_id' => $completedLine['sku_id'],
                'stock_item_id' => $completedLine['stock_item_id'],
                'barcode_scanned' => $barcodeScanned,
                'normalized_barcode' => $normalized,
                'result' => FulfillmentPackScan::RESULT_OVER_SCAN,
                'message' => $message,
            ]);
            $this->error($message);
            $this->focusScanner();

            return;
        }

        if ($matchedLines->count() === 1 && $this->packMode !== 'strict' && ! $matchedLine['strict_only'] && $matchedLine['remaining_qty'] > 1) {
            $display = $this->lineDisplay($matchedLine, $normalized);

            $this->pendingQuantityScan = [
                'line_key' => $matchedLine['key'],
                'sku_id' => $matchedLine['sku_id'],
                'stock_item_id' => $matchedLine['stock_item_id'],
                'display' => $display,
                'barcode_scanned' => $barcodeScanned,
                'normalized_barcode' => $normalized,
                'remaining_qty' => $matchedLine['remaining_qty'],
                'strict_only' => false,
            ];
            $this->pendingQuantity = 1;
            $this->feedbackType = 'prompt';
            $this->feedbackMessage = __('fulfillment_pack.quantity_prompt_message', [
                'sku' => $display,
                'remaining' => $matchedLine['remaining_qty'],
            ]);
            $this->dispatch('pack-quantity-focus');

            return;
        }

        $this->acceptScan($order, $matchedLine, $barcodeScanned, $normalized, 1);
        $this->focusScanner();
    }

    public function confirmPendingQuantity(FulfillmentPackService $service): void
    {
        $this->authorizeInternalUser();

        if ($this->pendingQuantityScan === null) {
            $this->focusScanner();

            return;
        }

        $order = $this->loadOrder();
        $pending = $this->pendingQuantityScan;

        if ($order->status !== OutboundOrder::STATUS_PENDING) {
            $message = $order->status === OutboundOrder::STATUS_SHIPPED
                ? __('fulfillment_pack.already_shipped')
                : __('fulfillment_pack.cancelled_group');

            $this->writeScan($order, [
                'barcode_scanned' => (string) $pending['barcode_scanned'],
                'normalized_barcode' => (string) $pending['normalized_barcode'],
                'result' => FulfillmentPackScan::RESULT_BLOCKED_STATUS,
                'message' => $message,
            ]);
            $this->clearPendingQuantity();
            $this->error($message);
            $this->focusScanner();

            return;
        }

        $line = collect($service->packLinesWithProgress($order))
            ->first(fn (array $line): bool => $line['key'] === $pending['line_key']);

        if (! $line || $line['remaining_qty'] <= 0) {
            $this->clearPendingQuantity();
            $this->error(__('fulfillment_pack.over_scan'));
            $this->focusScanner();

            return;
        }

        $quantity = max(1, (int) $this->pendingQuantity);
        $quantity = min($quantity, (int) $line['remaining_qty']);

        $this->acceptScan(
            $order,
            $line,
            (string) $pending['barcode_scanned'],
            (string) $pending['normalized_barcode'],
            $quantity
        );
        $this->clearPendingQuantity();
        $this->lastScannedLineKey = $line['key'];
        $this->focusScanner();
    }

    public function cancelPendingQuantity(): void
    {
        $this->clearPendingQuantity();
        $this->lastScannedLineKey = null;
        $this->focusScanner();
    }

    public function updatedPackMode(): void
    {
        if (! in_array($this->packMode, ['normal', 'strict'], true)) {
            $this->packMode = 'normal';
        }

        $this->clearPendingQuantity();
        $this->lastScannedLineKey = null;
        $this->focusScanner();
    }

    public function markShipped(FulfillmentPackService $packService, ShipOutboundOrderService $shipService): void
    {
        $this->authorizeInternalUser();

        $order = $this->loadOrder();

        if ($this->pendingQuantityScan !== null) {
            $this->error(__('fulfillment_pack.confirm_quantity_before_shipping'));

            return;
        }

        if ($order->status !== OutboundOrder::STATUS_PENDING) {
            $this->error(__('fulfillment_pack.already_shipped'));

            return;
        }

        if (! $packService->allLinesComplete($order)) {
            $this->error(__('fulfillment_pack.scan_all_before_shipping'));

            return;
        }

        try {
            DB::transaction(function () use ($order, $packService, $shipService): void {
                $lockedOrder = OutboundOrder::query()
                    ->where('reason', OutboundOrder::REASON_CUSTOMER_ORDER)
                    ->whereIn('tenant_id', $this->allowedTenantIds())
                    ->whereKey($order->id)
                    ->with('shippingMethod:id,name')
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($lockedOrder->status !== OutboundOrder::STATUS_PENDING || ! $packService->allLinesComplete($lockedOrder)) {
                    throw new InvalidArgumentException(__('fulfillment_pack.scan_all_before_shipping'));
                }

                $shipService->ship($lockedOrder, [
                    'courier' => $lockedOrder->courier,
                    'tracking_no' => $lockedOrder->tracking_no,
                ]);
            });
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return;
        }

        session()->flash('status', __('fulfillment_pack.pack_complete'));
        $this->redirectRoute('outbound.show', $order, navigate: true);
    }

    public function render(FulfillmentPackService $service)
    {
        $this->authorizeInternalUser();

        $order = $this->loadOrder();
        $lines = $service->packLinesWithProgress($order);
        $allComplete = $lines !== [] && collect($lines)->every(fn (array $line): bool => $line['remaining_qty'] <= 0);
        $progress = $this->progressSummary($order, $lines);

        $reference = $order->ref;

        return view('livewire.fulfillment-pack', [
            'order' => $order,
            'lines' => $lines,
            'allComplete' => $allComplete,
            'progress' => $progress,
            'readOnly' => $order->status !== OutboundOrder::STATUS_PENDING,
        ])->layout('inventory', [
            'title' => __('fulfillment_pack.page_title'),
            'subtitle' => $reference,
        ]);
    }

    private function loadOrder(): OutboundOrder
    {
        return OutboundOrder::query()
            ->where('reason', OutboundOrder::REASON_CUSTOMER_ORDER)
            ->whereIn('tenant_id', $this->allowedTenantIds())
            ->with([
                'tenant:id,code,name',
                'shippingMethod:id,name',
                'leafLines.sku.barcodeAliases:id,tenant_id,model_type,model_id,normalized_barcode,is_active',
                'leafLines.stockItem.barcodeAliases:id,tenant_id,model_type,model_id,normalized_barcode,is_active',
                'leafLines.parentLine.sku.barcodeAliases:id,tenant_id,model_type,model_id,normalized_barcode,is_active',
                'salesOrders:id',
            ])
            ->findOrFail($this->outboundOrderId);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function writeScan(OutboundOrder $order, array $data): void
    {
        FulfillmentPackScan::create($data + [
            'tenant_id' => $order->tenant_id,
            'outbound_order_id' => $order->id,
            'quantity' => 1,
            'scanned_by_user_id' => Auth::id(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function acceptScan(OutboundOrder $order, array $line, string $barcodeScanned, string $normalized, int $quantity): void
    {
        $display = $this->lineDisplay($line, $normalized);
        $message = $quantity > 1
            ? __('fulfillment_pack.scanned_quantity_message', ['sku' => $display, 'quantity' => $quantity])
            : __('fulfillment_pack.scanned_message', ['sku' => $display]);

        $this->writeScan($order, [
            'sku_id' => $line['sku_id'],
            'stock_item_id' => $line['stock_item_id'],
            'barcode_scanned' => $barcodeScanned,
            'normalized_barcode' => $normalized,
            'result' => FulfillmentPackScan::RESULT_ACCEPTED,
            'quantity' => $quantity,
            'message' => $message,
        ]);

        $this->feedbackType = 'success';
        $this->feedbackMessage = $message;
        $this->lastScannedLineKey = $line['key'];
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function lineDisplay(array $line, string $fallback): string
    {
        return $line['sku']?->sku ?? $line['stock_item']?->code ?? $fallback;
    }

    private function clearPendingQuantity(): void
    {
        $this->pendingQuantityScan = null;
        $this->pendingQuantity = 1;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array<string, int>
     */
    private function progressSummary(OutboundOrder $order, array $lines): array
    {
        $lineCollection = collect($lines);

        return [
            'lines_complete' => $lineCollection->filter(fn (array $line): bool => $line['remaining_qty'] <= 0)->count(),
            'lines_total' => $lineCollection->count(),
            'qty_scanned' => (int) $lineCollection->sum('scanned_qty'),
            'qty_required' => (int) $lineCollection->sum('required_qty'),
            'qty_remaining' => (int) $lineCollection->sum('remaining_qty'),
            'exceptions' => FulfillmentPackScan::query()
                ->where('outbound_order_id', $order->id)
                ->where('result', '!=', FulfillmentPackScan::RESULT_ACCEPTED)
                ->count(),
        ];
    }

    private function error(string $message): void
    {
        $this->feedbackType = 'error';
        $this->feedbackMessage = $message;
    }

    private function focusScanner(): void
    {
        $this->dispatch('pack-scan-focus');
    }

    private function authorizeInternalUser(): void
    {
        if (Auth::user()?->user_type !== 'internal') {
            abort(403);
        }
    }

    private function allowedTenantIds(): array
    {
        if ($this->allowedTenantIdsResolved) {
            return $this->allowedTenantIdsCache;
        }

        $this->allowedTenantIdsResolved = true;

        return $this->allowedTenantIdsCache = Tenant::query()->pluck('id')->all();
    }
}
