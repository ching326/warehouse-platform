<div class="tenant-team-page">
    <x-flash-toast />

    @if ($tempPassword)
        <section class="table-shell flux-panel form-panel">
            <div class="form-panel-header">
                <div>
                    <strong>{{ __('users.temp_password_title') }}</strong>
                    <span>{{ __('users.temp_password_hint') }}</span>
                </div>
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

    <section class="table-shell flux-panel form-panel">
        <div class="form-panel-header">
            <div>
                <strong>{{ __('users.team_title') }}</strong>
                <span>{{ __('users.team_subtitle') }}</span>
            </div>
        </div>

        @if ($tenants->count() > 1)
            <flux:select wire:model.live="tenantId" :label="__('users.field_tenant')">
                @foreach ($tenants as $tenant)
                    <flux:select.option value="{{ $tenant->id }}">{{ $tenant->code }} - {{ $tenant->name }}</flux:select.option>
                @endforeach
            </flux:select>
        @endif

        <flux:table class="data-table">
            <flux:table.columns>
                <flux:table.column>{{ __('users.col_user') }}</flux:table.column>
                <flux:table.column>{{ __('users.col_role') }}</flux:table.column>
                <flux:table.column>{{ __('users.col_membership_status') }}</flux:table.column>
                <flux:table.column>{{ __('users.col_actions') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($memberships as $membership)
                    <flux:table.row :key="$membership->id">
                        <flux:table.cell>
                            <strong>{{ $membership->user?->name ?: '-' }}</strong>
                            <span class="subtle">{{ $membership->user?->email ?: '-' }}</span>
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
                @endforeach
            </flux:table.rows>
        </flux:table>
    </section>

    <div class="form-grid">
        <section class="table-shell flux-panel form-panel">
            <div class="form-panel-header">
                <div>
                    <strong>{{ __('users.create_member_heading') }}</strong>
                </div>
            </div>

            <div class="form-grid">
                <flux:input wire:model="newName" :label="__('users.field_name')" />
                <flux:input wire:model="newEmail" type="email" :label="__('users.field_email')" />
            </div>
            <flux:select wire:model="newRole" :label="__('users.field_tenant_role')">
                @foreach ($tenantRoles as $value => $label)
                    <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>
            <div class="form-actions">
                <flux:button type="button" variant="primary" wire:click="createMember">{{ __('users.btn_create_member') }}</flux:button>
            </div>
        </section>

        <section class="table-shell flux-panel form-panel">
            <div class="form-panel-header">
                <div>
                    <strong>{{ __('users.attach_existing_heading') }}</strong>
                    <span>{{ __('users.attach_existing_hint') }}</span>
                </div>
            </div>

            <flux:input wire:model="existingEmail" type="email" :label="__('users.field_existing_email')" />
            <flux:select wire:model="existingRole" :label="__('users.field_tenant_role')">
                @foreach ($tenantRoles as $value => $label)
                    <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>
            <div class="form-actions">
                <flux:button type="button" variant="primary" wire:click="attachExisting">{{ __('users.btn_attach_existing') }}</flux:button>
            </div>
        </section>
    </div>
</div>
