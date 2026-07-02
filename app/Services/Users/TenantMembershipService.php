<?php

namespace App\Services\Users;

use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TenantMembershipService
{
    public function addMembership(User $actor, int $tenantId, User $member, string $role): TenantUser
    {
        $this->guardTenantRole($role);
        $this->guardCanManageTenant($actor, $tenantId);

        if ($member->user_type !== User::TYPE_TENANT) {
            throw ValidationException::withMessages([
                'memberEmail' => __('users.no_eligible_user'),
            ]);
        }

        return DB::transaction(function () use ($tenantId, $member, $role): TenantUser {
            $membership = TenantUser::query()
                ->where('tenant_id', $tenantId)
                ->where('user_id', $member->id)
                ->lockForUpdate()
                ->first();

            if ($membership instanceof TenantUser) {
                if ($membership->status === TenantUser::STATUS_ACTIVE) {
                    throw ValidationException::withMessages([
                        'memberEmail' => __('users.no_eligible_user'),
                    ]);
                }

                $membership->forceFill([
                    'role' => $role,
                    'status' => TenantUser::STATUS_ACTIVE,
                    'joined_at' => $membership->joined_at ?? now(),
                ])->save();

                return $membership;
            }

            return TenantUser::query()->create([
                'tenant_id' => $tenantId,
                'user_id' => $member->id,
                'role' => $role,
                'status' => TenantUser::STATUS_ACTIVE,
                'joined_at' => now(),
            ]);
        });
    }

    public function setRole(User $actor, TenantUser $membership, string $role): void
    {
        $this->guardTenantRole($role);
        $this->guardCanManageTenant($actor, (int) $membership->tenant_id);

        DB::transaction(function () use ($membership, $role): void {
            $locked = TenantUser::query()
                ->whereKey($membership->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->role === $role) {
                return;
            }

            if ($locked->role === TenantUser::ROLE_ADMIN && $role !== TenantUser::ROLE_ADMIN) {
                $this->guardNotLastActiveTenantAdmin($locked);
            }

            $locked->forceFill(['role' => $role])->save();
        });
    }

    public function remove(User $actor, TenantUser $membership): void
    {
        $this->guardCanManageTenant($actor, (int) $membership->tenant_id);

        DB::transaction(function () use ($membership): void {
            $locked = TenantUser::query()
                ->whereKey($membership->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== TenantUser::STATUS_ACTIVE) {
                return;
            }

            if ($locked->role === TenantUser::ROLE_ADMIN) {
                $this->guardNotLastActiveTenantAdmin($locked);
            }

            $locked->forceFill(['status' => TenantUser::STATUS_INACTIVE])->save();
        });
    }

    public function assertUserIsNotLastAdminForAnyTenant(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $memberships = TenantUser::query()
                ->where('user_id', $user->id)
                ->where('status', TenantUser::STATUS_ACTIVE)
                ->where('role', TenantUser::ROLE_ADMIN)
                ->lockForUpdate()
                ->get();

            foreach ($memberships as $membership) {
                $this->guardNotLastActiveTenantAdmin($membership);
            }
        });
    }

    private function guardCanManageTenant(User $actor, int $tenantId): void
    {
        if (! $actor->canManageTenantUsers($tenantId)) {
            abort(403);
        }
    }

    private function guardTenantRole(string $role): void
    {
        if (! in_array($role, TenantUser::ROLES, true)) {
            throw ValidationException::withMessages([
                'role' => __('users.validation_invalid_tenant_role'),
            ]);
        }
    }

    private function guardNotLastActiveTenantAdmin(TenantUser $membership): void
    {
        $remainingAdmins = TenantUser::query()
            ->where('tenant_id', $membership->tenant_id)
            ->where('status', TenantUser::STATUS_ACTIVE)
            ->where('role', TenantUser::ROLE_ADMIN)
            ->where('id', '!=', $membership->id)
            ->lockForUpdate()
            ->count();

        if ($remainingAdmins === 0) {
            throw ValidationException::withMessages([
                'membership' => __('users.validation_last_tenant_admin'),
            ]);
        }
    }
}
