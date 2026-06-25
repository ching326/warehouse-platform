<?php

namespace App\Livewire;

use App\Models\SalesOrder;
use App\Models\Tenant;
use App\Models\Warehouse;
use App\Services\Fulfillment\OutboundConsolidationService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Component;

class FulfillmentCreate extends Component
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
            $outbound = app(OutboundConsolidationService::class)->createGroup(
                tenantId: $tenantId,
                warehouseId: (int) $this->warehouseId,
                salesOrderIds: $this->selectedOrderIds,
            );
        } catch (InvalidArgumentException $exception) {
            session()->flash('error', $exception->getMessage());

            return;
        }

        session()->flash('status', __('fulfillment.group_created'));

        return redirect()->route('outbound.show', $outbound);
    }

    public function render()
    {
        return view('livewire.fulfillment-create', [
            'tenants' => $this->tenantOptions(),
            'warehouses' => $this->warehouseOptions(),
            'shipKeyOptions' => $this->shipKeyOptions(),
            'eligibleOrders' => $this->eligibleOrders(),
            'showTenantSelect' => $this->isInternalUser(),
        ])->layout('inventory', [
            'title' => __('fulfillment.create_page_title'),
            'subtitle' => __('fulfillment.create_page_subtitle'),
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

    private function selectedTenantIsAllowed(): bool
    {
        return $this->tenantId !== ''
            && in_array((int) $this->tenantId, $this->allowedTenantIds(), true);
    }

    private function validatedTenantId(): int
    {
        $tenantId = (int) $this->tenantId;

        if ($tenantId <= 0 || ! in_array($tenantId, $this->allowedTenantIds(), true)) {
            throw ValidationException::withMessages(['tenant_id' => __('fulfillment.invalid_tenant')]);
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

        return $this->allowedTenantIdsCache = $user->activeTenantIds();
    }

    private function authorizeTenantAccess(): void
    {
        if (! $this->isInternalUser() && $this->allowedTenantIds() === []) {
            abort(403);
        }
    }
}
