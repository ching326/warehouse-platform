<?php

namespace App\Livewire;

use App\Models\InventoryBalance;
use App\Models\StockItem;
use App\Models\Tenant;
use App\Models\Warehouse;
use App\Services\InventoryService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Attributes\Url;
use Livewire\Component;

class StockAdjustmentCreate extends Component
{
    #[Url(as: 'tenant_id', except: '')]
    public string $tenantId = '';

    #[Url(as: 'warehouse_id', except: '')]
    public string $warehouseId = '';

    #[Url(as: 'stock_item_id', except: '')]
    public string $stockItemId = '';

    public string $stockItemSearch = '';

    public string $quantity = '';

    public string $note = '';

    public string $refId = '';

    public function mount(): void
    {
        if (! $this->isInternalUser() && $this->tenantId === '') {
            $this->tenantId = (string) ($this->activeTenantIds()[0] ?? '');
        }
    }

    public function updatedTenantId(): void
    {
        $this->warehouseId = '';
        $this->stockItemId = '';
        $this->stockItemSearch = '';
    }

    public function updatedWarehouseId(): void
    {
        $this->stockItemId = '';
        $this->stockItemSearch = '';
    }

    public function currentBalance(): ?InventoryBalance
    {
        if ($this->tenantId === '' || $this->warehouseId === '' || $this->stockItemId === '') {
            return null;
        }

        return InventoryBalance::query()
            ->where('tenant_id', $this->tenantId)
            ->where('warehouse_id', $this->warehouseId)
            ->where('stock_item_id', $this->stockItemId)
            ->first();
    }

    public function save()
    {
        $tenantId = $this->validatedTenantId();
        $this->validateInput($tenantId);

        try {
            app(InventoryService::class)->adjustStock(
                $tenantId,
                (int) $this->warehouseId,
                (int) $this->stockItemId,
                (int) $this->quantity,
                [
                    'ref_type' => 'manual_adjustment',
                    'ref_id' => $this->nullableString($this->refId),
                    'user_id' => Auth::id(),
                    'note' => $this->nullableString($this->note),
                ],
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['quantity' => $exception->getMessage()]);
        }

        session()->flash('status', __('stock_adjustments.adjustment_saved'));

        return redirect()->route('inventory.index');
    }

    public function render()
    {
        return view('livewire.stock-adjustment-create', [
            'tenants' => $this->tenantOptions(),
            'warehouses' => $this->warehouseOptions(),
            'stockItems' => $this->stockItemOptions(),
            'currentBalance' => $this->currentBalance(),
            'showTenantSelect' => $this->isInternalUser(),
            'currentTenant' => $this->currentTenant(),
        ])->layout('inventory', [
            'title' => __('stock_adjustments.page_title'),
            'subtitle' => __('stock_adjustments.page_subtitle'),
        ]);
    }

    private function validateInput(int $tenantId): void
    {
        validator($this->formData(), [
            'tenant_id' => ['required', 'integer'],
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')],
            'stock_item_id' => ['required', 'integer', Rule::exists('stock_items', 'id')->where('tenant_id', $tenantId)],
            'quantity' => ['required', 'integer', 'not_in:0'],
            'note' => ['nullable', 'string', 'max:1000'],
            'ref_id' => ['nullable', 'string', 'max:255'],
        ])->validate();
    }

    private function formData(): array
    {
        return [
            'tenant_id' => $this->tenantId,
            'warehouse_id' => $this->warehouseId,
            'stock_item_id' => $this->stockItemId,
            'quantity' => $this->quantity,
            'note' => $this->note,
            'ref_id' => $this->refId,
        ];
    }

    // TODO: remove unauthenticated fallback when auth is implemented
    private function isInternalUser(): bool
    {
        $user = Auth::user();

        return ! $user || $user->user_type === 'internal';
    }

    private function allowedTenantIds(): array
    {
        if ($this->isInternalUser()) {
            return Tenant::query()->pluck('id')->all();
        }

        return $this->activeTenantIds();
    }

    private function validatedTenantId(): int
    {
        $tenantId = (int) $this->tenantId;

        if ($tenantId <= 0 || ! in_array($tenantId, $this->allowedTenantIds(), true)) {
            throw ValidationException::withMessages(['tenantId' => __('stock_adjustments.invalid_tenant')]);
        }

        return $tenantId;
    }

    private function activeTenantIds(): array
    {
        return Auth::user()
            ->tenantUsers()
            ->where('status', 'active')
            ->pluck('tenant_id')
            ->all();
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
            ->orderBy('name')
            ->get(['id', 'code', 'name']);
    }

    private function stockItemOptions(): Collection
    {
        $search = '%'.$this->stockItemSearch.'%';

        return StockItem::query()
            ->where('tenant_id', $this->tenantId)
            ->when($this->stockItemSearch !== '', function ($query) use ($search) {
                $query->where(function ($query) use ($search) {
                    $query
                        ->where('code', 'like', $search)
                        ->orWhere('name', 'like', $search)
                        ->orWhere('barcode', 'like', $search);
                });
            })
            ->orderBy('code')
            ->limit(30)
            ->get(['id', 'code', 'name', 'barcode']);
    }

    private function currentTenant(): ?Tenant
    {
        if ($this->tenantId === '') {
            return null;
        }

        return Tenant::query()->find($this->tenantId, ['id', 'code', 'name']);
    }

    private function nullableString(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
