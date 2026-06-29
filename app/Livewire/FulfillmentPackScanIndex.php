<?php

namespace App\Livewire;

use App\Models\FulfillmentPackScan;
use App\Models\StockItem;
use App\Models\Tenant;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class FulfillmentPackScanIndex extends Component
{
    use WithPagination;

    #[Url(as: 'tenant_id', except: '')]
    public string $tenantId = '';

    #[Url(as: 'outbound_order_id', except: '')]
    public string $outboundOrderId = '';

    #[Url(as: 'result', except: '')]
    public string $result = '';

    #[Url(as: 'scanned_by_user_id', except: '')]
    public string $scannedByUserId = '';

    #[Url(as: 'date_from', except: '')]
    public string $dateFrom = '';

    #[Url(as: 'date_to', except: '')]
    public string $dateTo = '';

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
        if (in_array($property, ['tenantId', 'outboundOrderId', 'result', 'scannedByUserId', 'dateFrom', 'dateTo', 'search'], true)) {
            $this->resetPage();
        }
    }

    public function render()
    {
        $query = $this->filteredQuery();
        $summary = $this->summary($query);

        return view('livewire.fulfillment-pack-scan-index', [
            'scans' => (clone $query)
                ->with([
                    'tenant:id,code,name',
                    'outboundOrder:id,ref',
                    'salesOrder:id,platform_order_id',
                    'sku' => fn ($q) => $q->select(['id', 'sku', 'stock_item_id']),
                    'sku.stockItem:id,name,short_name,name_en,name_ja,name_zh_tw,name_zh_cn',
                    'stockItem' => fn ($q) => $q->select(['id', 'code', ...StockItem::DISPLAY_NAME_COLUMNS]),
                    'scannedBy:id,name',
                ])
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->paginate(50),
            'tenants' => Tenant::query()
                ->whereIn('id', $this->allowedTenantIds())
                ->orderBy('name')
                ->get(['id', 'code', 'name']),
            'results' => $this->resultOptions(),
            'summary' => $summary,
            'showTenantFilter' => $this->isInternalUser(),
        ])->layout('inventory', [
            'title' => __('fulfillment_pack.scan_history_title'),
            'subtitle' => __('fulfillment_pack.scan_history_subtitle'),
            'pageWide' => true,
        ]);
    }

    private function filteredQuery(): Builder
    {
        return FulfillmentPackScan::query()
            ->whereIn('tenant_id', $this->allowedTenantIds())
            ->when($this->tenantId !== '', fn ($query) => $query->where('tenant_id', (int) $this->tenantId))
            ->when($this->outboundOrderId !== '', fn ($query) => $query->where('outbound_order_id', (int) $this->outboundOrderId))
            ->when($this->result !== '', fn ($query) => $query->where('result', $this->result))
            ->when($this->scannedByUserId !== '', fn ($query) => $query->where('scanned_by_user_id', (int) $this->scannedByUserId))
            ->when($this->dateFrom !== '', fn ($query) => $query->where('created_at', '>=', Carbon::parse($this->dateFrom)->startOfDay()))
            ->when($this->dateTo !== '', fn ($query) => $query->where('created_at', '<=', Carbon::parse($this->dateTo)->endOfDay()))
            ->when($this->search !== '', function ($query): void {
                $like = '%'.$this->search.'%';

                $query->where(fn ($query) => $query
                    ->where('barcode_scanned', 'like', $like)
                    ->orWhere('normalized_barcode', 'like', $like)
                    ->orWhere('message', 'like', $like)
                    ->orWhereHas('outboundOrder', fn ($query) => $query->where('ref', 'like', $like))
                    ->orWhereHas('salesOrder', fn ($query) => $query->where('platform_order_id', 'like', $like))
                    ->orWhereHas('sku', fn ($query) => $query->where('sku', 'like', $like))
                    ->orWhereHas('stockItem', fn ($query) => $query
                        ->where('code', 'like', $like)
                        ->orWhere('name', 'like', $like)));
            });
    }

    private function summary(Builder $query): array
    {
        return [
            'filtered_scans' => (clone $query)->count(),
            'accepted_quantity' => (int) (clone $query)
                ->where('result', FulfillmentPackScan::RESULT_ACCEPTED)
                ->sum('quantity'),
            'exceptions' => (clone $query)
                ->where('result', '!=', FulfillmentPackScan::RESULT_ACCEPTED)
                ->count(),
            'latest_scan' => (clone $query)->max('created_at'),
        ];
    }

    private function resultOptions(): array
    {
        return [
            FulfillmentPackScan::RESULT_ACCEPTED => __('fulfillment_pack.scan_result_accepted'),
            FulfillmentPackScan::RESULT_WRONG_ITEM => __('fulfillment_pack.scan_result_wrong_item'),
            FulfillmentPackScan::RESULT_OVER_SCAN => __('fulfillment_pack.scan_result_over_scan'),
            FulfillmentPackScan::RESULT_NOT_FOUND => __('fulfillment_pack.scan_result_not_found'),
            FulfillmentPackScan::RESULT_BLOCKED_STATUS => __('fulfillment_pack.scan_result_blocked_status'),
        ];
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
