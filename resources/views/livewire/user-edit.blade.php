<div class="user-edit-page">
    <x-flash-toast />

    <section class="table-shell flux-panel form-panel">
        <div class="form-panel-header">
            <div>
                <strong>{{ $managedUser->name }}</strong>
                <span>{{ $managedUser->email }}</span>
            </div>
            <flux:button href="{{ route('setup.users.index') }}" variant="outline">{{ __('users.btn_back_users') }}</flux:button>
        </div>

        @if ($managedUser->user_type === \App\Models\User::TYPE_INTERNAL)
            <form wire:submit="saveInternal" class="sku-form">
                <div class="segmented-row">
                    <label>
                        <input type="radio" wire:model="internalRole" value="{{ \App\Models\User::ROLE_INTERNAL_ADMIN }}">
                        <span>
                            <strong>{{ __('users.role_internal_admin') }}</strong>
                            <span class="subtle">{{ __('users.internal_role_admin_hint') }}</span>
                        </span>
                    </label>
                    <label>
                        <input type="radio" wire:model="internalRole" value="{{ \App\Models\User::ROLE_WAREHOUSE_STAFF }}">
                        <span>
                            <strong>{{ __('users.role_warehouse_staff') }}</strong>
                            <span class="subtle">{{ __('users.internal_role_staff_hint') }}</span>
                        </span>
                    </label>
                </div>

                <div class="checkbox-stack">
                    <label><input type="checkbox" wire:model="isActive"> {{ __('users.field_active') }}</label>
                </div>

                <div class="form-actions">
                    <flux:button type="submit" variant="primary">{{ __('users.btn_save') }}</flux:button>
                </div>
            </form>
        @else
            <p class="subtle">{{ __('users.tenant_user_role_hint') }}</p>

            <form wire:submit="saveTenantAccount" class="sku-form">
                <div class="checkbox-stack">
                    <label><input type="checkbox" wire:model="isActive"> {{ __('users.field_active') }}</label>
                </div>

                <div class="form-actions">
                    <flux:button type="submit" variant="primary">{{ __('users.btn_save') }}</flux:button>
                </div>
            </form>
        @endif
    </section>

    @if ($managedUser->user_type === \App\Models\User::TYPE_TENANT)
        <section class="table-shell flux-panel form-panel">
            <div class="form-panel-header">
                <div>
                    <strong>{{ __('users.field_tenant') }}</strong>
                    <span>{{ __('users.tenant_user_role_hint') }}</span>
                </div>
            </div>

            <flux:table class="data-table">
                <flux:table.columns>
                    <flux:table.column>{{ __('users.col_tenant') }}</flux:table.column>
                    <flux:table.column>{{ __('users.col_role') }}</flux:table.column>
                    <flux:table.column>{{ __('users.col_membership_status') }}</flux:table.column>
                    <flux:table.column>{{ __('users.col_actions') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @forelse ($memberships as $membership)
                        <flux:table.row :key="$membership->id">
                            <flux:table.cell>
                                <strong>{{ $membership->tenant?->code ?: '-' }}</strong>
                                <span class="subtle">{{ $membership->tenant?->name ?: '-' }}</span>
                            </flux:table.cell>
                            <flux:table.cell>
                                <select
                                    class="table-control"
                                    @disabled($membership->status !== \App\Models\TenantUser::STATUS_ACTIVE)
                                    x-on:change="$wire.setMembershipRole({{ $membership->id }}, $event.target.value)"
                                >
                                    @foreach ($tenantRoles as $value => $label)
                                        <option value="{{ $value }}" @selected($membership->role === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </flux:table.cell>
                            <flux:table.cell>
                                <x-status-badge :status="$membership->status" :label="$membership->status === \App\Models\TenantUser::STATUS_ACTIVE ? __('users.status_active') : __('users.status_inactive')" />
                            </flux:table.cell>
                            <flux:table.cell>
                                <flux:button
                                    size="sm"
                                    variant="outline"
                                    wire:click="removeMembership({{ $membership->id }})"
                                    wire:confirm="{{ __('users.btn_remove') }}?"
                                >
                                    {{ __('users.btn_remove') }}
                                </flux:button>
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="4">
                                <div class="empty-state">{{ __('users.no_memberships') }}</div>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>

            <div class="form-grid three">
                <flux:select wire:model="addTenantId" :label="__('users.field_tenant')">
                    <flux:select.option value="">{{ __('users.select_tenant') }}</flux:select.option>
                    @foreach ($tenants as $tenant)
                        <flux:select.option value="{{ $tenant->id }}">{{ $tenant->code }} - {{ $tenant->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model="addTenantRole" :label="__('users.field_tenant_role')">
                    @foreach ($tenantRoles as $value => $label)
                        <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>
                <div class="tenant-create-action">
                    <flux:button type="button" variant="primary" wire:click="addMembership">
                        {{ __('users.btn_add_membership') }}
                    </flux:button>
                </div>
            </div>
        </section>
    @endif
</div>
