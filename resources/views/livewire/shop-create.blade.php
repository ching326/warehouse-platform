<div class="shop-create-page">
    <form wire:submit="save" class="sku-form">
        <section class="table-shell flux-panel form-panel">
            <div class="form-panel-header">
                <div>
                    <strong>{{ __('shop.shop_create_page_title') }}</strong>
                    <span>{{ __('shop.shop_create_page_subtitle') }}</span>
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
                </div>

                <div>
                    <flux:select wire:model="status" :label="__('shop.field_status')">
                        @foreach ($statuses as $value => $label)
                            <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>
            </div>

            <div class="form-grid three">
                <div>
                    <flux:select wire:model="marketplace" :label="__('shop.field_marketplace')">
                        <flux:select.option value="">{{ __('shop.field_marketplace') }}</flux:select.option>
                        @foreach ($marketplaces as $value => $label)
                            <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <span class="subtle">{{ __('shop.field_marketplace_hint') }}</span>
                </div>

                <div>
                    <flux:input wire:model="code" :label="__('shop.field_code')" />
                    <span class="subtle">{{ __('shop.field_code_hint') }}</span>
                </div>

                <div>
                    <flux:input wire:model="name" :label="__('shop.field_name')" />
                </div>
            </div>

            <div class="form-grid three">
                <div>
                    <flux:select wire:model="consolidationMode" :label="__('shop.field_consolidation_mode')">
                        @foreach ($consolidationModes as $value => $label)
                            <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <span class="subtle">{{ __('shop.field_consolidation_mode_hint') }}</span>
                    @error('consolidation_mode') <p class="form-error">{{ $message }}</p> @enderror
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
            <div class="form-grid">
                <flux:input wire:model="shipLabelPhone" :label="__('shop.field_ship_label_phone')" />
                <flux:input wire:model="shipLabelPostcode" :label="__('shop.field_ship_label_postcode')" />
            </div>
            <flux:textarea wire:model="shipLabelAddress" rows="3" :label="__('shop.field_ship_label_address')" />
        </section>

        <section class="table-shell flux-panel form-panel">
            <label>
                <span>{{ __('shop.field_note') }}</span>
                <textarea wire:model="note" rows="4"></textarea>
                @error('note') <p class="form-error">{{ $message }}</p> @enderror
            </label>
        </section>

        <div class="form-actions">
            <flux:button href="{{ route('setup.shops.index') }}" variant="outline">{{ __('shop.btn_cancel') }}</flux:button>
            <flux:button type="submit" variant="primary">{{ __('shop.btn_create_shop') }}</flux:button>
        </div>
    </form>
</div>
