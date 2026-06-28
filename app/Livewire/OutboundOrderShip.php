<?php

namespace App\Livewire;

use App\Models\OutboundOrder;
use App\Models\Tenant;
use App\Services\Outbound\ShipOutboundOrderService;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use Livewire\Component;

class OutboundOrderShip extends Component
{
    public int $orderId = 0;

    public string $courier = '';

    public string $trackingNo = '';

    public string $packageCount = '';

    public string $packageWeightKg = '';

    public string $shipNote = '';

    private bool $visibleTenantIdsResolved = false;

    private array $visibleTenantIdsCache = [];

    public function mount(OutboundOrder $order): void
    {
        if (! in_array($order->tenant_id, $this->visibleTenantIds(), true)) {
            abort(403);
        }

        $this->orderId = $order->id;

        if ($order->status !== OutboundOrder::STATUS_RESERVED) {
            session()->flash('error', __('outbound.already_processed'));
            $this->redirectRoute('outbound.index', navigate: true);

            return;
        }

        if ($order->hold_status === OutboundOrder::HOLD_STATUS_ON_HOLD) {
            session()->flash('error', __('outbound.cannot_ship_on_hold'));
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

        try {
            app(ShipOutboundOrderService::class)->ship($order, [
                'courier' => $this->courier,
                'tracking_no' => $this->trackingNo,
                'package_count' => $this->packageCount,
                'package_weight_g' => $this->packageWeightKg === '' ? null : (int) round(((float) $this->packageWeightKg) * 1000),
                'ship_note' => $this->shipNote,
            ]);
        } catch (InvalidArgumentException $exception) {
            session()->flash('error', $exception->getMessage());

            return;
        }

        session()->flash('status', __('outbound.order_shipped'));

        return redirect()->route('outbound.index');
    }

    public function render()
    {
        $order = OutboundOrder::whereIn('tenant_id', $this->visibleTenantIds())
            ->with([
                'tenant:id,code,name',
                'warehouse:id,code,name',
                'parentLines.sku:id,sku,sku_type,name',
                'parentLines.childLines.stockItem:id,code,name',
                'parentLines.stockItem:id,code,name',
            ])
            ->findOrFail($this->orderId);

        return view('livewire.outbound-order-ship', [
            'order' => $order,
        ])->layout('inventory', [
            'title' => __('outbound.ship_page_title'),
            'subtitle' => __('outbound.ship_page_subtitle'),
        ]);
    }

    private function validateInput(): void
    {
        validator([
            'courier' => $this->courier,
            'tracking_no' => $this->trackingNo,
            'package_count' => $this->packageCount,
            'package_weight_kg' => $this->packageWeightKg,
            'ship_note' => $this->shipNote,
        ], [
            'courier' => ['nullable', 'string', 'max:100'],
            'tracking_no' => ['nullable', 'string', 'max:255'],
            'package_count' => ['nullable', 'integer', 'min:1'],
            'package_weight_kg' => ['nullable', 'numeric', 'min:0'],
            'ship_note' => ['nullable', 'string', 'max:1000'],
        ])->validate();
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
