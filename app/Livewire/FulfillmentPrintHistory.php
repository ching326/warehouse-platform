<?php

namespace App\Livewire;

use App\Models\CourierExportBatch;
use App\Models\Tenant;
use App\Support\CourierExportTypeLabels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class FulfillmentPrintHistory extends Component
{
    use WithPagination;

    #[Url(as: 'tenant_id', except: '')]
    public string $tenantId = '';

    #[Url(as: 'type', except: '')]
    public string $type = '';

    #[Url(as: 'date_from', except: '')]
    public string $dateFrom = '';

    #[Url(as: 'date_to', except: '')]
    public string $dateTo = '';

    #[Url(as: 'search', except: '')]
    public string $search = '';

    private bool $allowedTenantIdsResolved = false;

    private array $allowedTenantIdsCache = [];

    public function mount(): void
    {
        if (! $this->isInternalUser() && $this->allowedTenantIds() === []) {
            abort(403);
        }
    }

    public function updatedTenantId(): void
    {
        $this->resetPage();
    }

    public function updatedType(): void
    {
        $this->resetPage();
    }

    public function updatedDateFrom(): void
    {
        $this->resetPage();
    }

    public function updatedDateTo(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function typeLabel(string $type): string
    {
        return CourierExportTypeLabels::label($type);
    }

    public function render()
    {
        $allowedTenantIds = $this->allowedTenantIds();
        $typeOptions = CourierExportTypeLabels::labels();

        $batches = CourierExportBatch::query()
            ->with(['tenant:id,code,name', 'exportedBy:id,name'])
            ->whereIn('tenant_id', $allowedTenantIds)
            ->when($this->tenantId !== '', fn ($query) => $query->where('tenant_id', (int) $this->tenantId))
            ->when($this->type !== '' && array_key_exists($this->type, $typeOptions), fn ($query) => $query->where('carrier', $this->type))
            ->when($this->dateFrom !== '', function ($query): void {
                if ($from = $this->parseDate($this->dateFrom)?->startOfDay()) {
                    $query->where('exported_at', '>=', $from);
                }
            })
            ->when($this->dateTo !== '', function ($query): void {
                if ($to = $this->parseDate($this->dateTo)?->addDay()->startOfDay()) {
                    $query->where('exported_at', '<', $to);
                }
            })
            ->when(trim($this->search) !== '', function ($query): void {
                $search = trim($this->search);
                $like = '%'.$search.'%';

                $query->where(function ($inner) use ($like, $search): void {
                    $inner
                        ->where('file_name', 'like', $like)
                        ->orWhereHas('batchOrders', fn ($row) => $row
                            ->where('platform_order_id', 'like', $like)
                            ->orWhereHas('outboundOrder', fn ($outbound) => $outbound->where('ref', 'like', $like)));

                    if (ctype_digit($search)) {
                        $inner->orWhereKey((int) $search);
                    }
                });
            })
            ->orderByDesc('exported_at')
            ->orderByDesc('id')
            ->simplePaginate(30);

        $tenants = Tenant::query()
            ->whereIn('id', $allowedTenantIds)
            ->orderBy('name')
            ->get(['id', 'code', 'name']);

        return view('livewire.fulfillment-print-history', [
            'batches' => $batches,
            'tenants' => $tenants,
            'typeOptions' => $typeOptions,
            'showTenantFilter' => $this->isInternalUser(),
        ])->layout('inventory', [
            'title' => __('fulfillment.print_history_title'),
            'subtitle' => __('fulfillment.print_history_subtitle'),
            'pageWide' => true,
        ]);
    }

    private function parseDate(string $date): ?Carbon
    {
        try {
            return $date !== '' ? Carbon::parse($date) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function isInternalUser(): bool
    {
        return Auth::user()?->user_type === 'internal';
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
}
