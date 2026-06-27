<?php

namespace App\Livewire;

use App\Models\Tenant;
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
        ])->validate();
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
}
