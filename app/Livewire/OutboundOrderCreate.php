<?php

namespace App\Livewire;

use App\Models\OutboundOrder;
use App\Models\Sku;
use App\Models\StockItem;
use App\Models\Tenant;
use App\Models\Warehouse;
use App\Services\InventoryService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Attributes\Url;
use Livewire\Component;

class OutboundOrderCreate extends Component
{
    #[Url(as: 'tenant_id', except: '')]
    public string $tenantId = '';

    #[Url(as: 'warehouse_id', except: '')]
    public string $warehouseId = '';

    public string $ref = '';

    public string $expectedShipAt = '';

    public string $note = '';

    public string $skuSearch = '';

    public string $recipientName = '';

    public string $recipientPhone = '';

    public string $recipientCountryCode = '';

    public string $recipientPostalCode = '';

    public string $recipientState = '';

    public string $recipientCity = '';

    public string $recipientAddressLine1 = '';

    public string $recipientAddressLine2 = '';

    public string $shippingMethod = '';

    public array $lines = [
        ['sku_id' => '', 'qty' => '', 'note' => ''],
    ];

    public function mount(): void
    {
        if (! $this->isInternalUser() && $this->tenantId === '') {
            $this->tenantId = (string) ($this->activeTenantIds()[0] ?? '');
        }
    }

    public function updatedTenantId(): void
    {
        $this->warehouseId = '';
        $this->lines = [['sku_id' => '', 'qty' => '', 'note' => '']];
    }

    public function addLine(): void
    {
        $this->lines[] = ['sku_id' => '', 'qty' => '', 'note' => ''];
    }

    public function removeLine(int $index): void
    {
        if (count($this->lines) <= 1) {
            return;
        }

        array_splice($this->lines, $index, 1);
        $this->lines = array_values($this->lines);
    }

    public function save()
    {
        $tenantId = $this->validatedTenantId();
        $this->validateInput($tenantId);

        DB::transaction(function () use ($tenantId) {
            $order = OutboundOrder::create([
                'tenant_id' => $tenantId,
                'warehouse_id' => (int) $this->warehouseId,
                'ref' => $this->nullableString($this->ref),
                'status' => OutboundOrder::STATUS_PENDING,
                'expected_ship_at' => $this->nullableString($this->expectedShipAt),
                'note' => $this->nullableString($this->note),
                'recipient_name' => $this->nullableString($this->recipientName),
                'recipient_phone' => $this->nullableString($this->recipientPhone),
                'recipient_country_code' => $this->nullableString(strtoupper($this->recipientCountryCode)),
                'recipient_postal_code' => $this->nullableString($this->recipientPostalCode),
                'recipient_state' => $this->nullableString($this->recipientState),
                'recipient_city' => $this->nullableString($this->recipientCity),
                'recipient_address_line1' => $this->nullableString($this->recipientAddressLine1),
                'recipient_address_line2' => $this->nullableString($this->recipientAddressLine2),
                'shipping_method' => $this->nullableString($this->shippingMethod),
                'created_by_user_id' => Auth::id(),
            ]);

            foreach ($this->lines as $index => $lineInput) {
                $sku = Sku::query()
                    ->where('tenant_id', $tenantId)
                    ->with('bundleComponents')
                    ->findOrFail($lineInput['sku_id']);

                $userQty = (int) $lineInput['qty'];
                $lineNote = $this->nullableString($lineInput['note'] ?? '');

                if ($sku->sku_type === 'virtual_bundle') {
                    $this->createVirtualBundleLines($order, $sku, $tenantId, $userQty, $lineNote, $index);

                    continue;
                }

                if ($sku->stock_item_id === null) {
                    throw ValidationException::withMessages([
                        "lines.{$index}.sku_id" => __('outbound.sku_not_shippable'),
                    ]);
                }

                $this->reserveLine($tenantId, (int) $this->warehouseId, $sku->stock_item_id, $userQty, $order->id, $index);

                $order->lines()->create([
                    'tenant_id' => $tenantId,
                    'sku_id' => $sku->id,
                    'stock_item_id' => $sku->stock_item_id,
                    'qty' => $userQty,
                    'note' => $lineNote,
                ]);
            }
        });

        session()->flash('status', __('outbound.order_created'));

        return redirect()->route('outbound.index');
    }

    public function render()
    {
        return view('livewire.outbound-order-create', [
            'tenants' => $this->tenantOptions(),
            'warehouses' => $this->warehouseOptions(),
            'skus' => $this->skuOptions(),
            'showTenantSelect' => $this->isInternalUser(),
            'currentTenant' => $this->currentTenant(),
        ])->layout('inventory', [
            'title' => __('outbound.create_page_title'),
            'subtitle' => __('outbound.create_page_subtitle'),
        ]);
    }

    private function createVirtualBundleLines(OutboundOrder $order, Sku $sku, int $tenantId, int $userQty, ?string $lineNote, int $index): void
    {
        $components = $sku->bundleComponents;

        if ($components->isEmpty()) {
            throw ValidationException::withMessages([
                "lines.{$index}.sku_id" => __('outbound.bundle_no_components'),
            ]);
        }

        foreach ($components as $component) {
            if ($component->tenant_id !== $tenantId) {
                throw ValidationException::withMessages([
                    "lines.{$index}.sku_id" => __('outbound.bundle_invalid_tenant'),
                ]);
            }
        }

        $componentStockItemIds = $components->pluck('component_stock_item_id')->all();
        $invalidCount = StockItem::query()
            ->whereIn('id', $componentStockItemIds)
            ->where('tenant_id', '!=', $tenantId)
            ->count();

        if ($invalidCount > 0) {
            throw ValidationException::withMessages([
                "lines.{$index}.sku_id" => __('outbound.bundle_invalid_tenant'),
            ]);
        }

        $parentLine = $order->lines()->create([
            'tenant_id' => $tenantId,
            'sku_id' => $sku->id,
            'stock_item_id' => null,
            'qty' => $userQty,
            'note' => $lineNote,
        ]);

        foreach ($components as $component) {
            $componentQty = $userQty * $component->quantity;
            $this->reserveLine($tenantId, (int) $this->warehouseId, $component->component_stock_item_id, $componentQty, $order->id, $index);

            $order->lines()->create([
                'parent_line_id' => $parentLine->id,
                'tenant_id' => $tenantId,
                'sku_id' => $sku->id,
                'stock_item_id' => $component->component_stock_item_id,
                'qty' => $componentQty,
            ]);
        }
    }

    private function reserveLine(int $tenantId, int $warehouseId, int $stockItemId, int $quantity, int $orderId, int $index): void
    {
        try {
            app(InventoryService::class)->reserveStock(
                tenantId: $tenantId,
                warehouseId: $warehouseId,
                stockItemId: $stockItemId,
                quantity: $quantity,
                context: [
                    'ref_type' => 'outbound_order',
                    'ref_id' => (string) $orderId,
                    'user_id' => Auth::id(),
                ],
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                "lines.{$index}.qty" => $exception->getMessage(),
            ]);
        }
    }

    private function validateInput(int $tenantId): void
    {
        validator($this->formData(), [
            'tenant_id' => ['required', 'integer'],
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')],
            'ref' => ['nullable', 'string', 'max:255'],
            'expected_ship_at' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:1000'],
            'recipient_name' => ['nullable', 'string', 'max:255'],
            'recipient_phone' => ['nullable', 'string', 'max:50'],
            'recipient_country_code' => ['nullable', 'string', 'size:2'],
            'recipient_postal_code' => ['nullable', 'string', 'max:20'],
            'recipient_state' => ['nullable', 'string', 'max:100'],
            'recipient_city' => ['nullable', 'string', 'max:100'],
            'recipient_address_line1' => ['nullable', 'string', 'max:255'],
            'recipient_address_line2' => ['nullable', 'string', 'max:255'],
            'shipping_method' => ['nullable', 'string', 'max:100'],
            'lines' => [
                'required',
                'array',
                'min:1',
                function ($attribute, $value, $fail) {
                    $ids = collect($value)->pluck('sku_id')->filter()->values();

                    if ($ids->count() !== $ids->unique()->count()) {
                        $fail(__('outbound.duplicate_skus'));
                    }
                },
            ],
            'lines.*.sku_id' => ['required', 'integer', Rule::exists('skus', 'id')->where('tenant_id', $tenantId)],
            'lines.*.qty' => ['required', 'integer', 'min:1'],
            'lines.*.note' => ['nullable', 'string', 'max:500'],
        ])->validate();
    }

    private function formData(): array
    {
        return [
            'tenant_id' => $this->tenantId,
            'warehouse_id' => $this->warehouseId,
            'ref' => $this->ref,
            'expected_ship_at' => $this->expectedShipAt,
            'note' => $this->note,
            'recipient_name' => $this->recipientName,
            'recipient_phone' => $this->recipientPhone,
            'recipient_country_code' => $this->recipientCountryCode,
            'recipient_postal_code' => $this->recipientPostalCode,
            'recipient_state' => $this->recipientState,
            'recipient_city' => $this->recipientCity,
            'recipient_address_line1' => $this->recipientAddressLine1,
            'recipient_address_line2' => $this->recipientAddressLine2,
            'shipping_method' => $this->shippingMethod,
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
            ->orderBy('name')
            ->get(['id', 'code', 'name']);
    }

    private function skuOptions(): Collection
    {
        $search = '%'.$this->skuSearch.'%';

        return Sku::query()
            ->where('tenant_id', $this->tenantId)
            ->where(fn ($query) => $query
                ->where('sku_type', 'virtual_bundle')
                ->orWhereNotNull('stock_item_id'))
            ->with(['shop:id,code', 'stockItem:id,code,name'])
            ->when($this->skuSearch !== '', function ($query) use ($search) {
                $query->where(function ($query) use ($search) {
                    $query
                        ->where('sku', 'like', $search)
                        ->orWhere('name', 'like', $search)
                        ->orWhere('platform_sku', 'like', $search)
                        ->orWhere('platform_label_code', 'like', $search)
                        ->orWhereHas('stockItem', function ($query) use ($search) {
                            $query
                                ->where('code', 'like', $search)
                                ->orWhere('name', 'like', $search)
                                ->orWhere('barcode', 'like', $search);
                        });
                });
            })
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
            throw ValidationException::withMessages(['tenantId' => __('skus.invalid_tenant')]);
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

    private function nullableString(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
