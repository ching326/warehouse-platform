<?php

namespace App\Livewire;

use App\Models\FulfillmentGroup;
use App\Models\InboundReceipt;
use App\Models\InventoryBalance;
use App\Models\ReturnOrderLine;
use App\Models\ShippingMethod;
use App\Models\Tenant;
use App\Models\Warehouse;
use App\Services\Fulfillment\FulfillmentPackService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;

class FulfillmentPickSummary extends Component
{
    #[Url(as: 'warehouse_id', except: '')]
    public string $warehouseId = '';

    #[Url(as: 'shipping_method_id', except: '')]
    public string $shippingMethodId = '';

    #[Url(as: 'tenant_id', except: '')]
    public string $tenantId = '';

    #[Url(as: 'date_from', except: '')]
    public string $dateFrom = '';

    #[Url(as: 'date_to', except: '')]
    public string $dateTo = '';

    #[Url(as: 'q', except: '')]
    public string $q = '';

    private bool $allowedTenantIdsResolved = false;

    private array $allowedTenantIdsCache = [];

    private ?Warehouse $selectedWarehouseCache = null;

    private ?string $selectedWarehouseCacheKey = null;

    public function mount(): void
    {
        $this->authorizeInternalUser();

        if ($this->warehouseId === '') {
            $activeWarehouseIds = Warehouse::query()
                ->where('status', 'active')
                ->pluck('id');

            if ($activeWarehouseIds->count() === 1) {
                $this->warehouseId = (string) $activeWarehouseIds->first();
            }
        }

        $today = now($this->warehouseTimezone())->toDateString();
        $this->dateFrom = $this->dateFrom ?: $today;
        $this->dateTo = $this->dateTo ?: $today;
    }

    public function clearWarehouseFilter(): void
    {
        if ($this->warehouseOptions()->count() > 1) {
            $this->warehouseId = '';
        }
    }

    public function clearShippingMethodFilter(): void
    {
        $this->shippingMethodId = '';
    }

    public function clearTenantFilter(): void
    {
        $this->tenantId = '';
    }

    public function clearDateFilters(): void
    {
        $this->dateFrom = '';
        $this->dateTo = '';
    }

    public function render(FulfillmentPackService $packService)
    {
        $this->authorizeInternalUser();

        $rows = $this->warehouseReady()
            ? $this->pickRows($packService)
            : collect();
        $summary = $this->summary($rows);

        return view('livewire.fulfillment-pick-summary', [
            'warehouses' => $this->warehouseOptions(),
            'shippingMethods' => $this->shippingMethodOptions(),
            'tenants' => $this->tenantOptions(),
            'warehouseReady' => $this->warehouseReady(),
            'rows' => $rows,
            'summary' => $summary,
            'filterSummary' => $this->filterSummary(),
            'filterChips' => $this->filterChips(),
            'generatedAt' => now($this->warehouseTimezone())->format('Y-m-d H:i'),
        ])->layout('inventory', [
            'title' => __('fulfillment_pick.page_title'),
            'subtitle' => __('fulfillment_pick.page_subtitle'),
        ]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function pickRows(FulfillmentPackService $packService): Collection
    {
        $groups = $this->groupQuery()
            ->with([
                'tenant:id,code,name',
                'orders:id,platform_order_id',
                'orders.lines.sku.barcodeAliases',
                'orders.lines.sku.stockItem.barcodeAliases',
                'orders.lines.sku.bundleComponents.componentStockItem.barcodeAliases',
            ])
            ->get();

        $rows = [];

        foreach ($groups as $group) {
            foreach ($packService->packLines($group) as $line) {
                $stockItem = $line['stock_item'];
                $sku = $line['sku'];
                $key = 'sku:'.($line['sku_id'] ?? 'null').':stock:'.($line['stock_item_id'] ?? 'null');

                $rows[$key] ??= [
                    'sku_id' => $line['sku_id'],
                    'stock_item_id' => $line['stock_item_id'],
                    'stock_item' => $stockItem,
                    'sku_codes' => [],
                    'sku_names' => [],
                    'required_qty' => 0,
                    'groups' => [],
                    'orders' => [],
                    'barcode' => $stockItem?->barcode ?: $sku?->barcode,
                    'alias_count' => $this->aliasCount($line),
                    'is_strict' => $packService->lineIsStrictOnly($line),
                    'location_hint' => '-',
                    'pickable_qty' => 0,
                    'difference' => 0,
                ];

                if ($line['sku_id'] === null) {
                    $rows[$key]['sku_codes']['bundle-component'] = __('fulfillment_pick.bundle_component');
                } elseif ($sku) {
                    $rows[$key]['sku_codes'][$sku->id] = $sku->sku;
                    $rows[$key]['sku_names'][$sku->id] = $sku->name;
                }

                $rows[$key]['required_qty'] += (int) $line['required_qty'];
                $rows[$key]['groups'][$group->id] = $group;

                foreach ($group->orders as $order) {
                    $rows[$key]['orders'][$order->id] = $order;
                }
            }
        }

        $rows = collect(array_values($rows));

        if (trim($this->q) !== '') {
            $needle = mb_strtolower(trim($this->q));
            $rows = $rows->filter(fn (array $row): bool => str_contains($this->rowSearchText($row), $needle));
        }

        $stockItemIds = $rows->pluck('stock_item_id')->filter()->unique()->values();
        $pickableQuantities = $this->pickableQuantityMap($stockItemIds);
        $locations = $this->locationHints($stockItemIds);

        return $rows
            ->map(function (array $row) use ($pickableQuantities, $locations): array {
                $stockItemId = $row['stock_item_id'];
                $pickable = $stockItemId ? ($pickableQuantities[$stockItemId] ?? 0) : 0;

                $row['pickable_qty'] = $pickable;
                $row['difference'] = $pickable - (int) $row['required_qty'];
                $row['location_hint'] = $stockItemId ? ($locations[$stockItemId] ?? '-') : '-';

                return $row;
            })
            ->sortBy(fn (array $row): string => (string) ($row['stock_item']?->code ?? implode(',', $row['sku_codes'])))
            ->values();
    }

    private function groupQuery()
    {
        return FulfillmentGroup::query()
            ->whereIn('tenant_id', $this->allowedTenantIds())
            ->where('status', FulfillmentGroup::STATUS_RESERVED)
            ->where('warehouse_id', (int) $this->warehouseId)
            ->when($this->shippingMethodId !== '', fn ($query) => $query->where('shipping_method_id', (int) $this->shippingMethodId))
            ->when($this->tenantId !== '' && in_array((int) $this->tenantId, $this->allowedTenantIds(), true), fn ($query) => $query->where('tenant_id', (int) $this->tenantId))
            ->when($this->dateFrom !== '', fn ($query) => $query->where('created_at', '>=', Carbon::parse($this->dateFrom, $this->warehouseTimezone())->startOfDay()->utc()))
            ->when($this->dateTo !== '', fn ($query) => $query->where('created_at', '<=', Carbon::parse($this->dateTo, $this->warehouseTimezone())->endOfDay()->utc()))
            ->orderBy('created_at')
            ->orderBy('id');
    }

    /**
     * @param  Collection<int, int>  $stockItemIds
     * @return array<int, int>
     */
    private function pickableQuantityMap(Collection $stockItemIds): array
    {
        if ($stockItemIds->isEmpty()) {
            return [];
        }

        return InventoryBalance::query()
            ->where('warehouse_id', (int) $this->warehouseId)
            ->whereIn('stock_item_id', $stockItemIds)
            ->get()
            ->mapWithKeys(fn (InventoryBalance $balance): array => [
                $balance->stock_item_id => max(0, (int) $balance->on_hand_qty - (int) $balance->hold_qty - (int) $balance->damaged_qty),
            ])
            ->all();
    }

    /**
     * @param  Collection<int, int>  $stockItemIds
     * @return array<int, string>
     */
    private function locationHints(Collection $stockItemIds): array
    {
        if ($stockItemIds->isEmpty()) {
            return [];
        }

        $hints = [];
        $receipts = InboundReceipt::query()
            ->with('warehouseLocation:id,code,name')
            ->where('warehouse_id', (int) $this->warehouseId)
            ->whereIn('stock_item_id', $stockItemIds)
            ->latest('received_at')
            ->latest('id')
            ->get();

        foreach ($receipts as $receipt) {
            if (! isset($hints[$receipt->stock_item_id]) && $receipt->warehouseLocation) {
                $hints[$receipt->stock_item_id] = $receipt->warehouseLocation->code;
            }
        }

        $missing = $stockItemIds->diff(array_keys($hints));

        if ($missing->isNotEmpty()) {
            $returns = ReturnOrderLine::query()
                ->with('dispositionLocation:id,code,name')
                ->whereIn('stock_item_id', $missing)
                ->whereNotNull('disposition_location_id')
                ->latest('dispositioned_at')
                ->latest('id')
                ->get();

            foreach ($returns as $line) {
                if (! isset($hints[$line->stock_item_id]) && $line->dispositionLocation) {
                    $hints[$line->stock_item_id] = $line->dispositionLocation->code;
                }
            }
        }

        return $hints;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, int>
     */
    private function summary(Collection $rows): array
    {
        return [
            'pick_rows' => $rows->count(),
            'required_qty' => (int) $rows->sum('required_qty'),
            'shortage_rows' => $rows->filter(fn (array $row): bool => $row['pickable_qty'] < $row['required_qty'])->count(),
            'groups_included' => $rows->flatMap(fn (array $row): array => array_keys($row['groups']))->unique()->count(),
        ];
    }

    private function rowSearchText(array $row): string
    {
        $stockItem = $row['stock_item'];
        $values = [
            $stockItem?->code,
            $stockItem?->name,
            $stockItem?->short_name,
            $stockItem?->barcode,
            implode(' ', $row['sku_codes']),
            $row['barcode'],
        ];

        foreach ($row['groups'] as $group) {
            $values[] = $group->reference_no;
        }

        foreach ($row['orders'] as $order) {
            $values[] = $order->platform_order_id;
        }

        return mb_strtolower(implode(' ', array_filter($values)));
    }

    private function aliasCount(array $line): int
    {
        $skuAliases = $line['sku']?->barcodeAliases?->where('is_active', true)->count() ?? 0;
        $stockAliases = $line['stock_item']?->barcodeAliases?->where('is_active', true)->count() ?? 0;

        return $skuAliases + $stockAliases;
    }

    private function warehouseReady(): bool
    {
        return (int) $this->warehouseId > 0;
    }

    private function authorizeInternalUser(): void
    {
        if (Auth::user()?->user_type !== 'internal') {
            abort(403);
        }
    }

    private function allowedTenantIds(): array
    {
        if ($this->allowedTenantIdsResolved) {
            return $this->allowedTenantIdsCache;
        }

        $this->allowedTenantIdsResolved = true;

        return $this->allowedTenantIdsCache = Tenant::query()->pluck('id')->all();
    }

    private function filterSummary(): string
    {
        $parts = [];
        $parts[] = __('fulfillment_pick.print_warehouse', ['value' => $this->warehouseId ? (Warehouse::find($this->warehouseId)?->name ?? '-') : '-']);
        $parts[] = __('fulfillment_pick.print_shipping_method', ['value' => $this->shippingMethodId ? (ShippingMethod::find($this->shippingMethodId)?->name ?? '-') : __('common.all_types')]);
        $parts[] = __('fulfillment_pick.print_tenant', ['value' => $this->tenantId ? (Tenant::find($this->tenantId)?->code ?? '-') : __('common.all_tenants')]);
        $parts[] = __('fulfillment_pick.print_date', ['from' => $this->dateFrom ?: '-', 'to' => $this->dateTo ?: '-']);
        $parts[] = __('fulfillment_pick.print_generated', ['value' => now($this->warehouseTimezone())->format('Y-m-d H:i')]);

        return implode(' / ', $parts);
    }

    private function warehouseTimezone(): string
    {
        $timezone = $this->selectedWarehouse()?->timezone;

        if (! is_string($timezone) || trim($timezone) === '') {
            return 'Asia/Tokyo';
        }

        $timezone = trim($timezone);

        try {
            new \DateTimeZone($timezone);
        } catch (\Throwable) {
            return 'Asia/Tokyo';
        }

        return $timezone;
    }

    private function selectedWarehouse(): ?Warehouse
    {
        if ($this->warehouseId === '') {
            $this->selectedWarehouseCache = null;
            $this->selectedWarehouseCacheKey = null;

            return null;
        }

        if ($this->selectedWarehouseCacheKey === $this->warehouseId) {
            return $this->selectedWarehouseCache;
        }

        $this->selectedWarehouseCacheKey = $this->warehouseId;
        $this->selectedWarehouseCache = Warehouse::query()
            ->whereKey((int) $this->warehouseId)
            ->first(['id', 'timezone']);

        return $this->selectedWarehouseCache;
    }

    private function filterChips(): array
    {
        $warehouseCount = $this->warehouseOptions()->count();
        $chips = [];

        if ($this->warehouseId !== '') {
            $warehouse = Warehouse::find($this->warehouseId);
            $chips[] = [
                'label' => __('fulfillment_pick.chip_warehouse', ['value' => $warehouse?->name ?? $this->warehouseId]),
                'action' => $warehouseCount > 1 ? 'clearWarehouseFilter' : null,
            ];
        }

        if ($this->dateFrom !== '' || $this->dateTo !== '') {
            $chips[] = [
                'label' => __('fulfillment_pick.chip_date', ['from' => $this->dateFrom ?: '-', 'to' => $this->dateTo ?: '-']),
                'action' => 'clearDateFilters',
            ];
        }

        if ($this->shippingMethodId !== '') {
            $method = ShippingMethod::find($this->shippingMethodId);
            $chips[] = [
                'label' => __('fulfillment_pick.chip_shipping', ['value' => $method?->name ?? $this->shippingMethodId]),
                'action' => 'clearShippingMethodFilter',
            ];
        }

        if ($this->tenantId !== '') {
            $tenant = Tenant::find($this->tenantId);
            $chips[] = [
                'label' => __('fulfillment_pick.chip_tenant', ['value' => $tenant?->code ?? $this->tenantId]),
                'action' => 'clearTenantFilter',
            ];
        }

        return $chips;
    }

    private function warehouseOptions()
    {
        return Warehouse::query()->where('status', 'active')->orderBy('name')->get(['id', 'code', 'name']);
    }

    private function shippingMethodOptions()
    {
        return ShippingMethod::query()
            ->where('shipping_methods.status', 'active')
            ->ordered()
            ->get(['shipping_methods.id', 'shipping_methods.name']);
    }

    private function tenantOptions()
    {
        return Tenant::query()
            ->whereIn('id', $this->allowedTenantIds())
            ->orderBy('name')
            ->get(['id', 'code', 'name']);
    }
}
