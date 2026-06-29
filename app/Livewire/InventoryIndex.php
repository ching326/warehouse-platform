<?php

namespace App\Livewire;

use App\Livewire\Concerns\AutoSelectsSingleActiveWarehouse;
use App\Livewire\Concerns\HasEnumLabels;
use App\Models\InventoryBalance;
use App\Models\MediaAsset;
use App\Models\ProductType;
use App\Models\Shop;
use App\Models\StockItem;
use App\Models\Tenant;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithPagination;

class InventoryIndex extends Component
{
    use AutoSelectsSingleActiveWarehouse;
    use HasEnumLabels;
    use WithPagination;

    public string $search = '';

    public string $tenantId = '';

    public string $warehouseId = '';

    public string $shopId = '';

    public string $productType = '';

    public string $status = '';

    public int $perPage = 10;

    public bool $viewSettingsOpen = false;

    public string $stockItemCodeDisplay = self::STOCK_ITEM_CODE_DISPLAY_SYSTEM;

    private bool $visibleTenantIdsResolved = false;

    private ?array $visibleTenantIdsCache = null;

    /**
     * @var array<int, bool>
     */
    public array $expandedStockItems = [];

    private const STOCK_ITEM_CODE_DISPLAY_SYSTEM = 'system';

    private const STOCK_ITEM_CODE_DISPLAY_TENANT = 'tenant';

    private const STOCK_ITEM_CODE_DISPLAY_BOTH = 'both';

    private const STOCK_ITEM_CODE_DISPLAY_OPTIONS = [
        self::STOCK_ITEM_CODE_DISPLAY_SYSTEM,
        self::STOCK_ITEM_CODE_DISPLAY_TENANT,
        self::STOCK_ITEM_CODE_DISPLAY_BOTH,
    ];

    private const PER_PAGE_OPTIONS = [10, 15, 30, 50, 100];

    public function mount(): void
    {
        $this->stockItemCodeDisplay = $this->stockItemCodeDisplayPreference();
        $this->autoSelectSingleActiveWarehouse();
    }

    public function openViewSettings(): void
    {
        $this->stockItemCodeDisplay = $this->stockItemCodeDisplayPreference();
        $this->viewSettingsOpen = true;
    }

    public function closeViewSettings(): void
    {
        $this->viewSettingsOpen = false;
    }

    public function saveViewSettings(): void
    {
        $data = validator([
            'stock_item_code_display' => $this->stockItemCodeDisplay,
        ], [
            'stock_item_code_display' => ['required', Rule::in(self::STOCK_ITEM_CODE_DISPLAY_OPTIONS)],
        ])->validate();

        $this->stockItemCodeDisplay = $data['stock_item_code_display'];

        if ($user = Auth::user()) {
            $user->setPreference('stock_item_code_display', $this->stockItemCodeDisplay);
            session()->flash('status', __('skus.view_settings_saved'));
        }

        $this->viewSettingsOpen = false;
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatedTenantId(): void
    {
        $this->shopId = '';
        $this->resetPage();
    }

    public function updatedWarehouseId(): void
    {
        $this->resetPage();
    }

    public function updatedShopId(): void
    {
        $this->resetPage();
    }

    public function updatedProductType(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        $this->perPage = $this->normalizePerPage($this->perPage);
        $this->resetPage();
    }

    public function render()
    {
        return view('livewire.inventory-index', [
            'balances' => $this->balances(),
            'summary' => $this->summary(),
            'tenants' => $this->tenantOptions(),
            'warehouses' => $this->warehouseOptions(),
            'shops' => $this->shopOptions(),
            'productTypes' => $this->productTypeOptions(),
            'statuses' => $this->statusOptions(),
            'showTenantColumn' => $this->showTenantColumn(),
            'perPageOptions' => self::PER_PAGE_OPTIONS,
        ])->layout('inventory', [
            'title' => __('inventory.page_title'),
            'subtitle' => __('inventory.page_subtitle'),
            'pageWide' => true,
        ]);
    }

    public function toggleSkuList(int $stockItemId): void
    {
        $this->expandedStockItems[$stockItemId] = ! ($this->expandedStockItems[$stockItemId] ?? false);
    }

    public function isSkuListExpanded(int $stockItemId): bool
    {
        return $this->expandedStockItems[$stockItemId] ?? false;
    }

    public function availableStatusClass(int $availableQty): string
    {
        if ($availableQty <= 0) {
            return 'available available-danger';
        }

        if ($availableQty <= 10) {
            return 'available available-warning';
        }

        return 'available available-success';
    }

    public function productTypeLabel(string $type): string
    {
        static $map = null;
        $locale = app()->getLocale();
        $map ??= ProductType::all()->mapWithKeys(
            fn ($t) => [$t->slug => $t->translations[$locale] ?? $t->name]
        )->all();

        return $map[$type] ?? $type;
    }

    public function statusLabel(string $status): string
    {
        return $this->enumLabel('statuses', $status);
    }

    public function mediaUrl(?MediaAsset $asset): ?string
    {
        if (! $asset) {
            return null;
        }

        return $asset->url();
    }

    public function balances()
    {
        return $this->baseQuery()
            ->with([
                'tenant:id,code,name',
                'stockItem' => fn ($query) => $query->select([
                    'id',
                    'tenant_id',
                    'code',
                    'tenant_item_code',
                    'name',
                    'product_type',
                    'status',
                ]),
                'stockItem.barcodeAliases:id,tenant_id,model_type,model_id,barcode,is_primary,is_active',
                'stockItem.primaryImage:id,tenant_id,model_type,model_id,type,disk,path,file_name,is_primary,sort_order',
                'stockItem.skus' => fn ($query) => $query
                    ->select([
                        'id',
                        'tenant_id',
                        'shop_id',
                        'stock_item_id',
                        'sku',
                        'platform_sku',
                        'platform_label_code',
                    ])
                    ->orderBy('sku'),
                'stockItem.skus.shop:id,code,name,platform',
            ])
            ->orderBy('tenant_id')
            ->orderBy('warehouse_id')
            ->orderBy(
                InventoryBalance::query()
                    ->select('code')
                    ->from('stock_items')
                    ->whereColumn('stock_items.id', 'inventory_balances.stock_item_id')
                    ->limit(1),
            )
            ->paginate($this->perPage);
    }

    public function summary(): array
    {
        $query = $this->baseQuery();

        return [
            'stock_items' => (clone $query)->count(),
            'on_hand' => (clone $query)->sum('on_hand_qty'),
            'available' => (clone $query)->sum('available_qty'),
            'reserved' => (clone $query)->sum('reserved_qty'),
        ];
    }

    public function showTenantColumn(): bool
    {
        $user = Auth::user();

        return $user?->user_type === 'internal' && $this->tenantId === '';
    }

    /**
     * @return Builder<InventoryBalance>
     */
    private function baseQuery(): Builder
    {
        return InventoryBalance::query()
            ->when($this->visibleTenantIds() !== null, fn ($query) => $query->whereIn('tenant_id', $this->visibleTenantIds()))
            ->when($this->tenantId !== '', fn ($query) => $query->where('tenant_id', $this->tenantId))
            ->when($this->warehouseId !== '', fn ($query) => $query->where('warehouse_id', $this->warehouseId))
            ->when($this->productType !== '', fn ($query) => $query->whereHas('stockItem', fn ($query) => $query->where('product_type', $this->productType)))
            ->when($this->status !== '', fn ($query) => $query->whereHas('stockItem', fn ($query) => $query->where('status', $this->status)))
            ->when($this->shopId !== '', fn ($query) => $query->whereHas('stockItem.skus', fn ($query) => $query->where('shop_id', $this->shopId)))
            ->when($this->search !== '', function ($query) {
                $search = '%'.$this->search.'%';

                $query->where(function ($query) use ($search) {
                    $query
                        ->whereHas('stockItem', function ($query) use ($search) {
                            $query
                                ->where('code', 'like', $search)
                                ->orWhere('tenant_item_code', 'like', $search)
                                ->orWhere('name', 'like', $search)
                                ->orWhereHas('barcodeAliases', fn ($query) => $query
                                    ->where('is_active', true)
                                    ->where('barcode', 'like', $search));
                        })
                        ->orWhereHas('stockItem.skus', function ($query) use ($search) {
                            $query
                                ->where('sku', 'like', $search)
                                ->orWhere('platform_sku', 'like', $search)
                                ->orWhere('platform_label_code', 'like', $search);
                        });
                });
            });
    }

    private function tenantOptions(): Collection
    {
        return Tenant::query()
            ->when($this->visibleTenantIds() !== null, fn ($query) => $query->whereIn('id', $this->visibleTenantIds()))
            ->orderBy('name')
            ->get(['id', 'code', 'name']);
    }

    public function stockItemPrimaryCode(StockItem $stockItem): string
    {
        $tenantCode = trim((string) $stockItem->tenant_item_code);

        if (in_array($this->stockItemCodeDisplay, [self::STOCK_ITEM_CODE_DISPLAY_TENANT, self::STOCK_ITEM_CODE_DISPLAY_BOTH], true)
            && $tenantCode !== '') {
            return $tenantCode;
        }

        return (string) $stockItem->code;
    }

    public function stockItemSecondaryCode(StockItem $stockItem): ?string
    {
        if ($this->stockItemCodeDisplay !== self::STOCK_ITEM_CODE_DISPLAY_BOTH) {
            return null;
        }

        return filled($stockItem->tenant_item_code) ? (string) $stockItem->code : null;
    }

    public function stockItemCodeDisplayOptions(): array
    {
        return [
            self::STOCK_ITEM_CODE_DISPLAY_SYSTEM => __('skus.stock_item_code_display_system'),
            self::STOCK_ITEM_CODE_DISPLAY_TENANT => __('skus.stock_item_code_display_tenant'),
            self::STOCK_ITEM_CODE_DISPLAY_BOTH => __('skus.stock_item_code_display_both'),
        ];
    }

    private function warehouseOptions(): Collection
    {
        return Warehouse::query()
            ->where('status', 'active')
            ->whereHas('inventoryBalances', fn ($query) => $this->applyTenantScope($query))
            ->orderBy('name')
            ->get(['id', 'code', 'name']);
    }

    private function shopOptions(): Collection
    {
        return Shop::query()
            ->when($this->visibleTenantIds() !== null, fn ($query) => $query->whereIn('tenant_id', $this->visibleTenantIds()))
            ->when($this->tenantId !== '', fn ($query) => $query->where('tenant_id', $this->tenantId))
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'platform']);
    }

    private function productTypeOptions(): Collection
    {
        return ProductType::orderBy('sort_order')->orderBy('name')->get(['slug', 'name']);
    }

    private function statusOptions(): Collection
    {
        return InventoryBalance::query()
            ->join('stock_items', 'stock_items.id', '=', 'inventory_balances.stock_item_id')
            ->when($this->visibleTenantIds() !== null, fn ($query) => $query->whereIn('inventory_balances.tenant_id', $this->visibleTenantIds()))
            ->select('stock_items.status')
            ->distinct()
            ->orderBy('stock_items.status')
            ->pluck('stock_items.status');
    }

    private function applyTenantScope(Builder $query): Builder
    {
        return $query->when($this->visibleTenantIds() !== null, fn ($query) => $query->whereIn('tenant_id', $this->visibleTenantIds()));
    }

    private function visibleTenantIds(): ?array
    {
        if ($this->visibleTenantIdsResolved) {
            return $this->visibleTenantIdsCache;
        }

        $this->visibleTenantIdsResolved = true;
        $user = Auth::user();

        if (! $user) {
            return $this->visibleTenantIdsCache = [];
        }

        if ($user->user_type === 'internal') {
            return $this->visibleTenantIdsCache = null;
        }

        return $this->visibleTenantIdsCache = $user->activeTenantIds();
    }

    private function normalizeStockItemCodeDisplay(mixed $value): string
    {
        return is_string($value) && in_array($value, self::STOCK_ITEM_CODE_DISPLAY_OPTIONS, true)
            ? $value
            : self::STOCK_ITEM_CODE_DISPLAY_SYSTEM;
    }

    private function normalizePerPage(int $perPage): int
    {
        return in_array($perPage, self::PER_PAGE_OPTIONS, true) ? $perPage : 10;
    }

    private function stockItemCodeDisplayPreference(): string
    {
        $user = Auth::user();
        $preference = $user?->preference('stock_item_code_display');

        if (is_string($preference)) {
            return $this->normalizeStockItemCodeDisplay($preference);
        }

        return $user?->preference('show_tenant_item_code', false)
            ? self::STOCK_ITEM_CODE_DISPLAY_BOTH
            : self::STOCK_ITEM_CODE_DISPLAY_SYSTEM;
    }
}
