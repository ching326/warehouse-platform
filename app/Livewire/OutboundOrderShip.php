<?php

namespace App\Livewire;

use App\Models\OutboundOrder;
use App\Models\ShippingMethod;
use App\Models\Tenant;
use App\Services\Outbound\HoldOutboundOrderService;
use App\Services\Outbound\ShipOutboundOrderService;
use App\Support\BulkActionMessage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Livewire\Component;

class OutboundOrderShip extends Component
{
    public int $orderId = 0;

    public string $shippingMethodId = '';

    public string $trackingNo = '';

    public string $packageCount = '';

    public string $packageWeightKg = '';

    public string $courierCost = '';

    public string $courierCostCurrency = 'JPY';

    public string $shipNote = '';

    public bool $pendingPrintedHoldConfirmation = false;

    public ?string $pendingHoldWarning = null;

    private bool $visibleTenantIdsResolved = false;

    private array $visibleTenantIdsCache = [];

    public function mount(OutboundOrder $order): void
    {
        if (! in_array($order->tenant_id, $this->visibleTenantIds(), true)) {
            abort(403);
        }

        $this->orderId = $order->id;
        $this->shippingMethodId = (string) ($order->shipping_method_id ?? '');

        if ($order->status !== OutboundOrder::STATUS_RESERVED) {
            session()->flash('error', __('outbound.already_processed'));
            $this->redirectRoute('outbound.index', navigate: true);

            return;
        }

    }

    public function save()
    {
        $order = OutboundOrder::whereIn('tenant_id', $this->visibleTenantIds())
            ->findOrFail($this->orderId);

        if ($order->status !== OutboundOrder::STATUS_RESERVED) {
            session()->flash('error', __('outbound.already_processed'));

            return redirect()->route('outbound.index');
        }

        if ($order->hold_status === OutboundOrder::HOLD_STATUS_ON_HOLD) {
            session()->flash('error', __('outbound.cannot_ship_on_hold'));

            return redirect()->route('outbound.index');
        }

        $this->validateInput();
        $this->courierCostCurrency = strtoupper(trim($this->courierCostCurrency));

        try {
            app(ShipOutboundOrderService::class)->ship($order, [
                'shipping_method_id' => $this->shippingMethodId,
                'tracking_no' => $this->trackingNo,
                'package_count' => $this->packageCount,
                'package_weight_g' => $this->packageWeightKg === '' ? null : (int) round(((float) $this->packageWeightKg) * 1000),
                'courier_cost' => $this->courierCost,
                'courier_cost_currency' => $this->courierCost === '' ? null : $this->courierCostCurrency,
                'ship_note' => $this->shipNote,
            ]);
        } catch (InvalidArgumentException $exception) {
            session()->flash('error', $exception->getMessage());

            return;
        }

        session()->flash('status', __('outbound.order_shipped'));
    }

    public function holdOutbound(HoldOutboundOrderService $service): void
    {
        $order = OutboundOrder::whereIn('tenant_id', $this->visibleTenantIds())
            ->findOrFail($this->orderId);

        try {
            $result = $service->holdOutbound(
                outbound: $order,
                source: 'direct_ship',
                confirmedPrinted: false,
            );
        } catch (InvalidArgumentException $exception) {
            session()->flash('error', $exception->getMessage());

            return;
        }

        if ($result->requiresConfirmation) {
            $this->pendingPrintedHoldConfirmation = true;
            $this->pendingHoldWarning = __('outbound.hold_printed_confirm_body');

            return;
        }

        session()->flash('status', BulkActionMessage::make('fulfillment.batch_hold_result', 1, 0));
    }

    public function confirmPrintedHold(HoldOutboundOrderService $service): void
    {
        $this->pendingPrintedHoldConfirmation = false;
        $this->pendingHoldWarning = null;

        try {
            $service->holdOutbound(
                outbound: OutboundOrder::whereIn('tenant_id', $this->visibleTenantIds())->findOrFail($this->orderId),
                source: 'direct_ship',
                confirmedPrinted: true,
            );
        } catch (InvalidArgumentException $exception) {
            session()->flash('error', $exception->getMessage());

            return;
        }

        session()->flash('status', BulkActionMessage::make('fulfillment.batch_hold_result', 1, 0));
    }

    public function cancelPrintedHold(): void
    {
        $this->pendingPrintedHoldConfirmation = false;
        $this->pendingHoldWarning = null;
    }

    public function releaseHold(HoldOutboundOrderService $service): void
    {
        try {
            $service->releaseOutbound(
                OutboundOrder::whereIn('tenant_id', $this->visibleTenantIds())->findOrFail($this->orderId),
                source: 'direct_ship',
            );
        } catch (InvalidArgumentException $exception) {
            session()->flash('error', $exception->getMessage());

            return;
        }

        session()->flash('status', BulkActionMessage::make('fulfillment.batch_release_hold_result', 1, 0));
    }

    public function render()
    {
        $order = OutboundOrder::whereIn('tenant_id', $this->visibleTenantIds())
            ->with([
                'tenant:id,code,name',
                'warehouse:id,code,name',
                'shippingMethod:id,name,status',
                'parentLines.sku:id,sku,sku_type,name',
                'parentLines.childLines.stockItem:id,code,name',
                'parentLines.stockItem:id,code,name',
            ])
            ->findOrFail($this->orderId);

        return view('livewire.outbound-order-ship', [
            'order' => $order,
            'shippingMethods' => $this->shippingMethodOptions($order),
        ])->layout('inventory', [
            'title' => __('outbound.direct_pack_page_title'),
            'subtitle' => __('outbound.direct_pack_page_subtitle'),
        ]);
    }

    private function validateInput(): void
    {
        $currentShippingMethodId = OutboundOrder::query()
            ->whereKey($this->orderId)
            ->value('shipping_method_id');

        validator([
            'shipping_method_id' => $this->shippingMethodId,
            'tracking_no' => $this->trackingNo,
            'package_count' => $this->packageCount,
            'package_weight_kg' => $this->packageWeightKg,
            'courier_cost' => $this->courierCost,
            'courier_cost_currency' => $this->courierCostCurrency,
            'ship_note' => $this->shipNote,
        ], [
            'shipping_method_id' => [
                'nullable',
                'integer',
                Rule::exists('shipping_methods', 'id')->where(function ($query) use ($currentShippingMethodId) {
                    $query->where('status', 'active')
                        ->when($currentShippingMethodId, fn ($query) => $query->orWhere('id', $currentShippingMethodId));
                }),
            ],
            'tracking_no' => ['nullable', 'string', 'max:255'],
            'package_count' => ['nullable', 'integer', 'min:1'],
            'package_weight_kg' => ['nullable', 'numeric', 'min:0'],
            'courier_cost' => ['nullable', 'numeric', 'min:0'],
            'courier_cost_currency' => ['required_with:courier_cost', 'nullable', 'string', 'size:3'],
            'ship_note' => ['nullable', 'string', 'max:1000'],
        ])->validate();
    }

    private function shippingMethodOptions(OutboundOrder $order): Collection
    {
        return ShippingMethod::query()
            ->with('carrier:id,code,name')
            ->where(function ($query) use ($order) {
                $query->where('shipping_methods.status', 'active')
                    ->when($order->shipping_method_id, fn ($query) => $query->orWhere('shipping_methods.id', $order->shipping_method_id));
            })
            ->ordered()
            ->get(['shipping_methods.id', 'shipping_methods.carrier_id', 'shipping_methods.code', 'shipping_methods.name', 'shipping_methods.status']);
    }

    private function isInternalUser(): bool
    {
        $user = Auth::user();

        return $user?->user_type === 'internal';
    }

    private function visibleTenantIds(): array
    {
        if ($this->visibleTenantIdsResolved) {
            return $this->visibleTenantIdsCache;
        }

        $this->visibleTenantIdsResolved = true;

        if ($this->isInternalUser()) {
            return $this->visibleTenantIdsCache = Tenant::query()->pluck('id')->all();
        }

        $user = Auth::user();

        if (! $user) {
            return $this->visibleTenantIdsCache = [];
        }

        return $this->visibleTenantIdsCache = $user->activeTenantIds();
    }
}
