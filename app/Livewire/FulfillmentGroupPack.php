<?php

namespace App\Livewire;

use App\Models\FulfillmentGroup;
use App\Models\FulfillmentPackScan;
use App\Models\Tenant;
use App\Services\Fulfillment\FulfillmentPackService;
use App\Services\Outbound\ShipOutboundOrderService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Livewire\Component;

class FulfillmentGroupPack extends Component
{
    public int $groupId = 0;

    public string $barcode = '';

    public ?string $feedbackMessage = null;

    public string $feedbackType = 'success';

    public string $packMode = 'normal';

    public ?array $pendingQuantityScan = null;

    public int $pendingQuantity = 1;

    private bool $allowedTenantIdsResolved = false;

    private array $allowedTenantIdsCache = [];

    public function mount(FulfillmentGroup $group): void
    {
        $this->authorizeInternalUser();

        $this->groupId = FulfillmentGroup::query()
            ->whereIn('tenant_id', $this->allowedTenantIds())
            ->findOrFail($group->id)
            ->id;
    }

    public function scan(FulfillmentPackService $service): void
    {
        $this->authorizeInternalUser();

        if ($this->pendingQuantityScan !== null) {
            $this->clearPendingQuantity();
        }

        $group = $this->loadGroup();
        $barcodeScanned = $this->barcode;
        $normalized = $service->normalizeProductBarcode($barcodeScanned);
        $this->barcode = '';

        if ($normalized === '') {
            $this->focusScanner();

            return;
        }

        if ($group->status !== FulfillmentGroup::STATUS_RESERVED) {
            $this->writeScan($group, [
                'barcode_scanned' => $barcodeScanned,
                'normalized_barcode' => $normalized,
                'result' => FulfillmentPackScan::RESULT_BLOCKED_STATUS,
                'message' => $group->status === FulfillmentGroup::STATUS_SHIPPED
                    ? __('fulfillment_pack.already_shipped')
                    : __('fulfillment_pack.cancelled_group'),
            ]);
            $this->error($group->status === FulfillmentGroup::STATUS_SHIPPED
                ? __('fulfillment_pack.already_shipped')
                : __('fulfillment_pack.cancelled_group'));
            $this->focusScanner();

            return;
        }

        $lines = $service->packLinesWithProgress($group);
        $matchedLines = collect($lines)
            ->filter(fn (array $line): bool => $service->lineMatchesScan($line, $normalized))
            ->values();

        if ($matchedLines->isEmpty()) {
            $message = __('fulfillment_pack.wrong_item');
            $this->writeScan($group, [
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
            $this->writeScan($group, [
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
            $this->feedbackType = 'success';
            $this->feedbackMessage = __('fulfillment_pack.quantity_prompt_message', [
                'sku' => $display,
                'remaining' => $matchedLine['remaining_qty'],
            ]);
            $this->dispatch('pack-quantity-focus');

            return;
        }

        $this->acceptScan($group, $matchedLine, $barcodeScanned, $normalized, 1);
        $this->focusScanner();
    }

    public function confirmPendingQuantity(FulfillmentPackService $service): void
    {
        $this->authorizeInternalUser();

        if ($this->pendingQuantityScan === null) {
            $this->focusScanner();

            return;
        }

        $group = $this->loadGroup();
        $pending = $this->pendingQuantityScan;

        if ($group->status !== FulfillmentGroup::STATUS_RESERVED) {
            $message = $group->status === FulfillmentGroup::STATUS_SHIPPED
                ? __('fulfillment_pack.already_shipped')
                : __('fulfillment_pack.cancelled_group');

            $this->writeScan($group, [
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

        $line = collect($service->packLinesWithProgress($group))
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
            $group,
            $line,
            (string) $pending['barcode_scanned'],
            (string) $pending['normalized_barcode'],
            $quantity
        );
        $this->clearPendingQuantity();
        $this->focusScanner();
    }

    public function cancelPendingQuantity(): void
    {
        $this->clearPendingQuantity();
        $this->focusScanner();
    }

    public function updatedPackMode(): void
    {
        if (! in_array($this->packMode, ['normal', 'strict'], true)) {
            $this->packMode = 'normal';
        }

        $this->clearPendingQuantity();
        $this->focusScanner();
    }

    public function markShipped(FulfillmentPackService $packService, ShipOutboundOrderService $shipService): void
    {
        $this->authorizeInternalUser();

        $group = $this->loadGroup();

        if ($this->pendingQuantityScan !== null) {
            $this->error(__('fulfillment_pack.confirm_or_cancel_quantity'));

            return;
        }

        if ($group->status !== FulfillmentGroup::STATUS_RESERVED) {
            $this->error(__('fulfillment_pack.already_shipped'));

            return;
        }

        if (! $packService->allLinesComplete($group)) {
            $this->error(__('fulfillment_pack.scan_all_before_shipping'));

            return;
        }

        if (! $group->outboundOrder) {
            $this->error(__('fulfillment_pack.outbound_missing'));

            return;
        }

        try {
            DB::transaction(function () use ($group, $packService, $shipService): void {
                $lockedGroup = FulfillmentGroup::query()
                    ->whereIn('tenant_id', $this->allowedTenantIds())
                    ->whereKey($group->id)
                    ->with('outboundOrder')
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($lockedGroup->status !== FulfillmentGroup::STATUS_RESERVED || ! $packService->allLinesComplete($lockedGroup)) {
                    throw new InvalidArgumentException(__('fulfillment_pack.scan_all_before_shipping'));
                }

                if (! $lockedGroup->outboundOrder) {
                    throw new InvalidArgumentException(__('fulfillment_pack.outbound_missing'));
                }

                $shipService->ship($lockedGroup->outboundOrder, [
                    'courier' => $lockedGroup->courier,
                    'shipping_method' => $lockedGroup->shippingMethod?->name,
                    'tracking_no' => $lockedGroup->tracking_no,
                ]);
            });
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return;
        }

        session()->flash('status', __('fulfillment_pack.pack_complete'));
        $this->redirectRoute('fulfillment-groups.show', $group, navigate: true);
    }

    public function render(FulfillmentPackService $service)
    {
        $this->authorizeInternalUser();

        $group = $this->loadGroup();
        $lines = $service->packLinesWithProgress($group);
        $allComplete = $lines !== [] && collect($lines)->every(fn (array $line): bool => $line['remaining_qty'] <= 0);

        return view('livewire.fulfillment-group-pack', [
            'group' => $group,
            'lines' => $lines,
            'allComplete' => $allComplete,
            'readOnly' => $group->status !== FulfillmentGroup::STATUS_RESERVED,
        ])->layout('inventory', [
            'title' => __('fulfillment_pack.page_title'),
            'subtitle' => $group->reference_no,
        ]);
    }

    private function loadGroup(): FulfillmentGroup
    {
        return FulfillmentGroup::query()
            ->whereIn('tenant_id', $this->allowedTenantIds())
            ->with([
                'tenant:id,code,name',
                'shippingMethod:id,name',
                'outboundOrder:id,fulfillment_group_id,status,tracking_no',
                'groupOrders.salesOrder.lines.sku.stockItem',
                'orders.lines.sku.stockItem',
                'orders.lines.sku.bundleComponents.componentStockItem',
            ])
            ->findOrFail($this->groupId);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function writeScan(FulfillmentGroup $group, array $data): void
    {
        FulfillmentPackScan::create($data + [
            'tenant_id' => $group->tenant_id,
            'fulfillment_group_id' => $group->id,
            'quantity' => 1,
            'scanned_by_user_id' => Auth::id(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function acceptScan(FulfillmentGroup $group, array $line, string $barcodeScanned, string $normalized, int $quantity): void
    {
        $display = $this->lineDisplay($line, $normalized);
        $message = $quantity > 1
            ? __('fulfillment_pack.scanned_quantity_message', ['sku' => $display, 'quantity' => $quantity])
            : __('fulfillment_pack.scanned_message', ['sku' => $display]);

        $this->writeScan($group, [
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
