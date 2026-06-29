<?php

namespace App\Livewire;

use App\Livewire\Concerns\AutoSelectsSingleActiveWarehouse;
use App\Models\ReturnOrder;
use App\Models\Tenant;
use App\Models\Warehouse;
use App\Support\BulkActionMessage;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class ReturnOrderIndex extends Component
{
    use AutoSelectsSingleActiveWarehouse;
    use WithPagination;

    #[Url(as: 'tenant_id', except: '')]
    public string $tenantId = '';

    #[Url(except: '')]
    public string $warehouseId = '';

    #[Url(except: '')]
    public string $statusFilter = '';

    #[Url(except: '')]
    public string $typeFilter = '';

    #[Url(except: '')]
    public string $reasonFilter = '';

    #[Url(except: '')]
    public string $paymentFilter = '';

    #[Url(except: '')]
    public string $search = '';

    public array $selectedIds = [];

    public function mount(): void
    {
        $this->autoSelectSingleActiveWarehouse();
    }

    public function updateStatus(int $orderId, string $value): void
    {
        validator(['status' => $value], [
            'status' => ['required', Rule::in(array_keys(ReturnOrder::statusOptions()))],
        ])->validate();

        $payload = [
            'status' => $value,
        ];

        if ($value === ReturnOrder::STATUS_CLOSED) {
            $payload['closed_at'] = now();
        } elseif ($value === ReturnOrder::STATUS_CANCELLED) {
            $payload['cancelled_at'] = now();
            $payload['cancelled_by_user_id'] = Auth::id();
        }

        $this->returnOrderQuery()->findOrFail($orderId)->update($payload);

        session()->flash('status', __('return_orders.status_updated'));
    }

    public function updateNote(int $orderId, string $value): void
    {
        validator(['note' => $value], [
            'note' => ['nullable', 'string', 'max:2000'],
        ])->validate();

        $this->returnOrderQuery()->findOrFail($orderId)->update([
            'note' => $this->nullableString($value),
        ]);

        session()->flash('status', __('return_orders.note_updated'));
    }

    public function closeSelected(): void
    {
        $selectedIds = $this->normalizedSelectedIds();

        if ($selectedIds === []) {
            session()->flash('error', __('return_orders.select_returns_first'));

            return;
        }

        $updated = $this->returnOrderQuery()
            ->whereIn('id', $selectedIds)
            ->where('status', '!=', ReturnOrder::STATUS_CLOSED)
            ->update([
                'status' => ReturnOrder::STATUS_CLOSED,
                'closed_at' => now(),
                'updated_at' => now(),
            ]);

        $this->selectedIds = [];

        session()->flash('status', BulkActionMessage::make('return_orders.close_selected_result', $updated, count($selectedIds) - $updated));
    }

    public function render()
    {
        $orders = ReturnOrder::query()
            ->with([
                'tenant:id,code,name',
                'warehouse:id,code,name',
                'lines.sku:id,sku,name',
                'lines.stockItem:id,code,name',
                'costs',
                'mediaAssets:id,tenant_id,model_type,model_id,disk,path,file_name,mime_type,sort_order,is_primary',
            ])
            ->whereIn('tenant_id', $this->allowedTenantIds())
            ->when($this->tenantId !== '', fn ($q) => $q->where('tenant_id', $this->tenantId))
            ->when($this->warehouseId !== '', fn ($q) => $q->where('warehouse_id', $this->warehouseId))
            ->when($this->statusFilter !== '', fn ($q) => $q->where('status', $this->statusFilter))
            ->when($this->statusFilter === '', fn ($q) => $q->whereNotIn('status', [
                ReturnOrder::STATUS_DISPOSITIONED,
                ReturnOrder::STATUS_CLOSED,
                ReturnOrder::STATUS_CANCELLED,
                ReturnOrder::STATUS_EXPIRED,
            ]))
            ->when($this->typeFilter !== '', fn ($q) => $q->where('return_type', $this->typeFilter))
            ->when($this->reasonFilter !== '', fn ($q) => $q->where('return_reason', $this->reasonFilter))
            ->when($this->paymentFilter !== '', fn ($q) => $q->where('payment_type', $this->paymentFilter))
            ->when(trim($this->search) !== '', function ($q): void {
                $term = '%'.trim($this->search).'%';
                $q->where(function ($nested) use ($term): void {
                    $nested->where('return_no', 'like', $term)->orWhere('tracking_no', 'like', $term)->orWhere('original_order_no', 'like', $term)->orWhere('customer_name', 'like', $term)->orWhere('external_return_id', 'like', $term)->orWhere('note', 'like', $term)
                        ->orWhereHas('lines.sku', fn ($line) => $line->where('sku', 'like', $term))
                        ->orWhereHas('lines.stockItem', fn ($line) => $line->where('code', 'like', $term)->orWhere('name', 'like', $term));
                });
            })
            ->latest('id')
            ->paginate(15);

        return view('livewire.return-order-index', [
            'orders' => $orders,
            'tenants' => Tenant::query()->whereIn('id', $this->allowedTenantIds())->orderBy('name')->get(['id', 'code', 'name']),
            'warehouses' => Warehouse::query()->where('status', 'active')->orderBy('name')->get(['id', 'code', 'name']),
            'statuses' => ReturnOrder::statusOptions(),
            'types' => ReturnOrder::typeOptions(),
            'reasons' => ReturnOrder::reasonOptions(),
            'paymentTypes' => ReturnOrder::paymentTypeOptions(),
            'showTenantSelect' => $this->isInternalUser(),
        ])->layout('inventory', [
            'title' => __('return_orders.page_title'),
            'subtitle' => __('return_orders.page_subtitle'),
            'pageWide' => true,
        ]);
    }

    private function isInternalUser(): bool
    {
        return Auth::user()?->user_type === 'internal';
    }

    private function allowedTenantIds(): array
    {
        return $this->isInternalUser() ? Tenant::query()->pluck('id')->all() : (Auth::user()?->activeTenantIds() ?? []);
    }

    private function returnOrderQuery()
    {
        return ReturnOrder::query()->whereIn('tenant_id', $this->allowedTenantIds());
    }

    private function normalizedSelectedIds(): array
    {
        return array_values(array_unique(array_map('intval', $this->selectedIds)));
    }

    private function nullableString(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
