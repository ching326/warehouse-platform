<?php

namespace App\Livewire;

use App\Models\OutboundOrder;
use App\Models\Tenant;
use App\Services\InventoryService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Livewire\Component;

class OutboundOrderShip extends Component
{
    public int $orderId = 0;

    public string $courier = '';

    public string $shippingMethod = '';

    public string $trackingNo = '';

    public string $packageCount = '';

    public string $packageWeightG = '';

    public string $shipNote = '';

    private bool $visibleTenantIdsResolved = false;

    private array $visibleTenantIdsCache = [];

    public function mount(OutboundOrder $order): void
    {
        if (! in_array($order->tenant_id, $this->visibleTenantIds(), true)) {
            abort(403);
        }

        $this->orderId = $order->id;

        if ($order->status !== OutboundOrder::STATUS_PENDING) {
            session()->flash('error', __('outbound.already_processed'));
            $this->redirectRoute('outbound.index', navigate: true);

            return;
        }

        $this->shippingMethod = $order->shipping_method ?? '';
    }

    public function save()
    {
        $order = OutboundOrder::whereIn('tenant_id', $this->visibleTenantIds())
            ->findOrFail($this->orderId);

        if ($order->status !== OutboundOrder::STATUS_PENDING) {
            session()->flash('error', __('outbound.already_processed'));

            return redirect()->route('outbound.index');
        }

        $this->validateInput();

        try {
            DB::transaction(function () use ($order) {
                $order->load('leafLines');

                foreach ($order->leafLines as $line) {
                    $movement = app(InventoryService::class)->shipReservedStock(
                        tenantId: $order->tenant_id,
                        warehouseId: $order->warehouse_id,
                        stockItemId: $line->stock_item_id,
                        quantity: $line->qty,
                        context: [
                            'ref_type' => 'outbound_order',
                            'ref_id' => (string) $order->id,
                            'user_id' => Auth::id(),
                        ],
                    );

                    $line->inventory_movement_id = $movement->id;
                    $line->save();
                }

                $order->status = OutboundOrder::STATUS_SHIPPED;
                $order->shipped_at = now();
                $order->shipped_by_user_id = Auth::id();
                $order->shipping_method = $this->nullableString($this->shippingMethod);
                $order->courier = $this->nullableString($this->courier);
                $order->tracking_no = $this->nullableString($this->trackingNo);
                $order->package_count = $this->packageCount !== '' ? (int) $this->packageCount : null;
                $order->package_weight_g = $this->packageWeightG !== '' ? (int) $this->packageWeightG : null;
                $order->ship_note = $this->nullableString($this->shipNote);
                $order->save();
            });
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
            'shipping_method' => $this->shippingMethod,
            'tracking_no' => $this->trackingNo,
            'package_count' => $this->packageCount,
            'package_weight_g' => $this->packageWeightG,
            'ship_note' => $this->shipNote,
        ], [
            'courier' => ['nullable', 'string', 'max:100'],
            'shipping_method' => ['nullable', 'string', 'max:100'],
            'tracking_no' => ['nullable', 'string', 'max:255'],
            'package_count' => ['nullable', 'integer', 'min:1'],
            'package_weight_g' => ['nullable', 'integer', 'min:1'],
            'ship_note' => ['nullable', 'string', 'max:1000'],
        ])->validate();
    }

    // TODO: remove unauthenticated fallback when auth is implemented
    private function isInternalUser(): bool
    {
        $user = Auth::user();

        return ! $user || $user->user_type === 'internal';
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

        return $this->visibleTenantIdsCache = Auth::user()
            ->tenantUsers()
            ->where('status', 'active')
            ->pluck('tenant_id')
            ->all();
    }

    private function nullableString(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
