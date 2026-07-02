<div class="user-index-page">
    <x-flash-toast />

    <x-page-panel-header
        :title="__('users.index_title')"
        :subtitle="__('users.index_subtitle')"
    />

    <section class="table-shell flux-panel">
        <div class="movement-toolbar tenant-toolbar">
            <flux:select wire:model.live="userType" :label="__('users.filter_user_type')">
                <flux:select.option value="">{{ __('users.all_user_types') }}</flux:select.option>
                @foreach ($userTypes as $value => $label)
                    <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="role" :label="__('users.filter_role')">
                <flux:select.option value="">{{ __('users.all_roles') }}</flux:select.option>
                @foreach ($roles as $value => $label)
                    <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="status" :label="__('users.filter_status')">
                @foreach ($statuses as $value => $label)
                    <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:input
                wire:model.live.debounce.300ms="search"
                :label="__('users.search_label')"
                :placeholder="__('users.search_placeholder')"
            />

            <div class="tenant-create-action">
                <flux:button href="{{ route('setup.users.create') }}" variant="primary">
                    {{ __('users.btn_invite_user') }}
                </flux:button>
            </div>
        </div>

        <flux:table :paginate="$users" class="data-table">
            <flux:table.columns>
                <flux:table.column>{{ __('users.col_user') }}</flux:table.column>
                <flux:table.column>{{ __('users.col_type') }}</flux:table.column>
                <flux:table.column>{{ __('users.col_role') }}</flux:table.column>
                <flux:table.column>{{ __('users.col_status') }}</flux:table.column>
                <flux:table.column>{{ __('users.col_actions') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($users as $user)
                    @php
                        $chips = $this->membershipChips($user);
                        $visibleChips = array_slice($chips, 0, 2);
                    @endphp
                    <flux:table.row :key="$user->id">
                        <flux:table.cell>
                            <strong>{{ $user->name }}</strong>
                            <span class="subtle">{{ $user->email }}</span>
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:badge>{{ $this->userTypeLabel($user->user_type) }}</flux:badge>
                        </flux:table.cell>
                        <flux:table.cell>
                            @if ($user->user_type === \App\Models\User::TYPE_INTERNAL)
                                <flux:badge color="teal">{{ $this->roleLabel($user->role) }}</flux:badge>
                            @else
                                <div class="status-stack">
                                    @forelse ($visibleChips as $chip)
                                        <flux:badge color="zinc">{{ $chip }}</flux:badge>
                                    @empty
                                        <span class="subtle">-</span>
                                    @endforelse
                                    @if (count($chips) > 2)
                                        <span class="subtle">{{ __('users.more_memberships', ['count' => count($chips) - 2]) }}</span>
                                    @endif
                                </div>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>
                            <x-status-badge
                                :status="$user->is_active ? 'active' : 'inactive'"
                                :label="$user->is_active ? __('users.status_active') : __('users.status_inactive')"
                            />
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:button href="{{ route('setup.users.edit', $user) }}" variant="primary">
                                {{ __('users.btn_edit') }}
                            </flux:button>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="5">
                            <div class="empty-state">{{ __('users.empty_state') }}</div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </section>

    <style>
        .user-index-page .tenant-toolbar {
            grid-template-columns: 150px 180px 130px minmax(240px, 1fr) auto;
        }

        @media (max-width: 900px) {
            .user-index-page .tenant-toolbar {
                grid-template-columns: 1fr;
            }
        }
    </style>
</div>
