<?php

namespace App\Livewire;

use App\Models\InboundOrder;
use App\Models\InboundReceipt;
use App\Models\Tenant;
use App\Models\WarehouseLocation;
use App\Services\InventoryService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Component;

class InboundOrderReceive extends Component
{
    public int $orderId;

    public array $lineInputs = [];

    public function mount(InboundOrder $order): void
    {
        if (! in_array($order->tenant_id, $this->visibleTenantIds(), true)) {
            abort(403);
        }

        if (! in_array($order->status, [InboundOrder::STATUS_ARRIVED, InboundOrder::STATUS_PARTIALLY_RECEIVED], true)) {
            abort(403);
        }

        $this->orderId = $order->id;
        $order->load('lines');

        foreach ($order->lines as $line) {
            $remaining = $line->expected_qty - $line->received_qty;

            if ($remaining <= 0) {
                continue;
            }

            $this->lineInputs[$line->id] = [
                'actual_qty' => (string) $remaining,
                'location_id' => '',
            ];
        }
    }

    public function save()
    {
        $order = InboundOrder::query()
            ->with('lines')
            ->findOrFail($this->orderId);

        if (! in_array($order->tenant_id, $this->visibleTenantIds(), true)) {
            abort(403);
        }

        if (! in_array($order->status, [InboundOrder::STATUS_ARRIVED, InboundOrder::STATUS_PARTIALLY_RECEIVED], true)) {
            abort(403);
        }

        $this->validateInput($order);

        try {
            DB::transaction(function () use ($order) {
                foreach ($order->lines as $line) {
                    $remaining = $line->expected_qty - $line->received_qty;

                    if ($remaining <= 0) {
                        continue;
                    }

                    $actualQty = (int) ($this->lineInputs[$line->id]['actual_qty'] ?? 0);

                    if ($actualQty <= 0) {
                        continue;
                    }

                    $locationId = (int) ($this->lineInputs[$line->id]['location_id'] ?? 0);
                    $note = $line->note ?? $order->note;
                    $movement = app(InventoryService::class)->receiveStock(
                        $order->tenant_id,
                        $order->warehouse_id,
                        $line->stock_item_id,
                        $actualQty,
                        [
                            'ref_type' => 'inbound_order',
                            'ref_id' => (string) $order->id,
                            'user_id' => Auth::id(),
                            'note' => $note,
                        ],
                    );

                    InboundReceipt::create([
                        'inbound_order_id' => $order->id,
                        'inbound_order_line_id' => $line->id,
                        'tenant_id' => $order->tenant_id,
                        'warehouse_id' => $order->warehouse_id,
                        'warehouse_location_id' => $locationId,
                        'sku_id' => $line->sku_id,
                        'stock_item_id' => $line->stock_item_id,
                        'inventory_movement_id' => $movement->id,
                        'received_qty' => $actualQty,
                        'received_by_user_id' => Auth::id(),
                        'received_at' => now(),
                        'note' => $note,
                    ]);

                    $line->update([
                        'received_qty' => $line->received_qty + $actualQty,
                    ]);
                }

                $order->refresh();
                $order->load('lines');

                $allDone = $order->lines->every(fn ($line) => $line->received_qty >= $line->expected_qty);
                $anyDone = $order->lines->contains(fn ($line) => $line->received_qty > 0);
                $newStatus = match (true) {
                    $allDone => InboundOrder::STATUS_RECEIVED,
                    $anyDone => InboundOrder::STATUS_PARTIALLY_RECEIVED,
                    default => $order->status,
                };

                $order->update([
                    'status' => $newStatus,
                    'received_at' => $allDone ? now() : $order->received_at,
                    'received_by_user_id' => $allDone ? Auth::id() : $order->received_by_user_id,
                ]);
            });
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['lineInputs' => $exception->getMessage()]);
        }

        session()->flash('status', __('inbound.order_received'));

        return redirect()->route('inbound.index');
    }

    public function render()
    {
        $order = InboundOrder::query()
            ->with(['tenant:id,code,name', 'warehouse:id,code,name', 'lines.sku:id,sku,name', 'lines.stockItem:id,code,name'])
            ->findOrFail($this->orderId);

        return view('livewire.inbound-order-receive', [
            'order' => $order,
            'locationOptions' => $this->locationOptions($order->warehouse_id),
        ])->layout('inventory', [
            'title' => __('inbound.receive_page_title'),
            'subtitle' => __('inbound.receive_page_subtitle'),
        ]);
    }

    private function validateInput(InboundOrder $order): void
    {
        $rules = [];

        foreach ($order->lines as $line) {
            $remaining = $line->expected_qty - $line->received_qty;

            if ($remaining <= 0) {
                continue;
            }

            $lineId = $line->id;
            $rules["lineInputs.{$lineId}.actual_qty"] = [
                'required',
                'integer',
                'min:0',
                "max:{$remaining}",
            ];
            $rules["lineInputs.{$lineId}.location_id"] = [
                Rule::requiredIf(fn () => (int) ($this->lineInputs[$lineId]['actual_qty'] ?? 0) > 0),
                'nullable',
                'integer',
                Rule::exists('warehouse_locations', 'id')
                    ->where('warehouse_id', $order->warehouse_id)
                    ->where('status', 'active'),
            ];
        }

        validator(['lineInputs' => $this->lineInputs], $rules)->validate();
    }

    // TODO: remove unauthenticated fallback when auth is implemented
    private function isInternalUser(): bool
    {
        $user = Auth::user();

        return ! $user || $user->user_type === 'internal';
    }

    private function visibleTenantIds(): array
    {
        if ($this->isInternalUser()) {
            return Tenant::query()->pluck('id')->all();
        }

        return Auth::user()
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

    private function locationOptions(int $warehouseId): Collection
    {
        return WarehouseLocation::query()
            ->where('warehouse_id', $warehouseId)
            ->where('status', 'active')
            ->orderBy('code')
            ->get(['id', 'code', 'name']);
    }
}
