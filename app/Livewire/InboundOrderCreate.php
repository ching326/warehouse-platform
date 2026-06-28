<?php

namespace App\Livewire;

use App\Livewire\Concerns\AutoSelectsSingleActiveWarehouse;
use App\Models\InboundOrder;
use App\Models\Shop;
use App\Models\Sku;
use App\Models\Tenant;
use App\Models\Warehouse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\Component;

class InboundOrderCreate extends Component
{
    use AutoSelectsSingleActiveWarehouse;

    #[Url(as: 'tenant_id', except: '')]
    public string $tenantId = '';

    #[Url(as: 'warehouse_id', except: '')]
    public string $warehouseId = '';

    public string $shopId = '';

    public string $ref = '';

    public string $expectedAt = '';

    public string $expectedCartonCount = '';

    public string $cartonMark = '';

    public string $note = '';

    public array $lines = [
        ['sku_id' => '', 'expected_qty' => '', 'note' => ''],
    ];

    public array $skuSearches = [''];

    public function mount(): void
    {
        if (! $this->isInternalUser() && $this->tenantId === '') {
            $this->tenantId = (string) ($this->activeTenantIds()[0] ?? '');
        }

        $this->autoSelectSingleActiveWarehouse();
    }

    public function updatedTenantId(): void
    {
        $this->warehouseId = '';
        $this->shopId = '';
        $this->lines = [['sku_id' => '', 'expected_qty' => '', 'note' => '']];
        $this->skuSearches = [''];
        $this->autoSelectSingleActiveWarehouse();
    }

    public function updatedShopId(): void
    {
        $this->lines = [['sku_id' => '', 'expected_qty' => '', 'note' => '']];
        $this->skuSearches = [''];
    }

    public function updatedSkuSearches(mixed $_value, mixed $key): void
    {
        $index = (int) $key;

        if (isset($this->lines[$index])) {
            $this->lines[$index]['sku_id'] = '';
        }
    }

    public function addLine(): void
    {
        $this->lines[] = ['sku_id' => '', 'expected_qty' => '', 'note' => ''];
        $this->skuSearches[] = '';
    }

    public function removeLine(int $index): void
    {
        if (count($this->lines) <= 1) {
            return;
        }

        array_splice($this->lines, $index, 1);
        array_splice($this->skuSearches, $index, 1);
        $this->lines = array_values($this->lines);
        $this->skuSearches = array_values($this->skuSearches);
    }

    public function save()
    {
        $tenantId = $this->validatedTenantId();
        $this->validateInput($tenantId);

        DB::transaction(function () use ($tenantId) {
            $order = InboundOrder::create([
                'tenant_id' => $tenantId,
                'warehouse_id' => (int) $this->warehouseId,
                'ref' => $this->ref !== '' ? $this->nullableString($this->ref) : 'IB-PENDING-'.Str::uuid(),
                'status' => InboundOrder::STATUS_PENDING,
                'expected_at' => $this->nullableString($this->expectedAt),
                'expected_carton_count' => $this->expectedCartonCount === '' ? null : (int) $this->expectedCartonCount,
                'carton_mark' => $this->nullableString($this->cartonMark),
                'note' => $this->nullableString($this->note),
                'created_by_user_id' => Auth::id(),
            ]);

            if ($this->ref === '') {
                $order->update(['ref' => InboundOrder::buildRef($order->id, $order->tenant->code)]);
            }

            foreach ($this->lines as $index => $lineInput) {
                $sku = Sku::query()
                    ->where('tenant_id', $tenantId)
                    ->where('status', 'active')
                    ->findOrFail($lineInput['sku_id']);

                if ($sku->sku_type === 'virtual_bundle' || $sku->stock_item_id === null) {
                    throw ValidationException::withMessages([
                        "lines.{$index}.sku_id" => __('inbound.sku_not_receivable'),
                    ]);
                }

                $order->lines()->create([
                    'tenant_id' => $tenantId,
                    'sku_id' => $sku->id,
                    'stock_item_id' => $sku->stock_item_id,
                    'expected_qty' => (int) $lineInput['expected_qty'],
                    'received_qty' => 0,
                    'note' => $this->nullableString($lineInput['note'] ?? ''),
                ]);
            }
        });

        session()->flash('status', __('inbound.order_created'));

        return redirect()->route('inbound.index');
    }

    public function render()
    {
        return view('livewire.inbound-order-create', [
            'tenants' => $this->tenantOptions(),
            'warehouses' => $this->warehouseOptions(),
            'shops' => $this->shopOptions(),
            'skuOptionsByLine' => $this->skuOptionsByLine(),
            'showTenantSelect' => $this->isInternalUser(),
            'currentTenant' => $this->currentTenant(),
        ])->layout('inventory', [
            'title' => __('inbound.create_page_title'),
            'subtitle' => __('inbound.create_page_subtitle'),
        ]);
    }

    private function validateInput(int $tenantId): void
    {
        $skuExistsRule = Rule::exists('skus', 'id')->where('tenant_id', $tenantId)->where('status', 'active');

        if ($this->shopId !== '') {
            $skuExistsRule->where('shop_id', (int) $this->shopId);
        }

        validator($this->formData(), [
            'tenant_id' => ['required', 'integer'],
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('status', 'active')],
            'shop_id' => ['nullable', 'integer', Rule::exists('shops', 'id')->where('tenant_id', $tenantId)],
            'ref' => ['nullable', 'string', 'max:255'],
            'expected_at' => ['nullable', 'date'],
            'expected_carton_count' => ['nullable', 'integer', 'min:0'],
            'carton_mark' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:1000'],
            'lines' => [
                'required',
                'array',
                'min:1',
                function ($attribute, $value, $fail) {
                    $ids = collect($value)->pluck('sku_id')->filter()->values();

                    if ($ids->count() !== $ids->unique()->count()) {
                        $fail(__('inbound.duplicate_skus'));
                    }
                },
            ],
            'lines.*.sku_id' => ['required', 'integer', $skuExistsRule],
            'lines.*.expected_qty' => ['required', 'integer', 'min:1'],
            'lines.*.note' => ['nullable', 'string', 'max:500'],
        ])->validate();
    }

    private function formData(): array
    {
        return [
            'tenant_id' => $this->tenantId,
            'warehouse_id' => $this->warehouseId,
            'shop_id' => $this->shopId,
            'ref' => $this->ref,
            'expected_at' => $this->expectedAt,
            'expected_carton_count' => $this->expectedCartonCount,
            'carton_mark' => $this->cartonMark,
            'note' => $this->note,
            'lines' => $this->lines,
        ];
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

    private function shopOptions(): Collection
    {
        if ($this->tenantId === '') {
            return collect();
        }

        return Shop::query()
            ->where('tenant_id', $this->tenantId)
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'code', 'name']);
    }

    private function skuOptionsByLine(): array
    {
        return collect($this->lines)
            ->keys()
            ->mapWithKeys(fn ($index) => [$index => $this->skuOptions((int) $index)])
            ->all();
    }

    private function skuOptions(int $lineIndex): Collection
    {
        $searchTerm = trim((string) ($this->skuSearches[$lineIndex] ?? ''));
        $search = '%'.$searchTerm.'%';

        return Sku::query()
            ->where('tenant_id', $this->tenantId)
            ->where('status', 'active')
            ->where('sku_type', '!=', 'virtual_bundle')
            ->whereNotNull('stock_item_id')
            ->when($this->shopId !== '', fn ($query) => $query->where('shop_id', $this->shopId))
            ->when($searchTerm !== '', function ($query) use ($search) {
                $query->where(function ($query) use ($search) {
                    $query
                        ->where('sku', 'like', $search)
                        ->orWhere('name', 'like', $search)
                        ->orWhere('platform_sku', 'like', $search)
                        ->orWhere('platform_label_code', 'like', $search)
                        ->orWhereHas('stockItem', function ($query) use ($search) {
                            $query
                                ->where('code', 'like', $search)
                                ->orWhere('name', 'like', $search);
                        });
                });
            })
            ->with(['shop:id,code', 'stockItem:id,code,name'])
            ->orderBy('sku')
            ->limit(50)
            ->get(['id', 'tenant_id', 'shop_id', 'stock_item_id', 'sku', 'name', 'platform_sku', 'platform_label_code', 'sku_type']);
    }

    private function currentTenant(): ?Tenant
    {
        if ($this->tenantId === '') {
            return null;
        }

        return Tenant::query()->find($this->tenantId, ['id', 'code', 'name']);
    }

    private function isInternalUser(): bool
    {
        $user = Auth::user();

        return $user?->user_type === 'internal';
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
            throw ValidationException::withMessages(['tenantId' => __('skus.invalid_tenant')]);
        }

        return $tenantId;
    }

    private function activeTenantIds(): array
    {
        $user = Auth::user();

        if (! $user) {
            return [];
        }

        return $user->activeTenantIds();
    }

    private function nullableString(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
