<?php

namespace App\Livewire;

use App\Models\ReturnOrder;
use App\Models\ReturnOrderCost;
use App\Models\ReturnOrderLine;
use App\Models\Tenant;
use App\Models\WarehouseLocation;
use App\Services\InventoryService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Component;

class ReturnOrderDisposition extends Component
{
    public int $returnOrderId = 0;

    public array $lineDrafts = [];

    public string $cost_type = ReturnOrderCost::COST_OTHER;

    public string $cost_amount = '';

    public string $cost_note = '';

    public function mount(ReturnOrder $returnOrder): void
    {
        $this->staffOnly();
        $this->returnOrderId = $returnOrder->id;

        foreach ($returnOrder->lines as $line) {
            $this->lineDrafts[$line->id] = [
                'disposition' => $line->disposition,
                'disposition_location_id' => (string) $line->disposition_location_id,
            ];
        }
    }

    public function confirmDisposition(InventoryService $inventory)
    {
        $this->staffOnly();

        $order = $this->query()->with('lines')->findOrFail($this->returnOrderId);

        if (in_array($order->status, [ReturnOrder::STATUS_DISPOSITIONED, ReturnOrder::STATUS_CLOSED, ReturnOrder::STATUS_CANCELLED], true)) {
            session()->flash('status', __('return_orders.dispositioned'));

            return redirect()->route('return-orders.show', $order);
        }

        $validated = $this->validateDisposition($order);

        DB::transaction(function () use ($order, $inventory, $validated): void {
            $hasUndecided = false;

            foreach ($order->lines as $line) {
                if ($line->dispositioned_at && $line->disposition !== ReturnOrderLine::DISPOSITION_UNDECIDED) {
                    continue;
                }

                $draft = $validated['lineDrafts'][$line->id] ?? [];
                $disposition = $draft['disposition'] ?? ReturnOrderLine::DISPOSITION_UNDECIDED;

                $line->update([
                    'disposition' => $disposition,
                    'disposition_location_id' => $this->intOrNull($draft['disposition_location_id'] ?? ''),
                    'dispositioned_at' => $disposition === ReturnOrderLine::DISPOSITION_UNDECIDED ? null : now(),
                ]);

                if ($disposition === ReturnOrderLine::DISPOSITION_UNDECIDED) {
                    $hasUndecided = true;

                    continue;
                }

                $this->applyInventory($inventory, $order, $line->refresh());
            }

            if ($this->cost_amount !== '') {
                $order->costs()->create([
                    'tenant_id' => $order->tenant_id,
                    'cost_type' => $validated['cost_type'],
                    'amount' => $validated['cost_amount'],
                    'currency' => 'JPY',
                    'note' => $validated['cost_note'] ?: null,
                    'created_by_user_id' => Auth::id(),
                ]);
            }

            $order->update([
                'status' => $hasUndecided ? ReturnOrder::STATUS_AWAITING_DISPOSITION : ReturnOrder::STATUS_DISPOSITIONED,
                'dispositioned_at' => $hasUndecided ? null : now(),
                'dispositioned_by_user_id' => Auth::id(),
            ]);
        });

        session()->flash('status', __('return_orders.dispositioned'));

        return redirect()->route('return-orders.show', $order);
    }

    public function render()
    {
        $order = $this->query()
            ->with('lines.sku', 'lines.stockItem')
            ->findOrFail($this->returnOrderId);

        return view('livewire.return-order-disposition', [
            'order' => $order,
            'dispositions' => ReturnOrderLine::dispositionOptions(),
            'costTypes' => ReturnOrderCost::costTypeOptions(),
            'locations' => WarehouseLocation::query()
                ->where('warehouse_id', $order->warehouse_id)
                ->orderBy('code')
                ->get(['id', 'code', 'name']),
        ])->layout('inventory', [
            'title' => __('return_orders.disposition_page_title'),
            'subtitle' => $order->return_no,
        ]);
    }

    private function validateDisposition(ReturnOrder $order): array
    {
        return validator([
            'lineDrafts' => $this->lineDrafts,
            'cost_type' => $this->cost_type,
            'cost_amount' => $this->cost_amount,
            'cost_note' => $this->cost_note,
        ], [
            'lineDrafts' => ['array'],
            'lineDrafts.*.disposition' => ['required', Rule::in(array_keys(ReturnOrderLine::dispositionOptions()))],
            'lineDrafts.*.disposition_location_id' => [
                'nullable',
                'integer',
                Rule::exists('warehouse_locations', 'id')->where('warehouse_id', $order->warehouse_id),
            ],
            'cost_type' => ['required', Rule::in(array_keys(ReturnOrderCost::costTypeOptions()))],
            'cost_amount' => ['nullable', 'numeric', 'min:0'],
            'cost_note' => ['nullable', 'string'],
        ])->validate();
    }

    private function applyInventory(InventoryService $inventory, ReturnOrder $order, ReturnOrderLine $line): void
    {
        $quantity = (int) $line->received_qty;

        if ($quantity <= 0 || ! $line->stock_item_id || ! $order->warehouse_id) {
            return;
        }

        $context = [
            'ref_type' => 'return_order',
            'ref_id' => (string) $order->id,
            'user_id' => Auth::id(),
        ];

        if ($line->disposition === ReturnOrderLine::DISPOSITION_RETURN_TO_INVENTORY) {
            $inventory->receiveStock($order->tenant_id, $order->warehouse_id, $line->stock_item_id, $quantity, $context);
        } elseif ($line->disposition === ReturnOrderLine::DISPOSITION_MARK_DAMAGED) {
            $inventory->receiveStock($order->tenant_id, $order->warehouse_id, $line->stock_item_id, $quantity, $context);
            $inventory->markDamaged($order->tenant_id, $order->warehouse_id, $line->stock_item_id, $quantity, $context);
        } elseif ($line->disposition === ReturnOrderLine::DISPOSITION_HOLD_QUARANTINE) {
            $inventory->receiveStock($order->tenant_id, $order->warehouse_id, $line->stock_item_id, $quantity, $context);
            $inventory->placeHold($order->tenant_id, $order->warehouse_id, $line->stock_item_id, $quantity, $context);
        }
    }

    private function query()
    {
        return ReturnOrder::query()->whereIn('tenant_id', $this->allowedTenantIds());
    }

    private function isInternalUser(): bool
    {
        return Auth::user()?->user_type === 'internal';
    }

    private function staffOnly(): void
    {
        if (! $this->isInternalUser()) {
            abort(403);
        }
    }

    private function allowedTenantIds(): array
    {
        return $this->isInternalUser()
            ? Tenant::query()->pluck('id')->all()
            : (Auth::user()?->activeTenantIds() ?? []);
    }

    private function intOrNull($value): ?int
    {
        $value = trim((string) $value);

        return $value === '' ? null : (int) $value;
    }
}
