<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'user_type', 'role', 'is_active', 'preferences'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const TYPE_INTERNAL = 'internal';

    public const TYPE_TENANT = 'tenant';

    public const ROLE_INTERNAL_ADMIN = 'internal_admin';

    public const ROLE_WAREHOUSE_STAFF = 'warehouse_staff';

    public const TENANT_ROLE_ADMIN = 'admin';

    public const TENANT_ROLE_STAFF = 'staff';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'preferences' => 'array',
        ];
    }

    public function tenantUsers(): HasMany
    {
        return $this->hasMany(TenantUser::class);
    }

    /**
     * @return list<int>
     */
    public function activeTenantIds(): array
    {
        if (! $this->is_active) {
            return [];
        }

        return $this->tenantUsers()
            ->where('status', TenantUser::STATUS_ACTIVE)
            ->whereHas('tenant', fn ($query) => $query->where('status', 'active'))
            ->pluck('tenant_id')
            ->all();
    }

    /**
     * @return list<int>
     */
    public function adminTenantIds(): array
    {
        if (! $this->is_active || $this->user_type !== self::TYPE_TENANT) {
            return [];
        }

        return $this->tenantUsers()
            ->where('status', TenantUser::STATUS_ACTIVE)
            ->where('role', TenantUser::ROLE_ADMIN)
            ->whereHas('tenant', fn ($query) => $query->where('status', 'active'))
            ->pluck('tenant_id')
            ->all();
    }

    public function administersAnyTenant(): bool
    {
        return $this->adminTenantIds() !== [];
    }

    public function isInternalAdmin(): bool
    {
        return $this->is_active
            && $this->user_type === self::TYPE_INTERNAL
            && $this->role === self::ROLE_INTERNAL_ADMIN;
    }

    public function isWarehouseStaff(): bool
    {
        return $this->is_active
            && $this->user_type === self::TYPE_INTERNAL
            && $this->role === self::ROLE_WAREHOUSE_STAFF;
    }

    public function canManageSetup(): bool
    {
        return $this->isInternalAdmin();
    }

    public function canManageUsers(): bool
    {
        return $this->isInternalAdmin();
    }

    public function canManageTenantUsers(int $tenantId): bool
    {
        return $this->isInternalAdmin() || $this->isTenantAdminFor($tenantId);
    }

    public function canManageBilling(): bool
    {
        return $this->isInternalAdmin();
    }

    public function canManageApiCredentials(): bool
    {
        return $this->isInternalAdmin();
    }

    public function canOperateWarehouse(): bool
    {
        return $this->isInternalAdmin() || $this->isWarehouseStaff();
    }

    public function canExportCourierLabels(): bool
    {
        return $this->canOperateWarehouse();
    }

    public function canMutateInventory(): bool
    {
        return $this->canOperateWarehouse();
    }

    public function isTenantAdminFor(int $tenantId): bool
    {
        return $this->hasActiveTenantRole($tenantId, [self::TENANT_ROLE_ADMIN]);
    }

    public function isTenantStaffFor(int $tenantId): bool
    {
        return $this->hasActiveTenantRole($tenantId, [self::TENANT_ROLE_STAFF]);
    }

    public function canImportTenantOrders(int $tenantId): bool
    {
        return $this->isInternalAdmin()
            || $this->hasActiveTenantRole($tenantId, [self::TENANT_ROLE_ADMIN, self::TENANT_ROLE_STAFF]);
    }

    /**
     * @param  array<int, string>  $roles
     */
    private function hasActiveTenantRole(int $tenantId, array $roles): bool
    {
        if (! $this->is_active || $this->user_type !== self::TYPE_TENANT) {
            return false;
        }

        return $this->tenantUsers()
            ->where('tenant_id', $tenantId)
            ->where('status', TenantUser::STATUS_ACTIVE)
            ->whereIn('role', $roles)
            ->whereHas('tenant', fn ($query) => $query->where('status', 'active'))
            ->exists();
    }

    public function inventoryMovements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }

    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class, 'tenant_users')
            ->withPivot(['role', 'status', 'invited_at', 'joined_at'])
            ->withTimestamps();
    }

    public function preference(string $key, mixed $default = null): mixed
    {
        return data_get($this->preferences, $key, $default);
    }

    public function setPreference(string $key, mixed $value): void
    {
        $preferences = $this->preferences ?? [];
        data_set($preferences, $key, $value);

        $this->update(['preferences' => $preferences]);
    }

    public function forgetPreference(string $key): void
    {
        $preferences = $this->preferences ?? [];
        data_forget($preferences, $key);

        $this->update(['preferences' => $preferences === [] ? null : $preferences]);
    }
}
