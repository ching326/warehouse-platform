<?php

namespace App\Livewire;

use App\Livewire\Concerns\AutoSelectsSingleActiveWarehouse;
use App\Models\FbaWarehouse;
use App\Models\OutboundOrder;
use App\Models\ShippingMethod;
use App\Models\Shop;
use App\Models\Sku;
use App\Models\StockItem;
use App\Models\Tenant;
use App\Models\Warehouse;
use App\Services\InventoryService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Attributes\Url;
use Livewire\Component;

class OutboundOrderCreate extends Component
{
    use AutoSelectsSingleActiveWarehouse;

    #[Url(as: 'tenant_id', except: '')]
    public string $tenantId = '';

    #[Url(as: 'warehouse_id', except: '')]
    public string $warehouseId = '';

    public string $shopId = '';

    public string $ref = '';

    public string $note = '';

    public string $recipientName = '';

    public string $recipientPhone = '';

    public string $recipientCountryCode = '';

    public string $recipientPostalCode = '';

    public string $recipientState = '';

    public string $recipientCity = '';

    public string $recipientAddressLine1 = '';

    public string $recipientAddressLine2 = '';

    public string $shippingMethodId = '';

    #[Url(as: 'reason', except: '')]
    public string $reason = '';

    public string $status = OutboundOrder::STATUS_DRAFT;

    public string $fbaWarehouseId = '';

    public bool $recipientCollapsed = false;

    public bool $showDraftSaveConfirmation = false;

    public array $lines = [
        ['sku_id' => '', 'qty' => '', 'note' => ''],
    ];

    public array $skuSearches = [''];

    /**
     * Reasons offered for manual outbound creation (customer_order is consolidation-only).
     *
     * @return list<string>
     */
    public function manualReasons(): array
    {
        return [
            OutboundOrder::REASON_RE_SHIP,
            OutboundOrder::REASON_REPLACEMENT,
            OutboundOrder::REASON_GIFT,
            OutboundOrder::REASON_FBA,
            OutboundOrder::REASON_RETURN_TO_TENANT,
            OutboundOrder::REASON_B2B,
            OutboundOrder::REASON_SAMPLE,
            OutboundOrder::REASON_OTHER,
        ];
    }

    public function mount(): void
    {
        if (! $this->isInternalUser() && $this->tenantId === '') {
            $this->tenantId = (string) ($this->activeTenantIds()[0] ?? '');
        }

        if ($this->reason !== '' && ! in_array($this->reason, $this->manualReasons(), true)) {
            $this->reason = '';
        }

        $this->autoSelectSingleActiveWarehouse();
    }

    public function updatedTenantId(): void
    {
        $this->warehouseId = '';
        $this->shopId = '';
        $this->lines = [['sku_id' => '', 'qty' => '', 'note' => '']];
        $this->skuSearches = [''];
        $this->autoSelectSingleActiveWarehouse();
    }

    public function updatedShopId(): void
    {
        $this->lines = [['sku_id' => '', 'qty' => '', 'note' => '']];
        $this->skuSearches = [''];
    }

    public function updatedReason(): void
    {
        if ($this->reason !== OutboundOrder::REASON_FBA) {
            $this->fbaWarehouseId = '';
        }
    }

    public function updatedStatus(): void
    {
        $this->showDraftSaveConfirmation = false;
    }

    public function updatedFbaWarehouseId(): void
    {
        if ($this->fbaWarehouseId === '') {
            return;
        }

        $warehouse = FbaWarehouse::query()
            ->where('status', FbaWarehouse::STATUS_ACTIVE)
            ->find($this->fbaWarehouseId);

        if (! $warehouse) {
            $this->fbaWarehouseId = '';

            return;
        }

        $this->recipientName = $warehouse->name;
        $this->recipientPhone = (string) ($warehouse->phone ?? '');
        $this->recipientCountryCode = $warehouse->country_code;
        $this->recipientPostalCode = (string) ($warehouse->postal_code ?? '');
        $this->recipientState = (string) ($warehouse->state ?? '');
        $this->recipientCity = (string) ($warehouse->city ?? '');
        $this->recipientAddressLine1 = (string) ($warehouse->address_line1 ?? '');
        $this->recipientAddressLine2 = (string) ($warehouse->address_line2 ?? '');
    }

    public function toggleRecipientCollapsed(): void
    {
        $this->recipientCollapsed = ! $this->recipientCollapsed;
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
        $this->lines[] = ['sku_id' => '', 'qty' => '', 'note' => ''];
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

    public function save(bool $confirmedDraft = false)
    {
        $tenantId = $this->validatedTenantId();
        $this->validateInput($tenantId);

        if ($this->status === OutboundOrder::STATUS_DRAFT && ! $confirmedDraft) {
            $this->showDraftSaveConfirmation = true;

            return null;
        }

        $this->showDraftSaveConfirmation = false;

        DB::transaction(function () use ($tenantId) {
            $order = OutboundOrder::create([
                'tenant_id' => $tenantId,
                'warehouse_id' => (int) $this->warehouseId,
                'ref' => $this->ref !== '' ? $this->nullableString($this->ref) : 'OB-PENDING-'.Str::uuid(),
                'status' => $this->status,
                'reason' => $this->reason,
                'note' => $this->nullableString($this->note),
                'recipient_name' => $this->nullableString($this->recipientName),
                'recipient_phone' => $this->nullableString($this->recipientPhone),
                'recipient_country_code' => $this->nullableString(strtoupper($this->recipientCountryCode)),
                'recipient_postal_code' => $this->nullableString($this->recipientPostalCode),
                'recipient_state' => $this->nullableString($this->recipientState),
                'recipient_city' => $this->nullableString($this->recipientCity),
                'recipient_address_line1' => $this->nullableString($this->recipientAddressLine1),
                'recipient_address_line2' => $this->nullableString($this->recipientAddressLine2),
                'shipping_method_id' => $this->shippingMethodId !== '' ? (int) $this->shippingMethodId : null,
                'created_by_user_id' => Auth::id(),
            ]);

            if ($this->ref === '') {
                $order->update(['ref' => OutboundOrder::buildRef($order->id, $order->tenant->code)]);
            }

            foreach ($this->lines as $index => $lineInput) {
                $sku = Sku::query()
                    ->where('tenant_id', $tenantId)
                    ->where('status', 'active')
                    ->with('bundleComponents')
                    ->findOrFail($lineInput['sku_id']);

                $userQty = (int) $lineInput['qty'];
                $lineNote = $this->nullableString($lineInput['note'] ?? '');

                if ($sku->sku_type === 'virtual_bundle') {
                    $this->createVirtualBundleLines($order, $sku, $tenantId, $userQty, $lineNote, $index, $this->status === OutboundOrder::STATUS_RESERVED);

                    continue;
                }

                if ($sku->stock_item_id === null) {
                    throw ValidationException::withMessages([
                        "lines.{$index}.sku_id" => __('outbound.sku_not_shippable'),
                    ]);
                }

                if ($this->status === OutboundOrder::STATUS_RESERVED) {
                    $this->reserveLine($tenantId, (int) $this->warehouseId, $sku->stock_item_id, $userQty, $order->id, $index);
                }

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

    public function confirmSaveDraft()
    {
        return $this->save(confirmedDraft: true);
    }

    public function cancelSaveDraft(): void
    {
        $this->showDraftSaveConfirmation = false;
    }

    public function render()
    {
        return view('livewire.outbound-order-create', [
            'tenants' => $this->tenantOptions(),
            'shops' => $this->shopOptions(),
            'warehouses' => $this->warehouseOptions(),
            'fbaWarehouses' => $this->fbaWarehouseOptions(),
            'shippingMethods' => $this->shippingMethodOptions(),
            'statusOptions' => $this->statusOptions(),
            'skuOptionsByLine' => $this->skuOptionsByLine(),
            'showTenantSelect' => $this->isInternalUser(),
            'currentTenant' => $this->currentTenant(),
        ])->layout('inventory', [
            'title' => __('outbound.create_page_title'),
            'subtitle' => __('outbound.create_page_subtitle'),
        ]);
    }

    public function updatedRecipientPostalCode(): void
    {
        $postcode = $this->normalizeJapanesePostalCode($this->recipientPostalCode);

        if ($postcode === '') {
            return;
        }

        $this->recipientPostalCode = $postcode;

        $address = $this->japanesePostalAddress($postcode) ?? $this->lookupJapanesePostalAddress($postcode);

        if (! $address) {
            return;
        }

        $this->recipientCountryCode = 'JP';
        $this->recipientState = $address['state'];
        $this->recipientCity = $address['city'];
        $this->recipientAddressLine1 = $address['address_line1'];
    }

    private function createVirtualBundleLines(OutboundOrder $order, Sku $sku, int $tenantId, int $userQty, ?string $lineNote, int $index, bool $reserveStock): void
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
            if ($reserveStock) {
                $this->reserveLine($tenantId, (int) $this->warehouseId, $component->component_stock_item_id, $componentQty, $order->id, $index);
            }

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
        $skuExistsRule = Rule::exists('skus', 'id')->where('tenant_id', $tenantId)->where('status', 'active');

        if ($this->shopId !== '') {
            $skuExistsRule->where('shop_id', (int) $this->shopId);
        }

        $validator = validator($this->formData(), [
            'tenant_id' => ['required', 'integer'],
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('status', 'active')],
            'shop_id' => ['nullable', 'integer', Rule::exists('shops', 'id')->where('tenant_id', $tenantId)],
            'ref' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:1000'],
            'recipient_name' => ['nullable', 'string', 'max:255'],
            'recipient_phone' => ['nullable', 'string', 'max:50'],
            'recipient_country_code' => ['nullable', 'string', 'size:2'],
            'recipient_postal_code' => ['nullable', 'string', 'max:20'],
            'recipient_state' => ['nullable', 'string', 'max:100'],
            'recipient_city' => ['nullable', 'string', 'max:100'],
            'recipient_address_line1' => ['nullable', 'string', 'max:255'],
            'recipient_address_line2' => ['nullable', 'string', 'max:255'],
            'shipping_method_id' => ['nullable', Rule::exists('shipping_methods', 'id')->where('status', 'active')],
            'status' => ['required', Rule::in(array_keys($this->statusOptions()))],
            'reason' => ['required', Rule::in($this->manualReasons())],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.sku_id' => ['required', 'integer', $skuExistsRule],
            'lines.*.qty' => ['required', 'integer', 'min:1'],
            'lines.*.note' => ['nullable', 'string', 'max:500'],
        ]);

        $validator->after(function ($validator): void {
            $seen = [];

            foreach ($this->lines as $index => $line) {
                $skuId = (string) ($line['sku_id'] ?? '');

                if ($skuId === '') {
                    continue;
                }

                if (isset($seen[$skuId])) {
                    $validator->errors()->add("lines.{$index}.sku_id", __('outbound.duplicate_skus'));

                    continue;
                }

                $seen[$skuId] = true;
            }
        });

        $validator->validate();
    }

    private function formData(): array
    {
        return [
            'tenant_id' => $this->tenantId,
            'warehouse_id' => $this->warehouseId,
            'shop_id' => $this->shopId,
            'ref' => $this->ref,
            'note' => $this->note,
            'recipient_name' => $this->recipientName,
            'recipient_phone' => $this->recipientPhone,
            'recipient_country_code' => $this->recipientCountryCode,
            'recipient_postal_code' => $this->recipientPostalCode,
            'recipient_state' => $this->recipientState,
            'recipient_city' => $this->recipientCity,
            'recipient_address_line1' => $this->recipientAddressLine1,
            'recipient_address_line2' => $this->recipientAddressLine2,
            'shipping_method_id' => $this->shippingMethodId,
            'status' => $this->status,
            'reason' => $this->reason,
            'lines' => $this->lines,
        ];
    }

    private function statusOptions(): array
    {
        return [
            OutboundOrder::STATUS_DRAFT => __('outbound.status_draft'),
            OutboundOrder::STATUS_PENDING => __('outbound.status_pending'),
            OutboundOrder::STATUS_RESERVED => __('outbound.status_reserved'),
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

    private function shippingMethodOptions(): Collection
    {
        return ShippingMethod::query()
            ->where('shipping_methods.status', 'active')
            ->with('carrier:id,code,name')
            ->ordered()
            ->get(['shipping_methods.id', 'shipping_methods.carrier_id', 'shipping_methods.code', 'shipping_methods.name']);
    }

    private function fbaWarehouseOptions(): Collection
    {
        return FbaWarehouse::query()
            ->where('status', FbaWarehouse::STATUS_ACTIVE)
            ->orderBy('country_code')
            ->orderBy('code')
            ->get(['id', 'country_code', 'code', 'name', 'postal_code', 'state', 'city']);
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
            ->where(fn ($query) => $query
                ->where('sku_type', 'virtual_bundle')
                ->orWhereNotNull('stock_item_id'))
            ->when($this->shopId !== '', fn ($query) => $query->where('shop_id', $this->shopId))
            ->when($searchTerm !== '', function ($query) use ($search) {
                $query->where(function ($query) use ($search) {
                    $query
                        ->where('sku', 'like', $search)
                        ->orWhere('platform_sku', 'like', $search)
                        ->orWhere('platform_label_code', 'like', $search)
                        ->orWhereHas('stockItem', function ($query) use ($search) {
                            $query
                                ->where('code', 'like', $search)
                                ->orWhere('name', 'like', $search)
                                ->orWhere('name_en', 'like', $search)
                                ->orWhere('name_ja', 'like', $search)
                                ->orWhere('name_zh_tw', 'like', $search)
                                ->orWhere('name_zh_cn', 'like', $search)
                                ->orWhere('short_name', 'like', $search)
                                ->orWhereHas('barcodeAliases', fn ($query) => $query
                                    ->where('is_active', true)
                                    ->where('barcode', 'like', $search));
                        });
                });
            })
            ->with(['shop:id,code', 'stockItem:id,code,name,short_name,name_en,name_ja,name_zh_tw,name_zh_cn'])
            ->orderBy('sku')
            ->limit(50)
            ->get(['id', 'tenant_id', 'shop_id', 'stock_item_id', 'sku', 'platform_sku', 'platform_label_code', 'sku_type']);
    }

    private function normalizeJapanesePostalCode(string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';

        if (strlen($digits) !== 7) {
            return trim($value);
        }

        return substr($digits, 0, 3).'-'.substr($digits, 3);
    }

    private function japanesePostalAddress(string $postalCode): ?array
    {
        return [
            '100-0001' => ['state' => 'Tokyo', 'city' => 'Chiyoda-ku', 'address_line1' => 'Chiyoda'],
            '103-0025' => ['state' => 'Tokyo', 'city' => 'Chuo-ku', 'address_line1' => 'Nihonbashi Kayabacho'],
            '150-0001' => ['state' => 'Tokyo', 'city' => 'Shibuya-ku', 'address_line1' => 'Jingumae'],
            '150-0002' => ['state' => 'Tokyo', 'city' => 'Shibuya-ku', 'address_line1' => 'Shibuya'],
            '542-0076' => ['state' => 'Osaka', 'city' => 'Osaka-shi Chuo-ku', 'address_line1' => 'Namba'],
            '550-0001' => ['state' => 'Osaka', 'city' => 'Osaka-shi Nishi-ku', 'address_line1' => 'Tosabori'],
        ][$postalCode] ?? null;
    }

    private function lookupJapanesePostalAddress(string $postalCode): ?array
    {
        try {
            $response = Http::timeout(3)
                ->acceptJson()
                ->get('https://zipcloud.ibsnet.co.jp/api/search', [
                    'zipcode' => str_replace('-', '', $postalCode),
                ]);
        } catch (\Throwable) {
            return null;
        }

        if (! $response->ok()) {
            return null;
        }

        $result = $response->json('results.0');

        if (! is_array($result)) {
            return null;
        }

        return [
            'state' => (string) ($result['address1'] ?? ''),
            'city' => (string) ($result['address2'] ?? ''),
            'address_line1' => (string) ($result['address3'] ?? ''),
        ];
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
