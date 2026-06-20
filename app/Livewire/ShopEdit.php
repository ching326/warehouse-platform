<?php

namespace App\Livewire;

use App\Models\AmazonSpapiConnection;
use App\Models\Shop;
use App\Models\Tenant;
use App\Services\Amazon\AmazonSpapiTokenException;
use App\Services\Amazon\AmazonSpapiTokenService;
use App\Support\AmazonSpapiRegion;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class ShopEdit extends Component
{
    public Shop $shop;

    public string $tenantId      = '';
    public string $platform      = '';
    public string $marketplace   = '';
    public string $code          = '';
    public string $name          = '';
    public string $contactName   = '';
    public string $contactEmail  = '';
    public string $status        = 'active';
    public string $note          = '';

    public string $spapiSellerId     = '';
    public string $spapiMarketplaceId = '';
    public string $spapiRegion       = AmazonSpapiRegion::FE;
    public string $spapiLwaClientId  = '';
    public string $spapiLwaClientSecretInput = '';
    public string $spapiRefreshTokenInput = '';
    public bool $spapiSyncEnabled = false;

    public function mount(Shop $shop): void
    {
        if (! $this->isInternalUser()) {
            abort(403);
        }

        $this->shop         = $shop->load('amazonSpapiConnection');
        $this->tenantId     = (string) $shop->tenant_id;
        $this->platform     = $shop->platform;
        $this->marketplace  = $shop->marketplace ?? '';
        $this->code         = $shop->code;
        $this->name         = $shop->name;
        $this->contactName  = $shop->contact_name ?? '';
        $this->contactEmail = $shop->contact_email ?? '';
        $this->status       = $shop->status;
        $this->note         = $shop->note ?? '';
        $this->fillAmazonSpapiState();
    }

    public function save()
    {
        $this->code = strtoupper(trim($this->code));
        $marketplace = trim($this->marketplace);

        validator([
            'tenant_id'     => $this->tenantId,
            'platform'      => $this->platform,
            'marketplace'   => $marketplace,
            'code'          => $this->code,
            'name'          => $this->name,
            'contact_name'  => $this->contactName,
            'contact_email' => $this->contactEmail,
            'status'        => $this->status,
            'note'          => $this->note,
        ], [
            'tenant_id'     => ['required', Rule::exists('tenants', 'id')->where('status', 'active')],
            'platform'      => ['required', 'string', Rule::in(array_keys($this->platforms()))],
            'marketplace'   => ['string', 'max:100'],
            'code'          => [
                'required',
                'string',
                'max:50',
                Rule::unique('shops', 'code')
                    ->where('tenant_id', (int) $this->tenantId)
                    ->where('platform', $this->platform)
                    ->where('marketplace', $marketplace)
                    ->ignore($this->shop->id),
            ],
            'name'          => ['required', 'string', 'max:255'],
            'contact_name'  => ['nullable', 'string', 'max:255'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'status'        => ['required', 'string', Rule::in(['active', 'inactive'])],
            'note'          => ['nullable', 'string', 'max:2000'],
        ])->validate();

        $this->shop->update([
            'tenant_id'     => (int) $this->tenantId,
            'platform'      => $this->platform,
            'marketplace'   => $marketplace,
            'code'          => $this->code,
            'name'          => trim($this->name),
            'contact_name'  => $this->nullableString($this->contactName),
            'contact_email' => $this->nullableString($this->contactEmail),
            'status'        => $this->status,
            'note'          => $this->nullableString($this->note),
        ]);

        session()->flash('status', __('shop.shop_updated'));

        return redirect()->route('setup.shops.index');
    }

    public function saveAmazonSettings(): void
    {
        if ($this->shop->platform !== 'amazon') {
            throw ValidationException::withMessages([
                'amazon_spapi' => __('amazon_spapi.amazon_shop_only'),
            ]);
        }

        $connection = $this->shop->amazonSpapiConnection;
        $creating = $connection === null;

        $data = validator([
            'seller_id' => trim($this->spapiSellerId),
            'marketplace_id' => trim($this->spapiMarketplaceId),
            'region' => $this->spapiRegion,
            'lwa_client_id' => trim($this->spapiLwaClientId),
            'lwa_client_secret' => trim($this->spapiLwaClientSecretInput),
            'refresh_token' => trim($this->spapiRefreshTokenInput),
            'sync_enabled' => $this->spapiSyncEnabled,
        ], [
            'seller_id' => ['required', 'string', 'max:255'],
            'marketplace_id' => ['required', 'string', 'max:255'],
            'region' => ['required', 'string', Rule::in(AmazonSpapiRegion::values())],
            'lwa_client_id' => ['required', 'string', 'max:255'],
            'lwa_client_secret' => [$creating ? 'required' : 'nullable', 'string', 'max:5000'],
            'refresh_token' => [$creating ? 'required' : 'nullable', 'string', 'max:5000'],
            'sync_enabled' => ['boolean'],
        ])->validate();

        $expectedRegion = AmazonSpapiRegion::regionForMarketplaceId($data['marketplace_id']);
        if ($expectedRegion !== null && $expectedRegion !== $data['region']) {
            throw ValidationException::withMessages([
                'marketplace_id' => __('amazon_spapi.marketplace_region_mismatch', [
                    'region' => AmazonSpapiRegion::label($expectedRegion),
                ]),
            ]);
        }

        $attributes = [
            'tenant_id' => $this->shop->tenant_id,
            'shop_id' => $this->shop->id,
            'seller_id' => $data['seller_id'],
            'marketplace_id' => $data['marketplace_id'],
            'region' => $data['region'],
            'endpoint' => AmazonSpapiRegion::endpoint($data['region']),
            'lwa_client_id' => $data['lwa_client_id'],
            'sync_enabled' => (bool) $data['sync_enabled'],
            'status' => $connection?->status ?? AmazonSpapiConnection::STATUS_NOT_TESTED,
        ];

        if ($data['lwa_client_secret'] !== '') {
            $attributes['lwa_client_secret'] = $data['lwa_client_secret'];
        }

        if ($data['refresh_token'] !== '') {
            $attributes['refresh_token'] = $data['refresh_token'];
        }

        AmazonSpapiConnection::query()->updateOrCreate(
            ['shop_id' => $this->shop->id],
            $attributes,
        );

        $this->spapiLwaClientSecretInput = '';
        $this->spapiRefreshTokenInput = '';
        $this->shop->refresh()->load('amazonSpapiConnection');
        $this->fillAmazonSpapiState();

        session()->flash('amazon_spapi_status', __('amazon_spapi.connection_saved'));
    }

    public function testAmazonConnection(): void
    {
        $connection = $this->shop->amazonSpapiConnection()->first();

        if (! $connection) {
            $this->addError('amazon_test', __('amazon_spapi.save_before_testing'));

            return;
        }

        $this->shop->setRelation('amazonSpapiConnection', $connection);

        if ($this->amazonSettingsDirty()) {
            $this->addError('amazon_test', __('amazon_spapi.save_before_testing'));

            return;
        }

        try {
            app(AmazonSpapiTokenService::class)->exchangeRefreshToken($connection);

            $connection->update([
                'status' => AmazonSpapiConnection::STATUS_CONNECTED,
                'last_tested_at' => now(),
                'last_test_successful_at' => now(),
                'last_error' => null,
            ]);

            session()->flash('amazon_spapi_status', __('amazon_spapi.connection_test_success'));
        } catch (AmazonSpapiTokenException $exception) {
            $connection->update([
                'status' => AmazonSpapiConnection::STATUS_FAILED,
                'last_tested_at' => now(),
                'last_error' => $exception->getMessage(),
            ]);

            session()->flash('amazon_spapi_status', __('amazon_spapi.connection_test_failed'));
        }

        $this->shop->refresh()->load('amazonSpapiConnection');
        $this->fillAmazonSpapiState();
    }

    public function toggleAmazonSync(): void
    {
        $connection = $this->shop->amazonSpapiConnection()->first();

        if (! $connection) {
            $this->addError('amazon_test', __('amazon_spapi.save_before_testing'));

            return;
        }

        $connection->update([
            'sync_enabled' => ! $connection->sync_enabled,
        ]);

        $this->shop->refresh()->load('amazonSpapiConnection');
        $this->fillAmazonSpapiState();

        session()->flash('amazon_spapi_status', __('amazon_spapi.connection_saved'));
    }

    public function render()
    {
        $amazonConnection = $this->shop->amazonSpapiConnection;

        return view('livewire.shop-edit', [
            'tenants'   => Tenant::where('status', 'active')->orderBy('name')->get(['id', 'code', 'name']),
            'platforms' => $this->platforms(),
            'statuses'  => [
                'active'   => __('shop.status_active'),
                'inactive' => __('shop.status_inactive'),
            ],
            'amazonConnection' => $amazonConnection,
            'amazonEndpoint' => AmazonSpapiRegion::endpoint($this->spapiRegion),
            'amazonMarketplaceOptions' => AmazonSpapiRegion::marketplaceOptions(),
            'amazonRegionOptions' => AmazonSpapiRegion::options(),
            'canTestAmazonConnection' => $amazonConnection !== null && ! $this->amazonSettingsDirty(),
        ])->layout('inventory', [
            'title'    => __('shop.shop_edit_page_title'),
            'subtitle' => $this->shop->code.' - '.$this->shop->name,
        ]);
    }

    // TODO: remove unauthenticated fallback when auth is implemented
    private function isInternalUser(): bool
    {
        $user = Auth::user();

        return ! $user || $user->user_type === 'internal';
    }

    private function nullableString(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function platforms(): array
    {
        return [
            'amazon'  => __('shop.platform_amazon'),
            'rakuten' => __('shop.platform_rakuten'),
            'shopify' => __('shop.platform_shopify'),
            'manual'  => __('shop.platform_manual'),
        ];
    }

    private function fillAmazonSpapiState(): void
    {
        $connection = $this->shop->amazonSpapiConnection;

        if ($connection) {
            $this->spapiSellerId = $connection->seller_id;
            $this->spapiMarketplaceId = $connection->marketplace_id;
            $this->spapiRegion = $connection->region;
            $this->spapiLwaClientId = $connection->lwa_client_id;
            $this->spapiSyncEnabled = $connection->sync_enabled;
        } else {
            $this->spapiSellerId = '';
            $this->spapiMarketplaceId = AmazonSpapiRegion::marketplaceIdForLabel($this->shop->marketplace) ?? '';
            $this->spapiRegion = AmazonSpapiRegion::regionForMarketplaceId($this->spapiMarketplaceId) ?? AmazonSpapiRegion::FE;
            $this->spapiLwaClientId = '';
            $this->spapiSyncEnabled = false;
        }

        $this->spapiLwaClientSecretInput = '';
        $this->spapiRefreshTokenInput = '';
    }

    private function amazonSettingsDirty(): bool
    {
        $connection = $this->shop->amazonSpapiConnection;

        if (! $connection) {
            return true;
        }

        return trim($this->spapiSellerId) !== $connection->seller_id
            || trim($this->spapiMarketplaceId) !== $connection->marketplace_id
            || $this->spapiRegion !== $connection->region
            || trim($this->spapiLwaClientId) !== $connection->lwa_client_id
            || (bool) $this->spapiSyncEnabled !== (bool) $connection->sync_enabled
            || trim($this->spapiLwaClientSecretInput) !== ''
            || trim($this->spapiRefreshTokenInput) !== '';
    }
}
