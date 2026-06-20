<?php

namespace App\Livewire;

use App\Models\ExceptionCase;
use App\Models\Tenant;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class ExceptionCaseIndex extends Component
{
    use WithPagination;

    #[Url(as: 'tenant_id', except: '')]
    public string $tenantId = '';

    #[Url(as: 'status', except: '')]
    public string $statusFilter = '';

    #[Url(as: 'type', except: '')]
    public string $typeFilter = '';

    #[Url(as: 'sales_order_id', except: '')]
    public string $salesOrderId = '';

    #[Url(as: 'outbound_order_id', except: '')]
    public string $outboundOrderId = '';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    private bool $allowedTenantIdsResolved = false;

    private array $allowedTenantIdsCache = [];

    public function mount(): void
    {
        if (! $this->isInternalUser() && $this->allowedTenantIds() === []) {
            abort(403);
        }
    }

    public function updated($property): void
    {
        if (in_array($property, ['tenantId', 'statusFilter', 'typeFilter', 'salesOrderId', 'outboundOrderId', 'search'], true)) {
            $this->resetPage();
        }
    }

    public function render()
    {
        $cases = ExceptionCase::query()
            ->with([
                'tenant:id,code,name',
                'salesOrder:id,platform_order_id',
                'outboundOrder:id,ref',
                'lines.sku:id,sku,name',
                'lines.stockItem:id,code,name',
            ])
            ->whereIn('tenant_id', $this->allowedTenantIds())
            ->when($this->tenantId !== '', fn ($query) => $query->where('tenant_id', (int) $this->tenantId))
            ->when($this->statusFilter !== '', fn ($query) => $query->where('status', $this->statusFilter))
            ->when($this->typeFilter !== '', fn ($query) => $query->where('case_type', $this->typeFilter))
            ->when($this->salesOrderId !== '', fn ($query) => $query->where('sales_order_id', (int) $this->salesOrderId))
            ->when($this->outboundOrderId !== '', fn ($query) => $query->where('outbound_order_id', (int) $this->outboundOrderId))
            ->when($this->search !== '', function ($query) {
                $like = '%'.$this->search.'%';

                $query->where(fn ($query) => $query
                    ->where('case_no', 'like', $like)
                    ->orWhere('note', 'like', $like)
                    ->orWhereHas('salesOrder', fn ($query) => $query->where('platform_order_id', 'like', $like))
                    ->orWhereHas('outboundOrder', fn ($query) => $query->where('ref', 'like', $like))
                    ->orWhereHas('lines.sku', fn ($query) => $query->where('sku', 'like', $like))
                    ->orWhereHas('lines.stockItem', fn ($query) => $query
                        ->where('code', 'like', $like)
                        ->orWhere('name', 'like', $like)));
            })
            ->orderByDesc('created_at')
            ->paginate(30);

        return view('livewire.exception-case-index', [
            'cases' => $cases,
            'tenants' => Tenant::query()
                ->whereIn('id', $this->allowedTenantIds())
                ->orderBy('name')
                ->get(['id', 'code', 'name']),
            'types' => ExceptionCase::typeOptions(),
            'statuses' => ExceptionCase::statusOptions(),
            'showTenantFilter' => $this->isInternalUser(),
        ])->layout('inventory', [
            'title' => __('exception_cases.page_title'),
            'subtitle' => __('exception_cases.page_subtitle'),
            'pageWide' => true,
        ]);
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
}
