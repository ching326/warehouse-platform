<?php

namespace App\Livewire;

use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\ShippingMethod;
use App\Models\Shop;
use App\Models\Sku;
use App\Models\Tenant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\Component;

class SalesOrderCreate extends Component
{
    #[Url(as: 'shop_id', except: '')]
    public string $shopId = '';

    public string $platformOrderId = '';

    public string $shippingMethodId = '';

    public string $note = '';

    public string $recipientName = '';

    public string $recipientPhone = '';

    public string $recipientCountryCode = '';

    public string $recipientPostalCode = '';

    public string $recipientState = '';

    public string $recipientCity = '';

    public string $recipientAddressLine1 = '';

    public string $recipientAddressLine2 = '';

    public array $lines = [
        ['sku_id' => '', 'quantity' => '', 'note' => ''],
    ];

    public array $skuSearches = [''];

    private bool $allowedTenantIdsResolved = false;

    private array $allowedTenantIdsCache = [];

    public function mount(): void
    {
        $this->authorizeTenantAccess();
    }

    public function updatedShopId(): void
    {
        $this->lines = [['sku_id' => '', 'quantity' => '', 'note' => '']];
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
        $this->lines[] = ['sku_id' => '', 'quantity' => '', 'note' => ''];
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
        $shop = $this->validatedShop();
        $this->recipientCountryCode = strtoupper(trim($this->recipientCountryCode));
        $this->validateInput($shop);

        $shippingMethod = $this->selectedShippingMethod();

        $order = DB::transaction(function () use ($shop, $shippingMethod) {
            $order = SalesOrder::create([
                'tenant_id' => $shop->tenant_id,
                'shop_id' => $shop->id,
                'source' => SalesOrder::SOURCE_MANUAL,
                'platform_order_id' => $this->nullableString($this->platformOrderId),
                'order_status' => SalesOrder::ORDER_STATUS_PENDING,
                'fulfillment_status' => SalesOrder::FULFILLMENT_STATUS_UNFULFILLED,
                'shipping_method_id' => $shippingMethod?->id,
                'shipping_method' => $shippingMethod?->carrier?->code,
                'recipient_name' => $this->nullableString($this->recipientName),
                'recipient_phone' => $this->nullableString($this->recipientPhone),
                'recipient_country_code' => $this->nullableString($this->recipientCountryCode),
                'recipient_postal_code' => $this->nullableString($this->recipientPostalCode),
                'recipient_state' => $this->nullableString($this->recipientState),
                'recipient_city' => $this->nullableString($this->recipientCity),
                'recipient_address_line1' => $this->nullableString($this->recipientAddressLine1),
                'recipient_address_line2' => $this->nullableString($this->recipientAddressLine2),
                'note' => $this->nullableString($this->note),
                'created_by_user_id' => Auth::id(),
            ]);

            foreach ($this->lines as $lineInput) {
                $order->lines()->create([
                    'sku_id' => (int) $lineInput['sku_id'],
                    'quantity' => (int) $lineInput['quantity'],
                    'line_status' => SalesOrderLine::STATUS_READY,
                    'note' => $this->nullableString($lineInput['note'] ?? ''),
                ]);
            }

            return $order;
        });

        session()->flash('status', __('sales_orders.order_created'));

        return redirect()->route('sales.orders.show', $order);
    }

    public function render()
    {
        return view('livewire.sales-order-create', [
            'shops' => $this->shopOptions(),
            'skuOptionsByLine' => $this->skuOptionsByLine(),
            'shippingMethods' => $this->shippingMethodOptions(),
            'showShopSelect' => true,
        ])->layout('inventory', [
            'title' => __('sales_orders.create_page_title'),
            'subtitle' => __('sales_orders.create_page_subtitle'),
        ]);
    }

    private function validateInput(Shop $shop): void
    {
        validator([
            'platform_order_id' => $this->platformOrderId,
            'shipping_method_id' => $this->shippingMethodId,
            'note' => $this->note,
            'recipient_name' => $this->recipientName,
            'recipient_phone' => $this->recipientPhone,
            'recipient_country_code' => $this->recipientCountryCode,
            'recipient_postal_code' => $this->recipientPostalCode,
            'recipient_state' => $this->recipientState,
            'recipient_city' => $this->recipientCity,
            'recipient_address_line1' => $this->recipientAddressLine1,
            'recipient_address_line2' => $this->recipientAddressLine2,
            'lines' => $this->lines,
        ], [
            'platform_order_id' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique('sales_orders', 'platform_order_id')
                    ->where('tenant_id', $shop->tenant_id)
                    ->where('shop_id', $shop->id),
            ],
            'note' => ['nullable', 'string', 'max:2000'],
            'shipping_method_id' => ['nullable', 'integer', Rule::exists('shipping_methods', 'id')->where('status', 'active')],
            'recipient_name' => ['nullable', 'string', 'max:255'],
            'recipient_phone' => ['nullable', 'string', 'max:50'],
            'recipient_country_code' => ['nullable', 'string', 'regex:/^[A-Z]{2}$/'],
            'recipient_postal_code' => ['nullable', 'string', 'max:20'],
            'recipient_state' => ['nullable', 'string', 'max:100'],
            'recipient_city' => ['nullable', 'string', 'max:100'],
            'recipient_address_line1' => ['nullable', 'string', 'max:255'],
            'recipient_address_line2' => ['nullable', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.sku_id' => [
                'required',
                'integer',
                Rule::exists('skus', 'id')->where('tenant_id', $shop->tenant_id)->where('status', 'active'),
            ],
            'lines.*.quantity' => ['required', 'integer', 'min:1'],
            'lines.*.note' => ['nullable', 'string', 'max:500'],
        ])->validate();
    }

    private function validatedShop(): Shop
    {
        if ($this->shopId === '') {
            throw ValidationException::withMessages(['shopId' => __('sales_orders.shop_required')]);
        }

        $shop = Shop::query()
            ->where('status', 'active')
            ->whereIn('tenant_id', $this->allowedTenantIds())
            ->find((int) $this->shopId);

        if (! $shop) {
            throw ValidationException::withMessages(['shopId' => __('sales_orders.invalid_shop')]);
        }

        return $shop;
    }

    private function shopOptions(): Collection
    {
        return Shop::query()
            ->whereIn('tenant_id', $this->allowedTenantIds())
            ->where('status', 'active')
            ->with('tenant:id,code')
            ->orderBy('name')
            ->get(['id', 'tenant_id', 'platform', 'marketplace', 'code', 'name']);
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
        if ($this->shopId === '') {
            return collect();
        }

        $shop = Shop::query()
            ->whereIn('tenant_id', $this->allowedTenantIds())
            ->where('status', 'active')
            ->find((int) $this->shopId);

        if (! $shop) {
            return collect();
        }

        $searchTerm = trim((string) ($this->skuSearches[$lineIndex] ?? ''));
        $search = '%'.$searchTerm.'%';

        return Sku::query()
            ->where('tenant_id', $shop->tenant_id)
            ->where('status', 'active')
            ->where(fn ($query) => $query
                ->where('sku_type', 'virtual_bundle')
                ->orWhereNotNull('stock_item_id'))
            ->when($searchTerm !== '', function ($query) use ($search) {
                $query->where(function ($query) use ($search) {
                    $query
                        ->where('sku', 'like', $search)
                        ->orWhere('name', 'like', $search)
                        ->orWhere('name_en', 'like', $search)
                        ->orWhere('name_ja', 'like', $search)
                        ->orWhere('name_zh_tw', 'like', $search)
                        ->orWhere('name_zh_cn', 'like', $search)
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
                                ->orWhere('barcode', 'like', $search);
                        });
                });
            })
            ->with('stockItem:id,code,name')
            ->orderBy('sku')
            ->limit(50)
            ->get(['id', 'tenant_id', 'sku', 'name', 'platform_sku', 'platform_label_code', 'stock_item_id', 'sku_type']);
    }

    private function shippingMethodOptions(): Collection
    {
        return ShippingMethod::query()
            ->where('shipping_methods.status', 'active')
            ->with('carrier:id,code,name')
            ->ordered()
            ->get();
    }

    private function selectedShippingMethod(): ?ShippingMethod
    {
        if ($this->shippingMethodId === '') {
            return null;
        }

        return ShippingMethod::query()
            ->where('status', 'active')
            ->with('carrier:id,code')
            ->find((int) $this->shippingMethodId);
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

        return $this->allowedTenantIdsCache = $user->activeTenantIds();
    }

    private function authorizeTenantAccess(): void
    {
        if (! $this->isInternalUser() && $this->allowedTenantIds() === []) {
            abort(403);
        }
    }

    private function nullableString(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
