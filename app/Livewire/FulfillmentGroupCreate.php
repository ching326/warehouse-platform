<?php

namespace App\Livewire;

use App\Models\FulfillmentGroup;
use App\Models\OutboundOrder;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\Tenant;
use App\Models\Warehouse;
use App\Services\InventoryService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Component;

class FulfillmentGroupCreate extends Component
{
    public string $tenantId = '';

    public string $warehouseId = '';

    public string $shipKey = '';

    public array $selectedOrderIds = [];

    private bool $allowedTenantIdsResolved = false;

    private array $allowedTenantIdsCache = [];

    public function mount(): void
    {
        $this->authorizeTenantAccess();

        if (! $this->isInternalUser()) {
            $this->tenantId = (string) ($this->allowedTenantIds()[0] ?? '');
        }
    }

    public function updatedTenantId(): void
    {
        $this->shipKey = '';
        $this->selectedOrderIds = [];
    }

    public function updatedShipKey(): void
    {
        $this->selectedOrderIds = $this->eligibleOrders()
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->all();
    }

    public function save()
    {
        $tenantId = $this->validatedTenantId();
        $this->validateInput($tenantId);

        try {
            $group = DB::transaction(function () use ($tenantId) {
                $orders = SalesOrder::query()
                    ->whereIn('id', $this->selectedOrderIds)
                    ->where('tenant_id', $tenantId)
                    ->with('lines.sku.bundleComponents.componentStockItem')
                    ->lockForUpdate()
                    ->get();

                if ($orders->count() !== count(array_unique($this->selectedOrderIds))) {
                    throw ValidationException::withMessages([
                        'selectedOrderIds' => __('fulfillment_groups.orders_required'),
                    ]);
                }

                foreach ($orders as $order) {
                    if (
                        $order->order_status !== SalesOrder::ORDER_STATUS_PENDING
                        || $order->fulfillment_status !== SalesOrder::FULFILLMENT_STATUS_READY
                    ) {
                        throw ValidationException::withMessages([
                            'selectedOrderIds' => __('fulfillment_groups.order_no_longer_ready', ['id' => $order->id]),
                        ]);
                    }
                }

                $keys = $orders->pluck('ship_together_key')->unique();
                if ($keys->count() !== 1 || $keys->first() === null || $keys->first() !== $this->shipKey) {
                    throw ValidationException::withMessages([
                        'selectedOrderIds' => __('fulfillment_groups.orders_must_share_key'),
                    ]);
                }

                $firstOrder = $orders->firstOrFail();
                $group = FulfillmentGroup::create([
                    'tenant_id' => $tenantId,
                    'warehouse_id' => (int) $this->warehouseId,
                    'reference_no' => 'FG-PENDING-'.Str::uuid(),
                    'status' => FulfillmentGroup::STATUS_RESERVED,
                    'ship_together_key' => $firstOrder->ship_together_key,
                    'recipient_name' => $firstOrder->recipient_name,
                    'recipient_phone' => $firstOrder->recipient_phone,
                    'recipient_country_code' => $firstOrder->recipient_country_code,
                    'recipient_postal_code' => $firstOrder->recipient_postal_code,
                    'recipient_state' => $firstOrder->recipient_state,
                    'recipient_city' => $firstOrder->recipient_city,
                    'recipient_address_line1' => $firstOrder->recipient_address_line1,
                    'recipient_address_line2' => $firstOrder->recipient_address_line2,
                    'created_by_user_id' => Auth::id(),
                ]);
                $group->update(['reference_no' => FulfillmentGroup::buildReferenceNo($group->id)]);
                $group->orders()->attach($orders->pluck('id')->all());

                [$bySkuAndItem, $byStockItem] = $this->aggregateLines($orders);

                $outbound = OutboundOrder::create([
                    'fulfillment_group_id' => $group->id,
                    'tenant_id' => $tenantId,
                    'warehouse_id' => (int) $this->warehouseId,
                    'ref' => $group->reference_no,
                    'status' => OutboundOrder::STATUS_PENDING,
                    'recipient_name' => $firstOrder->recipient_name,
                    'recipient_phone' => $firstOrder->recipient_phone,
                    'recipient_country_code' => $firstOrder->recipient_country_code,
                    'recipient_postal_code' => $firstOrder->recipient_postal_code,
                    'recipient_state' => $firstOrder->recipient_state,
                    'recipient_city' => $firstOrder->recipient_city,
                    'recipient_address_line1' => $firstOrder->recipient_address_line1,
                    'recipient_address_line2' => $firstOrder->recipient_address_line2,
                    'created_by_user_id' => Auth::id(),
                ]);

                foreach ($byStockItem as $stockItemId => $totalQty) {
                    app(InventoryService::class)->reserveStock(
                        tenantId: $tenantId,
                        warehouseId: (int) $this->warehouseId,
                        stockItemId: (int) $stockItemId,
                        quantity: (int) $totalQty,
                        context: [
                            'ref_type' => 'fulfillment_group',
                            'ref_id' => (string) $group->id,
                            'user_id' => Auth::id(),
                        ],
                    );
                }

                foreach ($bySkuAndItem as $line) {
                    $outboundLine = $outbound->lines()->create([
                        'tenant_id' => $tenantId,
                        'sku_id' => $line['sku_id'],
                        'stock_item_id' => $line['stock_item_id'],
                        'qty' => $line['qty'],
                        'inventory_movement_id' => null,
                    ]);

                    foreach ($line['children'] as $childLine) {
                        $outbound->lines()->create([
                            'parent_line_id' => $outboundLine->id,
                            'tenant_id' => $tenantId,
                            'sku_id' => $childLine['sku_id'],
                            'stock_item_id' => $childLine['stock_item_id'],
                            'qty' => $childLine['qty'],
                            'inventory_movement_id' => null,
                        ]);
                    }
                }

                SalesOrder::query()
                    ->whereIn('id', $orders->pluck('id')->all())
                    ->update(['fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_IN_GROUP]);

                return $group;
            });
        } catch (InvalidArgumentException $exception) {
            session()->flash('error', $exception->getMessage());

            return;
        }

        session()->flash('status', __('fulfillment_groups.group_created'));

        return redirect()->route('fulfillment-groups.show', $group);
    }

    public function render()
    {
        return view('livewire.fulfillment-group-create', [
            'tenants' => $this->tenantOptions(),
            'warehouses' => $this->warehouseOptions(),
            'shipKeyOptions' => $this->shipKeyOptions(),
            'eligibleOrders' => $this->eligibleOrders(),
            'showTenantSelect' => $this->isInternalUser(),
        ])->layout('inventory', [
            'title' => __('fulfillment_groups.create_page_title'),
            'subtitle' => __('fulfillment_groups.create_page_subtitle'),
        ]);
    }

    private function validateInput(int $tenantId): void
    {
        validator([
            'tenant_id' => $this->tenantId,
            'warehouse_id' => $this->warehouseId,
            'ship_key' => $this->shipKey,
            'selected_order_ids' => $this->selectedOrderIds,
        ], [
            'tenant_id' => ['required', 'integer', Rule::in($this->allowedTenantIds())],
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('status', 'active')],
            'ship_key' => ['required', 'string'],
            'selected_order_ids' => ['required', 'array', 'min:1'],
            'selected_order_ids.*' => [
                'required',
                'integer',
                Rule::exists('sales_orders', 'id')->where('tenant_id', $tenantId),
            ],
        ])->validate();
    }

    private function tenantOptions(): Collection
    {
        return Tenant::query()
            ->whereIn('id', $this->allowedTenantIds())
            ->orderBy('name')
            ->get(['id', 'code', 'name']);
    }

    private function warehouseOptions(): Collection
    {
        return Warehouse::query()
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'code', 'name']);
    }

    private function shipKeyOptions(): Collection
    {
        if (! $this->selectedTenantIsAllowed()) {
            return collect();
        }

        return SalesOrder::query()
            ->whereIn('tenant_id', $this->allowedTenantIds())
            ->where('tenant_id', (int) $this->tenantId)
            ->where('order_status', SalesOrder::ORDER_STATUS_PENDING)
            ->where('fulfillment_status', SalesOrder::FULFILLMENT_STATUS_READY)
            ->whereNotNull('ship_together_key')
            ->selectRaw('ship_together_key, min(recipient_name) as recipient_name, min(recipient_city) as recipient_city, count(*) as order_count')
            ->groupBy('ship_together_key')
            ->orderBy('recipient_name')
            ->get();
    }

    private function eligibleOrders(): Collection
    {
        if (! $this->selectedTenantIsAllowed() || $this->shipKey === '') {
            return collect();
        }

        return SalesOrder::query()
            ->whereIn('tenant_id', $this->allowedTenantIds())
            ->where('tenant_id', (int) $this->tenantId)
            ->where('order_status', SalesOrder::ORDER_STATUS_PENDING)
            ->where('fulfillment_status', SalesOrder::FULFILLMENT_STATUS_READY)
            ->where('ship_together_key', $this->shipKey)
            ->withCount('lines')
            ->orderBy('created_at')
            ->get();
    }

    /**
     * @return array{0: array<string,array{sku_id:int,stock_item_id:?int,qty:int,children:array<int,array{sku_id:int,stock_item_id:int,qty:int}>}>, 1: array<int,int>}
     */
    private function aggregateLines(Collection $orders): array
    {
        $outboundLines = [];
        $byStockItem = [];

        foreach ($orders as $order) {
            foreach ($order->lines as $line) {
                if ($line->line_status !== SalesOrderLine::STATUS_READY) {
                    continue;
                }

                $sku = $line->sku;
                if (! $sku) {
                    continue;
                }

                if ($sku->sku_type === 'virtual_bundle') {
                    if ($sku->bundleComponents->isEmpty()) {
                        throw ValidationException::withMessages([
                            'selectedOrderIds' => __('outbound.bundle_no_components'),
                        ]);
                    }

                    $parentKey = 'bundle:'.$sku->id;
                    $outboundLines[$parentKey] ??= [
                        'sku_id' => $sku->id,
                        'stock_item_id' => null,
                        'qty' => 0,
                        'children' => [],
                    ];
                    $outboundLines[$parentKey]['qty'] += (int) $line->quantity;

                    foreach ($sku->bundleComponents as $component) {
                        if (
                            $component->tenant_id !== $order->tenant_id
                            || ! $component->componentStockItem
                            || $component->componentStockItem->tenant_id !== $order->tenant_id
                        ) {
                            throw ValidationException::withMessages([
                                'selectedOrderIds' => __('outbound.bundle_invalid_tenant'),
                            ]);
                        }

                        $componentQty = (int) $line->quantity * (int) $component->quantity;
                        $stockItemId = (int) $component->component_stock_item_id;

                        $outboundLines[$parentKey]['children'][$stockItemId] ??= [
                            'sku_id' => $sku->id,
                            'stock_item_id' => $stockItemId,
                            'qty' => 0,
                        ];
                        $outboundLines[$parentKey]['children'][$stockItemId]['qty'] += $componentQty;
                        $byStockItem[$stockItemId] = ($byStockItem[$stockItemId] ?? 0) + $componentQty;
                    }

                    continue;
                }

                if (! $sku->stock_item_id) {
                    throw ValidationException::withMessages([
                        'selectedOrderIds' => __('skus.missing_stock_item'),
                    ]);
                }

                $key = $sku->id.':'.$sku->stock_item_id;
                $outboundLines[$key] ??= [
                    'sku_id' => $sku->id,
                    'stock_item_id' => $sku->stock_item_id,
                    'qty' => 0,
                    'children' => [],
                ];
                $outboundLines[$key]['qty'] += (int) $line->quantity;
                $byStockItem[$sku->stock_item_id] = ($byStockItem[$sku->stock_item_id] ?? 0) + (int) $line->quantity;
            }
        }

        return [$outboundLines, $byStockItem];
    }

    private function selectedTenantIsAllowed(): bool
    {
        return $this->tenantId !== ''
            && in_array((int) $this->tenantId, $this->allowedTenantIds(), true);
    }

    private function validatedTenantId(): int
    {
        $tenantId = (int) $this->tenantId;

        if ($tenantId <= 0 || ! in_array($tenantId, $this->allowedTenantIds(), true)) {
            throw ValidationException::withMessages(['tenant_id' => __('fulfillment_groups.invalid_tenant')]);
        }

        return $tenantId;
    }
    private function isInternalUser(): bool
    {
        $user = Auth::user();

        return $user?->user_type === 'internal';
    }

    private function allowedTenantIds(): array
    {
        if ($this->allowedTenantIdsResolved) {
            return $this->allowedTenantIdsCache;
        }

        $this->allowedTenantIdsResolved = true;

        if ($this->isInternalUser()) {
            return $this->allowedTenantIdsCache = Tenant::query()->pluck('id')->all();
        }

        $user = Auth::user();

        if (! $user) {
            return $this->allowedTenantIdsCache = [];
        }

        return $this->allowedTenantIdsCache = $user
            ->tenantUsers()
            ->where('status', 'active')
            ->pluck('tenant_id')
            ->all();
    }

    private function authorizeTenantAccess(): void
    {
        if (! $this->isInternalUser() && $this->allowedTenantIds() === []) {
            abort(403);
        }
    }
}
