<?php

namespace App\Livewire;

use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\Users\TenantMembershipService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class TenantTeam extends Component
{
    public string $tenantId = '';

    public string $newName = '';

    public string $newEmail = '';

    public string $newRole = TenantUser::ROLE_STAFF;

    public string $existingEmail = '';

    public string $existingRole = TenantUser::ROLE_STAFF;

    public ?string $tempPassword = null;

    public function mount(): void
    {
        $adminTenantIds = $this->adminTenantIds();

        if ($adminTenantIds === []) {
            abort(403);
        }

        $this->tenantId = (string) $adminTenantIds[0];
    }

    public function updatedTenantId(): void
    {
        $this->authorizeSelectedTenant();
    }

    public function createMember(TenantMembershipService $memberships): void
    {
        $this->authorizeSelectedTenant();

        $data = $this->validate([
            'newName' => ['required', 'string', 'max:255'],
            'newEmail' => ['required', 'email', 'max:255'],
            'newRole' => ['required', Rule::in(TenantUser::ROLES)],
        ]);

        if (User::query()->where('email', mb_strtolower(trim($data['newEmail'])))->exists()) {
            throw ValidationException::withMessages([
                'newEmail' => __('users.no_eligible_user'),
            ]);
        }

        $password = Str::random(16);
        $actor = Auth::user();

        DB::transaction(function () use ($data, $password, $actor, $memberships): void {
            $user = User::query()->create([
                'name' => trim($data['newName']),
                'email' => mb_strtolower(trim($data['newEmail'])),
                'password' => $password,
                'user_type' => User::TYPE_TENANT,
                'role' => null,
                'is_active' => true,
            ]);

            $memberships->addMembership($actor, (int) $this->tenantId, $user, $data['newRole']);
        });

        $this->tempPassword = $password;
        $this->newName = '';
        $this->newEmail = '';
        $this->newRole = TenantUser::ROLE_STAFF;
        session()->flash('status', __('users.member_created'));
    }

    public function attachExisting(TenantMembershipService $memberships): void
    {
        $this->authorizeSelectedTenant();

        $data = $this->validate([
            'existingEmail' => ['required', 'email', 'max:255'],
            'existingRole' => ['required', Rule::in(TenantUser::ROLES)],
        ]);

        $user = User::query()
            ->where('email', mb_strtolower(trim($data['existingEmail'])))
            ->first();

        if (! $user instanceof User || $user->user_type !== User::TYPE_TENANT) {
            throw ValidationException::withMessages([
                'existingEmail' => __('users.no_eligible_user'),
            ]);
        }

        $memberships->addMembership(Auth::user(), (int) $this->tenantId, $user, $data['existingRole']);
        $this->existingEmail = '';
        $this->existingRole = TenantUser::ROLE_STAFF;
        session()->flash('status', __('users.membership_added'));
    }

    public function setMembershipRole(int $membershipId, string $role, TenantMembershipService $memberships): void
    {
        $membership = $this->membershipForSelectedTenant($membershipId);
        $memberships->setRole(Auth::user(), $membership, $role);
        session()->flash('status', __('users.membership_updated'));
    }

    public function removeMembership(int $membershipId, TenantMembershipService $memberships): void
    {
        $membership = $this->membershipForSelectedTenant($membershipId);
        $memberships->remove(Auth::user(), $membership);
        session()->flash('status', __('users.membership_removed'));
    }

    public function render()
    {
        $this->authorizeSelectedTenant();

        return view('livewire.tenant-team', [
            'tenants' => Tenant::query()
                ->whereIn('id', $this->adminTenantIds())
                ->orderBy('code')
                ->get(['id', 'code', 'name']),
            'memberships' => TenantUser::query()
                ->with('user:id,name,email,is_active')
                ->where('tenant_id', (int) $this->tenantId)
                ->orderBy('role')
                ->orderBy('id')
                ->get(),
            'tenantRoles' => $this->tenantRoleOptions(),
        ])->layout('inventory', [
            'title' => __('users.team_title'),
            'subtitle' => __('users.team_subtitle'),
            'pageWide' => true,
        ]);
    }

    private function membershipForSelectedTenant(int $membershipId): TenantUser
    {
        return TenantUser::query()
            ->where('tenant_id', (int) $this->tenantId)
            ->findOrFail($membershipId);
    }

    private function authorizeSelectedTenant(): void
    {
        if (! in_array((int) $this->tenantId, $this->adminTenantIds(), true)) {
            abort(403);
        }
    }

    private function adminTenantIds(): array
    {
        return Auth::user()?->adminTenantIds() ?? [];
    }

    private function tenantRoleOptions(): array
    {
        return [
            TenantUser::ROLE_ADMIN => __('users.role_tenant_admin'),
            TenantUser::ROLE_STAFF => __('users.role_tenant_staff'),
        ];
    }
}
