<div class="user-create-page">
    <x-flash-toast />

    @if ($tempPassword)
        <section class="table-shell flux-panel form-panel">
            <div class="form-panel-header">
                <div>
                    <strong>{{ __('users.temp_password_title') }}</strong>
                    <span>{{ __('users.temp_password_hint') }}</span>
                </div>
                <flux:button href="{{ route('setup.users.index') }}" variant="outline">{{ __('users.btn_back_users') }}</flux:button>
            </div>

            <div class="form-grid">
                <flux:input readonly value="{{ $tempPassword }}" :label="__('users.temp_password_title')" />
                <div class="form-actions">
                    <flux:button
                        type="button"
                        variant="primary"
                        onclick="navigator.clipboard.writeText(@js($tempPassword))"
                    >
                        {{ __('common.copy') }}
                    </flux:button>
                </div>
            </div>
        </section>
    @endif

    <form wire:submit="save" class="sku-form">
        <section class="table-shell flux-panel form-panel">
            <div class="form-panel-header">
                <div>
                    <strong>{{ __('users.create_title') }}</strong>
                    <span>{{ __('users.create_subtitle') }}</span>
                </div>
                <flux:button href="{{ route('setup.users.index') }}" variant="outline">{{ __('users.btn_back_users') }}</flux:button>
            </div>

            <div class="form-grid">
                <flux:input wire:model="name" required :label="__('users.field_name')" />
                <flux:input wire:model="email" type="email" required :label="__('users.field_email')" />
            </div>

            <div class="form-grid three">
                <flux:select wire:model.live="userType" :label="__('users.field_user_type')" required>
                    @foreach ($userTypes as $value => $label)
                        <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>

                @if ($userType === \App\Models\User::TYPE_INTERNAL)
                    <flux:select wire:model="internalRole" :label="__('users.field_internal_role')" required>
                        @foreach ($internalRoles as $value => $label)
                            <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @else
                    <flux:select wire:model="tenantId" :label="__('users.field_tenant')" required>
                        <flux:select.option value="">{{ __('users.select_tenant') }}</flux:select.option>
                        @foreach ($tenants as $tenant)
                            <flux:select.option value="{{ $tenant->id }}">{{ $tenant->code }} - {{ $tenant->name }}</flux:select.option>
                        @endforeach
                    </flux:select>

                    <flux:select wire:model="tenantRole" :label="__('users.field_tenant_role')" required>
                        @foreach ($tenantRoles as $value => $label)
                            <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @endif
            </div>

            <div class="checkbox-stack">
                <label><input type="checkbox" wire:model="isActive"> {{ __('users.field_active') }}</label>
            </div>
        </section>

        <div class="form-actions">
            <flux:button href="{{ route('setup.users.index') }}" variant="outline">{{ __('common.cancel') }}</flux:button>
            <flux:button type="submit" variant="primary">{{ __('users.btn_invite_user') }}</flux:button>
        </div>
    </form>
</div>
