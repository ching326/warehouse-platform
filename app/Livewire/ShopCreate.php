<?php

namespace App\Livewire;

use App\Models\Shop;
use App\Models\Tenant;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Component;

class ShopCreate extends Component
{
    public string $tenantId = '';

    public string $platform = '';

    public string $marketplace = '';

    public string $code = '';

    public string $name = '';

    public string $consolidationMode = Shop::CONSOLIDATION_SAME_SHOP;

    public string $contactName = '';

    public string $contactEmail = '';

    public string $status = 'active';

    public string $note = '';

    public function mount(): void
    {
        if (! $this->isInternalUser()) {
            abort(403);
        }
    }

    public function save()
    {
        $this->code = strtoupper(trim($this->code));
        $this->marketplace = Shop::normalizeMarketplace($this->marketplace);

        $this->validateInput();

        Shop::create([
            'tenant_id' => (int) $this->tenantId,
            'platform' => $this->platform,
            'marketplace' => $this->marketplace,
            'code' => $this->code,
            'name' => trim($this->name),
            'consolidation_mode' => $this->consolidationMode,
            'contact_name' => $this->nullableString($this->contactName),
            'contact_email' => $this->nullableString($this->contactEmail),
            'status' => $this->status,
            'note' => $this->nullableString($this->note),
        ]);

        session()->flash('status', __('shop.shop_created'));

        return redirect()->route('setup.shops.index');
    }

    public function render()
    {
        return view('livewire.shop-create', [
            'tenants' => Tenant::where('status', 'active')->orderBy('name')->get(['id', 'code', 'name']),
            'platforms' => $this->platforms(),
            'marketplaces' => $this->marketplaces(),
            'statuses' => [
                'active' => __('shop.status_active'),
                'inactive' => __('shop.status_inactive'),
            ],
            'consolidationModes' => $this->consolidationModes(),
        ])->layout('inventory', [
            'title' => __('shop.shop_create_page_title'),
            'subtitle' => __('shop.shop_create_page_subtitle'),
        ]);
    }

    private function validateInput(): void
    {
        $marketplace = Shop::normalizeMarketplace($this->marketplace);

        validator([
            'tenant_id' => $this->tenantId,
            'platform' => $this->platform,
            'marketplace' => $marketplace,
            'code' => $this->code,
            'name' => $this->name,
            'consolidation_mode' => $this->consolidationMode,
            'contact_name' => $this->contactName,
            'contact_email' => $this->contactEmail,
            'status' => $this->status,
            'note' => $this->note,
        ], [
            'tenant_id' => ['required', Rule::exists('tenants', 'id')->where('status', 'active')],
            'platform' => ['required', 'string', Rule::in(array_keys($this->platforms()))],
            'marketplace' => ['nullable', 'string', Rule::in(Shop::marketplaceOptions())],
            'code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('shops', 'code')
                    ->where('tenant_id', (int) $this->tenantId)
                    ->where('platform', $this->platform)
                    ->where('marketplace', $marketplace),
            ],
            'name' => ['required', 'string', 'max:255'],
            'consolidation_mode' => ['required', 'string', Rule::in(Shop::consolidationModes())],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'status' => ['required', 'string', Rule::in(['active', 'inactive'])],
            'note' => ['nullable', 'string', 'max:2000'],
        ])->validate();
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

    private function platforms(): array
    {
        return [
            'amazon' => __('shop.platform_amazon'),
            'rakuten' => __('shop.platform_rakuten'),
            'shopify' => __('shop.platform_shopify'),
            'manual' => __('shop.platform_manual'),
        ];
    }

    private function marketplaces(): array
    {
        return array_combine(Shop::marketplaceOptions(), Shop::marketplaceOptions());
    }

    private function consolidationModes(): array
    {
        return [
            Shop::CONSOLIDATION_NONE => __('shop.consolidation_none'),
            Shop::CONSOLIDATION_SAME_SHOP => __('shop.consolidation_same_shop'),
            Shop::CONSOLIDATION_CROSS_SHOP => __('shop.consolidation_cross_shop'),
        ];
    }
}
