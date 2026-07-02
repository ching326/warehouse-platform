<?php

namespace App\Livewire;

use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\Users\TenantMembershipService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class UserEdit extends Component
{
    public User $managedUser;

    public string $internalRole = '';

    public bool $isActive = true;

    public string $addTenantId = '';

    public string $addTenantRole = TenantUser::ROLE_STAFF;

    public function mount(User $user): void
    {
        if (! Auth::user()?->canManageUsers()) {
            abort(403);
        }

        $this->managedUser = $user->load(['tenantUsers.tenant:id,code,name']);
        $this->internalRole = (string) $user->role;
        $this->isActive = (bool) $user->is_active;
    }

    public function saveInternal(): void
    {
        if ($this->managedUser->user_type !== User::TYPE_INTERNAL) {
            abort(404);
        }

        $data = $this->validate([
            'internalRole' => ['required', Rule::in([User::ROLE_INTERNAL_ADMIN, User::ROLE_WAREHOUSE_STAFF])],
            'isActive' => ['boolean'],
        ]);

        DB::transaction(function () use ($data): void {
            $user = User::query()
                ->whereKey($this->managedUser->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($user->role === User::ROLE_INTERNAL_ADMIN
                && ($data['internalRole'] !== User::ROLE_INTERNAL_ADMIN || ! $data['isActive'])) {
                $remainingAdmins = User::query()
                    ->where('user_type', User::TYPE_INTERNAL)
                    ->where('role', User::ROLE_INTERNAL_ADMIN)
                    ->where('is_active', true)
                    ->where('id', '!=', $user->id)
                    ->lockForUpdate()
                    ->count();

                if ($remainingAdmins === 0) {
                    throw ValidationException::withMessages([
                        'internalRole' => __('users.validation_last_internal_admin'),
                    ]);
                }
            }

            $user->forceFill([
                'role' => $data['internalRole'],
                'is_active' => (bool) $data['isActive'],
            ])->save();
        });

        $this->managedUser->refresh();
        session()->flash('status', __('users.user_updated'));
    }

    public function saveTenantAccount(TenantMembershipService $memberships): void
    {
        if ($this->managedUser->user_type !== User::TYPE_TENANT) {
            abort(404);
        }

        $data = $this->validate(['isActive' => ['boolean']]);

        if (! $data['isActive']) {
            $memberships->assertUserIsNotLastAdminForAnyTenant($this->managedUser);
        }

        $this->managedUser->forceFill([
            'role' => null,
            'is_active' => (bool) $data['isActive'],
        ])->save();

        $this->managedUser->refresh();
        session()->flash('status', __('users.user_updated'));
    }

    public function addMembership(TenantMembershipService $memberships): void
    {
        if ($this->managedUser->user_type !== User::TYPE_TENANT) {
            abort(404);
        }

        $data = $this->validate([
            'addTenantId' => ['required', Rule::exists('tenants', 'id')->where('status', 'active')],
            'addTenantRole' => ['required', Rule::in(TenantUser::ROLES)],
        ]);

        $memberships->addMembership(Auth::user(), (int) $data['addTenantId'], $this->managedUser, $data['addTenantRole']);
        $this->managedUser->load(['tenantUsers.tenant:id,code,name']);
        $this->addTenantId = '';
        $this->addTenantRole = TenantUser::ROLE_STAFF;
        session()->flash('status', __('users.membership_added'));
    }

    public function setMembershipRole(int $membershipId, string $role, TenantMembershipService $memberships): void
    {
        $membership = $this->membershipForManagedUser($membershipId);
        $memberships->setRole(Auth::user(), $membership, $role);
        $this->managedUser->load(['tenantUsers.tenant:id,code,name']);
        session()->flash('status', __('users.membership_updated'));
    }

    public function removeMembership(int $membershipId, TenantMembershipService $memberships): void
    {
        $membership = $this->membershipForManagedUser($membershipId);
        $memberships->remove(Auth::user(), $membership);
        $this->managedUser->load(['tenantUsers.tenant:id,code,name']);
        session()->flash('status', __('users.membership_removed'));
    }

    public function render()
    {
        return view('livewire.user-edit', [
            'internalRoles' => $this->internalRoleOptions(),
            'tenantRoles' => $this->tenantRoleOptions(),
            'tenants' => Tenant::query()->orderBy('code')->get(['id', 'code', 'name']),
            'memberships' => $this->managedUser->tenantUsers()->with('tenant:id,code,name')->orderBy('tenant_id')->get(),
        ])->layout('inventory', [
            'title' => __('users.edit_title'),
            'subtitle' => $this->managedUser->email,
        ]);
    }

    private function membershipForManagedUser(int $membershipId): TenantUser
    {
        return TenantUser::query()
            ->where('user_id', $this->managedUser->id)
            ->findOrFail($membershipId);
    }

    private function internalRoleOptions(): array
    {
        return [
            User::ROLE_INTERNAL_ADMIN => __('users.role_internal_admin'),
            User::ROLE_WAREHOUSE_STAFF => __('users.role_warehouse_staff'),
        ];
    }

    private function tenantRoleOptions(): array
    {
        return [
            TenantUser::ROLE_ADMIN => __('users.role_tenant_admin'),
            TenantUser::ROLE_STAFF => __('users.role_tenant_staff'),
        ];
    }
}
