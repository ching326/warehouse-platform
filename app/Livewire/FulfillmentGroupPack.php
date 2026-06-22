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
        $matchedLine = collect($lines)->first(fn (array $line): bool => $service->lineMatchesScan($line, $normalized));

        if (! $matchedLine) {
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

        if ($matchedLine['remaining_qty'] <= 0) {
            $message = __('fulfillment_pack.over_scan');
            $this->writeScan($group, [
                'sku_id' => $matchedLine['sku_id'],
                'stock_item_id' => $matchedLine['stock_item_id'],
                'barcode_scanned' => $barcodeScanned,
                'normalized_barcode' => $normalized,
                'result' => FulfillmentPackScan::RESULT_OVER_SCAN,
                'message' => $message,
            ]);
            $this->error($message);
            $this->focusScanner();

            return;
        }

        $sku = $matchedLine['sku'];
        $message = __('fulfillment_pack.scanned_message', ['sku' => $sku?->sku ?? $matchedLine['stock_item']?->code ?? $normalized]);
        $this->writeScan($group, [
            'sku_id' => $matchedLine['sku_id'],
            'stock_item_id' => $matchedLine['stock_item_id'],
            'barcode_scanned' => $barcodeScanned,
            'normalized_barcode' => $normalized,
            'result' => FulfillmentPackScan::RESULT_ACCEPTED,
            'message' => $message,
        ]);

        $this->feedbackType = 'success';
        $this->feedbackMessage = $message;
        $this->focusScanner();
    }

    public function markShipped(FulfillmentPackService $packService, ShipOutboundOrderService $shipService): void
    {
        $this->authorizeInternalUser();

        $group = $this->loadGroup();

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
            'scanned_by_user_id' => Auth::id(),
        ]);
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
