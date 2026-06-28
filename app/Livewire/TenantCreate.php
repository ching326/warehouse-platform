<?php

namespace App\Livewire;

use App\Models\Tenant;
use App\Models\Warehouse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Component;

class TenantCreate extends Component
{
    public string $code = '';

    public string $name = '';

    public string $contactName = '';

    public string $contactEmail = '';

    public string $contactPhone = '';

    public string $billingTerms = '';

    public string $status = 'active';

    public string $notes = '';

    public string $skuNameLocale = '';

    public string $defaultWarehouseId = '';

    public string $fulfillmentItemCodeSource = Tenant::FULFILLMENT_ITEM_CODE_SOURCE_SKU;

    private const STOCK_ITEM_NAME_LOCALE = 'ja';

    private const NAME_LOCALE_OPTIONS = ['en', 'ja', 'zh_TW', 'zh_CN'];

    public function mount(): void
    {
        if (! $this->isInternalUser()) {
            abort(403);
        }
    }

    public function save()
    {
        $this->code = strtoupper(trim($this->code));

        $this->validateInput();

        Tenant::create([
            'code' => $this->code,
            'name' => trim($this->name),
            'contact_name' => $this->nullableString($this->contactName),
            'contact_email' => $this->nullableString($this->contactEmail),
            'contact_phone' => $this->nullableString($this->contactPhone),
            'billing_terms' => $this->nullableString($this->billingTerms),
            'status' => $this->status,
            'notes' => $this->nullableString($this->notes),
            'sku_name_locale' => $this->skuNameLocale,
            'stock_item_name_locale' => self::STOCK_ITEM_NAME_LOCALE,
            'fulfillment_item_code_source' => $this->fulfillmentItemCodeSource,
            'default_warehouse_id' => $this->defaultWarehouseId === '' ? null : (int) $this->defaultWarehouseId,
        ]);

        session()->flash('status', __('setup.tenant_created'));

        return redirect()->route('setup.tenants.index');
    }

    public function render()
    {
        return view('livewire.tenant-create', [
            'statuses' => [
                'active' => __('setup.status_active'),
                'inactive' => __('setup.status_inactive'),
            ],
            'localeOptions' => $this->localeOptions(),
            'fulfillmentItemCodeSourceOptions' => $this->fulfillmentItemCodeSourceOptions(),
            'warehouses' => $this->warehouseOptions(),
        ])->layout('inventory', [
            'title' => __('setup.tenant_create_page_title'),
            'subtitle' => __('setup.tenant_create_page_subtitle'),
        ]);
    }

    private function validateInput(): void
    {
        validator([
            'code' => $this->code,
            'name' => $this->name,
            'contact_name' => $this->contactName,
            'contact_email' => $this->contactEmail,
            'contact_phone' => $this->contactPhone,
            'billing_terms' => $this->billingTerms,
            'status' => $this->status,
            'notes' => $this->notes,
            'sku_name_locale' => $this->skuNameLocale,
            'fulfillment_item_code_source' => $this->fulfillmentItemCodeSource,
            'default_warehouse_id' => $this->defaultWarehouseId,
        ], [
            'code' => ['required', 'string', 'max:50', Rule::unique('tenants', 'code')],
            'name' => ['required', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:50'],
            'billing_terms' => ['nullable', 'string', 'max:255'],
            'status' => ['required', 'string', Rule::in(['active', 'inactive'])],
            'notes' => ['nullable', 'string', 'max:2000'],
            'sku_name_locale' => ['required', 'string', Rule::in(self::NAME_LOCALE_OPTIONS)],
            'fulfillment_item_code_source' => ['required', 'string', Rule::in(Tenant::FULFILLMENT_ITEM_CODE_SOURCES)],
            'default_warehouse_id' => ['nullable', 'integer', Rule::exists('warehouses', 'id')->where('status', 'active')],
        ])->validate();
    }

    private function fulfillmentItemCodeSourceOptions(): array
    {
        return [
            Tenant::FULFILLMENT_ITEM_CODE_SOURCE_SKU => __('setup.fulfillment_item_code_source_sku'),
            Tenant::FULFILLMENT_ITEM_CODE_SOURCE_TENANT_ITEM_CODE => __('setup.fulfillment_item_code_source_tenant_item_code'),
            Tenant::FULFILLMENT_ITEM_CODE_SOURCE_STOCK_ITEM_CODE => __('setup.fulfillment_item_code_source_stock_item_code'),
        ];
    }

    private function localeOptions(): array
    {
        return [
            'en' => __('setup.locale_en'),
            'ja' => __('setup.locale_ja'),
            'zh_TW' => __('setup.locale_zh_TW'),
            'zh_CN' => __('setup.locale_zh_CN'),
        ];
    }

    private function isInternalUser(): bool
    {
        $user = Auth::user();

        return $user?->user_type === 'internal';
    }

    private function nullableString(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function warehouseOptions()
    {
        return Warehouse::query()
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'code', 'name']);
    }
}
