<div class="shop-edit-page">
    <form wire:submit="save" class="sku-form">
        <section class="table-shell flux-panel form-panel">
            <div class="form-panel-header">
                <div>
                    <strong>{{ __('shop.shop_edit_page_title') }}</strong>
                    <span>{{ $shop->code }}</span>
                </div>
                <flux:button href="{{ route('setup.shops.index') }}" variant="outline">{{ __('shop.btn_back_shops') }}</flux:button>
            </div>

            <div class="form-grid three">
                <div>
                    <flux:select wire:model="tenantId" :label="__('shop.field_tenant')">
                        <flux:select.option value="">{{ __('shop.field_tenant') }}</flux:select.option>
                        @foreach ($tenants as $tenant)
                            <flux:select.option value="{{ $tenant->id }}">{{ $tenant->code }} - {{ $tenant->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    @error('tenant_id') <p class="form-error">{{ $message }}</p> @enderror
                </div>

                <div>
                    <flux:select wire:model="platform" :label="__('shop.field_platform')">
                        <flux:select.option value="">{{ __('shop.field_platform') }}</flux:select.option>
                        @foreach ($platforms as $value => $label)
                            <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    @error('platform') <p class="form-error">{{ $message }}</p> @enderror
                </div>

                <div>
                    <flux:select wire:model="status" :label="__('shop.field_status')">
                        @foreach ($statuses as $value => $label)
                            <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    @error('status') <p class="form-error">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="form-grid three">
                <div>
                    <flux:input wire:model="marketplace" :label="__('shop.field_marketplace')" />
                    <span class="subtle">{{ __('shop.field_marketplace_hint') }}</span>
                    @error('marketplace') <p class="form-error">{{ $message }}</p> @enderror
                </div>

                <div>
                    <flux:input wire:model="code" :label="__('shop.field_code')" />
                    <span class="subtle">{{ __('shop.field_code_hint') }}</span>
                    @error('code') <p class="form-error">{{ $message }}</p> @enderror
                </div>

                <div>
                    <flux:input wire:model="name" :label="__('shop.field_name')" />
                    @error('name') <p class="form-error">{{ $message }}</p> @enderror
                </div>
            </div>
        </section>

        <section class="table-shell flux-panel form-panel">
            <div class="form-grid">
                <div>
                    <flux:input wire:model="contactName" :label="__('shop.field_contact_name')" />
                    @error('contact_name') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <flux:input wire:model="contactEmail" type="email" :label="__('shop.field_contact_email')" />
                    @error('contact_email') <p class="form-error">{{ $message }}</p> @enderror
                </div>
            </div>
        </section>

        <section class="table-shell flux-panel form-panel">
            <label>
                <span>{{ __('shop.field_note') }}</span>
                <textarea wire:model="note" rows="4"></textarea>
                @error('note') <p class="form-error">{{ $message }}</p> @enderror
            </label>
        </section>

        @if ($shop->platform === 'amazon')
            <section class="table-shell flux-panel form-panel" data-testid="amazon-spapi-panel">
                <div class="form-panel-header">
                    <div>
                        <strong>{{ __('amazon_spapi.panel_title') }}</strong>
                        <span>{{ __('amazon_spapi.panel_hint') }}</span>
                    </div>
                    <div class="status-line">
                        @if ($amazonConnection?->status === \App\Models\AmazonSpapiConnection::STATUS_CONNECTED)
                            <flux:badge color="green">{{ __('amazon_spapi.status_connected') }}</flux:badge>
                        @elseif ($amazonConnection?->status === \App\Models\AmazonSpapiConnection::STATUS_FAILED)
                            <flux:badge color="red">{{ __('amazon_spapi.status_failed') }}</flux:badge>
                        @else
                            <flux:badge color="zinc">{{ __('amazon_spapi.status_not_tested') }}</flux:badge>
                        @endif

                        @if ($amazonConnection && ! $amazonConnection->sync_enabled)
                            <flux:badge color="amber">{{ __('amazon_spapi.sync_disabled') }}</flux:badge>
                        @endif
                    </div>
                </div>

                @if (session('amazon_spapi_status'))
                    <div class="status-message">{{ session('amazon_spapi_status') }}</div>
                @endif

                <p class="subtle">{{ __('amazon_spapi.connection_scope_hint') }}</p>

                <div class="form-grid three">
                    <div>
                        <flux:select wire:model="spapiRegion" :label="__('amazon_spapi.field_region')">
                            @foreach ($amazonRegionOptions as $region => $option)
                                <flux:select.option value="{{ $region }}">{{ $option['label'] }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <span class="subtle">{{ $amazonEndpoint }}</span>
                        @error('region') <p class="form-error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <flux:input value="{{ $shop->marketplace ?: '-' }}" :label="__('amazon_spapi.field_marketplace')" readonly />
                    </div>

                    <div>
                        <flux:input wire:model="spapiMarketplaceId" :label="__('amazon_spapi.field_marketplace_id')" />
                        @error('marketplace_id') <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="form-grid three">
                    <div>
                        <flux:input wire:model="spapiSellerId" :label="__('amazon_spapi.field_seller_id')" />
                        @error('seller_id') <p class="form-error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <flux:input wire:model="spapiLwaClientId" :label="__('amazon_spapi.field_lwa_client_id')" />
                        @error('lwa_client_id') <p class="form-error">{{ $message }}</p> @enderror
                    </div>

                    <label class="active-only-toggle amazon-sync-toggle">
                        <input type="checkbox" wire:model="spapiSyncEnabled">
                        <span>{{ __('amazon_spapi.field_sync_enabled') }}</span>
                    </label>
                </div>

                <div class="form-grid">
                    <div>
                        <flux:input
                            wire:model="spapiLwaClientSecretInput"
                            type="password"
                            :label="__('amazon_spapi.field_lwa_client_secret')"
                            placeholder="{{ $amazonConnection ? __('amazon_spapi.secret_saved_placeholder') : '' }}"
                            autocomplete="new-password"
                        />
                        @error('lwa_client_secret') <p class="form-error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <flux:input
                            wire:model="spapiRefreshTokenInput"
                            type="password"
                            :label="__('amazon_spapi.field_refresh_token')"
                            placeholder="{{ $amazonConnection ? __('amazon_spapi.secret_saved_placeholder') : '' }}"
                            autocomplete="new-password"
                        />
                        @error('refresh_token') <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="form-grid">
                    <div>
                        <span class="subtle">{{ __('amazon_spapi.field_last_tested_at') }}</span>
                        <strong>{{ $amazonConnection?->last_tested_at?->format('Y-m-d H:i') ?? '-' }}</strong>
                    </div>
                    <div>
                        <span class="subtle">{{ __('amazon_spapi.field_last_error') }}</span>
                        <strong>{{ $amazonConnection?->last_error ?? '-' }}</strong>
                    </div>
                </div>

                @error('amazon_spapi') <p class="form-error">{{ $message }}</p> @enderror
                @error('amazon_test') <p class="form-error">{{ $message }}</p> @enderror

                <div class="form-actions">
                    <flux:button type="button" variant="outline" wire:click="saveAmazonSettings">
                        {{ __('amazon_spapi.btn_save') }}
                    </flux:button>
                    @if ($amazonConnection)
                        <flux:button type="button" variant="outline" wire:click="toggleAmazonSync">
                            {{ $amazonConnection->sync_enabled ? __('amazon_spapi.btn_disable_sync') : __('amazon_spapi.btn_enable_sync') }}
                        </flux:button>
                    @endif
                    <flux:button type="button" variant="primary" wire:click="testAmazonConnection" :disabled="! $canTestAmazonConnection">
                        {{ __('amazon_spapi.btn_test_connection') }}
                    </flux:button>
                </div>
            </section>
        @endif

        <div class="form-actions">
            <flux:button href="{{ route('setup.shops.index') }}" variant="outline">{{ __('shop.btn_cancel') }}</flux:button>
            <flux:button type="submit" variant="primary">{{ __('setup.btn_save') }}</flux:button>
        </div>
    </form>
</div>
